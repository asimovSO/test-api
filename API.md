# test-api — контракт API

Справка для клиента (веб/мобильного фронтенда в отдельном репозитории). Описывает то, что
реально реализовано на 2026-08-09. Учебный контекст и история решений — в `CLAUDE.md`,
здесь только контракт.

## Стек и общие вещи

- Laravel 13, аутентификация — Sanctum (Bearer-токены, не сессии/cookies).
- Все эндпоинты ниже, кроме `/register` и `/login`, требуют заголовок
  `Authorization: Bearer {token}`.
- Ошибки валидации → `422` с телом Laravel по умолчанию (`{"message": ..., "errors": {...}}`).
- Ошибки авторизации (не участник беседы и т.п.) → `403 {"message": "This action is unauthorized."}`.
- Несуществующий id в route model binding → `404`.

## Аутентификация

### `POST /api/register`
Body: `{ "name": string (3-230), "email": string (email, unique), "password": string (min 3) }`
Ответ `200`: `{ "token": "1|xxxxx", "user": { ...сырая модель User, включая email } }`

⚠️ `user` здесь — **не** `UserResource`, отдаётся сырая модель (утечка email/timestamps).
Фронту стоит закладываться только на `id`/`name`, остальное может исчезнуть.

### `POST /api/login`
Body: `{ "email": string, "password": string }`
Ответ `200`: `{ "token": "1|xxxxx", "user": {...} }` (та же сырая модель)
Ответ `401`: `{ "message": "Invalid credentials" }`

### `POST /api/logout` (auth)
Отзывает **текущий** токен (тот, которым запрос авторизован), не все токены пользователя.
Ответ `200`: `{ "message": "Logged out" }`

## Беседы

### `GET /api/conversations` (auth)
Список бесед текущего юзера, `paginate(20)`, сортировка по свежести (см. ниже про `updated_at`).
Возвращает **пагинированную ресурс-коллекцию напрямую** (не обёрнуто в `{"conversations": ...}`) —
значит верхний уровень ответа это стандартная Laravel-пагинация:
```json
{
  "data": [ /* ConversationResource[] */ ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 42, ... }
}
```

### `POST /api/conversations/{user}` (auth)
Находит приватную беседу с указанным пользователем или создаёт новую.
- `201` + `{ "message": "Created", "conversation": ConversationResource }` — если создана.
- `200` + `{ "message": "Was existing", "conversation": ConversationResource }` — если уже была.

Известный краевой случай: нет защиты от гонки на уровне БД — параллельные запросы
теоретически могут создать два приватных чата с одним и тем же собеседником.

### `DELETE /api/conversations/{conversation}` (auth)
Удаляет беседу целиком, сообщения каскадом. Разрешено **любому участнику**, не только владельцу.
`200`: `{ "message": "Conversation deleted" }`

### `PUT /api/conversations/{conversation}/mark-as-read` (auth)
Отмечает беседу прочитанной **для текущего юзера** (обновляет его `last_read_at` в pivot).
`200`: `{ "message": "Conversation marked as read" }`

### `ConversationResource` — форма объекта беседы
```json
{
  "id": 1,
  "name": null,
  "is_group": false,
  "users": undefined,            // есть ТОЛЬКО если is_group == true, UserResource[]
  "interlocutor": { "id": 2, "name": "Alice" }, // есть ТОЛЬКО если is_group == false
  "last_message": { "id": 6, "body": "...", "created_at": "...", "author": {"id":2,"name":"Alice"} } | undefined,
  "unread_messages_count": 3 | undefined,  // undefined, если контроллер его не запрашивал (см. ниже)
  "messages": undefined,          // не используется в текущих ответах
  "created_at": "...",
  "updated_at": "..."
}
```
Важно для фронта:
- Ключ **либо** `interlocutor` (объект), **либо** `users` (массив) — какой из них есть,
  определяется `is_group`. Групповые чаты в проекте пока нигде не создаются (`is_group`
  всегда `false` на практике), но контракт уже на них рассчитан.
- `unread_messages_count` присутствует только в ответе `GET /api/conversations` (там есть
  `withCount`). В ответах `createConversation` его не будет — не полагаться на его наличие
  везде, проверять `!== undefined`.
- `last_message` может отсутствовать (`undefined`), если в беседе ещё нет сообщений или
  ресурс собран без eager-load `lastMessage` — на практике в `getAllUserConversations` он
  всегда подгружен.
- Сортировка списка — по `conversations.updated_at`, который трогается **любым** изменением
  беседы, не только новым сообщением (например, будущее переименование группы тоже поднимет
  её наверх).

## Сообщения

### `GET /api/conversations/{conversation}/messages` (auth)
403, если не участник. Иначе — `latest()->paginate(20)` (та же форма пагинации, что у списка
бесед), `data` — массив `MessageResource`.

