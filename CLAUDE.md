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
- `GET /api/conversations` → `getAllUserConversations` — список бесед текущего пользователя.

`ConversationService::firstOrCreate()` — поиск через два `whereRelation`, создание в `DB::transaction`
(беседа + `attach` участников должны примениться атомарно).

## Где остановились (2026-07-30)

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
  модель сообщения (будущий `MessageResource` заведём в `MessageController`).
- **БАГ (не исправлен):** в контроллере `->with('lastMessage:id,body')` не хватает внешнего
  ключа → `last_message` придёт `null`. Нужно `lastMessage:id,body,conversation_id`.
  Правило: при `relation:колонки` для hasOne/hasMany включать FK дочерней таблицы.
- Автор сообщения в превью пока НЕ грузится (убрали `lastMessage.author:id,name`). Если нужен
  «Bob: привет» — вернуть его в `with` и обернуть в `UserResource`.

**Следующие шаги по списку бесед (в этом порядке):**

1. Починить FK-баг выше и проверить, что `last_message` не `null`.
2. Сортировка по свежести. Развилка: подзапрос в `orderBy` vs денормализованная
   колонка `last_message_at` в `conversations`.
3. `paginate()` вместо `get()` — сейчас список возвращает всё без ограничений.
   `ConversationResource::collection()` поверх `paginate()` сам сохранит мета пагинации.

**Решение, которое нужно принять до `MessageController`:** как считать непрочитанные.
Вариант — `last_read_at` в pivot `conversation_user` (тогда нужен `withPivot()`),
альтернатива — отдельная таблица `message_reads` (даёт галочки прочтения, но растёт как
сообщения × участники).

**Дальше по проекту:** `MessageController` (пока пустой), Policies (сейчас любой авторизованный
может создать чат с любым, и доступ к чужой беседе ничем не ограничен), групповые чаты
(`is_group`/`owner_id` в схеме есть, код их не использует), броадкастинг (Reverb + Echo).

**Известные шероховатости:** гонка при одновременном создании чата (два запроса могут создать
два приватных чата между теми же людьми — защита уровня БД не придумана); в запросе поиска нет
условия «ровно два участника», флаг `is_group=false` его не гарантирует.
