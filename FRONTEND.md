# Документация для frontend-разработчика

Актуально на 25 августа 2026 года. Контракт сверён с маршрутами, контроллерами,
ресурсами и policies проекта.

## Подключение

Локальный REST base URL: `http://127.0.0.1:8000/api`.
Broadcast auth: `http://127.0.0.1:8000/broadcasting/auth` (без `/api`).

Все запросы, кроме регистрации и входа, требуют:

```http
Accept: application/json
Authorization: Bearer {token}
```

Для JSON добавляйте `Content-Type: application/json`. Это token-based Sanctum:
cookies, CSRF и `credentials: include` не нужны.

```js
const API_URL = import.meta.env.VITE_API_URL;

export async function api(path, options = {}) {
  const token = localStorage.getItem('token');
  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });
  if (response.status === 204) return null;
  const data = await response.json();
  if (!response.ok) throw Object.assign(new Error(data.message), { status: response.status, data });
  return data;
}
```

## Аутентификация

### `POST /register`

```json
{ "name": "Alice", "email": "alice@example.com", "password": "password" }
```

`name`: 3–230 символов; email уникальный; password: минимум 3 символа.
Ответ `200`: `{ "token": "3|...", "user": { "id": 3, "name": "Alice", "email": "..." } }`.
Регистрация синхронно отправляет welcome-письмо, поэтому ошибка mail transport
может привести к ошибке запроса.

### `POST /login`

Body: `{ "email": "...", "password": "..." }`. Ответ `200`: `{ token, user }`.
Неверные данные: `401 { "message": "Invalid credentials" }`. Каждый вход
создаёт новый бессрочный токен.

### Прочее

- `GET /user` — полная модель текущего пользователя;
- `POST /logout` — отзывает только текущий токен, ответ `200`.

После logout удалите token и локальные данные и вызовите `echo.disconnect()`.

## Типы данных

```ts
type UserSummary = { id: number; name: string };

type Message = {
  id: number;
  body: string;
  created_at: string;
  is_edited?: true;
  author?: UserSummary;
};

type Conversation = {
  id: number;
  name: string | null;
  is_group: boolean;
  users?: UserSummary[];                 // только группа
  interlocutor?: UserSummary | null;     // только личный чат
  last_message?: Message | null;
  unread_messages_count?: number;
  created_at: string;
  updated_at: string;
};
```

Условные поля действительно отсутствуют в JSON. `is_edited` у исходного
сообщения отсутствует, а не равен `false`.

## Беседы

### Список

`GET /conversations?page=1` возвращает по 20 бесед, свежие сверху:

```json
{
  "data": [],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 0 }
}
```

`last_message` и `unread_messages_count` гарантированно загружены именно здесь.

### Личный чат

`POST /conversations/{userId}` без body:

- `201 { message: "Created", conversation }` — создан;
- `200 { message: "Was existing", conversation }` — уже существовал;
- `422` — чат с самим собой; `404` — пользователя нет.

Блокируйте повторный параллельный запрос: уникальной защиты от двух одинаковых
личных бесед на уровне БД сейчас нет.

### Группа

`POST /conversations/group`:

```json
{ "name": "Frontend team", "user_ids": [2, 3] }
```

Имя обязательно, максимум 255 символов; `user_ids` содержит минимум два id.
Отправляйте уникальные существующие id без id автора: backend пока не проверяет
это достаточно строго. Автор становится владельцем. Ответ `201`.

Только владелец группы может:

- `POST /conversations/{conversation}/participant/{user}` — добавить участника;
- `DELETE /conversations/{conversation}/participant/{user}` — исключить;
- `DELETE /conversations/{conversation}` — удалить группу.

Повторное добавление даёт `400`. Участник выходит через
`POST /conversations/{conversation}/quit`. При выходе владельца роль передаётся
оставшемуся участнику; пустая группа удаляется.

