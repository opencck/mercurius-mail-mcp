<?php

declare(strict_types=1);

namespace Mercurius\MailMcp;

use SensitiveParameter;

/**
 * One mailbox: IMAP over TLS (993) and SMTP over TLS (465) or STARTTLS (587). The MCP client gives it per request in
 * X-Mail-* headers, so the server keeps no credentials and the model never sees them.
 */
final class Account {
    public function __construct(
        public readonly string $host,
        public readonly int $imapPort,
        public readonly int $smtpPort,
        public readonly string $user,
        #[SensitiveParameter] public readonly string $password,
        public readonly string $from,
        /** the authserv-id of the receiving server in Authentication-Results; '' — the host */
        public readonly string $authservId = '',
    ) {}

    /**
     * @param array<string, string> $headers names in lower case
     * @throws MailException when something is missing
     */
    public static function fromHeaders(array $headers): self {
        $get = static fn(string $name): string => trim($headers['x-mail-' . $name] ?? '');
        $host = $get('host');
        $user = $get('user');
        $password = $headers['x-mail-password'] ?? '';
        if ($host === '' || preg_match('~^[a-z0-9.-]{1,253}$~i', $host) !== 1 || $user === '' || $password === '') {
            throw new MailException('Почта не настроена: нужны X-Mail-Host, X-Mail-User, X-Mail-Password.');
        }
        $imap = ctype_digit($get('imap-port')) ? (int) $get('imap-port') : 993;
        $smtp = ctype_digit($get('smtp-port')) ? (int) $get('smtp-port') : 465;
        $from = $get('from') !== '' ? $get('from') : $user;
        return new self($host, $imap, $smtp, $user, $password, $from, strtolower($get('authserv-id')));
    }

    public function authserv(): string {
        return $this->authservId !== '' ? $this->authservId : strtolower($this->host);
    }
}
