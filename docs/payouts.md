# Выплаты из эскроу (intent/report)

> Архитектура, инварианты и план — в [`payouts-redesign.md`](payouts-redesign.md); здесь — операторский регламент.
> Статус: **реализовано** — `docker exec --user=root tronopay-php-fpm-1 ./bin/phpunit` зелёный.

Как в Tronopay устроены исходящие выплаты: по `accepted` задаче эскроу платит исполнителю, по
`rejected` — возвращает деньги заказчику.

Главное отличие от «обычной» интеграции: **приватные ключи и мнемоника эскроу никогда не попадают на
онлайн-сервер**. Онлайн-часть знает только «откуда/куда/сколько» (интент) и результат (отчёт). Транзакцию
строит, подписывает и публикует **сама офлайн-машина с ключами** (`app:payout:settle`), у которой нет базы
данных. Обмен между машинами — два файла.

## Модель угроз

| Компрометация | Последствие |
| --- | --- |
| Онлайн-сервер | Утечка `TRON_XPUB_MASTER_KEY` (watch-only). Адреса эскроу видны, **но подписать перевод нельзя**: сервер не хранит `raw_data`, подписи, ключи. |
| `*.intent.json` в пути | Только публичные данные (адреса, сумма, `walletIndex`). Подмена суммы/получателя ловится SHA-256-чексиком; кроме того, машина сверяет выведенный из мнемоники адрес с `fromAddress`. |
| `*.report.json` в пути | Только результат. Сервер не считает деньги отправленными по чужому отчёту: итог применяется только к строке `payout`, совпадающей с интентом (taskId/direction/from/to/amount). |
| Офлайн-машина | Единственное место, где есть мнемоника. Ключ выводится в память на время обработки, на диск не пишется. |

## Компоненты

| Что | Файл |
| --- | --- |
| Строка выплаты (одна на задачу) | `src/Entity/Payout.php`, `unique(task_id)` |
| Направление (`worker` / `refund`) | `src/Entity/PayoutDirection.php` |
| Статус (`prepared` / `broadcast` / `confirmed` / `failed` / `abandoned`) | `src/Entity/PayoutStatus.php` |
| Причина сбоя (`retryable` / `permanent`) | `src/Entity/PayoutFailureKind.php` |
| Итог обработки (`confirmed` / `broadcast` / `failed`) | `src/Entity/PayoutOutcome.php` |
| Форматы `*.intent.json` / `*.report.json` | `src/ValueObject/PayoutIntentBundle.php`, `PayoutReportBundle.php` |
| Чтение/запись файлов | `src/Service/PayoutBundleStorage.php` |
| Подготовка интента (онлайн) | `src/Service/PayoutPreparer.php` |
| Сборка+подпись+публикация (офлайн) | `src/Service/PayoutSettler.php` |
| Применение отчёта (онлайн) | `src/Service/PayoutReportApplier.php` |
| Шлюз TRON-ноды | `src/Service/TronGateway.php` (интерфейс `TronGatewayInterface`) |

Направление и адресат определяются **терминальным статусом задачи**:

- `accepted` → `worker`: с адреса `Task.wallet.address` (эскроу) на `Task.assignedTo.tronAddress`, сумма `Task.priceSun`;
- `rejected` → `refund`: с того же эскроу на `Task.refundAddress`, сумма `Task.priceSun`.

Статус задачи при этом не меняется: `accepted`/`rejected` остаются терминальными, выплата живёт
отдельной строкой. Начисление исполнителю (`SUM(price_sun)` по `accepted`) не затронуто.

## Настройка

В `.env` (или `.env.local`):

- `PAYOUT_ENABLED=1` — рубильник подготовки. Пока `0`, `app:payout:prepare` отказывается строить.
- `PAYOUT_DIR=var/payout` — каталог обмена файлами (интенты/отчёты).
- `PAYOUT_BATCH_SIZE=200` — максимум выплат на один запуск подготовки.
- `PAYOUT_TTL="30 minutes"` — короткий запас машине на время между сборкой транзакции и подписью.
  Не влияет на корректность: `ref_block` всегда свежий, потому что транзакция публикуется сразу.
- `PAYOUT_FEE_MARGIN=20` — процент запаса к оценке комиссии при проверке баланса эскроу.
- `TRON_MNEMONIC` / `TRON_PASSPHRASE` — только на офлайн-машине (или `--mnemonic-file`/`--passphrase-file`).
- `TRON_NETWORK` — `mainnet` / `nile` / `shasta`.

Офлайн-окружение не обращается к БД: `app:payout:settle` читает файл, общается с нодой, пишет отчёт.
Сеть записана в интенте, и машина отказывается сводить интент, собранный для другой сети.

## Ежедневный регламент

### 1. Онлайн: подготовить интент

```bash
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:prepare
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:prepare --limit 50
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:prepare --date 2026-10-12-b1
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:prepare --dry-run
```

Пишет `PAYOUT_DIR/<batchId>.intent.json`, помечает строки `prepared` и печатает таблицы `prepared`,
`failed`, `skipped`. Код возврата — `FAILURE`, если есть `failed`. Мьютекс `app.payout-prepare` не даёт
запустить две подготовки одновременно.

Типичные `failed` (разбираются вручную, см. ниже):

