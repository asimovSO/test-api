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

## Непрочитанные сообщения — сделано (2026-08-07)

**Решение: `last_read_at` в pivot `conversation_user`.** Отдельную таблицу
`message_reads` не делаем — она даёт галочки «кто прочитал конкретное сообщение», но растёт
как сообщения × участники, а для учебного чата хватает счётчика.

Что сделано:
1. Миграция: `last_read_at` (nullable timestamp) в `conversation_user` — правкой существующей
   миграции, без новой (`migrate:fresh` всё равно пересоздаёт).
2. `User::conversations()` → `withPivot('last_read_at')`. Заодно был добавлен `withTimestamps()`
   и снят: в pivot нет колонок `created_at`/`updated_at`, `attach()` падал бы на «Column not found».
   Учесть: на `Conversation::users()` `withPivot` нет — со стороны беседы pivot не прочитается.
3. `PUT /api/conversations/{conversation}/mark-as-read` → `ConversationController::markAsRead`,
   `ConversationPolicy::markAsRead` (по конвенции «метод по действию», внутри тот же
   `isParticipant()`), тело — `$conversation->users()->updateExistingPivot($request->user()->id,
   ['last_read_at' => now()])`, ответ 200 + `message`.
4. Счётчик в списке бесед — `withCount` в `getAllUserConversations`, ключ
   `unread_messages_count`, в `ConversationResource` отдаётся через `whenCounted()`.

Проверено вживую: свежие сообщения от собеседника, ноль сразу после `mark-as-read`,
беседа с `last_read_at = null`.

**Про `updateExistingPivot($id, $attrs)`:** отмечает ровно одного участника, не всех.
`where` собирается из двух мест: связь `$conversation->users()` уже даёт
`conversation_id = <id беседы>`, а первый аргумент — id **противоположной** стороны
(стартуешь от беседы → это `user_id`; стартуешь от юзера → это `conversation_id`).
Передать не ту сторону — тихий баг: обновится чужая/несуществующая строка без ошибки.
У всех сразу отметил бы только `newPivotQuery()->update(...)`.
Плюс: `updateExistingPivot` не трогает `updated_at` беседы, поэтому чтение не поднимает
чат наверх списка (сортировка-то по `conversations.updated_at`).

**Как устроен счётчик (главное — N+1 нет).** `withCount` не делает второй запрос и не делает
join: он дописывает в `select` основного запроса коррелированный подзапрос. Итог — один запрос
на все 20 бесед:

```php
->withCount(['messages as unread_messages_count' => fn ($query) =>
    $query->where('messages.user_id', '!=', $myId)
          ->where(fn ($q) => $q->whereColumn('messages.created_at', '>', 'conversation_user.last_read_at')
                               ->orWhereNull('conversation_user.last_read_at'))])
```

- Ключевой трюк: `last_read_at` **не достаётся в PHP**. Подзапрос ссылается на
  `conversation_user.last_read_at` из внешнего запроса — коррелированному подзапросу это можно,
  а нужная строка pivot там ровно одна, потому что `belongsToMany` уже отфильтровал join по
  `user_id = <мой>`. Попытка вытащить значение через отдельный запрос + `first()->pivot` даёт
  одно и то же число для всех бесед.
- Сравнение колонки с колонкой — `whereColumn`, у обычного `where` второй аргумент это всегда
  значение (`where('conversations.id', 'messages.conversation_id')` сравнит со **строкой**).
- Вложенное замыкание в `where` = круглые скобки в SQL. Без него получается `(A and B) or C`,
  и в беседе с `last_read_at = null` условие C истинно для всех строк — в счётчик попадают
  собственные сообщения.
- `withCount(['relation as alias' => fn])` — именно массив. `withCount('...', fn)` вторым
  аргументом молча игнорирует замыкание, и счётчик считает все сообщения беседы.
- В ресурсе — `whenCounted()` (парный к `whenLoaded`): в `createConversation` счётчик не
  запрашивается, и ключ там просто не появится вместо `null`.

**Известные крайние случаи счётчика (осознанно не чиним):**
- Секундная гранулярность: `timestamp` без precision в MariaDB хранит целые секунды, у
  `messages.created_at` то же самое. Сообщение и отметка о прочтении могут попасть в одну
  секунду и оказаться равны — со строгим `>` такое сообщение навсегда выпадет из непрочитанных.
  (Безопаснее `>=`: посчитается лишним разок и вылечится следующим открытием чата.)
