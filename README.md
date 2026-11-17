**English** | [Русский](README.ru.md)

# mercurius-mail-mcp

An [MCP](https://modelcontextprotocol.io) server with its own async IMAP and SMTP client on [AMPHP](https://amphp.org).
Part of [Mercurius](https://github.com/opencck/mercurius), usable on its own.

Tools:

- `mail_folders` — the folders of the mailbox.
- `mail_list` — letters of a folder, newest first, with filters (unseen, from, subject, text, since).
- `mail_read` — one letter: headers, text (HTML turned into text), attachments by name and size, and whether the
  sender is authenticated.
- `mail_send` — a plain-text letter, a reply keeps the thread. Marked destructive: a careful client asks a person.

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

## Checks

`composer check` — PHPUnit, PHPStan (max) and Psalm (level 1).

## License

MIT
