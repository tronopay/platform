# Мобильный личный кабинет исполнителя задач

> Версия: 1.1  ·  Проект: Tronopay — Human-in-the-Loop Task Platform

## 1. Обзор

Мобильный веб-кабинет `/cabinet` для **исполнителей задач**. Исполнитель регистрируется по Tron-адресу и паролю, выбирает одну оплаченную задачу из пула, выполняет её и сдаёт результат (обязательный текст + опциональные файлы). На задачу выделяется 2 часа: по истечении срока задача завершается автоматически — с результатом `processed`, без результата — возвращается в пул `prepaid` (поля исполнителя очищаются).

Пул поддерживает фильтрацию по типу и радиусу в милях, сортировку (по новизне — по умолчанию, по цене, по расстоянию) и пагинацию 10/20/50 задач на страницу; локация исполнителя используется для фильтров/сортировки по расстоянию и обновляется с устройства (см. §15).

## 2. Технологии

- PHP 8.5 + Symfony 8.1 (серверный рендеринг)
- Twig + Bootstrap 5.3.8 (importmap, bootstrap-icons), mobile-first
- Doctrine ORM (PostgreSQL + PostGIS)
- Symfony Security (сессионная авторизация, form_login)
- Symfony Workflow (state_machine `task_status`)
- Symfony Messenger (doctrine transport, отложенные сообщения)
- Symfony Lock (flock) — защита гонок при взятии задачи
- Symfony Validator + Form
- Symfony Stimulus (UX) — счётчик дедлайна, загрузка файлов, модалка подтверждения, трекер геолокации (`location-tracker`), авто-сабмит фильтров пула (`pool-filter`), определение позиции в профиле (`profile-location`)

Новые компоненты не требуются: всё необходимое уже в `composer.json`. Если понадобится (расширение S3) — предложить отдельно.

## 3. Роли и термины

- **Клиент** — создаёт задачу и платит за неё (вне этого кабинета, существующий API Platform flow).
- **Исполнитель (worker)** — пользователь мобильного кабинета.
- **Пул (pool)** — задачи со статусом `prepaid` (оплачены, ждут исполнителя).
- **Активная задача** — единственная задача исполнителя со статусом `assigned`.

## 4. Статусная модель (workflow `task_status`)

Новые статусы/переходы не нужны — используются существующие переходы. Схема:

```
created → prepaid → assigned → processed → accepted → finished
                    assigned → prepaid        (таймер без результата / ручной release)
                    assigned → processed      (ручное завершение / таймер с результатом)
                    processed → rejected → finished
```

| От | К | Переход | Инициатор |
|---|---|---|---|
| `prepaid` | `assigned` | `to_assigned` | Выбор задачи исполнителем |
| `assigned` | `prepaid` | `to_prepaid_from_assigned` | Таймер без результата / ручной release |
| `assigned` | `processed` | `to_processed` | Ручное завершение / таймер с результатом |

При `assigned → prepaid` очищаются поля исполнителя: `assignedTo = null`, `assignedAt = null`, `executionDeadlineAt = null`; задача снова доступна всем исполнителям пула.

## 5. Сущности (новые)

### `App\Entity\Worker` — исполнитель
- `Uuid id` (v7)
- `string tronAddress` — unique, `#[ValidTronAddress]`
- `string password` — hashed (`UserPasswordHasherInterface`)
- `DateTimeImmutable createdAt`
- `string? location` — **геопозиция** (`geography(POINT, 4326)`, GeoJSON как у `Task::$location`); `null` — не задана
- `DateTimeImmutable? locationUpdatedAt` — отметка последнего обновления позиции
- implements `UserInterface`, `PasswordAuthenticatedUserInterface`

### `App\Entity\TaskResult` — результат выполнения
- `Uuid id`
- `Task task` — `OneToOne` (одна задача = один результат, «upsert» до финализации)
- `string message` — **обязательный** текст
- `DateTimeImmutable submittedAt`
- `Collection<TaskResultFile> files` — `OneToMany(cascade: persist, remove)`

### `App\Entity\TaskResultFile` — вложение (несколько)
- `Uuid id`
- `TaskResult result` — `ManyToOne`
- `string originalName`
- `string virtualPath` — путь в абстрактном хранилище (напр. `tasks/{taskId}/{uuid}.ext`)
- `string mimeType`, `int size`
- `DateTimeImmutable createdAt`

