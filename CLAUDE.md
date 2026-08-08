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

**Дальше по проекту:** Redis-присутствие (`CheckUserIsOnline` — заготовка уже есть),
групповые чаты (`is_group`/`owner_id` в схеме есть, код их не использует),
броадкастинг (Reverb + Echo).

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
