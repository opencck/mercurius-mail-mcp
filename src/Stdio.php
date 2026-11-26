<?php

declare(strict_types=1);

namespace Mercurius\MailMcp;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use SensitiveParameter;

/**
 * The stdio side: one mailbox per process, as an entry of .mcp.json starts it. The mailbox comes from the arguments, the
 * password from the environment (MAIL_PASSWORD), so other processes do not see it in the list of processes; each line
 * of stdin is a JSON-RPC request, each answer one line of stdout.
 */
final class Stdio {
    private const LINE_MAX = 36 * 1024 * 1024;
    private const OPTIONS = ['host', 'user', 'imap-port', 'smtp-port', 'from', 'authserv-id'];

    /** @param array<string, string> $headers the mailbox as the handler takes it */
    public function __construct(
        private readonly McpHandler $handler,
        #[SensitiveParameter] private readonly array $headers,
    ) {}

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     * @return array<string, string>
     */
    public static function headers(array $argv, #[SensitiveParameter] array $env): array {
        $headers = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (preg_match('~^--([a-z-]+)=(.*)$~s', $arg, $m) === 1 && in_array($m[1], self::OPTIONS, true) && $m[2] !== '') {
                $headers['x-mail-' . $m[1]] = $m[2];
            }
        }
        $password = $env['MAIL_PASSWORD'] ?? '';
        if ($password !== '') {
            $headers['x-mail-password'] = $password;
        }
        return $headers;
    }

    /**
     * @param list<string> $lines
     * @return list<array<string, mixed>> the answers; a notification has none
     */
    public function lines(array $lines): array {
        $answers = [];
        foreach ($lines as $line) {
            $answer = $this->line($line);
            if ($answer !== null) {
                $answers[] = $answer;
            }
        }
        return $answers;
    }

    /** Reads requests until stdin ends. */
    public function serve(ReadableStream $in, WritableStream $out): void {
        $buffer = '';
        while (($chunk = $in->read()) !== null) {
            $buffer .= $chunk;
            while (($end = strpos($buffer, "\n")) !== false) {
                $answer = $this->line(substr($buffer, 0, $end));
                $buffer = substr($buffer, $end + 1);
                if ($answer !== null) {
                    $out->write(json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                }
            }
            if (strlen($buffer) > self::LINE_MAX) {
                $buffer = '';
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function line(string $line): ?array {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        try {
            /** @var mixed $request */
            $request = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']];
        }
        return is_array($request) ? $this->handler->handle($this->headers, $request) : null;
    }
}