### Изменения в `App\Entity\Task`
- `Worker? assignedTo` — `ManyToOne`
- `DateTimeImmutable? assignedAt`
- `DateTimeImmutable? executionDeadlineAt` (= assignedAt + 2h)
- `TaskResult? result` — `OneToOne` (Owner, колонка `result_id` на `task`)

## 6. Безопасность (`security.yaml`)

- Провайдер: `entity: App\Entity\Worker`, property `tronAddress`.
- Firewall `main`: `form_login` (login route `app_worker_login`, target `/cabinet`), `logout`.
- `access_control`: `{ path: ^/cabinet, roles: ROLE_WORKER }`.
- Пароль — только hash; адрес кошелька — через существующий `ValidTronAddress`.
- Регистрация без подтверждения email (wallet + пароль).

## 7. Маршруты

| Метод | Путь | Контроллер → Сервис | Назначение |
|---|---|---|---|
| GET/POST | `/register` | RegistrationController → WorkerRegistrationService | Регистрация |
| GET/POST | `/login` | SecurityController (form_login) | Вход |
| POST | `/logout` | SecurityController (form_login logout) | Выход |
| GET | `/cabinet` | CabinetController::index → TaskCabinetService | Пул + активная задача |
| GET | `/cabinet/history` | CabinetController::history → TaskCabinetService | История сданных задач + сумма заработанных TRX |
| GET | `/cabinet/profile` | CabinetController::profile | Профиль: просмотр/ручной ввод геолокации |
| POST | `/cabinet/location` | CabinetController::updateLocation | Фоновая регистрация позиции устройства (JSON) |
| POST | `/cabinet/tasks/{id}/assign` | CabinetController::assign | Взять задачу |
| GET | `/cabinet/task/{id}` | CabinetController::showTask | Активная задача + форма результата |
| POST | `/cabinet/task/{id}/result` | CabinetController::submitResult → TaskResultService | Сохранить/обновить результат; с `save_and_complete` — сохранить и завершить |
| POST | `/cabinet/task/{id}/remove-file/{fileId}` | CabinetController::removeFile | Удалить одно вложение результата |
| POST | `/cabinet/task/{id}/release` | CabinetController::release | Вернуть в пул (до дедлайна) |
| GET | `/cabinet/files/{id}` | CabinetController::downloadFile | Скачать вложение |

### Правила assign
1. Задача существует и имеет статус `prepaid`.
2. У текущего исполнителя нет задачи в `assigned` (**ровно одна активная**).
3. Валидация выполняется внутри `LockFactory`-блокировки `worker:{uuid}` — защита от гонки нескольких запросов/вкладок.
4. Успех: `to_assigned`, `assignedAt = now`, `executionDeadlineAt = now + 2h`, dispatch отложенного `TaskExecutionDeadlineMessage`.

## 8. Таймер и авто-завершение

- `TaskExecutionDeadlineMessage` (`#[AsMessage]`) — dispatch с задержкой `2 hours`.
- `TaskExecutionDeadlineHandler` (`#[AsMessageHandler]`).
- **Ленивая страховка**: `TaskDeadlineFinalizer::finalizeExpired(Task)` вызывается при каждом чтении активной задачи и на дашборде — корректность не зависит от запущенного consumer.

Логика финализации:
```
if task.status === assigned && now >= executionDeadlineAt:
    if task.result !== null:      → to_processed
    else:                         → to_prepaid_from_assigned + очистка assignedTo/assignedAt/executionDeadlineAt
```

### Отображение таймера

- Порог «осталось мало» — **15 минут** (`900` с). Сервер рендерит класс алерта по этому порогу, контроллер переключает `alert-warning` ↔ `alert-danger` на клиенте по тому же значению, которое получает в `data-countdown-urgency-threshold-value` (единый источник — `urgencyThreshold` в `cabinet/task.html.twig`).
- Сервер рендерит стартовое значение `HH:MM:SS` (`deadlineRemainingText`) в тот же узел, что и контроллер, — таймер виден без JavaScript.
- Разметка: `role="timer"` + `aria-live="off"` (значение меняется ежесекундно, анонсировать его нельзя) и визуально скрытая строка с абсолютным дедлайном.
- `window.location.reload()` при `remaining <= 0` выполняется **только** на пересечении нуля при открытой странице и однократно; страница, пришедшая уже истёкшей, не перезагружается (защита от цикла, если финализация не удалась).

