[English](README.md) | **Русский**

# mercurius-mail-mcp

Сервер [MCP](https://modelcontextprotocol.io) со своим асинхронным клиентом IMAP и SMTP на [AMPHP](https://amphp.org).
Часть [Меркурия](https://github.com/opencck/mercurius), годится и сам по себе.

Инструменты:

- `mail_folders` — папки ящика.
- `mail_list` — письма папки, новые сверху, с фильтрами (непрочитанные, от кого, тема, текст, с даты).
- `mail_read` — одно письмо: заголовки, текст (HTML превращается в текст), вложения по имени и размеру и подтверждён
  ли отправитель.
- `mail_send` — письмо простым текстом, ответ сохраняет цепочку. Помечен как необратимый: внимательный клиент
  спрашивает человека.

Чтение открывает папки только для чтения (`EXAMINE`) и берёт письма через `BODY.PEEK` — письма не помечаются
прочитанными.

**Подлинность отправителя.** `From` пишет сам отправитель. Письмо считается подтверждённым только по первому
заголовку `Authentication-Results` принимающего сервера, привязанному к его authserv-id: DMARC pass, или SPF либо DKIM
pass, выровненные с доменом `From` (по образцу почтового адаптера
[hermes-agent](https://github.com/NousResearch/hermes-agent)).

**Сервер не хранит учётные данные.** Каждый запрос несёт ящик в заголовках: `X-Mail-Host`, `X-Mail-User`,
`X-Mail-Password`, по желанию `X-Mail-Imap-Port` (993), `X-Mail-Smtp-Port` (465; 587 и 25 — через STARTTLS),
`X-Mail-From`, `X-Mail-Authserv-Id`. Вход — `Authorization: Bearer <MAIL_MCP_TOKEN>`.

```sh
MAIL_MCP_TOKEN=$(openssl rand -hex 32) MAIL_MCP_PORT=8090 vendor/bin/mail-mcp
```

`POST /mcp` принимает JSON-RPC (`initialize`, `tools/list`, `tools/call`, `ping`) и отвечает JSON; `GET /health`.

## Docker

```sh
docker build -t mercurius-mail-mcp .
docker run --rm -e MAIL_MCP_TOKEN=$(openssl rand -hex 32) -p 8090:8090 mercurius-mail-mcp
```

В Меркурии сервер — в профиле compose `mail` (сабмодуль `mcp/mail`): добавьте его в `COMPOSE_PROFILES` и задайте
`MAIL_MCP_TOKEN` в `.env`.

## Проверки

`composer check` — PHPUnit, PHPStan (max) и Psalm (уровень 1).

## Лицензия

MIT
