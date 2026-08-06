# test-api — учебный чат-мессенджер на Laravel 13

## Режим работы с Claude

**Это обучающий проект.** Пользователь учится писать мессенджер сам.

- Объяснять, задавать наводящие вопросы, делать ревью написанного им кода.
- **Код не писать, пока он прямо не попросит** («напиши», «сделай», «поправь файл»).
  Вопрос «как это сделать?» — это просьба объяснить, а не сделать.
- Общение на русском.
- Инфраструктурные вещи (сидеры, конфиги, тулинг) — не предмет обучения, их писать можно по просьбе.

## Стек

Laravel 13 (`^13.8`), PHP `^8.3`, MariaDB, Sanctum для API-токенов.

## Соглашения в коде

- Контроллеры возвращают `response()->json([...], $status)`; при создании — 201.
- Бизнес-логика — в сервисах (`app/Services`), контроллер только принимает запрос и отдаёт ответ.
- Route model binding вместо ручного поиска по id.
- Ошибки ввода — `ValidationException::withMessages()` (даёт 422), не голый `\Exception` (даёт 500).

## Схема чата

- `conversations` — `name` (nullable), `is_group` (bool), `owner_id` (nullable, nullOnDelete)
- `conversation_user` — pivot, составной PK `(conversation_id, user_id)`, без доп. колонок
- `messages` — `body`, `user_id` (nullable, nullOnDelete — история переживает удаление автора), `conversation_id` (cascadeOnDelete)
- `User::conversations()` — `belongsToMany` (не `hasMany`: приватный чат тоже имеет двух участников)

## Локальная разработка

`php artisan migrate:fresh --seed` поднимает БД и создаёт двух пользователей с **постоянными** токенами
(`database/seeders/DevUsersSeeder.php`) — чтобы не перелогиниваться в Bruno:

| Пользователь | Пароль | Заголовок Authorization |
|---|---|---|
| alice@example.com | password | `Bearer 1\|dev-token-alice` |
| bob@example.com | password | `Bearer 2\|dev-token-bob` |

Работает потому, что Sanctum хранит sha256-хеш секрета, а не сам секрет — сидер считает хеш сам.

## Готовые эндпоинты

Все под `auth:sanctum`:

- `POST /api/conversations/{user}` → `createConversation` — находит или создаёт приватный чат.
  201 при создании, 200 если уже был (различает через `wasRecentlyCreated`).
- `GET /api/conversations` → `getAllUserConversations` — список бесед текущего пользователя,
  `paginate(20)`, сортировка по свежести, отдаёт `ConversationResource::collection` напрямую.
- `DELETE /api/conversations/{conversation}` → `deleteConversation` — удаляет беседу целиком
  (сообщения уходят каскадом). Разрешено **любому участнику** — осознанное решение (2026-08-06).
- `GET /api/conversations/{conversation}/messages` → `getMessages` — история беседы,
  `latest()->paginate(20)`, автор через `with('author:id,name')`, отдаётся `MessageResource`.
- `POST /api/conversations/{conversation}/messages` → `sendMessage` — 201, тело в `MessageResource`.
- `GET /api/heartbeat` → `CheckUserIsOnline` (invokable) — заготовка. Замысел: фронт стучится
  раз в 30 сек, дальше Redis-ключ с TTL; нет ключа → пользователь оффлайн. Логики пока нет,
  отдаёт голую модель `User` (надо на `UserResource`).

`ConversationService::firstOrCreate()` — поиск через два `whereRelation`, создание в `DB::transaction`
(беседа + `attach` участников должны примениться атомарно).

## Список бесед — сделано (2026-07-30)

**API Resources — сделаны и подключены.** `ConversationResource` + `UserResource`
(`app/Http/Resources`), оба эндпоинта отдают через них.
- «Собеседник vs список участников» решён: в ресурсе `when($this->is_group, ...)` —
  приват отдаёт ключ `interlocutor` (один `UserResource`, вычислен `firstWhere('id','!=',$myId)`
  по уже загруженной коллекции), группа отдаёт `users` (`UserResource::collection`).
- Везде `whenLoaded(...)` + значение в `fn () => ...`, чтобы связь не грузилась вхолостую.
- `UserResource` отдаёт только `id`+`name` — теперь это граница API (email/хеш не текут),
  а `users:id,name` в контроллере стал чисто оптимизацией запроса.
