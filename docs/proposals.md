# Tronopay — предложения по развитию (onboarding)

> Дата: 2026-10-11 · Область: onboarding, файл с предложениями · Статус: черновик (реализовано: п. 1.1)
>
> Состояние проекта на момент написания: Symfony 8.1 / PHP 8.5, API Platform 5, Doctrine ORM 3 + PostGIS,
> Twig + Bootstrap 5.3.8 (AssetMapper/importmap), Workflow, Messenger (doctrine), Lock (flock).
> Рабочее дерево чистое (1057fd7). Тесты: 109 / 109 зелёные. `lint:twig` и `lint:container` — ок.

## 0. Что было проверено

- `/` рендерится (200) в `APP_ENV=dev`; все ассеты отдаются 200; DONATE-секция активна (`APP_DONATE` в `.env.local`).
- `POST /api/tasks/create` (как в landing) — **405**; реальный endpoint — `POST /api/tasks` (см. п. 2.2).
- `POST /api/tasks` (без куки-профайлера) — **500**: `EntityChangesService::addTagAdded(): Argument #1 ($string) must be of type ?string, DateTimeImmutable given` (см. п. 1.1).
- В dev-БД одна «мусорная» задача от рекон-пробы (см. п. 7): `01a12b4d-cdd3-7829-aec6-3e6ffaab0b0e`.

## 1. Блокеры продукта (по убыванию критичности)

### 1.1 Создание задачи падает с 500 — главный блокер

> **СТАТУС: ИСПРАВЛЕНО (2026-10-11).** Изменения:
> `src/Service/EntityChangesService.php:103,105` — `\DateTime` → `\DateTimeInterface`;
> `addTagRemoved()`/`addTagAdded()` — приведение `(string)`;
> `src/Helper/DiffHelper.php:63` — null-смещение в массиве (`$itemValue ?? ''`), снимает PHP-деприкацию
> «Using null as an array offset is deprecated».
> Тесты: добавлены `tests/Service/EntityChangesServiceTest.php` (unit, регрессия форматирования
> `DateTimeImmutable`) и `tests/Api/CreateTaskApiTest.php` (WebTestCase, `POST /api/tasks` → 201 +
> аудит-комментарий в БД). Полный прогон: **112 тестов / 344 ассерта — OK**. Живая проверка через nginx
> (`POST /api/tasks`, `application/ld+json`) → **201**, задача в статусе `created`, адрес escrow-кошелька
> выведен, аудит-комментарий записан.

**Где:** `src/Service/EntityChangesService.php:103-108`.

**Симптом:** `POST /api/tasks` → 500. Задача вставляется в БД (статус `created`), затем падает аудит-диф.

**Причина:** в `prepareTextEntityRowDiff()` (строки 103–108) дата приводится к строке только если значение
`instanceof \DateTime`. Но `DateTimeImmutable` (а именно такие поля у `Task`: `createdAt`, `updatedAt`,
`paymentDeadlineAt`, `assignedAt`, `executionDeadlineAt`) **не наследует `\DateTime`** — оба лишь
`DateTimeInterface`. При этом `DateTimeImmutable` не `Stringable`, поэтому строка 112–118 его тоже не
приводит, и «сырой» объект доходит до `addTagAdded(?string)` на строке 138 — TypeError.

**Почему тесты это пропустили:** `CreateTaskProcessorTest` стабает внутренний процессор, поэтому
`TaskService::save()` (где происходит аудит) в unit-тестах не исполняется; в `WorkerCabinetTest`
используется `sync://`-мессенджер, но там путь `TaskService::save()` не покрыт этим кейсом. HTTP-теста на
`POST /api/tasks` нет.

**Предлагаемое исправление:**

```php
// EntityChangesService.php:103 и :105
if ($data[0] instanceof \DateTimeInterface || $data[1] instanceof \DateTimeInterface) {
    // ...
    if ($data[$i] instanceof \DateTimeInterface) {
        $data[$i] = $data[$i]->format('d.m.Y H:i:s');
    }
}
```

**Дополнительно** (снижение хрупкости): привести `addTagRemoved`/`addTagAdded` к приведению к строке:

```php
private function addTagRemoved(?string $string): string
{
    return '<del>' . (string) $string . '</del>';
}
private function addTagAdded(?string $string): string
{
    return '<ins>' . (string) $string . '</ins>';
}
```

**Критерий приёмки:** `php bin/phpunit`; плюс новый HTTP-тест: `POST /api/tasks` с валидным payload →
201 и `taskStatus: created`; повторный `POST` с теми же данными не ломает аудит.

