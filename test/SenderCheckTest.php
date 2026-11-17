<?php

declare(strict_types=1);

namespace Mercurius\MailMcp\Test;

use Mercurius\MailMcp\SenderCheck;
use PHPUnit\Framework\TestCase;

final class SenderCheckTest extends TestCase {
    public function testOnlyTheReceivingServerVouchesForTheSender(): void {
        $ok = 'mail.kwinta.net; dkim=pass header.d=example.ru header.s=s1; spf=pass smtp.mailfrom=bounce@example.ru; dmarc=pass header.from=example.ru';
        self::assertSame([true, 'dmarc=pass'], SenderCheck::verify('Иван <ivan@example.ru>', $ok, 'mail.kwinta.net'));
        self::assertSame([true, 'spf=pass'], SenderCheck::verify('ivan@example.ru', 'mail.kwinta.net; spf=pass smtp.mailfrom=news.example.ru', 'mail.kwinta.net'));

        self::assertFalse(SenderCheck::verify('ivan@example.ru', null, 'mail.kwinta.net')[0], 'no header — not verified');
        self::assertFalse(SenderCheck::verify('ivan@example.ru', 'evil.example; dmarc=pass', 'mail.kwinta.net')[0], 'another server');
        self::assertFalse(SenderCheck::verify('ivan@example.ru', 'mail.kwinta.net; spf=pass smtp.mailfrom=attacker.example', 'mail.kwinta.net')[0], 'not aligned');
        self::assertFalse(SenderCheck::verify('ivan@example.ru', 'mail.kwinta.net; spf=fail (dmarc=pass; spf=pass) smtp.mailfrom=example.ru', 'mail.kwinta.net')[0], 'a comment cannot smuggle a pass');
        self::assertNull(SenderCheck::clauses('a; (unbalanced'));
    }
}