- Проверено вживую: `interlocutor` разный по беседам и это не текущий юзер, лишних полей нет,
  `is_group` — boolean.

**Последнее сообщение — почти готово, но есть незакрытый баг.**
- Связь на `Conversation`: `lastMessage()` = `hasOne(Message::class)->latestOfMany()` (по `id`,
  не по `created_at` — id уникален, нет неоднозначности tie-break). Есть.
- В ресурсе: ключ `last_message` через `whenLoaded('lastMessage', ...)` — пока отдаёт сырую
  модель сообщения (будущий `MessageResource` заведём в `MessageController`). Из-за этого в
  JSON сейчас торчит служебный `conversation_id` — уйдёт вместе с ресурсом.
- **БАГ ИСПРАВЛЕН (2026-07-30):** в контроллере было `->with('lastMessage:id,body')`.
  Итог: `->with('lastMessage:messages.id,messages.body,messages.conversation_id')`.
  Два слоя проблемы:
  1. Не хватало FK. Eager loading — это два запроса, а не JOIN; сшивка родителей с детьми
     идёт в PHP (`Relation::buildDictionary()` читает у ребёнка `conversation_id`).
     Нет колонки в `select` → нет атрибута → сшивать не по чему → `null` без ошибки.
     Правило: при `relation:колонки` для hasOne/hasMany включать FK дочерней таблицы.
  2. Голого `conversation_id` мало: `latestOfMany()` добавляет `inner join` с подзапросом-
     агрегатом, у которого та же колонка → SQLSTATE 23000 «Column ... is ambiguous».
     Правило: если на связи висит `latestOfMany()`/`ofMany()`, колонки в `relation:...`
     квалифицировать таблицей (`messages.id`). Laravel пропускает имена с точкой как есть.
- Проверено вживую: `last_message` приходит заполненным и разным по беседам.
- Сообщений в БД не было (`DevUsersSeeder` их не создаёт) — накидали по 2 в каждую беседу
  через tinker. Если понадобится снова — либо tinker, либо завести фабрику/сидер сообщений.
- Автор сообщения в превью пока НЕ грузится (убрали `lastMessage.author:id,name`). Если нужен
  «Bob: привет» — вернуть его в `with` и обернуть в `UserResource`.

**Следующие шаги по списку бесед (в этом порядке):**

1. ~~Починить FK-баг выше~~ — сделано.
2. ~~Сортировка по свежести~~ — сделано (2026-07-30). Выбран самый простой путь:
   `protected $touches = ['conversation']` в модели `Message` (Laravel сам обновляет
   `updated_at` у беседы при сохранении сообщения) + `->orderByDesc('conversations.updated_at')`
   в контроллере. Ни миграции, ни новой колонки.
   - Имя колонки квалифицировано таблицей, потому что запрос идёт через `belongsToMany`
     с join'ом на `conversation_user`.
   - Известный компромисс: `updated_at` растёт от любого изменения беседы, не только от
     сообщений (переименование группы поднимет её наверх). Для учебного чата приемлемо.
     Если станет мешать — отдельная колонка `last_message_at`, обновляемая в `MessageService`.
   - Проверено: новое сообщение в старую беседу поднимает её на первое место.
3. ~~`paginate()` вместо `get()`~~ — сделано (2026-07-30). `->paginate(20)`.
   - **Грабли:** мета пагинации (`links`/`meta`) добавляется только когда ресурс-коллекция
     возвращается из контроллера **напрямую**. Вложение в `response()->json(['conversations' => ...])`
     её теряет — там берётся лишь массив элементов, а `links`/`meta` дописываются позже,
     на этапе формирования ответа, до которого дело не доходит.
   - Итог: `return ConversationResource::collection($conversations);` без `response()->json()`.
     Статус 200 проставляется сам. Ключ стал `data` (дефолт ресурсов) вместо `conversations` —
     если захочется вернуть своё имя, есть `public static $wrap = 'conversations'` в ресурсе.
   - Это осознанное отступление от конвенции «контроллеры возвращают `response()->json()`»:
     для ресурс-коллекций с пагинацией иначе не получится.

**Список бесед на этом закончен.**

