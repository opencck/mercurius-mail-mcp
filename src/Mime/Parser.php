<?php

declare(strict_types=1);

namespace Mercurius\MailMcp\Mime;

/**
 * Reads an RFC 5322 message: headers unfolded and decoded (RFC 2047), MIME parts walked, transfer encodings undone,
 * charsets brought to UTF-8, the first text/plain part taken (or text/html as text), attachments listed.
 *
 * ref: references/hermes-agent/plugins/platforms/email/adapter.py@e96221fa:L237-L292, L428-L462 — charset-safe
 *      decoding, HTML to text, attachments by metadata
 */
final class Parser {
    private const DEPTH = 10;

    public static function parse(string $raw): Message {
        [$headers, $body] = self::split($raw);
        $plain = null;
        $html = null;
        $attachments = [];
        $files = [];
        self::walk($headers, $body, $plain, $html, $attachments, $files, 0);
        $text = $plain ?? ($html === null ? '' : Html::toText($html));
        return new Message(self::decodeAll($headers), trim($text), $attachments);
    }

    /**
     * Only headers (a FETCH of header fields).
     *
     * @return array<string, list<string>>
     */
    public static function headers(string $raw): array {
        return self::decodeAll(self::split($raw . "\r\n\r\n")[0]);
    }

    /** @return array{array<string, array<int, string>>, string} raw header values by name, and the body */
    private static function split(string $raw): array {
        $raw = str_replace("\r\n", "\n", $raw);
        $at = strpos($raw, "\n\n");
        $head = $at === false ? $raw : substr($raw, 0, $at);
        $body = $at === false ? '' : substr($raw, $at + 2);
        $headers = [];
        $name = null;
        foreach (explode("\n", $head) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $name !== null) {
                $last = count($headers[$name]) - 1;
                $headers[$name][$last] .= ' ' . trim($line);
            } elseif (preg_match('~^([^\s:]+):\s?(.*)$~', $line, $m) === 1) {
                $name = strtolower($m[1]);
                $headers[$name][] = $m[2];
            }
        }
        return [$headers, $body];
    }

    /**
     * The attachments of a message with their bytes, in the order mail_read numbers them.
     *
     * @return list<array{name: string, type: string, bytes: string}>
     */
    public static function attachments(string $raw): array {
        [$headers, $body] = self::split($raw);
        $plain = null;
        $html = null;
        $attachments = [];
        $files = [];
        self::walk($headers, $body, $plain, $html, $attachments, $files, 0);
        return $files;
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @param list<array{name: string, type: string, size: int}> $attachments
     * @param list<array{name: string, type: string, bytes: string}> $files
     */
    private static function walk(array $headers, string $body, ?string &$plain, ?string &$html, array &$attachments, array &$files, int $depth): void {
        [$type, $params] = self::contentType($headers['content-type'][0] ?? 'text/plain');
        if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $depth < self::DEPTH) {
            foreach (self::parts($body, $params['boundary']) as $part) {
                [$partHeaders, $partBody] = self::split($part);
                self::walk($partHeaders, $partBody, $plain, $html, $attachments, $files, $depth + 1);
            }
            return;
        }
        $decoded = self::transfer($body, strtolower(trim($headers['content-transfer-encoding'][0] ?? '7bit')));
        $disposition = strtolower($headers['content-disposition'][0] ?? '');
        [, $dispositionParams] = self::contentType('x/x; ' . (explode(';', $disposition, 2)[1] ?? ''));
        $name = $dispositionParams['filename'] ?? $params['name'] ?? '';
        if (str_starts_with($disposition, 'attachment') || ($name !== '' && !str_starts_with($type, 'text/'))) {
            $attachments[] = ['name' => self::decode($name === '' ? 'без имени' : $name), 'type' => $type, 'size' => strlen($decoded)];
            $files[] = ['name' => self::decode($name === '' ? 'без имени' : $name), 'type' => $type, 'bytes' => $decoded];
            return;
        }
        $text = self::charset($decoded, $params['charset'] ?? 'utf-8');
        if ($type === 'text/plain' && $plain === null) {
            $plain = $text;
        } elseif ($type === 'text/html' && $html === null) {
            $html = $text;
        }
    }

    /** @return list<string> */
    private static function parts(string $body, string $boundary): array {
        $parts = [];
        $chunks = explode("\n--" . $boundary, "\n" . $body);
        array_shift($chunks);
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) {
                break;
            }
            $parts[] = ltrim(substr($chunk, (int) strpos($chunk . "\n", "\n") + 1), "\r");
        }
        return $parts;
    }

    /** @return array{string, array<string, string>} the type in lower case and its parameters */
    public static function contentType(string $value): array {
        $pieces = explode(';', $value);
        $type = strtolower(trim(array_shift($pieces)));
        $params = [];
        foreach ($pieces as $piece) {
            if (preg_match('~^\s*([\w*-]+)\s*=\s*"?([^"]*)"?\s*$~', $piece, $m) === 1) {
                $key = strtolower(rtrim($m[1], '*'));
                $value = $m[2];
                if (str_ends_with($m[1], '*') && preg_match("~^([\\w-]+)''(.*)$~", $value, $e) === 1) {
                    $value = self::charset(rawurldecode($e[2]), $e[1]);
                }
                $params[$key] = $value;
            }
        }
        return [$type, $params];
    }

    private static function transfer(string $body, string $encoding): string {
        return match ($encoding) {
            'base64' => self::bytes(base64_decode(preg_replace('~[^A-Za-z0-9+/=]~', '', $body) ?? '', false)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    public static function charset(string $text, string $charset): string {
        $charset = strtolower(trim($charset));
        if ($charset !== '' && $charset !== 'utf-8' && $charset !== 'us-ascii') {
            try {
                $converted = mb_convert_encoding($text, 'UTF-8', $charset);
                $text = is_string($converted) ? $converted : $text;
            } catch (\ValueError) {
            }
        }
        return mb_scrub($text, 'UTF-8');
    }

    /** RFC 2047 encoded words to UTF-8. */
    public static function decode(string $value): string {
        $decoded = preg_replace_callback('~=\?([^?]+)\?([bBqQ])\?([^?]*)\?=~', static function (array $m): string {
            $bytes = strtoupper($m[2]) === 'B' ? self::bytes(base64_decode($m[3], false)) : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            return self::charset($bytes, $m[1]);
        }, preg_replace('~\?=\s+=\?~', '?==?', $value) ?? $value);
        return mb_scrub($decoded ?? $value, 'UTF-8');
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array<string, list<string>>
     */
    private static function decodeAll(array $headers): array {
        $out = [];
        foreach ($headers as $name => $values) {
            $out[$name] = array_values(array_map(self::decode(...), $values));
        }
        return $out;
    }

    /** A decoded string, or '' when it could not be decoded: the analysers disagree about "string or false". */
    private static function bytes(mixed $value): string {
        return is_string($value) ? $value : '';
    }
}
