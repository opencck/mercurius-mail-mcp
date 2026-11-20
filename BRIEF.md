# BRIEF — mercurius-mail-mcp

Сервер MCP для почты агента Меркурий: свой асинхронный клиент IMAP и SMTP на AMPHP, без внешних почтовых библиотек.
Агент через него смотрит папки, ищет и читает письма, получает вложения и отправляет письма от имени ящика из своих
настроек. Без модуля агент работает, но без почты.

## Назначение и границы

Сервер делает:

- `mail_folders` — список папок ящика;
- `mail_list` — письма папки, новые сверху, с фильтрами `unseen`, `from`, `subject`, `text`, `since`, `limit` (до 50);
- `mail_read` — одно письмо: заголовки, текст (HTML в текст, до 20 000 символов), вложения с номерами, подтверждён ли
  отправитель и по какому authserv-id;
- `mail_attachment` — вложение по uid и номеру, встроенным ресурсом MCP (`blob`);
- `mail_send` — письмо простым текстом, ответ с цепочкой (`reply_to`), вложения `files`.

Остаётся в приложении (`src/Infrastructure/Config/MailSettings.php` и клиент MCP приложения):

- ящик и пароль: настройки агента `MAIL_HOST`, `MAIL_IMAP_PORT`, `MAIL_SMTP_PORT`, `MAIL_USER`, `MAIL_PASSWORD`,
  `MAIL_FROM`, `MAIL_AUTHSERV_ID`; модель их не видит;
- карточка владельца перед `mail_send` — инструмент помечен разрушающим;
- сохранение вложения из ответа в `inbox/<день>/` участника и чтение файлов песочницы для `files`;
- рамка недоверенных данных для писем.

## Устройство

| Путь / файл | Что делает |
|---|---|
| `GET /health` | `{"status":"ok"}`, без токена |
| `POST /mcp` | JSON-RPC: `initialize`, `tools/list`, `tools/call`, `ping`; ответ — JSON (Streamable HTTP без потока); уведомление без `id` — `202` |
| `bin/mail-mcp` | запуск: `MAIL_MCP_TOKEN`, `MAIL_MCP_PORT` (8090), `MAIL_MCP_HOST` (0.0.0.0) |
| `src/Server.php` | HTTP: токен, предел тела, разбор JSON |
| `src/McpHandler.php` | методы MCP, описания и схемы инструментов, проверка `files` |
| `src/Account.php` | ящик из заголовков `X-Mail-*` |
| `src/Mailbox.php` | сценарии над ящиком: папки, список, письмо, вложение, отправка |
| `src/ImapClient.php`, `src/SmtpClient.php` | протоколы IMAP и SMTP |
| `src/SocketConnection.php`, `src/Connection.php` | TCP с TLS сразу или после STARTTLS, таймаут чтения |
| `src/SenderCheck.php` | подлинность отправителя по `Authentication-Results` |
| `src/Mime/` | разбор писем (`Parser`, `Html`, `Message`) и сборка (`Builder`) |

Ящик приходит в каждом запросе: `X-Mail-Host`, `X-Mail-User`, `X-Mail-Password`, по желанию `X-Mail-Imap-Port` (993),
`X-Mail-Smtp-Port` (465), `X-Mail-From`, `X-Mail-Authserv-Id`.

## Безопасность и пределы

- Вход — `Authorization: Bearer <MAIL_MCP_TOKEN>`, сравнение `hash_equals`; токен короче 16 символов — сервер не
  стартует (код 78).
- Учётные данные не хранятся: только в заголовках запроса; пароль помечен `SensitiveParameter`. Хост ящика проверяется
  по шаблону имени.
- IMAP — TLS с начала соединения; SMTP — TLS на 465, STARTTLS на 587 и 25. TLS-контекст с именем хоста.
- Чтение не меняет ящик: папки открываются `EXAMINE`, письма берутся `BODY.PEEK`.
- Отправитель подтверждён только по первому `Authentication-Results` своего сервера (authserv-id из заголовка или
  хост): DMARC pass или SPF/DKIM pass, выровненные с доменом `From`; иначе — пометка, что `From` мог быть подделан.
- Пределы в коде: тело запроса — 36 МБ; письмо из IMAP — до 5 000 000 байт; строка протокола — до 1 МБ; таймаут
  соединения и чтения — 30 с; `mail_list` — до 50 писем; текст письма в ответе — 20 000 символов; `mail_send` — до 20
  адресатов в `to` и в `cc`, текст до 50 000 символов, до 10 вложений и вместе до 25 МБ.
- Образ: пользователь `mailmcp` (uid 10042), `memory_limit` PHP — 256 МБ. Compose: сеть `internal`, `read_only`,
  `mem_limit: 256m`, `no-new-privileges`. Порт наружу не публикуется.

## Решения

- Свой IMAP и SMTP на AMPHP: асинхронно, без блокирующих расширений PHP.
- Сервер без учётных данных: ящик живёт в настройках агента, сервис можно перезапускать и делить без секретов на диске.
- `mail_send` помечен `destructiveHint`: отправка необратима, клиент спрашивает человека.
- Чтение через `EXAMINE` и `BODY.PEEK`: агент не помечает письма прочитанными.
- Проверка отправителя — по образцу почтового адаптера hermes-agent: `From` пишет сам отправитель.
- Вложение отдаётся ресурсом MCP, а не текстом: файл сохраняет клиент.
- Свой образ, а не зависимость Composer приложения: модуль живёт в своём профиле compose.

## Проверки

- `composer check` — PHPUnit (`test/ClientsTest.php` с `FakeConnection`, `test/MimeTest.php`,
  `test/SenderCheckTest.php`), PHPStan (max) и Psalm (уровень 1).
- CI (`.github/workflows/ci.yml`): одна задача, PHP 8.2, `composer check`; тесты с сетью и секретами (группа
  `integration`) в CI не идут; изменения только в `*.md` CI не запускают.

## Связь с Меркурием

- Профиль compose — `mail`: добавить в `COMPOSE_PROFILES`; сервис `mail-mcp`.
- `.env`: `MAIL_MCP_TOKEN` (пусто — почты у агента нет), `MAIL_MCP_URL` (по умолчанию `http://mail-mcp:8090/mcp`).
  Ящик задаётся в диалоге (`/set MAIL_HOST …`).
- В приложении это встроенный сервер MCP `mail`: инструменты регистрируются без одобрения; `doctor` проверяет модуль.
- Спецификация:
  [Почта: встроенный MCP-сервер](../../brief/skills-mcp.md#почта-встроенный-mcp-сервер-решение-владельца-2026-11-17),
  [Скиллы и MCP](../../brief/skills-mcp.md#скиллы-и-mcp),
  [Модули: сервисы, MCP и скиллы](../../brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы),
  [Подтверждения и запреты](../../brief/agent-loop.md#контекст).