- `messages.user_id` nullable (`nullOnDelete`) → `user_id != $myId` даёт `NULL`, и сообщения
  удалённых пользователей в непрочитанные не попадают.

**Дальше по проекту (на 2026-08-08):** Redis-присутствие (`CheckUserIsOnline` — заготовка уже есть),
групповые чаты (`is_group`/`owner_id` в схеме есть, код их не использует),
броадкастинг (Reverb + Echo).

*(Обновление 2026-08-09: presence через Redis-heartbeat заменена presence-каналами, см. ниже —
пункт снят с очереди.)*

**Известные шероховатости:** гонка при одновременном создании чата (два запроса могут создать
два приватных чата между теми же людьми — защита уровня БД не придумана); в запросе поиска нет
условия «ровно два участника», флаг `is_group=false` его не гарантирует.

## Присутствие — код готов, отображение в API отложено (2026-08-08)

`app/Services/PresenceService.php` написан и работает, но подключать его к списку бесед
(показывать «кто онлайн» пользователю) решили пока не делать — вернёмся отдельной задачей.

**Финальная схема — sorted set через `Redis`-фасад, не `Cache`.** По пути было два разворота:
1. Сначала спроектировали ZSET `users:online` под задачу «список всех, кто онлайн».
2. Потом посчитали, что реально нужна только точечная проверка известных id (участников
   беседы) — под неё ZSET избыточен, переехали на `Cache` с TTL-ключом на юзера
   (`presence:{id}`, `Cache::put`/`Cache::many`, установлен `predis/predis`,
   `REDIS_CLIENT=predis`, `CACHE_STORE=redis`).
3. Требование расширилось до «глобальный список онлайн-юзеров вне контекста конкретной
   беседы» — а это уже перечисление, которое `Cache`-фасад в принципе не умеет ни на каком
   драйвере (нет операции «дай все ключи»). Вернулись к ZSET через `Redis`-фасад — он одной
   структурой закрывает и точечную проверку (`ZMSCORE`), и глобальный список (`ZRANGEBYSCORE`),
   так что параллельно с `Cache`-версией держать не пришлось, она полностью заменена.

**`app/Services/PresenceService.php` — четыре метода**, ключ `users:online`,
порог свежести 40 сек (30-секундный heartbeat + запас):
- `heartbeat(int $userId): void` — `Redis::zadd(KEY, now()->timestamp, $userId)`.
- `isOnline(int $userId): bool` — через `onlineStatuses([$userId])`.
- `onlineStatuses(array $userIds): array` — `Redis::zmscore(KEY, ...$userIds)` одной командой
  (не в цикле — N+1), возвращает `[id => bool]`; для отсутствующих id `zmscore` даёт `null`,
  учтено (`$score !== null && $score >= threshold`).
- `onlineUserIds(): array` — `Redis::zrangebyscore(KEY, threshold, '+inf')`, глобальный список.
- `cleanup(): void` — `Redis::zremrangebyscore(KEY, '-inf', '('.threshold)`, гигиена (не
  обязательна для корректности — `zrangebyscore`/`zmscore` и так фильтруют по score на чтении),
  дёргать по расписанию, если понадобится.
- Проверено вживую через tinker: heartbeat переводит юзера в онлайн, `onlineStatuses` отдаёт
  батч правильно, `onlineUserIds` не показывает протухшую запись даже до `cleanup()`
  (порог фильтрует на чтении), `cleanup()` физически вычищает её из ZSET.

`CheckUserIsOnline::__invoke()` дёргает `PresenceService::heartbeat($user->id)` и отдаёт
`UserResource` (эндпоинт-заготовка под будущий фронтовый heartbeat-поллинг).

**Не сделано (сознательно отложено):** подключение `onlineStatuses`/`onlineUserIds`
к списку бесед и `UserResource` (поле `online` в ресурсе, простановка `$user->online`
на моделях до сериализации), планировщик под `cleanup()`. Логика для этого уже готова
в сервисе — когда вернёмся, остаётся только вызвать её из контроллера.

## Broadcasting — новые сообщения сделаны, дальше счётчик и presence (2026-08-09)

Три пункта в очереди на broadcasting: (1) новые сообщения, (2) счётчик непрочитанных
на лету, (3) presence-каналы Echo — **они заменят** только что построенный `PresenceService`
на Redis/heartbeat-поллинг, а не дополнят его (presence-канал сам знает, кто подключён,
без опроса). Пункт (1) закрыт, ниже — что сделано и на чём споткнулись.

