<?php

declare(strict_types=1);

namespace Mercurius\MailMcp\Mime;

/**
 * A message read for an agent: headers decoded to UTF-8, the text (plain, or HTML turned into text), attachments by
 * name and size only.
 */
final class Message {
    /**
     * @param array<string, list<string>> $headers names in lower case, decoded values in their order
     * @param list<array{name: string, type: string, size: int}> $attachments
     */
    public function __construct(
        public readonly array $headers,
        public readonly string $text,
        public readonly array $attachments,
    ) {}

    public function header(string $name): string {
        return $this->headers[strtolower($name)][0] ?? '';
    }
}
