<?php

declare(strict_types=1);

namespace Mercurius\MailMcp\Test;

use Mercurius\MailMcp\Mailbox;
use Mercurius\MailMcp\McpHandler;
use Mercurius\MailMcp\Stdio;
use PHPUnit\Framework\TestCase;

final class StdioTest extends TestCase {
    public function testTheMailboxComesFromArgumentsAndThePasswordFromTheEnvironment(): void {
        $headers = Stdio::headers(['mail-mcp-stdio', '--host=imap.example.com', '--user=me@example.com', '--imap-port=993', '--smtp-port=587', '--from=Me <me@example.com>', '--authserv-id=mx.example.com'], ['MAIL_PASSWORD' => 'pw']);

        self::assertSame([
            'x-mail-host' => 'imap.example.com',
            'x-mail-user' => 'me@example.com',
            'x-mail-imap-port' => '993',
            'x-mail-smtp-port' => '587',
            'x-mail-from' => 'Me <me@example.com>',
            'x-mail-authserv-id' => 'mx.example.com',
            'x-mail-password' => 'pw',
        ], $headers);
    }

    public function testEachLineIsARequestAndEachAnswerALine(): void {
        $stdio = new Stdio(new McpHandler(new Mailbox()), []);

        $answers = $stdio->lines([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            'not json',
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mail_folders","arguments":{}}}',
        ]);

        self::assertCount(3, $answers, 'a notification has no answer');
        $json = json_encode($answers, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"serverInfo":{"name":"mercurius-mail"', $json);
        self::assertStringContainsString('"error":{"code":-32700', $json);
        self::assertStringContainsString('"isError":true', $json, 'a mailbox not named is an error of the call');
    }
}
