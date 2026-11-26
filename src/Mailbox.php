<?php

declare(strict_types=1);

namespace Mercurius\MailMcp;

use Closure;
use DateTimeImmutable;
use Mercurius\MailMcp\Mime\Builder;
use Mercurius\MailMcp\Mime\Parser;

/**
 * What an agent does with a mailbox: folders, a list by filters, one message with its sender checked, sending.
 * Each call opens its own connection and closes it.
 */
final class Mailbox {
    public const LIMIT_MAX = 50;
    public const TEXT_MAX = 20_000;

    /** @var Closure(string, int, bool): Connection */
    private readonly Closure $connect;

    /** @param (Closure(string, int, bool): Connection)|null $connect host, port, TLS from the start */
    public function __construct(?Closure $connect = null) {
        $this->connect = $connect ?? static fn(string $host, int $port, bool $tls): Connection => SocketConnection::open($host, $port, $tls);
    }

    /** @return list<string> */
    public function folders(Account $account): array {
        return $this->with($account, static fn(ImapClient $imap): array => $imap->folders());
    }

    /**
     * @param array{unseen?: bool, from?: string, subject?: string, since?: string, text?: string} $filter
     * @return list<array{uid: int, date: string, from: string, subject: string, seen: bool, size: int, verified: bool}>
     */
    public function list(Account $account, string $folder, array $filter, int $limit): array {
        $criteria = [];
        if (($filter['unseen'] ?? false) === true) {
            $criteria[] = 'UNSEEN';
        }
        foreach (['FROM' => $filter['from'] ?? '', 'SUBJECT' => $filter['subject'] ?? '', 'TEXT' => $filter['text'] ?? ''] as $word => $value) {
            if (trim($value) !== '') {
                $criteria[] = $word;
                $criteria[] = trim($value);
            }
        }
        if (isset($filter['since']) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $filter['since']) === 1) {
            $criteria[] = 'SINCE';
            $criteria[] = (new DateTimeImmutable($filter['since']))->format('j-M-Y');
        }
        $limit = max(1, min(self::LIMIT_MAX, $limit));
        return $this->with($account, static function (ImapClient $imap) use ($folder, $criteria, $limit, $account): array {
            $imap->examine($folder);
            $uids = array_slice($imap->search($criteria), 0, $limit);
            $found = [];
            foreach ($imap->headers($uids) as $uid => $item) {
                $headers = Parser::headers($item['headers']);
                $from = $headers['from'][0] ?? '';
                [$verified] = SenderCheck::verify($from, $headers['authentication-results'][0] ?? null, $account->authserv());
                $found[] = [
                    'uid' => $uid,
                    'date' => $headers['date'][0] ?? '',
                    'from' => $from,
                    'subject' => $headers['subject'][0] ?? '',
                    'seen' => str_contains($item['flags'], '\Seen'),
                    'size' => $item['size'],
                    'verified' => $verified,
                ];
            }
            usort($found, static fn(array $a, array $b): int => $b['uid'] <=> $a['uid']);
            return $found;
        });
    }

    /** @return array{\Mercurius\MailMcp\Mime\Message, bool, string} the message, whether its sender is authenticated, why */
    public function read(Account $account, string $folder, int $uid): array {
        return $this->with($account, static function (ImapClient $imap) use ($folder, $uid, $account): array {
            $imap->examine($folder);
            $raw = $imap->message($uid) ?? throw new MailException(sprintf('Письма %d в «%s» нет.', $uid, $folder));
            $message = Parser::parse($raw);
            $results = $message->headers['authentication-results'][0] ?? null;
            [$verified, $why] = SenderCheck::verify($message->header('from'), $results, $account->authserv());
            return [$message, $verified, $why];
        });
    }

    /**
     * One attachment of a message by its number (from 1, as mail_read lists them).
     *
     * @return array{name: string, type: string, bytes: string}
     */
    public function attachment(Account $account, string $folder, int $uid, int $index): array {
        return $this->with($account, static function (ImapClient $imap) use ($folder, $uid, $index): array {
            $imap->examine($folder);
            $raw = $imap->message($uid) ?? throw new MailException(sprintf('Письма %d в «%s» нет.', $uid, $folder));
            $files = Parser::attachments($raw);
            return $files[$index - 1] ?? throw new MailException(sprintf('У письма %d вложений %d, номера %d нет.', $uid, count($files), $index));
        });
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<array{name: string, type: string, bytes: string}> $files
     * @return string the Message-ID
     */
    public function send(Account $account, array $to, array $cc, string $subject, string $text, ?int $replyTo = null, string $folder = 'INBOX', array $files = []): string {
        $inReplyTo = '';
        $references = '';
        if ($replyTo !== null) {
            [$original] = $this->read($account, $folder, $replyTo);
            $inReplyTo = $original->header('message-id');
            $references = $original->header('references');
            if (!preg_match('~^re:~i', $subject)) {
                $subject = 'Re: ' . ($subject !== '' ? $subject : $original->header('subject'));
            }
        }
        if ($to === []) {
            throw new MailException('Нужен хотя бы один получатель.');
        }
        [$message, $id] = Builder::build($account->from, $to, $cc, $subject, $text, $inReplyTo, $references, $files);
        $startTls = $account->smtpPort === 587 || $account->smtpPort === 25;
        (new SmtpClient(($this->connect)($account->host, $account->smtpPort, !$startTls)))->send($account, Builder::sender($account->from)[1], [...$to, ...$cc], $message, $startTls);
        return $id;
    }

    /**
     * @template T
     * @param Closure(ImapClient): T $work
     * @return T
     */
    private function with(Account $account, Closure $work): mixed {
        $imap = new ImapClient(($this->connect)($account->host, $account->imapPort, true));
        $imap->greet();
        $imap->login($account->user, $account->password);
        try {
            return $work($imap);
        } finally {
            $imap->logout();
        }
    }
}