## Сообщения — сделано (2026-08-06)

`sendMessage()` и `getMessages()` написаны пользователем. Логика осталась в контроллере,
`MessageService` не заводили — её там на три строки. Вынести, когда появится что-то
посерьёзнее (непрочитанные — вероятный кандидат).

- Порядок в `sendMessage`: сначала авторизация (403), потом `validate` (422). Не участник
  не должен даже узнавать, правильно ли составлен запрос.
- Создание идёт через `$authUser->messages()->create(['conversation_id' => ...])` —
  зеркальный вариант к `$conversation->messages()->create(['user_id' => ...])`,
  один FK всё равно ставится руками. Выбор осознанный.
- `MessageResource` — `id`, `body`, `created_at`, `author` (через `UserResource`,
  под `whenLoaded`). Служебный `conversation_id` из `last_message` этим и ушёл.
- `ConversationResource.last_message` теперь тоже отдаётся через `MessageResource`.

**Грабли, на которых посидели:**
- `$conversation->messages` (без скобок) — готовая коллекция; `$conversation->messages()`
  (со скобками) — конструктор запроса, в БД ещё не ходили. Возврат конструктора из
  контроллера ломается: нужен завершающий вызов (`get()`/`paginate()`/`first()`).
- `validate()` возвращает **массив данных формы**, а не модель. Модель — то, что вернул
  `create()`. Передача массива в ресурс даёт тихие `null` вместо ошибки.
  Отсюда правило именования: `$validated` для массива, `$message` для модели.
- `min:1` при `required` для строки избыточен.

## Policies — сделано (2026-08-06)

`ConversationPolicy` (`app/Policies`), автообнаружение по имени
(`App\Models\Conversation` → `App\Policies\ConversationPolicy`), регистрировать не нужно.

- Методы названы **по действию**, а не по правилу: `view`, `delete`, `sendMessages`.
  Причина: место вызова говорит «что я делаю», а «кому можно» решает политика — когда
  правило разойдётся (например, удалять сможет только владелец группы), контроллер
  не придётся трогать.
- Общая проверка вынесена в приватный `isParticipant()` — три публичных метода зовут его.
- Вызов — `Gate::authorize('view', $conversation)`, а **не** `$this->authorize(...)`:
  в базовом `Controller` нет трейта `AuthorizesRequests` (в Laravel 11+ его убрали).
- Провал → `AuthorizationException` → 403 JSON сам, ловить не надо.
- Пустые заготовки генератора (`viewAny`, `create`, `update`, `restore`, `forceDelete`)
  удалены. Осторожно с `create`: у него **нет** второго аргумента-модели (объекта ещё
  не существует), сигнатура с `Conversation` уронит вызов.
- Проверено вживую: чужая беседа → 403 `"This action is unauthorized."`, своя работает.

## Следующая задача: непрочитанные сообщения (на этом остановились 2026-08-06)

**Решение принято: `last_read_at` в pivot `conversation_user`.** Отдельную таблицу
`message_reads` не делаем — она даёт галочки «кто прочитал конкретное сообщение», но растёт
как сообщения × участники, а для учебного чата хватает счётчика.

Что предстоит:
1. Миграция: добавить `last_read_at` (nullable timestamp) в `conversation_user`.
2. `User::conversations()` → `withPivot('last_read_at')`, иначе колонка не приедет.
3. Эндпоинт «отметить прочитанным» (`updateExistingPivot`).
4. Счётчик непрочитанных в списке бесед.

**Главная ловушка шага 4 — N+1.** На экране 20 бесед; наивный счётчик = 20 отдельных
`count()`-запросов. Пользователь это предвидит («надо в один-два запроса»), способ ещё
не разбирали — смотреть в сторону `withCount` с условием по pivot.

**Дальше по проекту:** Redis-присутствие (`CheckUserIsOnline` — заготовка уже есть),
групповые чаты (`is_group`/`owner_id` в схеме есть, код их не использует),
броадкастинг (Reverb + Echo).

**Известные шероховатости:** гонка при одновременном создании чата (два запроса могут создать
два приватных чата между теми же людьми — защита уровня БД не придумана); в запросе поиска нет
условия «ровно два участника», флаг `is_group=false` его не гарантирует.