### `POST /api/conversations/{conversation}/messages` (auth)
403, если не участник (проверяется **до** валидации тела).
Body: `{ "body": string (required, max 2000) }`
`201`: `{ "message": MessageResource }`

Одновременно с ответом синхронно (см. Broadcasting) уходит `MessageSent` в приватный канал
беседы и `UnreadCountUpdated` каждому остальному участнику.

### `MessageResource` — форма объекта сообщения
```json
{
  "id": 6,
  "body": "привет",
  "created_at": "2026-08-09T16:05:15.000000Z",
  "author": { "id": 2, "name": "Alice" }   // whenLoaded — теоретически может отсутствовать,
                                            // но во всех текущих ответах всегда есть
}
```

## Realtime / Broadcasting

Сервер — Laravel Reverb (self-hosted, протокол Pusher). Для фронта это значит: обычный
`laravel-echo` + `pusher-js`, настроенные на свой хост, а не на pusher.com.

- `.env` (для клиента понадобятся аналоги): `REVERB_APP_KEY`, `REVERB_HOST=localhost`,
  `REVERB_PORT=8080`, `REVERB_SCHEME=http`.
- Подписка на приватные/presence-каналы идёт через `POST /broadcasting/auth` — **под
  `auth:sanctum`**, не `web`-сессию. Echo обычно сам дергает этот эндпоинт при
  `Echo.private(...)`/`Echo.join(...)`, если передать в его конфиг Bearer-токен
  (`auth: { headers: { Authorization: 'Bearer ...' } }`).

### Канал `private-conversation.{conversationId}`
Кто слушает: участник конкретной беседы (та же проверка, что `ConversationPolicy::view`).
Событие: **`MessageSent`**
```json
{ "message": { /* MessageResource */ } }
```
Приходит при `POST .../messages`. Слушать имеет смысл только пока у пользователя открыт
именно этот чат.

### Канал `private-App.Models.User.{userId}`
Личный канал юзера (дефолтный канал Laravel). Кто слушает: сам пользователь (`{id}` должен
совпадать с айди подключённого юзера).
Событие: **`UnreadCountUpdated`**
```json
{ "conversation_id": 2, "user_id": 3, "unread_count": 1 }
```
Приходит каждому получателю сообщения (кроме автора) при `POST .../messages` — независимо
от того, открыта ли у него именно эта беседа. `unread_count` — **точное** пересчитанное
значение (не дельта, фронту не нужно инкрементить самому — просто подставить как есть).

### Канал `presence-online`
Presence-канал: кто в данный момент к нему подключён по WS — и есть список «кто онлайн».
Никакого REST-эндпоинта или события на сервере для этого нет и не нужно — Reverb сам ведёт
список подключений. На фронте:
```js
Echo.join('online')
  .here(users => /* массив тех, кто уже подключён на момент join */)
  .joining(user => /* кто-то зашёл */)
  .leaving(user => /* кто-то вышел */);
```
⚠️ **Известный баг на сервере (не пофикшен):** payload по каждому юзеру сейчас приходит как
`[{"id":2,"name":"Alice"}]` — массив из одного объекта, а не сам объект `{"id":2,"name":"Alice"}`.
Смотри `routes/channels.php`, канал `'online'` — лишняя обёртка `[...]` вокруг
`new UserResource($user)`. Пока не поправлено на сервере, фронту придётся читать `user.id`
как `[0].id` в колбэках `here`/`joining`/`leaving`, либо — правильнее — сначала поправить
эту строчку на бэке.

Старый механизм присутствия через Redis-heartbeat (`PresenceService`, `GET /api/heartbeat`)
**заменён** presence-каналом выше. Эндпоинта `/api/heartbeat` больше нет (роут удалён).
Класс `PresenceService.php` в кодовой базе ещё физически остался, но нигде не используется.

## Пока не реализовано

- Групповые чаты: в схеме есть `is_group`/`owner_id`, но код нигде не создаёт группы и не
  даёт добавлять/убирать участников. Контракт ресурсов (`interlocutor` vs `users`) уже
  рассчитан на группы, но реальных эндпоинтов для управления ими нет.
- Редактирование/удаление отдельных сообщений.
- Профиль пользователя (аватар, смена имени/пароля) — есть только `GET /api/user` (сырая
  модель, `id`/`name`/`email`/...).

## Тестовые пользователи (только локальная разработка)

`php artisan migrate:fresh --seed` создаёт:

| Пользователь | Пароль | Authorization |
|---|---|---|
| alice@example.com | password | `Bearer 1\|dev-token-alice` |
| bob@example.com | password | `Bearer 2\|dev-token-bob` |
