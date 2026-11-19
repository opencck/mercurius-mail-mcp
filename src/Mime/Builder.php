<?php

declare(strict_types=1);

namespace Mercurius\MailMcp\Mime;

use Mercurius\MailMcp\MailException;

/**
 * Builds a plain-text UTF-8 message; a reply keeps the thread (In-Reply-To, References). Header values with line
 * breaks are refused: no header injection.
 *
 * ref: references/hermes-agent/plugins/platforms/email/adapter.py@e96221fa:L877-L893 — a threaded reply
 */
final class Builder {
    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<array{name: string, type: string, bytes: string}> $files attachments; with them the letter is multipart/mixed
     * @return array{string, string} the message and its Message-ID
     */
    public static function build(string $from, array $to, array $cc, string $subject, string $text, string $inReplyTo = '', string $references = '', array $files = []): array {
        foreach ([$from, ...$to, ...$cc] as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new MailException('Неверный адрес: ' . $address);
            }
        }
        foreach ([$subject, $inReplyTo, $references] as $value) {
            if (preg_match('~[\r\n]~', $value) === 1) {
                throw new MailException('Перевод строки в заголовке письма недопустим.');
            }
        }
        $domain = substr((string) strrchr($from, '@'), 1);
        $id = sprintf('<%s@%s>', bin2hex(random_bytes(12)), $domain === '' ? 'mercurius' : $domain);
        $headers = [
            'From: ' . $from,
            'To: ' . implode(', ', $to),
        ];
        if ($cc !== []) {
            $headers[] = 'Cc: ' . implode(', ', $cc);
        }
        $headers[] = 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
        $headers[] = 'Date: ' . date(DATE_RFC2822);
        $headers[] = 'Message-ID: ' . $id;
        if ($inReplyTo !== '') {
            $headers[] = 'In-Reply-To: ' . $inReplyTo;
            $headers[] = 'References: ' . trim($references . ' ' . $inReplyTo);
        }
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: Mercurius';
        $body = rtrim(chunk_split(base64_encode($text), 76, "\r\n"));
        if ($files === []) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return [implode("\r\n", $headers) . "\r\n\r\n" . $body, $id];
        }
        $boundary = 'mercurius-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $parts = ["--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $body];
        foreach ($files as $file) {
            if (preg_match('~[\r\n"]~', $file['name'] . $file['type']) === 1 || preg_match('~^[\w.+-]+/[\w.+-]+$~', $file['type']) !== 1) {
                throw new MailException('Неверное имя или тип вложения: ' . $file['name']);
            }
            // RFC 2231: a name in UTF-8 that any client reads
            $name = rawurlencode($file['name']);
            $parts[] = "--$boundary\r\nContent-Type: {$file['type']}; name*=UTF-8''$name\r\nContent-Disposition: attachment; filename*=UTF-8''$name\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n" . rtrim(chunk_split(base64_encode($file['bytes']), 76, "\r\n"));
        }
        return [implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts) . "\r\n--$boundary--\r\n", $id];
    }
}
