[English](README.md) | [Русский](README.ru.md) | **中文**

> 本文由模型翻译，尚未经人工审校；如有出入，以英文版为准。

# mercurius-mail-mcp

一个 [MCP](https://modelcontextprotocol.io) 服务器，内置基于 [AMPHP](https://amphp.org) 的异步 IMAP 和 SMTP 客户端。
属于 [Mercurius](https://github.com/opencck/mercurius)，也可单独使用。

工具：

- `mail_folders` —— 邮箱的文件夹。
- `mail_list` —— 文件夹中的邮件，最新的在前，带过滤条件（未读、发件人、主题、正文、起始时间）。
- `mail_read` —— 一封邮件：邮件头、正文（HTML 转为文本）、按名称和大小编号的附件、发件人是否已通过认证，以及该检查所信任的 authserv-id。
- `mail_attachment` —— 按 uid 和编号取一个附件，作为客户端保存的嵌入资源（`blob`）。
- `mail_send` —— 一封纯文本邮件，回复会保持会话线索；`files`（名称、mime 类型、base64），最多 10 个、合计最大 25 MB，会使其成为 multipart/mixed。标记为破坏性：谨慎的客户端会询问人。

读取时以只读方式打开文件夹（`EXAMINE`），并使用 `BODY.PEEK` 获取，因此邮件绝不会被标记为已读。

**发件人认证。** `From` 由发件人自己填写。邮件仅在接收服务器的第一个 `Authentication-Results` 头、并固定到其 authserv-id 时才算已认证：DMARC 通过，或与 `From` 域名对齐的 SPF 或 DKIM 通过（参照 [hermes-agent](https://github.com/NousResearch/hermes-agent) 的邮件适配器）。

**服务器不保存凭据。** 每个请求通过请求头携带邮箱信息：`X-Mail-Host`、
`X-Mail-User`、`X-Mail-Password`，可选的 `X-Mail-Imap-Port`（993）、`X-Mail-Smtp-Port`（465；587 和 25 使用 STARTTLS）、
`X-Mail-From`、`X-Mail-Authserv-Id`。该端点需要 `Authorization: Bearer <MAIL_MCP_TOKEN>`。

```sh
MAIL_MCP_TOKEN=$(openssl rand -hex 32) MAIL_MCP_PORT=8090 vendor/bin/mail-mcp
```

`POST /mcp` 接收 JSON-RPC（`initialize`、`tools/list`、`tools/call`、`ping`）并以 JSON 应答；`GET /health`。

## Docker

```sh
docker build -t mercurius-mail-mcp .
docker run --rm -e MAIL_MCP_TOKEN=$(openssl rand -hex 32) -p 8090:8090 mercurius-mail-mcp
```

在 Mercurius 中，该服务器位于 compose 配置档 `mail`（子模块 `mcp/mail`）：将其加入 `COMPOSE_PROFILES`，并在 `.env` 中设置
`MAIL_MCP_TOKEN`。

## 检查

`composer check` —— PHPUnit、PHPStan（max）和 Psalm（level 1）。

## 许可证

MIT