## 9. Хранилище файлов (подготовка к S3)

Интерфейс вместо жёсткой привязки к диску — замена на S3 не затронет схему БД и кабинет:

```php
namespace App\Storage;

interface FileStorageInterface
{
    public function put(string $virtualPath, UploadedFile|string $contents): void;
    public function get(string $virtualPath): string;
    public function delete(string $virtualPath): void;
    public function exists(string $virtualPath): bool;
    public function publicUrl(string $virtualPath): string;
}
```

- `LocalFileStorage`: корень `%kernel.project_dir%/var/uploads` (параметр `UPLOAD_DIR` в `.env`), виртуальный путь — в БД.
- Скачивание — через интерфейс, `Response`/`BinaryFileResponse` с `Content-Disposition: attachment`.
- **Будущее**: адаптер `S3FileStorage` поверх `league/flysystem-aws-s3-v3` / `symfony/flysystem-bundle` + замена сервиса под интерфейсом; DTO/БД не меняются.

## 10. Формы и валидация

- `WorkerRegistrationFormType`: `tronAddress` (TextType + `ValidTronAddress`), `plainPassword` (`RepeatedType`).
- `TaskResultFormType`:
  - `message` — обязателен (`NotBlank`, `Length max 5000`), textarea;
  - `files` — `FileType(multiple: true, required: false)`, на файл `Assert\File(maxSize, mimeTypes)`;
  - `files` дополнительно валидируется `Callback`-ограничением: имя загружаемого файла (`getClientOriginalName()`) не должно повторять уже прикреплённое к результату (одно имя на результат). Нарушение → 422 с текстом «A file with this name is already uploaded: <имя>.», введённое сообщение в форме сохраняется;
  - тот же инвариант дублируется в `TaskResultService::save()` (бросает `\RuntimeException` на повторное имя — страховка за пределами формы);
  - удаление загруженного файла — не чекбоксами, а кнопкой-крестиком с подтверждающей модалкой (отдельный POST), см. §12.
- CSRF — стандартный (Form/Security).

Параметры-умолчания (вынести в `.env`): лимит сообщения 5000 симв., файл ≤ 25 MiB, до 10 файлов.

## 11. Необратимость завершения

- Кнопка «Завершить задачу» → Bootstrap-модалка «Действие необратимо. Подтвердить?» → подтверждение сабмитит **ту же форму результата** с флагом `save_and_complete`: сначала сохраняется набранный результат, затем выполняется `assigned → processed` (правка по UX-аудиту §3.1, отдельного POST `/complete` больше нет).
- Бэкенд: только `assigned → processed`; обратного перехода в workflow нет — необратимость гарантируется и на уровне статусной машины.
- После `processed` форма результата скрывается, редактирование запрещено (в форме и в сервисе).
- Смена статуса и запись `TaskComment` через существующий `TaskService::save()` + DiffHelper.

## 12. UI: экраны (Twig, mobile-first)

