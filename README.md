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

**One mailbox per process, over stdio.** The mailbox comes from the arguments, the password from the environment, so
other processes do not see it in the list of processes:

```sh
MAIL_PASSWORD=… php bin/mail-mcp-stdio --host=mail.example.com --user=me@example.com \
    [--imap-port=993] [--smtp-port=465] [--from='Me <me@example.com>'] [--authserv-id=mx.example.com]
```

SMTP on 587 and 25 uses STARTTLS. Each line of stdin is a JSON-RPC request (`initialize`, `tools/list`, `tools/call`,
`ping`), each answer one line of stdout. Without a host the server starts and lists its tools, and every call says what
is missing: an install tries it so.

In Mercurius each mailbox is an entry of `.mcp.json`; `mercurius.mcp.json` holds the install plan (`composer install
--no-dev`, then `php ${INSTALL_DIR}/bin/mail-mcp-stdio`). The agent's core checks that the host of the mailbox does not
lead into an internal network and asks the keeper of the mailbox before a letter goes.

## Checks

`composer check` — PHPUnit, PHPStan (max) and Psalm (level 1).

## License

MIT
