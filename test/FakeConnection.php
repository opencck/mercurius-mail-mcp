<?php

declare(strict_types=1);

namespace Mercurius\MailMcp\Test;

use Mercurius\MailMcp\Connection;

/**
 * A server from a script: what it sends, and what the client wrote.
 */
final class FakeConnection implements Connection {
    private string $buffer;
    public string $written = '';
    public bool $tls = false;
    public bool $closed = false;

    public function __construct(string $script) {
        $this->buffer = $script;
    }

    public function write(string $data): void {
        $this->written .= $data;
    }

    public function readLine(): ?string {
        $end = strpos($this->buffer, "\n");
        if ($end === false) {
            return null;
        }
        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 1);
        return rtrim($line, "\r");
    }

    public function read(int $length): string {
        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $data;
    }

    public function startTls(): void {
        $this->tls = true;
    }

    public function close(): void {
        $this->closed = true;
    }
}