### 1.2 Исходящие выплаты (settlement) — реализовано (intent/report)

**Было:** `TronGatewayInterface` умел только `incomingTransfers()`; депозиты детектились, но **TRX никому
не отправлялся** — `AcceptTaskProcessor` и `RejectTaskProcessor` только переводили статус, хотя
OpenAPI обещал «Pay money to the worker» и «The money will refund back».

> **СТАТУС: реализовано (2026-10-14), но по схеме intent/report, а не air-gapped.** Изначально
> планировался air-gapped перенос подписанной транзакции (`prepare → sign → broadcast`), однако
> подписанная транзакция привязана к `ref_block`/`timestamp` и устаревает за время переноса файла
> через USB/часы. Действующие архитектура и план — [`docs/payouts-redesign.md`](payouts-redesign.md),
> операторский регламент — [`docs/payouts.md`](payouts.md).

**Как сделано:** онлайн-сервер готовит только «интент» (откуда/куда/сколько) и пишет
`<batchId>.intent.json`; офлайн-машина с мнемоникой (`app:payout:settle`) строит транзакцию у ноды,
подписывает и **сама** публикует её, затем пишет `<batchId>.report.json`; сервер
(`app:payout:apply-report`) сворачивает строку `Payout` в `broadcast`/`confirmed`/`failed`. Мнемоника
и ключи не покидают офлайн-машину. Компоненты, статусы, команды, разбор сбоев и тесты — в `docs/payouts.md`.


### 1.3 Состояние `finished` недостижимо

**Где:** `config/packages/workflow.yaml` — переходы `to_finished_accepted` / `to_finished_rejected`
определены, но **нигде не применяются** (`grep to_finished` по `src/` пуст): статус `finished` пока
недостижим.

**Решение (2026-10-14):** `finished` и его переходы оставляем — это конечный статус после проведения
выплат. Применить переходы в `AcceptTaskProcessor`/`RejectTaskProcessor` после (или вместе с) выплатой —
согласовать с п. 1.2.

### 1.4 Нет consumer'а Messenger — вебхуки и дедлайн-сообщения не исполняются

**Где:** `docker-compose.yaml` (нет сервиса `messenger:consume`); в dev-БД 4 неразобранных сообщения
(`delivered_at IS NULL`).

**Симптом:** `TaskStatusWebhookMessage` и отложенный `TaskExecutionDeadlineMessage` (2 ч) пишутся, но
никогда не обрабатываются. Дедлайн держится только «ленивым» путём `TaskDeadlineFinalizer` при чтении
кабинета. Вебхуки клиентам не приходят вовсе.

**Предлагаемый объём:**

1. Добавить сервис в `docker-compose.yaml`:
   ```yaml
   messenger-worker:
     extends: php-fpm
     command: ["php", "bin/console", "messenger:consume", "async", "-vv"]
     restart: unless-stopped
   ```
   (перезапуск через cron/systemd — вариант для prod-компоуза).
2. Продумать обработку `failed`-транспорта и ретраи (сейчас: retry 3×2, failure_transport `failed`).
3. Напомнить: `messenger:consume` в dev не обязателен для корректности дедлайна (есть ленивая страховка),
   но обязателен для вебхуков.

## 2. Landing page — расхождения и правки

> **СТАТУС: пункты 2.2–2.6 выполнены (2026-10-11).** Что сделано:
> - `templates/index.html.twig:255` — `POST /api/tasks` (вместо несуществующего `/create`), `{guid}` → `{id}` (строки 258–270);
> - `templates/index.html.twig:273` — удалён блок ``It`s coming soon...``;
> - `templates/index.html.twig:20` — hero-кнопка «API docs» → `path('api_doc')` (сделано вручную пользователем);
> - `templates/base.html.twig:65` — футер «API Docs» → `path('api_doc')`; «Contact» закомментирован (вручную);
> - `templates/base.html.twig:8,69` — кириллическая «Р» → латинская «P»;
> - `templates/base.html.twig:7` — заполнен `<meta name="description">`;
> - `templates/base.html.twig:28` — лого → `path('app_index')`; navbar-бейджи обёрнуты в ссылки (`api_entrypoint`, `app_cabinet`), добавлен `title`;
> - `templates/index.html.twig` — добавлена hero-кнопка «For humans» → `path('app_cabinet')` (точка входа для людей).
> Тест: добавлен `tests/Web/LandingPageTest.php` (GET `/` → 200, корректные ссылки, отсутствие гомоглифа
> и «coming soon», логика секции DONATE). Полный прогон: **114 тестов / 357 ассертов — OK**; `lint:twig` — OK.

### 2.1 Обзор страницы

`GET /` → `DefaultController::index()` → `templates/index.html.twig` с одной переменной `donate`
(`#[Autowire(env: 'APP_DONATE')]`). Страница статическая (Twig + Bootstrap-классы), рендерится 200.

