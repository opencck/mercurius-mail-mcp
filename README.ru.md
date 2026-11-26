[English](README.md) | **Русский** | [中文](README.cn.md)

# mercurius-mail-mcp

Сервер [MCP](https://modelcontextprotocol.io) со своим асинхронным клиентом IMAP и SMTP на [AMPHP](https://amphp.org).
Часть [Меркурия](https://github.com/opencck/mercurius), годится и сам по себе.

Инструменты:

- `mail_folders` — папки ящика.
- `mail_list` — письма папки, новые сверху, с фильтрами (непрочитанные, от кого, тема, текст, с даты).
- `mail_read` — одно письмо: заголовки, текст (HTML превращается в текст), вложения с номерами по имени и размеру,
  подтверждён ли отправитель и с каким authserv-id идёт сверка.
- `mail_attachment` — одно вложение по uid и номеру, встроенным ресурсом (`blob`), его сохраняет клиент.
- `mail_send` — письмо простым текстом, ответ сохраняет цепочку; `files` (имя, тип, base64), до 10 и вместе до 25 МБ,
  делают его multipart/mixed. Помечен как необратимый: внимательный клиент спрашивает человека.

Чтение открывает папки только для чтения (`EXAMINE`) и берёт письма через `BODY.PEEK` — письма не помечаются
прочитанными.

**Подлинность отправителя.** `From` пишет сам отправитель. Письмо считается подтверждённым только по первому
заголовку `Authentication-Results` принимающего сервера, привязанному к его authserv-id: DMARC pass, или SPF либо DKIM
pass, выровненные с доменом `From` (по образцу почтового адаптера
[hermes-agent](https://github.com/NousResearch/hermes-agent)).

**Один ящик на процесс, по stdio.** Ящик — из аргументов, пароль — из окружения, чтобы другие процессы не видели его
в списке процессов:

```sh
MAIL_PASSWORD=… php bin/mail-mcp-stdio --host=mail.example.com --user=me@example.com \
    [--imap-port=993] [--smtp-port=465] [--from='Me <me@example.com>'] [--authserv-id=mx.example.com]
```

SMTP на 587 и 25 — через STARTTLS. Каждая строка stdin — запрос JSON-RPC (`initialize`, `tools/list`, `tools/call`,
`ping`), каждый ответ — строка stdout. Без хоста сервер запускается и отдаёт список инструментов, а каждый вызов
говорит, чего не хватает: так его пробует установка.

В Меркурии каждый ящик — запись `.mcp.json`; в `mercurius.mcp.json` — план установки (`composer install --no-dev`,
затем `php ${INSTALL_DIR}/bin/mail-mcp-stdio`). Ядро агента проверяет, что хост ящика не ведёт во внутреннюю сеть, и
спрашивает хозяина ящика перед отправкой письма.

## Проверки

`composer check` — PHPUnit, PHPStan (max) и Psalm (уровень 1).

## Лицензия

MIT
