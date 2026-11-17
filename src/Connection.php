<?php

declare(strict_types=1);

namespace Mercurius\MailMcp;

/**
 * A line protocol over a byte stream: IMAP and SMTP read lines and counted literals.
 */
interface Connection {
    public function write(string $data): void;

    /**
     * A line without its CRLF; null when the other side closed.
     *
     * @phpstan-impure
     */
    public function readLine(): ?string;

    /**
     * Exactly $length bytes.
     *
     * @phpstan-impure
     */
    public function read(int $length): string;

    /** Turns the plain connection into TLS (STARTTLS). */
    public function startTls(): void;

    public function close(): void;
}