- `register.html.twig` / `login.html.twig` — центрированная карточка.
- `cabinet_layout.html.twig` — наследует `base.html.twig`, но переопределяет сайтовый `navbar` пустым (кабинет — отдельное мобильное приложение; маркетинговые бейджи «For AI agents»/«For humans» внутри не нужны, аналогично отключённому футеру). Шапка с адресом исполнителя и выходом (иконочная кнопка с `aria-label="Log out"`), флеши с `role="status"`/`role="alert"`, постоянная нижняя навигация (bottom bar): «Задачи», «История», «Профиль», «Моя задача» (последняя вкладка — только при активной задаче); активная вкладка подсвечивается `text-primary fw-semibold` и помечается `aria-current="page"` (выход — в шапке, не в баре). Бар закреплён по низу окна: контент кабинета лежит в flex-колонке высотой не меньше вьюпорта (`min-height: 100dvh`), бар — `position: sticky; bottom: 0`, поэтому на коротких страницах он прижат к низу окна, на длинных — держится у нижнего края при прокрутке.
- `cabinet/index.html.twig`: активная задача (карточка с дедлайном) + карточки `prepaid`-задач (бейдж `TaskType`, statement, цена в TRX, кнопка «Взять»).
- `cabinet/history.html.twig`: сводная карточка «Total earned» (сумма по `accepted`, `data-history-total` для тестов) + список сданных задач (бейдж типа, бейдж статуса с человекочитаемой подписью `TaskStatus::label()`, цена в TRX, statement, дата последнего обновления) + постраничная навигация 10/20/50 с тем же клампингом `?page`, что и у пула; пустое состояние — «No completed tasks yet». См. §18.
- `cabinet/task.html.twig`: тип/цена/статус, обратный отсчёт дедлайна (Stimulus, от серверного расхождения времени), предупреждение при <15 мин, форма результата (textarea + multi-file drag&drop), список загруженных файлов: имя · размер и кнопка удаления (крестик, `btn-outline-danger`, `aria-label="Remove <имя>"`, 44×44 px) с `data-file-remove-*` атрибутами; кнопки «Сохранить» и «Завершить задачу».
- `_confirm_remove_file_modal.html.twig` — общая модалка подтверждения удаления файла (вне формы результата, свой `<form method="post">`): Stimulus-контроллер `file-remove` при открытии (`show.bs.modal`) подставляет `action`, CSRF-токен и имя файла из атрибутов кнопки-триггера; подтверждение → POST `/cabinet/task/{id}/remove-file/{fileId}` → перевывод страницы с флешем «File removed.».
- Подсказка «Tick the checkbox and click "Save result" to remove a file» под списком файлов удалена.
- `_confirm_modal.html.twig` — переиспользуемый компонент подтверждения.
- Дедлайн считается от серверного `now` + `executionDeadlineAt` (не от часов клиента).

### Требования доступности и эргономики

- Все тап-таргеты ≥ 44×44 px: `.cabinet .btn { min-height: 44px }`, `.btn-icon { min-width: 44px }` (кнопка-крестик удаления файла — `.btn.btn-icon`, 44×44 px).
- Контраст текста ≥ 4.5:1 на фоне `#f8fafc`: палитра Bootstrap переопределена на скоупе `.cabinet` (`--bs-primary-rgb`, `--bs-secondary-rgb`, `--bs-success-rgb`, `--bs-info-rgb`, `--bs-secondary-color`, `--bs-link-color-rgb`); бейджи вне кабинета — класс `.icon-badge-success`.
- Иконочные элементы декоративны (`aria-hidden="true"`) и не остаются без текстовой альтернативы.

## 13. Миграции

Миграции создаются и накатываются стандартно (`bin/console make:migration` + `doctrine:migrations:migrate`):
- таблицы `worker`, `task_result`, `task_result_file`;
- колонки `Task`: `assigned_to_id`, `assigned_at`, `execution_deadline_at`, `result_id`;
- `Version20261012120000`: `worker.location` (`geography(POINT,4326)` + GiST-индекс) и `worker.location_updated_at`;
- таблица `messenger_messages` (транспорт `doctrine`, `auto_setup=0`).

Применено к dev- и test-базам.

## 14. Тесты

- Регистрация/вход; доступ к `/cabinet` (аноним → 302, worker → 200).
- Assign: успех; вторая активная задача запрещена; задача не `prepaid`/«чужая» → ошибка.
- Результат: пустой текст не проходит; загрузка файла; повторная отправка обновляет файлы.
- Ручное завершение: `processed`; повтор → 409; результат заморожен.
- Авто-финализация: дедлайн с результатом → `processed`; без результата → `prepaid` + очистка полей.
- Messenger handler вызывает финализацию. Безопасность: чужая активная задача → 404/403.
- Геолокация: профиль (показ, сохранение, сброс, невалидные координаты), фоновый endpoint `POST /cabinet/location` (CSRF, валидация), влияние локации на пул.
- Пул: фильтр типа; фильтр радиуса (реальная PostGIS-дистанция в милях, задачи без координат); сортировка (цена ↑/↓, ближайшие); пагинация 10/20/50 и клампинг `?page` за пределы диапазона.
- История: аноним → 302; пустое состояние и нулевая сумма; в списке `processed`/`accepted`/`rejected`, но сумма считается только по `accepted`; чужая задача не попадает в историю; задача, вернувшаяся в пул по таймеру без результата, в историю не попадает; пагинация и клампинг `?page`; вкладка «История» подсвечена в баре.
- Unit `TaskStatusTest`: `isHistory()` / `isPaid()` / `historyValues()` / `label()` — семантика истории и заработка, на которой держится сумма.