**Инфраструктура.** `laravel/reverb` установлен. Установщик `install:broadcasting --reverb`
дошёл до вопроса «ставить ли npm-пакеты» и завис (неинтерактивная среда, фронта тут и нет —
JS-клиент `laravel-echo`/`pusher-js` сознательно не ставили), пришлось убить процесс и
доделать руками:
- `.env`: `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID/KEY/SECRET` (сгенерированы вручную),
  `REVERB_HOST=localhost`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`, плюс `VITE_REVERB_*`
  (не используются без фронта, но не мешают).
- `config/broadcasting.php` и `config/reverb.php` опубликованы.
- `routes/channels.php` — из коробки только `App.Models.User.{id}`, добавлен свой канал
  (ниже).
- **Грабли с `bootstrap/app.php`:** шорткат `channels: __DIR__.'/../routes/channels.php'`
  внутри `withRouting()` не даёт передать атрибуты (middleware) для `/broadcasting/auth` —
  под капотом всегда зовёт `Broadcast::routes()` без аргументов, а там дефолт
  `['middleware' => ['web']]`. У нас же весь API на `auth:sanctum` (Bearer-токены, не
  сессии) — с `web` middleware `/broadcasting/auth` не видел авторизованного юзера вообще
  (403 на валидный Bearer-токен, проверено curl'ом). Фикс: убрали `channels:` из
  `withRouting()`, вместо этого отдельный вызов
  `->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['auth:sanctum']])`
  сразу за `withRouting()`. После фикса `route:list -v` показывает
  `Illuminate\Auth\Middleware\Authenticate:sanctum` на `/broadcasting/auth`.

**Канал.** `Broadcast::channel('conversation.{conversation}', function ($user, Conversation $conversation) { return $user->can('view', $conversation); })`
в `routes/channels.php`. Переиспользует существующую `ConversationPolicy::view()` —
не завели новый метод под broadcasting, раз проверка та же («участник ли юзер»).
- **Грабли:** implicit route-model-binding в каналах работает **по имени**, не по позиции
  (проверено в `Broadcaster::isImplicitlyBindable` — `$parameter->getName() === $key`).
  Значит wildcard в паттерне обязан совпадать с именем параметра колбэка буква в букву:
  `{conversation}` ⟷ `Conversation $conversation`. Промах (например, `{conversationId}`
  при параметре `$conversation`) не даёт ошибку явно — модель просто не резолвится,
  в параметр приходит сырая строка id, и следующий вызов (`$user->can(...)` с методом
  политики, типизированным под `Conversation`) падает `TypeError`.

**Событие.** `App\Events\MessageSent implements ShouldBroadcastNow` (не `ShouldBroadcast` —
осознанно взяли синхронную versию: `QUEUE_CONNECTION=database`, но воркер (`queue:work`)
не поднят, а тема очередей отдельная и пока не пройдена; `ShouldBroadcastNow` шлёт сразу,
без воркера).
- `public Message $message` в конструкторе (typed property, промотированный аргумент).
- `broadcastOn()` → `new PrivateChannel('conversation.'.$this->message->conversation_id)` —
  строка обязана совпадать с паттерном канала.
- **Грабли (важные, дважды заходили не с той стороны):**
  1. Если у события нет `broadcastWith()`, Laravel формирует payload рефлексией по
     **public**-свойствам класса (`BroadcastEvent::getPayloadFromEvent`,
     `getProperties(ReflectionProperty::IS_PUBLIC)`). `private Message $message` конкретно
     здесь означало бы пустой payload на фронте — без единой ошибки, молча.
  2. Определили `broadcastWith()` — он полностью переопределяет формирование payload,
     рефлексия в этом случае не запускается вообще, видимость свойства после этого уже
     не имеет значения технически (но `public` оставили — соответствует типу параметра
     конструктора, единообразно со стилем проекта).
  3. `broadcastWith()` отдаёт `['message' => MessageResource::make($this->message)]` —
     переиспользует существующий ресурс вместо сырой модели. У `MessageResource.author`
     `whenLoaded('author')` — а `$message` от `create()` эту связь не грузит. Решили
     **не** дёргать `$this->message->load('author')` внутри события (лишний SELECT на
     каждое сообщение), а подставлять уже известного в контроллере автора без похода
     в БД: `$message->setRelation('author', $authUser)` — `setRelation()` возвращает `$this`,
     поэтому это ушло прямо в диспатч одной строкой:
     `MessageSent::dispatch($message->setRelation('author', $authUser))`
     в `MessageController::sendMessage()`.

**Зачем в зависимостях `pusher-php-server`, если используем Reverb.** Reverb — свой,
self-hosted сервер, но говорит по протоколу Pusher; Laravel не пишет отдельный клиент под
Reverb, а переиспользует готовый Pusher PHP SDK, просто нацеленный на `localhost:8080`
вместо `pusher.com`. Не отдельный сервис, а библиотека протокола.

**Как тестировали (полная доставка через Bruno не проверяется — see below).**
1. `broadcastWith()` — вызвали как обычный метод объекта через tinker, посмотрели на
   сериализованный массив (`id`/`body`/`created_at`/`author.id`/`author.name`) —
   правильная форма, без похода в Reverb.
2. Реальный `MessageSent::dispatch(...)` через живой `reverb:start` — прошёл без
   исключений на двух прогонах. Это и есть надёжный сигнал успеха: неверные
   `REVERB_APP_KEY`/хост/порт роняют `Pusher`-клиента исключением (уже видели живьём на
   этапе настройки `.env`), так что отсутствие исключения = событие принято сервером.
   `reverb:start --debug` в лог по HTTP-trigger событиям, увы, ничего не пишет (только
   по факту WS-подключений), так что глазами в логе это не показать.
3. **Почему в Bruno нельзя увидеть саму доставку:** broadcasting держится на открытом
   WebSocket-соединении, по которому сервер сам, по своей инициативе, шлёт данные —
   Bruno такое соединение открыть не умеет (HTTP request/response, не WS). Нужен
   настоящий WS-клиент (браузер с Echo/pusher-js, `wscat` и т.п.), не заводили — фронта
   в проекте нет.
4. **Что из этого всё же тестируется в Bruno — авторизация подписки**, `POST
   /broadcasting/auth`, обычный HTTP-эндпоинт (см. грабли про `bootstrap/app.php` выше).
   Нужны `channel_name` (`private-conversation.{id}`, префикс `private-` — часть
   Pusher-протокола, в `Broadcast::channel()` его нет, а в запросе он есть) и `socket_id`
   (Pusher требует его для подписи ответа; настоящий выдаёт Reverb при WS-подключении,
   но для проверки только своей логики авторизации подходит любая непустая строка —
   Reverb эту сигнатуру дальше сверяет со своей стороны при реальном WS-хендшейке).
   Проверено curl'ом: участник (Alice, беседа 1) → `200` с телом `{"auth":"..."}`,
   не участник (Bob, та же беседа) → `403`. Логика `Broadcast::channel()` подтверждена
   рабочей без единого реального WS-соединения.

## Счётчик непрочитанных на лету — сделано (2026-08-09)

Пункт 2 из очереди broadcasting (после новых сообщений).

**Почему не тот же канал, что у сообщений.** `conversation.{id}` слушают только те, кто
**сейчас открыл именно эту** беседу. Счётчик же должен обновляться и тогда, когда получатель
сидит на экране списка бесед, а конкретный чат не открыт — значит, нужен канал **на юзера**,
не на беседу. В `routes/channels.php` такой уже есть из коробки — `App.Models.User.{id}`
(дефолтный канал Laravel, до этого не использовался).

**Решение по payload (осознанный выбор — точное число, не сигнал):** обсуждали два варианта —
слать только `conversation_id` (фронт сам инкрементит +1 локально, ноль лишних SELECT'ов) или
готовое точное число `unread_messages_count` для этого получателя (пересчитывается на бэке,
надёжнее — не разъедется при пропущенном событии/нескольких вкладках, но лишний SELECT на
каждого получателя на каждое сообщение). Выбрали второе — точность важнее для этого проекта.

**Реализовано (два файла):**
1. **`App\Events\UnreadCountUpdated implements ShouldBroadcastNow`** — отдельное от
   `MessageSent`, потому что другой адресат (юзер, не беседа) и другой смысл («у тебя
   изменился счётчик», а не «пришло сообщение»). Конструктор принимает целые модели
   `(Conversation $conversation, User $user, int $unreadCount)`, а не голые id — переживает
   `SerializesModels` нормально. `broadcastOn()` → `PrivateChannel('App.Models.User.'.$this->user->id)`,
   `broadcastWith()` → `['conversation_id' => ..., 'user_id' => ..., 'unread_count' => ...]`.
2. **`App\Listeners\SendUnreadCountToUsers`**, `handle(MessageSent $event)` — отдельным
   классом, не встроен в `MessageController`, чтобы не раздувать контроллер.
   Автообнаружение листенеров сработало само (Laravel 11+, `Application::configure()` сам
   зовёт `->withEvents()`), регистрировать вручную не пришлось.
   Внутри:
   - беседа — `$event->message->conversation` (lazy load);
   - получатели кроме отправителя — `$conversation->users()->where('users.id', '!=',
     $event->message->user_id)->get(['users.id'])` (колонка квалифицирована `users.`,
     та же причина, что в `ConversationPolicy::isParticipant` — запрос идёт через
     `belongsToMany` с join'ом на `conversation_user`);
   - **решение по «на подумать» из предыдущей сессии:** `Conversation::users()` у нас **уже**
     собран с `withPivot('last_read_at')` (ранняя заметка в разделе про непрочитанные, что на
     `Conversation::users()` этого нет — устарела, к моменту этой сессии уже было исправлено).
     Значит `last_read_at` доступен прямо на pivot полученных получателей:
     `$user->pivot->last_read_at` — без похода в pivot-таблицу отдельным запросом.
   - счётчик на получателя: `$conversation->messages()->where('user_id', '!=', $event->message->user_id)
     ->when($lastReadAt, fn ($q) => $q->where('created_at', '>', $lastReadAt))->count()`.
     Это обычный `where` со значением, а не `whereColumn` — в отличие от `withCount` в списке
     бесед, здесь нет join'а с pivot в этом же запросе, `$lastReadAt` уже готовое PHP-значение.
     `when()` с пустым `$lastReadAt` (юзер ни разу не открывал беседу) пропускает условие —
     считаются все чужие сообщения, тот же смысл, что был у `orWhereNull` в `withCount`.
   - для группы участников (не только приватных чатов) это цикл с одним `SELECT` на
     получателя за сообщение — риск N+1 при большом числе участников группы, но группы
     в проекте пока не используются реально (см. ниже), так что не блокирует.
   - в конце цикла: `UnreadCountUpdated::dispatch($conversation, $user, $unreadCount);`.

**Проверено вживую:** `POST .../messages` от Alice → `201`, `reverb:start` в фоне не выдал
исключений (тот же косвенный сигнал успеха, что и с `MessageSent` — `ShouldBroadcastNow`
дёргается синхронно в теле запроса, исключение уронило бы сам HTTP-ответ в `500`). Отдельно
пересчитан вручную ожидаемый `unread_count` для Боба (`last_read_at = null` → все чужие
сообщения, получилось `1` после одного нового) — сошлось с тем, что должен был посчитать
листенер.

## Presence-каналы — сделано, есть непочиненный баг (2026-08-09)

Пункт 3 из очереди broadcasting, последний. **Заменяет** `PresenceService` (Redis-heartbeat,
раздел «Присутствие» выше) — не дополняет: presence-канал сам знает, кто сейчас подключён по
WS, опрос не нужен.

- `Broadcast::channel('online', function ($user) { return [...]; })` в `routes/channels.php`.
  Ключевое отличие от private-канала: колбэк возвращает **не `bool`**, а данные о юзере —
  именно это Laravel/Reverb трактуют как признак presence-канала.
- Кроме объявления канала писать почти нечего: список «кто здесь» presence-канал ведёт сам
  (WS-подключение = «здесь», разрыв = «ушёл»), никакого сервисного кода на бэке под это не
  нужно.
- Старый heartbeat-эндпоинт снесён: `GET /api/heartbeat`, `CheckUserIsOnline` контроллер —
  удалены. `app/Services/PresenceService.php` физически ещё в кодовой базе, но больше нигде
  не используется — можно сносить, когда руки дойдут.
- **Проверено:** `POST /broadcasting/auth` с `channel_name=presence-online` → `200`
  (участник — любой авторизованный юзер, presence-канал открыт всем, не завязан на конкретную
  беседу). Полный список подключённых, как и с `MessageSent`/`UnreadCountUpdated`, живьём не
  проверить без реального Echo-клиента (фронта в проекте нет).
- **Баг с обёрткой `[UserResource]` — на проверку 2026-08-16 не подтвердился.**
  `git log -p -- routes/channels.php` показывает, что канал `online` появился одним
  коммитом (`unread count broadcast`, 2026-08-09) сразу в виде
  `return new \App\Http\Resources\UserResource($user);`, без `[...]`. Отдельного
  фикс-коммита нет — похоже, эта заметка описывала промежуточный черновик, который
  в реальности так и не был закоммичен. Текущий код в `routes/channels.php` верный.
- **Мёртвый импорт `CheckUserIsOnline` — тоже не подтвердился (проверено 2026-08-16).**
  В `routes/api.php` такого импорта нет вообще — файл чистый, только реально
  используемые контроллеры. Похоже, обе эти заметки описывали промежуточное
  состояние кода, которое так и не попало в коммит.

## Итог по broadcasting (2026-08-09)

Все три пункта очереди закрыты: новые сообщения, счётчик непрочитанных на лету,
presence-каналы. Полный контракт (эндпоинты + каналы + форматы payload) вынесен в
отдельный файл **`API.md`** в корне репозитория — предназначен для будущего фронтенд-проекта
(в отдельном репо), чтобы не пересказывать историю решений, только актуальный контракт.

**Открытые мелочи (не блокируют, но стоит закрыть в следующий заход):**
1. ~~Баг с обёрткой `[UserResource]` в presence-канале~~ — проверено 2026-08-16, в коде
   этого бага нет и не было в закоммиченном виде (см. выше). Снято с очереди.
2. ~~Мёртвый импорт `CheckUserIsOnline` в `routes/api.php`~~ — проверено 2026-08-16,
   импорта в файле нет. Снято с очереди.
3. ~~`PresenceService.php`~~ — удалён 2026-08-16, подтверждённый код-мертвец (ни одной
   ссылки на класс нигде в `app/`/роутах). `predis/predis` **не** тронут: `.env` держит
   `CACHE_STORE=redis` для всего приложения, не только для присутствия — Redis тут
   по-прежнему драйвер кэша, снимать зависимость нельзя без смены `CACHE_STORE` обратно
   на `database` (как в `.env.example`), а это отдельное решение, не сделано намеренно.

**Дальше по проекту (устарело, см. ниже свежие разделы).**

## Что сделано после 2026-08-09, но не задокументировано по ходу

Несколько сессий без обновления CLAUDE.md — по git-логу сделано: `updateMessage`/
`deleteMessage` + `MessagePolicy` (`edit`/`delete` через `isAuthor`), флаг `is_edited` на
`Message` (`created_at != updated_at`, `$appends`), `cursorPaginate` вместо `paginate` в
`getMessages`. Групповые чаты **начаты, но не закончены** (коммит `group chats(in
maintenance` — говорящее название): есть `POST /api/conversations/group` →
`ConversationController::createGroupConversation` → `ConversationService::firstOrCreateGroup`,
но **нет** управления составом (добавить/удалить участника, выйти из группы), **нет**
owner-only прав — `ConversationPolicy::delete` проверяет только `isParticipant`, значит
удалить группу может любой участник, не только `owner_id`. Самое очевидное продолжение,
когда дойдут руки.

## Рефакторинг на FormRequest — в процессе (2026-08-15)

Отдельная учебная задача, не привязана к продуктовым фичам: переносим inline
`$request->validate([...])` в контроллерах на классы `FormRequest` (`app/Http/Requests`),
чтобы разделить «как принять запрос» и «какие у него правила».

**Сделано:** `app/Http/Requests/MessageRequest.php` — правило `body` (`required|string|
min:1|max:2000`), `authorize()` оставлен `true` по умолчанию (авторизация продолжает жить
в `Gate::authorize(...)` + policies в контроллере, не дублируется в FormRequest —
осознанный выбор между «FormRequest только валидирует» и «ещё и авторизует», взят первый,
раз policies уже были готовы). Подключён в `MessageController::sendMessage` и
`updateMessage`.

**Баг на этом пути (пойман и исправлен):** `deleteMessage` изначально тоже получил
`MessageRequest $request` — а у DELETE нет `body`, так что `rules()` требовал поле,
которого в запросе нет → 422 на каждый вызов. Метод вообще не использовал объект запроса,
поэтому исправлено на `deleteMessage(Message $message)` без параметра запроса целиком, а
не просто заменой типа на `Request`. Правило на будущее: FormRequest привязан к форме
*данных*, не к контроллеру — если у метода нет входных данных для валидации, ему не
нужен даже `Request`, если он не используется.

**`createGroupConversation` тоже переведён — сделано (2026-08-16).**
`app/Http/Requests/CreateGroupConversationRequest.php`, правила один в один со старым
инлайном (`name` — `required|string|max:255`, `user_ids` — `required|array|min:2`),
`authorize()` по умолчанию `true` — та же схема, что у `MessageRequest`. Заодно убрана
дублирующая проверка `count($userIds) < 2` в `ConversationService::firstOrCreateGroup` —
теперь единственное место, где это проверяется, это правило `min:2`. Попутно вскрылось,
что в убранной проверке текст ошибки был рассинхронизирован с кодом («at least 3 members»
при условии `< 2») — баг ушёл вместе с самой проверкой.

