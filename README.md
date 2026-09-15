# Yandex Maps Reviews Prototype

Тестовый прототип на Laravel + Vue 3: вход под сид-пользователем, сохранение ссылки на карточку организации в Яндекс.Картах, фоновый парсинг отзывов и вывод результата по 50 отзывов на страницу.

## Быстрый запуск

```bash
docker compose up --build
```

После старта приложение доступно на `http://localhost:8080`.

Демо-доступ:

- email: `demo@example.com`
- password: `password`

Docker Compose поднимает три сервиса: Laravel web, queue worker и PostgreSQL. Таблицы создаются миграциями автоматически при старте `app`.

Если запускаете без Docker, нужны PHP 8.4.1+ с расширением `pdo_pgsql`, Composer, Node 24+ и доступный PostgreSQL:

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate --seed
php artisan queue:work
npm run dev
php artisan serve --port=8080
```

При запуске без Docker укажите в `.env` параметры своего PostgreSQL. Например, для локального сервера на Windows/macOS обычно нужен `DB_HOST=127.0.0.1`, а не `postgres`.

## Переменные окружения

- `APP_URL` - URL приложения.
- `APP_ENV`, `APP_DEBUG`, `APP_KEY` - окружение Laravel, режим отладки и ключ шифрования cookies/сессий.
- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` - подключение к PostgreSQL.
- `QUEUE_CONNECTION` - по умолчанию `database`, чтобы парсинг не выполнялся внутри HTTP-запроса.
- `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE` - домен и HTTPS-режим cookie для production.
- `SANCTUM_STATEFUL_DOMAINS` - домены SPA для cookie-аутентификации Sanctum.
- `YANDEX_MAPS_USER_AGENT` - User-Agent для запросов к Яндексу.

Пример production-настроек:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
APP_KEY=base64:...

LOG_LEVEL=error

DB_CONNECTION=pgsql
DB_HOST=postgres-host
DB_PORT=5432
DB_DATABASE=yandex_reviews
DB_USERNAME=yandex_reviews
DB_PASSWORD=secret

SESSION_DRIVER=database
SESSION_DOMAIN=example.com
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=example.com

QUEUE_CONNECTION=database
CACHE_STORE=database
```

## Архитектура

- `app/Http/Controllers` - только HTTP-вход, валидация и ответы.
- `app/Services/YandexMaps/YandexMapsParser.php` - изолированная логика внешнего источника.
- `app/Jobs/ParseYandexOrganizationJob.php` - фоновый парсинг с retry/backoff.
- `organizations` - карточка, статус, рейтинг, счётчики и прогресс.
- `reviews` - отзывы, уникальные по `organization_id + external_id`, поэтому повторный парсинг обновляет записи без дублей.
- `organization_snapshots` - история агрегатов карточки для будущего сравнения "было -> стало".

## Подход к парсингу

У Яндекс.Карт нет публичного API для отзывов. Это подтверждает ответ поддержки Яндекса на Stack Overflow: прямой публичный API для отзывов не предоставляется. В публичной справке Яндекс Бизнес также указано, что в Яндекс.Картах/Бизнесе доступны последние 600 опубликованных отзывов, поэтому парсер ограничен безопасным потолком около этого объёма.

Подход: сводка из HTML карточки, отзывы из внутреннего JSON-запроса:

1. Из ссылки достаётся `businessId`.
2. Короткие ссылки `https://yandex.ru/maps/-/...` раскрываются до обычной карточки или URL с `poi[uri]=ymapsbm1://org?oid=...`.
3. Парсер открывает `/maps/org/{businessId}/` и извлекает JSON-state из HTML.
4. В состоянии выбирается организация с совпадающим ID. Из её `ratingData` берутся `ratingValue`, `ratingCount`, `reviewCount`. Число оценок не подменяется числом отзывов.
5. Отзывы загружаются по 50 через `fetchReviews`, до `data.params.totalPages` или лимита 650. ID дедуплицируются, повторная страница и неполная загрузка вызывают ошибку.

Загрузка отзывов: `https://yandex.ru/maps/api/business/fetchReviews`:

1. Выполняется POST к `fetchReviews`, чтобы получить CSRF-токен и cookie-сессию.
2. Дальше выполняются GET-запросы с `pageSize=50`, `ranking=by_time`, `csrfToken`, `sessionId`, `reqId` и подписью `s`.
3. Подпись `s` считается как DJB2 xor по отсортированной query-string.

Почему не headless-браузер:

- Серверные страницы и JSON-запрос быстрее и дешевле по ресурсам.
- Очередь может обрабатывать десятки карточек без запуска Chrome на каждую.
- Данные приходят структурированными в JSON-state, меньше риска ошибиться на CSS-селекторах.