Любой участник личного чата может удалить его через
`DELETE /conversations/{conversation}`. Сообщения удалятся каскадно.

`PUT /conversations/{conversation}/mark-as-read` без body обновляет отметку
текущего пользователя. После открытия чата вызовите его и поставьте локальный
счётчик в ноль.

## Сообщения

`GET /conversations/{conversation}/messages?cursor=...` отдаёт по 20 сообщений,
новые первыми. Это cursor-, не page-пагинация:

```json
{
  "data": [],
  "links": { "first": "...", "prev": null, "next": "...?cursor=..." },
  "meta": { "path": "...", "per_page": 20, "next_cursor": "...", "prev_cursor": null }
}
```

Для истории переходите по `links.next`; batch обычно нужно развернуть для
показа по возрастанию времени.

- `POST /conversations/{id}/messages`, body `{ "body": "Привет" }` — создать,
  1–2000 символов, ответ `201 { message: Message }`;
- `PUT /messages/{id}` с тем же body — изменить своё, ответ `200`;
- `DELETE /messages/{id}` — удалить своё, ответ `200`.

Изменение и удаление пока не имеют realtime-событий.

## Посты

Все маршруты требуют токен:

- `GET /posts` — непагинированный массив;
- `POST /posts` — создать;
- `PUT /posts/{id}` — изменить свой;
- `DELETE /posts/{id}` — удалить свой, ответ `204`.

Создание/обновление с изображением отправляйте как `FormData`, не задавая
boundary вручную. `title` обязателен при создании (до 255), `body` — до 3000,
poster при создании: jpg/jpeg/png/webp. Для показа берите `poster_url`, не
внутренний путь `poster`.

## Realtime (Laravel Reverb)

```bash
npm install laravel-echo pusher-js
```

Переменные frontend:

```dotenv
VITE_API_URL=http://127.0.0.1:8000/api
VITE_BROADCAST_AUTH_URL=http://127.0.0.1:8000/broadcasting/auth
VITE_REVERB_APP_KEY=local-app-key
VITE_REVERB_HOST=127.0.0.1
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

`REVERB_APP_KEY` публичный; secret и app id клиенту не передаются.

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT || 80),
  wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
  forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: import.meta.env.VITE_BROADCAST_AUTH_URL,
  auth: { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
});
```

Echo принимает имена без `private-`/`presence-`:

```js
echo.private(`conversation.${conversationId}`)
  .listen('MessageSent', ({ message }) => {/* добавить по уникальному id */});

echo.private(`App.Models.User.${currentUser.id}`)
  .listen('UnreadCountUpdated', ({ conversation_id, unread_count }) => {
    // заменить точным значением, не инкрементировать
  });

echo.join('online')
  .here(users => {})
  .joining(user => {})
  .leaving(user => {});
```

`MessageSent` приходит и отправителю — дедуплицируйте по `message.id`.
Известный баг presence: user может прийти как `[UserSummary]`. Временно:

```js
const normalizePresenceUser = value => Array.isArray(value) ? value[0] : value;
```

При смене чата делайте `echo.leave('conversation.ID')`, при смене пользователя
пересоздавайте Echo с новым токеном.

## Ошибки

- `401` — очистить сессию и открыть login;
- `403` — не автор/не участник/не владелец;
- `404` — ресурса нет;
- `422` — `{ message, errors: { field: string[] } }`;
- `500` — внутренняя ошибка, не показывать технический текст пользователю.

Не стройте логику на английском `message`: используйте status и ключи errors.

## Текущие ограничения

- нет endpoint списка/поиска пользователей;
- CORS и Reverb origins разрешены для всех;
- Sanctum-токены бессрочные и без ограниченных abilities;
- realtime нет для edit/delete, состава группы и mark-as-read;
- `user_ids` группы валидируется недостаточно строго;
- registration открыт и синхронно отправляет email.

Перед production эти пункты нужно согласовать с backend-разработчиком.