Рефакторинг на FormRequest на этом закончен (оба кандидата, `MessageRequest` и этот,
переведены).

## Групповые чаты: owner-only права и управление составом — сделано (2026-08-16)

Закрывает пункт, оставшийся открытым с 2026-08-09 (см. раздел «Что сделано после
2026-08-09» выше).

**`ConversationPolicy::delete` теперь различает приват и группу:**
`$conversation->is_group ? $conversation->owner_id === $user->id : $this->isParticipant(...)`.
Для приватного чата поведение не поменялось (любой участник); для группы — только owner.

**Новые policy-методы**, тем же принципом «по действию»:
- `addParticipants`/`removeParticipants` — `is_group && owner_id === user.id`. Управление
  составом решили сделать owner-only, не «любой участник может добавить/удалить».
- `quitConversation` — `is_group && isParticipant`. Выход привязан к `is_group`: у
  приватного чата выхода как отдельного действия нет — там для этого уже есть
  `deleteConversation`, доступный любому участнику.

**Новые роуты** (`routes/api.php`, внутри `prefix('/conversations')`):
`POST /{conversation}/participant/{user}` → `addParticipant`,
`DELETE /{conversation}/participant/{user}` → `removeParticipant`,
`POST /{conversation}/quit` → `quitConversation`. Везде route model binding на `{user}`.