### 2.2 Несуществующий endpoint в примере API

**Где:** `templates/index.html.twig:269` — `<code>POST /api/tasks/create</code>`.

**Факт:** route нет, `curl -X POST /api/tasks/create` → 405 (`Allow: GET, DELETE`, путь матчится на
`/api/tasks/{id}`). Реальный: `POST /api/tasks`.

**Правка:** заменить на `POST /api/tasks`.

### 2.3 Ссылка «API docs» ведёт на JSON-LD entrypoint, а не на UI

**Где:** `templates/base.html.twig:61` (`path('api_entrypoint')` = `/api`) — и в hero-кнопке
`templates/index.html.twig:30`.

**Факт:** `/api/docs` (Swagger UI/ReDoc/Scalar — все включены) отдаёт 200. `/api` — «голый» JSON-LD.

**Правка:** использовать `path('api_doc')` или прямой `url('api_doc')` в обоих местах. Если нужен именно
Swagger — проверить, какой UI отдаётся дефолтно, и при желании выставить его в `api_platform.yaml`
(`show_webby: false`, кастомный `title` — сейчас «Hello API Platform»).

### 2.4 У landing нет входа для людей (нет ссылок на `/cabinet`, `/login`, `/register`)

**Где:** весь `templates/index.html.twig` + `templates/base.html.twig`.

**Факт:** весь worker-интерфейс (регистрация, вход, кабинет) существует, но с лендинга недостижим.

**Правка (минимальная):** кнопка/ссылка «Для исполнителей» → `/register` (или `/login`), например в navbar
и в hero; в `base.html.twig` navbar добавить условный блок: если пользователь авторизован → «Мой кабинет»
(`/cabinet`), иначе → «Войти»/«Регистрация».

### 2.5 Кириллический гомоглиф «Р» в бренде

**Где:** `templates/base.html.twig:8` и `:65` — `Рlatform` содержит **U+0420** (кириллическая Р), не латинскую `P`.
Видно во вкладке браузера и в футере.

**Правка:** заменить на латинскую `P` (или «Platform» полностью латиницей).

### 2.6 Мёртвые ссылки и мелочи

- `templates/base.html.twig:28` — лого navbar `href="#"` → `path('app_index')`.
- `templates/base.html.twig:62` — «Contact» `href="#"` → оставить заглушку или убрать.
- `templates/base.html.twig:7` — пустой `<meta name="description">` → заполнить.
- `templates/index.html.twig:312` — ``It`s coming soon...`` (гравис вместо апострофа) + сама фраза
  противоречит «живому» API выше по странице → убрать или переформулировать.

### 2.7 Тест на landing отсутствует

**Факт:** ни один тест не бьёт `/` (нет `WebTestCase` на `app_index`).

**Правка:** добавить `tests/Web/LandingPageTest.php` (`WebTestCase`): GET `/` → 200; в ответе есть
заголовок и ссылка на `/api`; при пустом `APP_DONATE` секция DONATE скрыта, при заданном — видна.

## 3. Качество и гигиена кода

### 3.1 Аудит-дифы пишут HTML в слой сервиса

`src/Service/EntityChangesService.php:135-141` вставляет `<del>/<ins>` внутрь строки диффа; в БД
(`TaskComment.changes`) хранится HTML, рендерится без экранирования. См. п. 1.1 про типобезопасность.

**Предложить:** хранить «чистый» дифф (массив old/new), а HTML собирать на уровне Twig/представления.

### 3.2 Лишние `flush()` в `TaskService::save()`

`src/Service/TaskService.php:46,53,63` — до трёх flush на один move (entity + wallet-address + comment).
Флэши редко бывают оправданы в тройном количестве: объединить в один `flush()` в конце после
установки адреса кошелька.

### 3.3 Дублирование запроса активной задачи

`src/Service/TaskCabinetService.php:54,58` — `findActiveTask` вызывается дважды подряд.

### 3.4 Мусорный `dump()` в продакшн-пути

`src/Service/PaymentChecker.php:96` — `dump($throwable->getMessage())`; ошибки индексатора проглатываются
(строки 95–99). Убрать `dump`, продумать логирование.

### 3.5 Слабая проверка платежа

`src/Service/PaymentChecker.php:102` — достаточно `amount >= priceSun`. Нет проверки точной суммы,
нет защиты от повтора по `txHash`, нет привязки «кошелёк ↔ задача» на случай коллизий. При росте объёма —
добавить.

### 3.6 `assigned_to_id` с `ON DELETE SET NULL`

`src/Entity/Task.php:280` — удаление Worker «осиротит» активную задачу (`assigned`). Рассмотреть
`RESTRICT`/`SET NULL` + отдельную логику возврата в пул.

### 3.7 Мёртвый код

- `TaskStatus::GROUP_ACTIVE` / `GROUP_INACTIVE` / `forGroupSelect()` — **удалены (2026-10-14)**, были неиспользуемыми.
- `GetTaskInvoiceResponse` — пустой подкласс `CreateTaskResponse` (`src/Dto/GetTaskInvoiceResponse.php:10`),
  фактически только для привязки `output` в операции invoice.

## 4. Безопасность (требует решения)

- API **не аутентифицирован**: `access_control` только на `^/cabinet`; `#[IsGranted]` в `src/` нет;
  `stateless: true` в `api_platform.yaml`. Вся защита — общий секрет `X-Task-Password`
  (`src/Helper/TaskPasswordVerifier.php:25-32`). Кто знает пароль задачи — может читать результат,
  принимать/отклонять.