Минусы:

- Это недокументированный контракт. Яндекс может изменить подпись, поля ответа или антибот-логику.
- При массовом использовании нужны прокси, лимиты скорости и мониторинг ошибок.
- В спорных случаях headless-браузер может быть более похож на пользовательский сценарий, но он тяжелее и тоже хрупок.

Источники для ориентира:

- [Справка Яндекс Бизнес о последних 600 отзывах](https://www.yandex.com/support/business-priority/en/manage/reviews)
- [Обсуждение отсутствия публичного API](https://ru.stackoverflow.com/questions/1590505/api-%D0%AF%D0%BD%D0%B4%D0%B5%D0%BA%D1%81-%D0%BF%D0%BE%D0%BB%D1%83%D1%87%D0%B0%D1%82%D1%8C-%D0%BE%D1%82%D0%B7%D1%8B%D0%B2%D1%8B-%D0%BD%D0%B0-%D1%81%D0%B2%D0%BE%D0%B8-%D1%81%D0%BE%D0%B7%D0%B4%D0%B0%D0%BD%D0%BD%D1%8B%D0%B5-%D0%BE%D1%80%D0%B3%D0%B0%D0%BD%D0%B8%D0%B7%D0%B0%D1%86%D0%B8%D0%B8)
- [Community-описание fetchReviews](https://qna.habr.com/q/549068)
- [Описание server-rendered pagination подхода](https://github.com/selimdev00/arcturus)

## Устойчивость к смене разметки и API

Парсер не должен молча возвращать пустоту. Сейчас он переводит организацию в `failed`, пишет понятную ошибку и логирует событие, если:

- не извлечён `businessId`;
- Яндекс не выдал CSRF-токен;
- получен `403` или `429`;
- ответ не JSON;
- не найдены рейтинг или счётчики;
- первая страница не содержит ни отзывов, ни счётчиков.

Для production я бы добавил scheduled canary-проверку на известной карточке и алерт, если структура ответа изменилась или резко выросла доля `failed`.

## Масштаб и очередь

Парсинг запускается через `ParseYandexOrganizationJob`, HTTP-запрос только сохраняет ссылку и ставит задачу в очередь. В интерфейсе есть polling статуса и прогресса. Для сети из 50 филиалов worker можно масштабировать отдельно от web-процесса, но обязательно с глобальным rate limit, чтобы не устроить всплеск запросов к Яндексу.

## Антибан на объёме

В прототипе есть небольшие случайные паузы между страницами и retry/backoff на уровне Laravel job. Для реальной эксплуатации нужны:

- общий троттлинг по домену Яндекса;
- jitter между карточками и страницами;
- экспоненциальный backoff при `403`, `429`, timeout и пустых ответах;
- ротация User-Agent и прокси с контролем качества;
- отдельный статус `blocked`, чтобы не ретраить бесконечно при бане;
- лимиты на частоту полного обновления карточек, например свежие отзывы чаще, полный ресинк реже.

## Идемпотентность и история

Отзывы сохраняются через `updateOrCreate` по внешнему id отзыва. Повторный парсинг той же карточки обновит автора, текст, оценку, дату и raw payload, но не создаст дубликат.

Снимки агрегатов пишутся в `organization_snapshots`. Для полноценной истории изменений я бы расширил модель:

- хранить hash нормализованного отзыва;
- при изменении писать `review_changes` с `before` и `after`;
- для удалённых из выдачи отзывов ставить `missing_since`, не удаляя строку сразу.

## Что доделал бы при большем времени

- Реальный мониторинг canary-карточек и алерты.
- Более точную нормализацию разных вариантов JSON-ответа Яндекса.
- Отдельный экран истории изменений.
- Админские настройки лимитов, прокси и расписания обновлений.
- E2E-тесты на UI и интеграционный контракт-тест парсера с сохранёнными fixtures.

## Проверка

Контрактные проверки (HTTP-заглушки, без запросов к Яндексу):

```bash
docker compose exec app php tests/parser-contract.php
```

Проверяются все страницы, отдельные счётчики, совпадение ID организации, отсутствие обязательного счётчика и повторная страница.

Реальный запуск без сохранения в БД:

```bash
docker compose exec app php artisan yandex:parse-test "https://yandex.ru/maps/-/CTtP72Zr"
```

На 14 сентября 2026 эта ссылка успешно вернула 166 уникальных отзывов, рейтинг 5 и 207 оценок. Два последовательных сохранения дали 166 записей без дублей, страницы 50/50/50/16 и два снимка. Эти результаты не гарантируют доступность каждой карточки: антибот-защита и внутренний контракт могут измениться.