## 15. Геолокация исполнителя, фильтры, сортировка и пагинация пула

### 15.1 Локация исполнителя (профиль)

- `Worker.location` — `geography(POINT, 4326)` GeoJSON, как у `Task::$location`; `Worker.locationUpdatedAt` — отметка последнего обновления.
- Профиль (`/cabinet/profile`): кнопка «Use my device location» (браузерный geolocation; на первый запрос нужен консент пользователя) заполняет поля широты/долготы; доступен ручной ввод; «Clear location» сбрасывает позицию.
- Позиция с устройства кэшируется в `localStorage` (`tronopay_worker_location`) и молча отправляется на сервер:
  - при каждой загрузке страниц кабинета;
  - **перед активными действиями** (взять задачу, сохранить результат, завершить, удалить файл, вернуть в пул, сохранить профиль) — `fetch(..., keepalive)`, без задержки самого действия;
  - если разрешение на geolocation уже выдано — позиция бесшумно перечитывается с устройства при загрузке страницы (без нового промпта).
- `POST /cabinet/location` (`app_cabinet_location`): принимает `{lat, lng}` + CSRF, сохраняет только при изменении (`updateWorkerLocation` → `{updated: bool}`).
- Без локации кабинет полностью рабочий: фильтр радиуса и сортировка «по расстоянию» скрыто игнорируются (и заблокированы в UI).

### 15.2 Фильтры, сортировка и пагинация пула (`GET /cabinet`)

| Параметр | Значения | По умолчанию |
|---|---|---|
| `type` | один из `TaskType` | все типы |
| `radius` | `1, 3, 5, 10, 20, 50` миль (нужна локация исполнителя) | без ограничения |
| `incl_noloc` | `show` / `hide` — показывать ли задачи без координат при заданном радиусе | `show` |
| `sort` | `newest` · `price_high` · `price_low` · `nearest` | `newest` |
| `per` | `10, 20, 50` задач на страницу | `10` |
| `page` | 1-based; значения за пределами диапазона клампатся к последней странице | `1` |

- Расстояние — честный расчёт PostGIS `ST_DWithin`/`ST_Distance` по `geography` (метры → мили `÷ 1609.344`) нативно в SQL (`TaskRepository::findPoolPage`).
- Сортировка `nearest`: задачи без координат всегда в конце; `price_high`/`price_low` — вторичный порядок по новизне.
- Фильтры и пагинация сохраняются в query string (шеринг и F5 безопасны); смена фильтра сбрасывает `page` на 1.
- На карточке задачи выводится дистанция «≈ N mi» (если у исполнителя есть локация и у задачи есть координаты).

## 16. Порядок реализации (выполнено в Act-режиме)

1. Сущности (`Worker`, `TaskResult`, `TaskResultFile`), правки `Task` + `FileStorageInterface`/`LocalFileStorage`.
2. Сервисы: `WorkerRegistrationService`, `TaskCabinetService`, `TaskResultService`, `TaskDeadlineFinalizer`.
3. Messenger: `TaskExecutionDeadlineMessage` + handler + задержка 2h.
4. Security (`security.yaml`: провайдер Worker, `form_login`, access_control).
5. Контроллеры + формы (`Registration`, `TaskResult`).
6. Шаблоны + Stimulus-контроллеры (счётчик, файлы, модалка).
7. Тесты + `lint:container`, `lint:twig`, phpunit. Миграции создавались по необходимости (см. итерации 2–3, список в §13).

Доработка (итерация 2): локация исполнителя + фильтры/сортировка/пагинация пула — см. §15. Реализовано в `CabinetController` (`index`/`profile`/`updateLocation`), `TaskCabinetService` (`poolPage`/`updateWorkerLocation`), `TaskRepository::findPoolPage`, шаблонах `cabinet/index.html.twig` и `cabinet/profile.html.twig`, контроллерах Stimulus `location-tracker`, `pool-filter`, `profile-location`, миграции `Version20261012120000` и тестах `WorkerCabinetTest` (геолокация, фильтры, сортировка, пагинация).