**`addParticipant`:** явная проверка `exists()` перед добавлением → `400 "User is already
a participant"`, потом `syncWithoutDetaching([$user->id])` (не `attach()`). Причина не
в стиле: `conversation_user` имеет составной PK `(conversation_id, user_id)`, `attach()` —
это голый `INSERT`, повторный вызов на уже прикреплённом юзере ловит нарушение PK →
`QueryException` → 500 без единой валидации. `syncWithoutDetaching` идемпотентен —
добавляет только отсутствующих, существующую строку не трогает — сам по себе уже не упал
бы, ручная проверка нужна только чтобы вернуть осмысленное сообщение вместо тихого 200
без изменений.

**`removeParticipant`:** просто `detach()`, без доп. проверок — `detach()` на не-участнике
это no-op, не ошибка, разбираться отдельно незачем.

**`quitConversation` — передача владения, если уходит owner.** Если `owner_id ===
$request->user()->id`, ищем `$conversation->users()->where('user_id', '!=',
$conversation->owner_id)->first()` и назначаем нового owner'а; если такого нет (owner —
последний участник) — beседа удаляется целиком, с ранним `return` сразу после `delete()`.
Только потом (или если уходящий не owner) — общий `detach($request->user()->id)`.

*Критерий выбора нового owner'а — «любой из оставшихся», не «самый старый».* Первая
версия пыталась `orderBy('conversation_user.created_at')` — не сработало бы: у pivot-таблицы
`conversation_user` нет колонки `created_at` вообще (см. миграцию — только `user_id`,
`conversation_id`, `last_read_at`; `withTimestamps()` на этой связи снят ещё в разделе
про непрочитанные). Да и по смыслу «самый старый участник» почти всегда сам owner (он
прикрепляется первым при создании группы) — сортировка без исключения `owner_id` из
кандидатов просто переназначила бы владение самому себе. Раз временной метки
присоединения в pivot нет в принципе, критерий упростили до первого попавшегося не-owner'а.