- `GET /api/tasks` (коллекция) открыт полностью; CORS ограничен `localhost`/`127.0.0.1` (`config/packages/nelmio_cors.yaml`).
- Регистрация без подтверждения email (wallet + пароль) — осознанное решение по `ui-spec.md`.

**Предложить отдельным эпиком:** продумать модель доверия API (клиенты-боты по API-ключам? задачи как
«объекты-секреты»?), сузить CORS, решить судьбу коллекции `/api/tasks`.

## 5. Состояние тестов

- `php bin/phpunit` в контейнере: **209/209 зелёных** (~10 c).
- `lint:twig` (8 файлов) и `lint:container` — ок.
- Пробелы: нет HTTP-теста на `POST /api/tasks` (п. 1.1), нет теста на landing (п. 2.7),
  `CreateTaskProcessorTest` стабает `TaskService` и не покрывает аудит.

## 6. Инфраструктура и окружение

- `var/cache/dev` **read-only** с хоста: `bin/console` запускать в контейнере
  (`docker compose exec -T php-fpm php bin/console ...`), не на хосте.
- `getcwd()` возвращает `false` в этой ФС (FUSE): из хоста команды запускать из `/tmp`, пути — абсолютные.
- Git из рабочей директории падает; использовать `git --git-dir=.../.git --work-tree=...`.
- Нет cron/scheduler для `app:payment:process` и `messenger:consume` в текущем compose (см. п. 1.4).

## 7. Мусор в dev-БД от рекон-пробы

Осталась одна «мусорная» задача (status `created`):
`01a12b4d-cdd3-7829-aec6-3e6ffaab0b0e` («Verify storefront operating hours.», 2026-10-11 14:11:21).
Аналогичная строка от 2026-10-08 подтверждает, что создание падало на 500 и раньше (см. п. 1.1).

**После фикса 1.1** добавилась «контрольная» задача живой проверки:
`01a12b76-9487-71d5-b274-6009f7a382f6` (status `created`, 2026-10-11 14:55:53) — создана успешно через
nginx (201), с аудит-комментарием. Её тоже можно удалить/пометить, если хотите оставить dev-датасет чистым.

**Предложение:** удалить все перечисленные тестовые строки (или помечать их как мусор), чтобы не путать
«реальный» датасет. Удаление — только по явному согласию.

## 8. Рекомендованный порядок работ

1. **П. 1.1** — починить `POST /api/tasks` (одна строка + тест). Разблокирует весь e2e-флоу.
2. **П. 2.2–2.6** — правки landing (endpoint, ссылки, гомоглиф, мёртвые ссылки, meta) + тест (п. 2.7).
3. **П. 1.2 + 1.3** — исходящие выплаты и судьба `finished` (одним эпиком).
4. **П. 1.4** — consumer Messenger + вебхуки.
5. **П. 3** — гигиена: flush, дубль запроса, `dump()`, дифф-HTML, платёжная проверка.
6. **П. 4** — безопасность API (отдельный эпик, требует продуктового решения).
