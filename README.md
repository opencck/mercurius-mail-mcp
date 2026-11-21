**English** | [Русский](README.ru.md) | [中文](README.cn.md)

# mercurius-mail-mcp

An [MCP](https://modelcontextprotocol.io) server with its own async IMAP and SMTP client on [AMPHP](https://amphp.org).
Part of [Mercurius](https://github.com/opencck/mercurius), usable on its own.

Tools:

- `mail_folders` — the folders of the mailbox.
- `mail_list` — letters of a folder, newest first, with filters (unseen, from, subject, text, since).
- `mail_read` — one letter: headers, text (HTML turned into text), numbered attachments by name and size, whether the
  sender is authenticated and the authserv-id the check trusts.
- `mail_attachment` — one attachment by uid and number, as an embedded resource (`blob`) the client keeps.
- `mail_send` — a plain-text letter, a reply keeps the thread; `files` (name, mime type, base64), up to 10 and 25 MB
  together, make it multipart/mixed. Marked destructive: a careful client asks a person.

Reading opens folders read-only (`EXAMINE`) and fetches with `BODY.PEEK`, so mail is never marked as seen.

**Sender authentication.** `From` is written by the sender. A letter counts as authenticated only by the first
`Authentication-Results` header of the receiving server, pinned to its authserv-id: DMARC pass, or SPF or DKIM pass
aligned with the `From` domain (after the email adapter of [hermes-agent](https://github.com/NousResearch/hermes-agent)).

**Credentials are not kept by the server.** Each request carries the mailbox in headers: `X-Mail-Host`,
`X-Mail-User`, `X-Mail-Password`, optional `X-Mail-Imap-Port` (993), `X-Mail-Smtp-Port` (465; 587 and 25 use STARTTLS),
`X-Mail-From`, `X-Mail-Authserv-Id`. The endpoint needs `Authorization: Bearer <MAIL_MCP_TOKEN>`.

```sh
MAIL_MCP_TOKEN=$(openssl rand -hex 32) MAIL_MCP_PORT=8090 vendor/bin/mail-mcp
```

`POST /mcp` takes JSON-RPC (`initialize`, `tools/list`, `tools/call`, `ping`) and answers with JSON; `GET /health`.

## Docker

```sh
docker build -t mercurius-mail-mcp .
docker run --rm -e MAIL_MCP_TOKEN=$(openssl rand -hex 32) -p 8090:8090 mercurius-mail-mcp
```

In Mercurius the server is in the compose profile `mail` (submodule `mcp/mail`): add it to `COMPOSE_PROFILES` and set
`MAIL_MCP_TOKEN` in `.env`.

## Checks

`composer check` — PHPUnit, PHPStan (max) and Psalm (level 1).

## License

MIT