**Баг по пути (пойман и исправлен):** промежуточная версия останавливала выполнение через
`!$oldestParticipant && $conversation->delete();` — короткое замыкание вызывает `delete()`,
но не выходит из метода; следующая строка (`$conversation->owner_id =
$oldestParticipant->id`) всё равно исполнялась и падала на `null->id`. Фикс — явный `if
(!$newOwner) { ...; return ...; }` с `return` внутри, а не короткое замыкание.

**Проверено:** пока только код-ревью (диалог с Claude), живых вызовов через Bruno в эту
сессию не делали.

**Дальше по проекту (устарело, см. ниже свежий раздел).**

## Ревизия открытых хвостов + тесты на групповой unread-счётчик — сделано (2026-08-16)

Прошлись по списку мелких открытых пунктов из предыдущих сессий — большинство не
подтвердилось при проверке по факту (коду/git log):

- **Баг с обёрткой `[UserResource]` в presence-канале** — не подтвердился. `git log -p --
  routes/channels.php` показывает, что канал `online` появился одним коммитом сразу в
  правильном виде (`return new UserResource($user);`, без массива). Похоже, заметка
  описывала промежуточный черновик, который так и не попал в коммит.
- **Мёртвый импорт `CheckUserIsOnline` в `routes/api.php`** — тоже не подтвердился,
  импорта в файле нет вообще.