- `the rejected task has no refund address` — у `rejected` задачи не заполнен `Task.refundAddress`;
- `the accepted task has no executor` — у задачи нет исполнителя или у него не заполнен `tronAddress`;
- `the escrow wallet address is not derived yet` — задача ещё не получила адрес эскроу;
- `the recipient equals the escrow wallet` — получатель совпал с отправителем;
- `the task has no positive amount` — `Task.priceSun` не положителен.

### 2. Перенести интент на офлайн-машину

USB или `scp`. Файл содержит только публичные данные; каталог `PAYOUT_DIR` создаётся с правами `0755`,
файлы пишутся с `0644`, чтобы оператор мог прочитать их и при запуске команды в контейнере от имени
`www-data` (bind-mount `var/` на хосте).

### 3. Офлайн: свести интент

```bash
APP_ENV=offline bin/console app:payout:settle --in <batchId>.intent.json [--out <batchId>.report.json]
APP_ENV=offline bin/console app:payout:settle --in ... --mnemonic-file /media/usb/mnemonic.txt
APP_ENV=offline bin/console app:payout:settle --in ... --passphrase-file /media/usb/passphrase.txt
APP_ENV=offline bin/console app:payout:settle --in ... --dry-run
```

- Мнемоника берётся в порядке: `--mnemonic-file` → `TRON_MNEMONIC` → скрытый интерактивный ввод.
  **Никогда не передавайте мнемонику аргументом командной строки** — она попадёт в history и в `ps`.
- Машина для каждого пункта выводит ключ по `walletIndex`, сверяет адрес с `fromAddress`, строит
  транзакцию у ноды, публикует и читает receipt — итог `confirmed` / `broadcast` / `failed`.
- Пишет `<batchId>.report.json` и журнал (`.batchId.journal.json`) — защита от повторной подписи/отправки
  при краше «после подписи, до отправки».

### 4. Вернуть отчёт на онлайн-сервер

### 5. Онлайн: применить отчёт

```bash
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:apply-report --intent <intent> --report <report>
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:apply-report --intent <intent> --report <report> --dry-run
```

Сворачивает строки в `broadcast` / `confirmed` / `failed` (идемпотентно; `confirmed`/`broadcast` больше
не трогается). Мьютекс `app.payout-apply-report`.

### 6. Онлайн: проконтролировать

```bash
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:status
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:status --status failed
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:status --batch 2026-10-12 --limit 50
```

«Требует внимания» — только `failed`.

## Разбор сбоев

Автоматического ретрая нет — сбой разбирается вручную, чтобы не отправить деньги дважды.

```bash
# Понять, что произошло
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:status --status failed

# Деньги НЕ ушли: закрыть выплату, потом при необходимости пересобрать
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:resolve <task-uuid> --abandon --reason "..."
docker exec --user=root tronopay-php-fpm-1 ./bin/console app:payout:prepare --date 2026-10-13
```

`resolve --abandon` разрешён для `prepared`/`failed`/`broadcast`; `confirmed` закрыть нельзя.

## Гарантии

- **Идемпотентность.** `unique(task_id)` — одна выплата на задачу. Выборка задач исключает те, у
  которых есть `broadcast`/`confirmed` или `failed/permanent` выплата, поэтому повторный ежедневный
  прогон ничего не переплатит.
- **Согласованность файла и БД.** Интент пишется до `flush()`; при исключении файл удаляется.
- **Целостность файлов.** SHA-256 по содержимому (`checksum`) + проверка версии/типа формата при чтении;
  файл, изменённый в пути, отвергается.
- **Свежий `ref_block`.** Подписанная транзакция не пересекает границу сервер↔машина: она строится,
  подписывается и публикуется на офлайн-машине в один заход, поэтому устаревание `ref_block`/`timestamp`
  не возникает в принципе.
- **Kill switch.** Пока `PAYOUT_ENABLED=0`, `app:payout:prepare` отказывается работать.

## Проверка перед mainnet

Перед mainnet обязательно прогнать полный цикл на **nile**:

1. `TRON_NETWORK=nile`, `PAYOUT_ENABLED=1`, эскроу-кошелёк с небольшим балансом.
2. Создать тестовую задачу, провести её в `accepted`, выполнить шаги 1–6 регламента.
3. Убедиться, что транзакция принята и `app:payout:status` показывает `confirmed`.

## Тесты

```bash
docker exec --user=root tronopay-php-fpm-1 ./bin/phpunit
```

Ключевые файлы:

- `tests/Service/PayoutPreparerTest.php` — выборка задач, адреса, направление, идемпотентность, пересборка;
- `tests/Service/PayoutSettlerTest.php` — сборка+подпись+broadcast+receipt, «уже в сети», ошибки, комиссия, журнал;
- `tests/Service/PayoutReportApplierTest.php` — идемпотентность, несовпадение интента/отчёта;
- `tests/Service/PayoutBundleStorageTest.php` — файлы интента/отчёта, checksum, path traversal;
- `tests/Service/OfflineTransferSignerTest.php`, `tests/Service/HdWalletMnemonicSignerTest.php` — подпись и вывод ключей;
- `tests/Command/PayoutWorkflowCommandTest.php` — полный цикл prepare → settle → apply-report через команды;
- `tests/Command/PayoutResolveCommandTest.php`, `tests/Command/PayoutStatusCommandTest.php` — ручной разбор сбоев и отчёт;
- `tests/ValueObject/PayoutIntentBundleTest.php`, `tests/ValueObject/PayoutReportBundleTest.php` — форматы файлов.

Все тесты, обращающиеся к ноде, работают с заглушкой `TronGatewayInterface`; реальных транзакций не отправляется.
