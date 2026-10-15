# Архитектура выплат из эскроу (intent/report)

> Статус: **реализовано**. `docker exec --user=root tronopay-php-fpm-1 ./bin/phpunit` и `lint:container`
> зелёные. Операторский регламент, модель угроз и гарантии — [`docs/payouts.md`](payouts.md).
>
> Прежняя air-gapped схема (`prepare → sign → broadcast` с переносом подписанной транзакции) отменена:
> подписанная транзакция привязана к `ref_block`/`timestamp` из момента сборки и устаревает за время
> переноса файла через USB/часы.

## 1. Целевая архитектура

Три стороны, два файла, ни один секрет не пересекает границу:

```
┌──────────────────────────────┐      intent-файл      ┌──────────────────────────────────┐
│ Онлайн-сервер (БД, веб)      │ ────────────────────▶ │ Машина с ключами (mnemonic)      │
│ app:payout:prepare           │  <batchId>.intent.json│ app:payout:settle                │
│ app:payout:apply-report      │ ◀──────────────────── │  (собирает tx у ноды,            │
│  только бизнес-логика        │  <batchId>.report.json│   подписывает, САМА публикует,   │
│  не знает ни raw_data,       │                       │   получает receipt)             │
│  ни подписей, ни ключей      │                       └──────────────────────────────────┘
└──────────────────────────────┘                                       │
    ▲          │                                                       │ TRON-нода (RPC)
    │          └── Payout-строка в БД (prepared) ◀─────────────────────┘ (свежий ref_block,
    └──── report → сворачиваем: broadcast / confirmed / failed            короткий expiration)
```

- **Онлайн-сервер** знает только «откуда/куда/сколько» (интент) и результат из отчёта.
  Не строит транзакции для выплат, не хранит `raw_data`, подписи, ключи.
- **Машина с ключами** (`APP_ENV=offline`): единственное место с мнемоникой. Строит
  транзакцию у ноды **в момент обработки**, подписывает и **сразу публикует**, поэтому
  `ref_block` всегда свежий — проблема TTL/ref_block исчезает в корне.
  У машины **нет БД** — она работает только с файлами.
- **TRON-нода**: доступ только у машины с ключами (для выплат). Сервер продолжает
  пользоваться нодой только для приёма платежей (`PaymentChecker`), это отдельный контур.

## 2. Форматы файлов (JSON, в каталоге `PAYOUT_DIR`)

### `<batchId>.intent.json` (пишет сервер, читает машина)
```jsonc
{
  "type": "intent",
  "version": 2,
  "network": "mainnet",
  "batchId": "2026-10-13",
  "createdAt": "2026-10-13T09:00:00+03:00",
  "checksum": "sha256(items-json)",
  "items": [
    {
      "taskId": "uuid",
      "payoutId": "uuid",          // id строки Payout, чтобы свернуть отчёт
      "direction": "worker|refund",
      "walletIndex": 3,            // машина выводит ключ из мнемоники по этому индексу
      "fromAddress": "T…",         // ОЖИДАЕМЫЙ адрес эскроу (watch-only xpub); машина
                                   // сверяет с выведенным из мнемоники и отказывает при расхождении
      "toAddress": "T…",
      "amountSun": 10000000
    }
  ]
}
```

### `<batchId>.report.json` (пишет машина, читает сервер)
```jsonc
{
  "type": "report",
  "version": 2,
  "network": "mainnet",
  "batchId": "2026-10-13",
  "createdAt": "2026-10-13T09:05:00+03:00",
  "settledAt": "2026-10-13T09:04:59+03:00",   // момент обработки машиной
  "checksum": "sha256(items-json)",
  "items": [
    {
      "taskId": "uuid",
      "payoutId": "uuid",
      "outcome": "confirmed|broadcast|failed",
      "txId": "64-hex",             // заполнен для confirmed/broadcast
      "feeSun": 123456,             // фактическая комиссия из receipt
      "blockNumber": 654321,        // из receipt
      "failureKind": "retryable|permanent", // только при outcome=failed
      "error": "человекочитаемая причина"
    }
  ]
}
```
- Отчёт **покрывает все пункты** интента (успех или ошибка) — сервер сверяет по `payoutId`.
- Оба файла: версия + SHA-256 по items, отказ от `..` в путях, `chmod 0700`/`0600`
  (переиспользуем логику `PayoutBundleStorage`).

## 3. Статусы строки `Payout` и переходы

```
prepared ──(report: sent)──────────▶ broadcast ──(report: receipt SUCCESS)──▶ confirmed
    │                                    │
    │(report: error, retryable)          │(report: error / нет receipt) ──▶ failed
    └────────────────────────────────────┘
prepared / failed / broadcast ──(operator: resolve --abandon)──▶ abandoned ─(reprepare)▶ prepared
```
- `confirmed` — деньги гарантированно ушли (receipt `SUCCESS` + контракт совпадает с интентом).
- `isSettled()` = `broadcast | confirmed`; таких задач повторный prepare не трогает.
- `failed` + `failureKind=retryable` → новый prepare подхватывает задачу снова.
- `failed` + `failureKind=permanent` → prepare **пропускает** до ручного `resolve`.
- `prepared` → prepare **не** пересобирает (машина может ещё обработать); оператор при
  потере интента сначала `resolve --abandon`, потом заново prepare.
- Ручного `--confirm-tx <hex>` **больше нет** — статус определяется только отчётом машины.

## 4. Комиссия

Фиксированный буфер `PAYOUT_FEE_BUFFER_SUN` на сервере **убираем** из prepare.
Реальную комиссию считает машина с ключами прямо перед отправкой (у неё есть нода):

1. Оценка bandwidth транзакции: `TransactionCostCalculator::bandwidth($tx, 1)` (~200 байт).
2. Доступный bandwidth эскроу: `accounts()->bandwidth($from)` / `accounts()->resources($from)`
   (`NetLimit-NetUsed`, остаток `freeNetLimit-freeNetUsed`).
3. Цена: `network()->chainParameters()` → `getTransactionFee`, `getEnergyFee`;
   активация получателя — `getCreateNewAccountFee`/`getCreateAccountFee`, вкл. только если
   `accounts()->get($to)` = null (получатель ещё не активирован).
4. `feeEstimate = max(0, bandwidthNeeded - availableBandwidth) * bandwidthPrice + activateFee`.
5. Требование перед отправкой: `balanceSun(from) >= amount + feeEstimate + margin`
   (margin конфигурируемый, по умолчанию ~20%).
6. Фактическую комиссию берём из `receipt().feeSun` и кладём в отчёт → сервер пишет
   в `Payout.feeSun` (аудит).

Аналогично уходит риск «упала после подписи»: баланс проверяется на машине **перед**
каждой отправкой, недостаток средств → `failed/retryable` с понятной причиной.

## 5. Хардненинг машины с ключами

Машина с ключами — самый чувствительный узел (ключи + доступ к ноде):

- только исходящий доступ к доверенной ноде, никакого входящего трафика и лишних сервисов;
- мнемоника только в памяти (`--mnemonic-file` / скрытый ввод / environment, никогда не в argv),
  ключ выводится in-memory на время обработки и не пишется на диск;
- сверка адреса: выведенный из мнемоники адрес обязан совпасть с `fromAddress` из интента,
  иначе отказ `failed/permanent`.

Инварианты и гарантии (идемпотентность, целостность файлов, соответствие интента/отчёта,
kill switch, свежий `ref_block`) — в [`payouts.md`](payouts.md), разделы «Модель угроз» и «Гарантии».