- **`PresenceService.php`** — этот пункт подтвердился: реальный код-мертвец (ни одной
  ссылки нигде в `app/`/роутах). Удалён (`git rm`). `predis/predis` **не** тронут —
  `.env` держит `CACHE_STORE=redis` для всего приложения, это отдельное решение вне
  контекста presence.
- **`unread_messages_count` для групп** — проверено вживую через tinker на тестовой
  группе из 3 участников (создана и потом удалена, в БД не осталась): и `withCount` в
  `getAllUserConversations`, и ручной пересчёт в `SendUnreadCountToUsers` посчитали
  верно (2 непрочитанных от двух разных отправителей, сброс в 0 после mark-as-read,
  корректный +1 на новое сообщение, разные числа для разных получателей с разным
  `last_read_at`). Опасение не подтвердилось: обе логики писались без привязки к
  числу участников (`user_id != $myId` + сравнение с `last_read_at`), поэтому на группе
  работают так же, как на привате — просто раньше не было ни одной группы с реальными
  сообщениями, чтобы это увидеть.
  **Закреплено тестами:** `tests/Feature/GroupUnreadCountTest.php`, 3 теста (список
  бесед, mark-as-read + новое сообщение, per-recipient рассылка через
  `Event::fake([UnreadCountUpdated::class])`). Учтена секундная гранулярность timestamp'ов
  (`$this->travel(1)->second()` между отметкой прочтения и следующим сообщением — тот же
  краевой случай, что описан в разделе про непрочитанные выше).