Доработка (итерация 3): история сданных задач + сумма заработанных TRX — см. §18. Реализовано в `CabinetController::history`, `TaskCabinetService` (`historyPage`/`totalEarnedSun`), `TaskRepository` (`findHistoryForWorker`/`countHistoryForWorker`/`sumEarnedSunForWorker`), `TaskStatus` (`isHistory`/`isPaid`/`historyValues`/`label`), шаблоне `cabinet/history.html.twig` и вкладке «История» в `cabinet_layout.html.twig`. Миграции не требовались: используются существующие колонки `assigned_to_id`, `status`, `price_sun`, `updated_at`. Тесты — `WorkerCabinetTest` (история, сумма, пагинация, доступ) и `TaskStatusTest`.

## 17. Открытые вопросы

- Окончательные лимиты файлов (по умолчанию: ≤25 MiB, ≤10 шт.).
- ~~Нужна ли секция «История завершённых задач» в первой итерации.~~ **Закрыто:** реализована в итерации 3 (§18).
- Оставить ли ручной `release` в пул (переход `to_prepaid_from_assigned` уже существует — по умолчанию оставляем).
- Приватность геолокации: хранение/срок действия координат исполнителя и политика согласия (пока — консент через браузер + ручной ввод).

## 18. История сданных задач и сумма заработанных TRX

Мотивация: после завершения задача исчезала из кабинета навсегда — исполнитель не видел ни статуса проверки (`processed → accepted/rejected`), ни факта выплаты (см. `docs/ui-ux-analysis.md` §6.5).

- **Маршрут:** `GET /cabinet/history` (`app_cabinet_history`), только `ROLE_WORKER`.
- **Что попадает в историю:** задачи, у которых `assigned_to_id` = текущий исполнитель и статус ∈ `TaskStatus::historyValues()` — `processed`, `accepted`, `rejected`, `finished`. Это «сданные» задачи: исполнитель уже отправил результат и задача ушла из его рук.
  - Задача, вернувшаяся в пул по таймеру без результата (или через ручной `release`), очищается от исполнителя (`assignedTo = null`) и в историю не попадает — это корректно, работы не было.
  - `assigned_to_id` не очищается при `processed`/`accepted`/`rejected`/`finished` (`AcceptTaskProcessor`/`RejectTaskProcessor` меняют только статус; `TaskDeadlineFinalizer` при наличии результата переводит в `processed`, сохраняя исполнителя), поэтому это устойчивый признак авторства.
- **Сумма заработанных TRX** (`sumEarnedSunForWorker`): `SUM(price_sun)` только по статусу `accepted` — именно `accepted` означает «деньги освобождены исполнителю».
  - `finished` **намеренно не считается**: в него ведут оба перехода — `to_finished_accepted` и `to_finished_rejected` (`config/packages/workflow.yaml`), и текущий статус не позволяет отличить выплату от отказа (см. `docs/proposals.md` §1.3). Сейчас `finished` вообще недостижим (`grep to_finished` по `src/` пуст), но правило остаётся безопасным.
  - Ограничение честно показано в UI: подпись под суммой — «Accepted tasks only — money released to you.»
  - Следствие для будущего: с реализацией исходящих выплат (`docs/payouts.md`)
    единственным надёжным источником факта выплаты становится сохранённый
    `txHash` в `Payout`, а не статус задачи; `isPaid()` стоит перевести на проверку `Payout.txId`.
- **Сортировка:** `updatedAt DESC, id DESC` — «сначала недавняя активность» (переход в `accepted`/`rejected` обновляет `updatedAt`).
- **Пагинация:** `per` ∈ {10, 20, 50} (по умолчанию 10), `page` 1-based, значения за пределами диапазона клампатся к последней странице — как в пуле (§15.2).
- **UI:** сводная карточка «Total earned» (`Price`-совместимый формат `number_format(6)`), список карточек с бейджем типа, бейджем статуса с подписью `TaskStatus::label()` (`Under review`, `Accepted`, `Rejected`, `Finished`) и ценой (`text-success` только у `accepted`). Вкладка «История» в нижней навигации.
