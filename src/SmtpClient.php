<?php

declare(strict_types=1);

namespace Mercurius\MailMcp;

/**
 * Sends one message: EHLO, STARTTLS when the port is 587, AUTH PLAIN, MAIL FROM, RCPT TO, DATA with dot-stuffing.
 */
final class SmtpClient {
    public function __construct(private readonly Connection $connection) {}

    /** @param list<string> $recipients */
    public function send(Account $account, string $from, array $recipients, string $message, bool $startTls = false): void {
        $this->expect(220);
        $this->hello($account);
        if ($startTls) {
            $this->call('STARTTLS', 220);
            $this->connection->startTls();
            $this->hello($account);
        }
        $this->call('AUTH PLAIN ' . base64_encode("\0" . $account->user . "\0" . $account->password), 235, 'вход SMTP не принят — проверьте логин и пароль');
        $this->call('MAIL FROM:<' . $from . '>', 250);
        foreach ($recipients as $to) {
            $this->call('RCPT TO:<' . $to . '>', 250, 'адрес ' . $to . ' не принят');
        }
        $this->call('DATA', 354);
        $body = preg_replace('~^\.~m', '..', str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $message)) ?? $message;
        $this->connection->write(rtrim($body, "\r\n") . "\r\n.\r\n");
        $this->expect(250, 'письмо не принято');
        try {
            $this->call('QUIT', 221);
        } catch (MailException) {
        }
        $this->connection->close();
    }

    private function hello(Account $account): void {
        $host = gethostname();
        $this->call('EHLO ' . (is_string($host) && $host !== '' ? $host : 'mercurius'), 250);
    }

    private function call(string $command, int $code, string $what = ''): void {
        $this->connection->write($command . "\r\n");
        $this->expect($code, $what);
    }

    private function expect(int $code, string $what = ''): void {
        do {
            $line = $this->connection->readLine() ?? throw new MailException('SMTP-сервер закрыл соединение.');
        } while (preg_match('~^\d{3}-~', $line) === 1);
        if (!str_starts_with($line, (string) $code)) {
            throw new MailException('SMTP: ' . ($what === '' ? '' : $what . ': ') . mb_substr($line, 0, 200));
        }
    }
}
