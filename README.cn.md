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

**每个进程一个邮箱，通过 stdio 运行。** 邮箱来自参数，密码来自环境变量，这样其他进程在进程列表中看不到它：

```sh
MAIL_PASSWORD=… php bin/mail-mcp-stdio --host=mail.example.com --user=me@example.com \
    [--imap-port=993] [--smtp-port=465] [--from='Me <me@example.com>'] [--authserv-id=mx.example.com]
```

SMTP 在 587 和 25 端口使用 STARTTLS。stdin 的每一行是一个 JSON-RPC 请求（`initialize`、`tools/list`、`tools/call`、
`ping`），每个回答是 stdout 的一行。没有主机时服务器照常启动并列出工具，每次调用会说明缺少什么：安装时就是这样试运行的。

在 Mercurius 中，每个邮箱是 `.mcp.json` 中的一个条目；`mercurius.mcp.json` 中是安装计划（`composer install --no-dev`，
然后 `php ${INSTALL_DIR}/bin/mail-mcp-stdio`）。智能体内核会检查邮箱主机不指向内部网络，并在发信前询问邮箱的所有者。

## 检查

`composer check` —— PHPUnit、PHPStan（max）和 Psalm（level 1）。

## 许可证

MIT
