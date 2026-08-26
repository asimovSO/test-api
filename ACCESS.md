# Как открыть доступ frontend-разработчику

Разработчику обычно нужны три независимых доступа: к коду, к запущенному API и
к отдельному тестовому пользователю.

## Доступ к коду

Добавьте его в приватный GitHub/GitLab/Bitbucket-репозиторий с ролью
Write/Developer. Admin не нужен. `.env` не коммитьте и передавайте только через
менеджер секретов.

Frontend-разработчику нельзя передавать `APP_KEY`, `REVERB_APP_SECRET`, пароли
БД/Redis/SMTP и production-токены. Ему нужны URL, публичный `REVERB_APP_KEY` и
собственная тестовая учётная запись.

## Вариант 1: backend запускается у разработчика

Это самый безопасный вариант для ежедневной разработки:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
```

После настройки MariaDB добавьте в `.env`:

```dotenv
APP_URL=http://127.0.0.1:8000
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=local-app
REVERB_APP_KEY=local-app-key
REVERB_APP_SECRET=local-app-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
```

```powershell
php artisan migrate:fresh --seed
php artisan storage:link
composer run dev
```

Seeder создаёт локальных пользователей:

| Email | Пароль | Authorization |
|---|---|---|
| `alice@example.com` | `password` | `Bearer 1\|dev-token-alice` |
| `bob@example.com` | `password` | `Bearer 2\|dev-token-bob` |

Они только для local/dev. Не запускайте `DevUsersSeeder` и не используйте эти
credentials на публичном стенде.

## Вариант 2: доступ в одной сети

На backend-машине:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
php artisan reverb:start --host=0.0.0.0 --port=8080
```

Узнайте локальный IPv4 через `ipconfig`, например `192.168.1.50`. Клиенту нужны:

```dotenv
VITE_API_URL=http://192.168.1.50:8000/api
VITE_BROADCAST_AUTH_URL=http://192.168.1.50:8000/broadcasting/auth
VITE_REVERB_HOST=192.168.1.50
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

В Windows Firewall разрешите входящие TCP 8000 и 8080 только для профиля
Private и, по возможности, локальной подсети/IP разработчика. Порт MariaDB 3306
не открывайте. `artisan serve` нельзя напрямую публиковать в интернет.

## Вариант 3: удалённый staging

Для удалённой команды предпочтителен отдельный HTTPS-домен за Nginx/Caddy:

```text
https://api-staging.example.com/api
wss://api-staging.example.com/app/{REVERB_APP_KEY}
```

На сервере должны работать web/PHP-FPM, queue worker и Reverb. Reverse proxy
должен поддерживать websocket Upgrade. Наружу публикуйте только 443; БД, Redis
и внутренний порт Reverb оставьте закрытыми.

Замените wildcard `allowed_origins` в `config/cors.php` и `config/reverb.php` на
точный origin фронтенда. После настройки:

```bash
php artisan optimize:clear
php artisan config:cache
```

HTTPS frontend требует WSS — обычный WS браузер заблокирует как mixed content.
Временный HTTPS tunnel допустим только с паролем/allowlist: сейчас registration
открыт, CORS wildcard, токены бессрочные.

## Тестовая учётная запись

На staging создайте отдельного пользователя через `POST /api/register` (или
административно) и передайте email/password защищённым каналом. Токен разработчик
получит через `/api/login`.

Не используйте один общий токен: `/api/logout` отзывает только текущий токен, а
отдельную учётную запись проще отключить. Для полного отзыва администратор должен
удалить токены пользователя из `personal_access_tokens`.

## Чек-лист передачи

- ссылка на репозиторий и роль Developer/Write;
- `VITE_API_URL` и `VITE_BROADCAST_AUTH_URL`;
- Reverb host, port, scheme и публичный app key;
- отдельные тестовые email/password;
- [документация интеграции](FRONTEND.md).

Проверка: `/up` отвечает `200`, login возвращает token, `/broadcasting/auth`
отвечает `200`, websocket получает `101 Switching Protocols`, а два тестовых
пользователя видят `MessageSent` и `UnreadCountUpdated`.
