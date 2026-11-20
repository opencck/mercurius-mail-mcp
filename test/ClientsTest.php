<?php

declare(strict_types=1);

namespace Mercurius\MailMcp\Test;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\MailMcp\Account;
use Mercurius\MailMcp\ImapClient;
use Mercurius\MailMcp\MailException;
use Mercurius\MailMcp\Mailbox;
use Mercurius\MailMcp\McpHandler;
use Mercurius\MailMcp\SmtpClient;

final class ClientsTest extends AsyncTestCase {
    private const HEADERS = "From: Ivan <ivan@example.ru>\r\nSubject: Hello\r\nDate: Mon, 16 Nov 2026 10:00:00 +0300\r\nAuthentication-Results: mail.kwinta.net; dmarc=pass header.from=example.ru\r\n\r\n";

    private static function account(int $smtpPort = 465): Account {
        return new Account('mail.kwinta.net', 993, $smtpPort, 'admin@example.org', 'secret-pass', 'admin@example.org');
    }

    private static function dig(mixed $data, string|int ...$path): mixed {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            /** @var mixed $data */
            $data = $data[$key];
        }
        return $data;
    }

    public function testListingSearchesWithLiteralsAndFetchesOnlyHeaders(): void {
        $headers = self::HEADERS;
        $script = "* OK ready\r\nm1 OK logged in\r\n* 3 EXISTS\r\nm2 OK [READ-ONLY] examined\r\n+ go\r\n* SEARCH 7 9\r\nm3 OK search\r\n"
            . '* 1 FETCH (UID 9 FLAGS (\Seen) RFC822.SIZE 900 BODY[HEADER.FIELDS (FROM)] {' . strlen($headers) . "}\r\n" . $headers . ")\r\n"
            . '* 2 FETCH (UID 7 FLAGS () RFC822.SIZE 400 BODY[HEADER.FIELDS (FROM)] {' . strlen($headers) . "}\r\n" . $headers . ")\r\n"
            . "m4 OK fetched\r\nm5 OK bye\r\n";
        $connection = new FakeConnection($script);
        $mailbox = new Mailbox(static fn(): FakeConnection => $connection);

        $rows = $mailbox->list(self::account(), 'INBOX', ['unseen' => true, 'from' => 'Иван'], 5);

        self::assertSame([9, 7], array_column($rows, 'uid'));
        self::assertSame([true, false], array_column($rows, 'seen'));
        self::assertTrue($rows[0]['verified']);
        self::assertStringContainsString('m1 LOGIN "admin@example.org" "secret-pass"', $connection->written);
        self::assertStringContainsString("m3 UID SEARCH CHARSET UTF-8 UNSEEN FROM {8}\r\nИван\r\n", $connection->written);
        self::assertStringContainsString('BODY.PEEK[HEADER.FIELDS', $connection->written, 'reading never marks as seen');
        self::assertStringContainsString('m2 EXAMINE "INBOX"', $connection->written);
        self::assertTrue($connection->closed);
    }

    public function testAWrongPasswordSaysSoWithoutThePassword(): void {
        $mailbox = new Mailbox(static fn(): FakeConnection => new FakeConnection("* OK ready\r\nm1 NO [AUTHENTICATIONFAILED] bad\r\n"));
        try {
            $mailbox->folders(self::account());
            self::fail('expected a failure');
        } catch (MailException $e) {
            self::assertStringContainsString('вход не принят', $e->getMessage());
            self::assertStringNotContainsString('secret-pass', $e->getMessage());
        }
    }

    public function testSmtpAuthenticatesAndDotStuffsTheBody(): void {
        $connection = new FakeConnection("220 hi\r\n250-mail\r\n250 AUTH PLAIN\r\n235 ok\r\n250 ok\r\n250 ok\r\n354 go\r\n250 queued\r\n221 bye\r\n");

        (new SmtpClient($connection))->send(self::account(), 'admin@example.org', ['ivan@example.ru'], "Subject: t\r\n\r\nline\n.dot\nend");

        self::assertStringContainsString('AUTH PLAIN ' . base64_encode("\0admin@example.org\0secret-pass"), $connection->written);
        self::assertStringContainsString("RCPT TO:<ivan@example.ru>\r\nDATA\r\n", $connection->written);
        self::assertStringContainsString("line\r\n..dot\r\nend\r\n.\r\n", $connection->written);
    }

    public function testTheMcpSideListsToolsAndTakesTheAccountFromHeaders(): void {
        $handler = new McpHandler(new Mailbox(static fn(): FakeConnection => new FakeConnection("* OK ready\r\nm1 OK in\r\n* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n* LIST () \"/\" \"&BB4EQgQ,BEAEMAQyBDsENQQ9BD0ESwQ1-\"\r\nm2 OK list\r\nm3 OK bye\r\n")));

        $tools = $handler->handle([], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        /** @var mixed $list */
        $list = self::dig($tools, 'result', 'tools');
        $names = array_column(is_array($list) ? $list : [], 'name');
        self::assertSame(['mail_folders', 'mail_list', 'mail_read', 'mail_attachment', 'mail_send'], $names);
        self::assertNull($handler->handle([], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        $missing = $handler->handle([], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'mail_folders', 'arguments' => []]]);
        self::assertTrue(self::dig($missing, 'result', 'isError'), 'no account — an error, not a crash');

        $headers = ['x-mail-host' => 'mail.kwinta.net', 'x-mail-user' => 'admin@example.org', 'x-mail-password' => 'secret-pass'];
        $folders = $handler->handle($headers, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'mail_folders', 'arguments' => []]]);
        self::assertSame("INBOX\nОтправленные", self::dig($folders, 'result', 'content', 0, 'text'));
    }

    public function testAnAttachmentComesAsAnEmbeddedResource(): void {
        $content = McpHandler::attachmentContent(3, 'счёт.pdf', 'application/pdf', 'PDF');

        self::assertSame('text', $content[0]['type'] ?? null);
        $line = $content[0]['text'] ?? null;
        self::assertIsString($line);
        self::assertStringContainsString('счёт.pdf', $line);
        self::assertSame('resource', $content[1]['type'] ?? null);
        self::assertSame(['uri' => 'mail-attachment:///3/' . rawurlencode('счёт.pdf'), 'mimeType' => 'application/pdf', 'blob' => base64_encode('PDF')], $content[1]['resource'] ?? null);
    }

    public function testQuotingRefusesLineBreaks(): void {
        $this->expectException(MailException::class);
        ImapClient::quote("a\r\nm9 DELETE INBOX");
    }
}