**N+1 в `SendUnreadCountToUsers` — тоже подтвердился и исправлен (2026-08-16).**
Было: 1 запрос на список получателей + по одному `COUNT`-запросу в цикле на каждого —
растёт линейно с числом участников группы. Заменено на один коррелированный `LEFT JOIN`
между `conversation_user` и `messages`, с условием сравнения `created_at > last_read_at`
внутри `ON` (а не в `WHERE`, где оно было бы уже PHP-значением, а не колонкой) и
`GROUP BY user_id`:

```php
DB::table('conversation_user as cu')
    ->leftJoin('messages as m', function ($join) {
        $join->on('m.conversation_id', '=', 'cu.conversation_id')
            ->on('m.user_id', '!=', 'cu.user_id')
            ->where(fn ($q) => $q->whereColumn('m.created_at', '>', 'cu.last_read_at')
                ->orWhereNull('cu.last_read_at'));
    })
    ->where('cu.conversation_id', $conversation->id)
    ->where('cu.user_id', '!=', $senderId)
    ->groupBy('cu.user_id')
    ->selectRaw('cu.user_id, COUNT(m.id) as unread_count')
    ->pluck('unread_count', 'user_id');
```

Тот же трюк, что раньше применялся в `getAllUserConversations` («один юзер, много бесед»
через коррелированный подзапрос в `withCount`), только развёрнутый на 180° — здесь «одна
беседа, много юзеров», и сравнение колонок переехало из `WHERE` в `ON` join'а, потому что
`last_read_at` для каждого получателя своя, а не одно фиксированное PHP-значение.

Итог — 2 запроса на весь листенер независимо от числа участников (1 за списком
получателей + 1 за счётчиками), было бы `1 + N`. **Проверено вживую через tinker** на
группе с 5 получателями (`DB::enableQueryLog()`): было 6 запросов до фикса (посчитано
по формуле, не перепроверялось живьём в старой версии), стало 2 после. Корректность
значений подтверждена существующими тестами `GroupUnreadCountTest.php` — прошли без
изменений после рефакторинга листенера, что и требовалось (внешнее поведение то же,
изменилась только реализация).

**Дальше по проекту:** и owner-only права/состав группы, и unread-счётчик для групп
(включая N+1), закрыты. Открытых хвостов по broadcasting/группам на 2026-08-16 не
осталось.
