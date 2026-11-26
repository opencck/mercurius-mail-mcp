<?php

declare(strict_types=1);

namespace Mercurius\MailMcp\Test;

use Mercurius\MailMcp\MailException;
use Mercurius\MailMcp\Mime\Builder;
use Mercurius\MailMcp\Mime\Parser;
use PHPUnit\Framework\TestCase;

final class MimeTest extends TestCase {
    public function testAMultipartLetterInCp1251WithAnAttachmentIsRead(): void {
        $text = base64_encode((string) mb_convert_encoding('Привет, счёт во вложении.', 'Windows-1251', 'UTF-8'));
        $raw = "From: =?UTF-8?B?" . base64_encode('Сергей Петров') . "?= <ivan@example.ru>\r\n"
            . "To: admin@example.org\r\nSubject: =?windows-1251?Q?" . str_replace(' ', '_', quoted_printable_encode((string) mb_convert_encoding('Счёт за ноябрь', 'Windows-1251', 'UTF-8'))) . "?=\r\n"
            . "Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            . "--b1\r\nContent-Type: text/plain; charset=windows-1251\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $text . "\r\n"
            . "--b1\r\nContent-Type: application/pdf; name=\"invoice.pdf\"\r\nContent-Disposition: attachment; filename=\"invoice.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode(str_repeat('x', 2048)) . "\r\n--b1--\r\n";

        $message = Parser::parse($raw);

        self::assertSame('Сергей Петров <ivan@example.ru>', $message->header('from'));
        self::assertSame('Счёт за ноябрь', $message->header('subject'));
        self::assertSame('Привет, счёт во вложении.', $message->text);
        self::assertSame([['name' => 'invoice.pdf', 'type' => 'application/pdf', 'size' => 2048]], $message->attachments);
        self::assertSame([['name' => 'invoice.pdf', 'type' => 'application/pdf', 'bytes' => str_repeat('x', 2048)]], Parser::attachments($raw), 'the files themselves, in their order');
    }

    public function testFilesGoAsAMultipartLetterThatReadsBack(): void {
        [$message] = Builder::build('admin@example.org', ['ivan@example.ru'], [], 'Отчёт', "Во вложении.", files: [
            ['name' => 'отчёт.pdf', 'type' => 'application/pdf', 'bytes' => "%PDF-1.4\0binary"],
            ['name' => 'data.csv', 'type' => 'text/csv', 'bytes' => "a;b\n1;2\n"],
        ]);

        $parsed = Parser::parse($message);
        self::assertSame('Во вложении.', $parsed->text);
        self::assertSame(['отчёт.pdf', 'data.csv'], array_column($parsed->attachments, 'name'));
        self::assertSame("%PDF-1.4\0binary", Parser::attachments($message)[0]['bytes'] ?? null);
        self::assertStringContainsString('multipart/mixed', $message);
    }

    public function testHtmlOnlyBecomesTextAndQuotedPrintableIsUndone(): void {
        $raw = "Subject: x\r\nContent-Type: multipart/alternative; boundary=zz\r\n\r\n--zz\r\nContent-Type: text/html; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n<html><style>p{}</style><p>=D0=97=D0=B4=D1=80=D0=B0=D0=B2=D1=81=D1=82=D0=B2=D1=83=D0=B9=D1=82=D0=B5</p>"
            . "<p>Ссылка: <a href=3D\"https://x.example/a\">тут</a><script>alert(1)</script></p>\r\n--zz--\r\n";

        self::assertSame("Здравствуйте\nСсылка: тут (https://x.example/a)", Parser::parse($raw)->text);
    }

    public function testAReplyKeepsTheThreadAndHeaderInjectionIsRefused(): void {
        [$message, $id] = Builder::build('admin@example.org', ['ivan@example.ru'], [], 'Re: Счёт', "Спасибо!\nПолучил.", '<a1@example.ru>', '<a0@example.ru>');

        self::assertStringContainsString("In-Reply-To: <a1@example.ru>\r\nReferences: <a0@example.ru> <a1@example.ru>", $message);
        self::assertMatchesRegularExpression('~^<[0-9a-f]{24}@example.org>$~', $id);
        $parsed = Parser::parse($message);
        self::assertSame('Re: Счёт', $parsed->header('subject'));
        self::assertSame("Спасибо!\nПолучил.", $parsed->text);

        $this->expectException(MailException::class);
        Builder::build('admin@example.org', ['ivan@example.ru'], [], "Тема\r\nBcc: all@example.ru", 'x');
    }

    public function testASenderWithANameIsWrittenWithItAndSentFromItsAddress(): void {
        self::assertSame(['Отдел продаж', 'admin@example.org'], Builder::sender('Отдел продаж <admin@example.org>'));
        self::assertSame(['', 'admin@example.org'], Builder::sender('admin@example.org'));
        self::assertSame(['Me', 'me@example.org'], Builder::sender('"Me" <me@example.org>'));

        [$message, $id] = Builder::build('Отдел продаж <admin@example.org>', ['ivan@example.ru'], [], 's', 't');

        self::assertSame('Отдел продаж <admin@example.org>', Parser::parse($message)->header('from'));
        self::assertMatchesRegularExpression('~@example.org>$~', $id);
        $this->expectException(MailException::class);
        Builder::sender("Me <me@example.org>\r\nBcc: all@example.ru");
    }

    public function testABadAddressIsRefused(): void {
        $this->expectException(MailException::class);
        Builder::build('admin@example.org', ['not an address'], [], 's', 't');
    }
}
