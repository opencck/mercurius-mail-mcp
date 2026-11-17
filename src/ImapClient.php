<?php

declare(strict_types=1);

namespace Mercurius\MailMcp;

/**
 * The part of IMAP4rev1 an agent needs: log in, list folders, open one read-only, search, fetch headers and messages.
 * Folders are opened with EXAMINE and bodies fetched with BODY.PEEK, so reading never marks mail as seen.
 *
 * ref: references/hermes-agent/plugins/platforms/email/adapter.py@e96221fa:L691-L743 — UID search and headers fetched
 *      before a whole message; here the folder is read-only and a message is cut at a size limit
 */
final class ImapClient {
    public const MESSAGE_MAX = 5_000_000;

    private int $tag = 0;

    public function __construct(private readonly Connection $connection) {}

    public function greet(): void {
        $line = $this->connection->readLine();
        if ($line === null || !str_starts_with($line, '* OK')) {
            throw new MailException('IMAP-сервер не поздоровался.');
        }
    }

    public function login(string $user, #[\SensitiveParameter] string $password): void {
        [, $status] = $this->command(sprintf('LOGIN %s %s', self::quote($user), self::quote($password)), 'LOGIN');
        if (!str_starts_with($status, 'OK')) {
            throw new MailException('IMAP: вход не принят — проверьте логин и пароль.');
        }
    }

    /** @return list<string> */
    public function folders(): array {
        [$untagged] = $this->expect('LIST "" "*"');
        $folders = [];
        foreach ($untagged as [$text]) {
            if (preg_match('~^\* LIST \([^)]*\) (?:"[^"]*"|NIL) (.+)$~i', $text, $m) === 1) {
                $folders[] = self::utf7(self::unquote($m[1]));
            }
        }
        return $folders;
    }

    public function examine(string $folder): void {
        $this->expect('EXAMINE ' . self::quote(self::toUtf7($folder)));
    }

    /**
     * @param list<string> $criteria pieces like UNSEEN, FROM and its value; values go as literals in UTF-8
     * @return list<int> UIDs, newest first
     */
    public function search(array $criteria): array {
        $parts = [];
        $current = 'UID SEARCH CHARSET UTF-8';
        foreach ($criteria === [] ? ['ALL'] : $criteria as $piece) {
            if (preg_match('~^[A-Z0-9-]+$~', $piece) === 1) {
                $current .= ' ' . $piece;
            } else {
                $parts[] = $current . sprintf(' {%d}', strlen($piece));
                $current = $piece;
            }
        }
        $parts[] = $current;
        [$untagged] = $this->expect($parts);
        $uids = [];
        foreach ($untagged as [$text]) {
            if (preg_match('~^\* SEARCH ?(.*)$~i', $text, $m) === 1) {
                foreach (preg_split('~\s+~', trim($m[1])) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }
        rsort($uids);
        return $uids;
    }

    /**
     * Headers, flags and size of messages.
     *
     * @param list<int> $uids
     * @return array<int, array{headers: string, flags: string, size: int}>
     */
    public function headers(array $uids): array {
        if ($uids === []) {
            return [];
        }
        [$untagged] = $this->expect(sprintf(
            'UID FETCH %s (UID FLAGS RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID AUTHENTICATION-RESULTS)])',
            implode(',', $uids),
        ));
        $found = [];
        foreach ($untagged as [$text, $literal]) {
            if (preg_match('~UID (\d+)~', $text, $m) !== 1) {
                continue;
            }
            preg_match('~FLAGS \(([^)]*)\)~', $text, $flags);
            preg_match('~RFC822\.SIZE (\d+)~', $text, $size);
            $found[(int) $m[1]] = ['headers' => $literal, 'flags' => $flags[1] ?? '', 'size' => (int) ($size[1] ?? 0)];
        }
        return $found;
    }

    /** The raw message, at most MESSAGE_MAX bytes; null when there is no such UID. */
    public function message(int $uid): ?string {
        [$untagged] = $this->expect(sprintf('UID FETCH %d (UID BODY.PEEK[]<0.%d>)', $uid, self::MESSAGE_MAX));
        foreach ($untagged as [$text, $literal]) {
            if (preg_match('~UID (\d+)~', $text, $m) === 1 && (int) $m[1] === $uid) {
                return $literal;
            }
        }
        return null;
    }

    public function logout(): void {
        try {
            $this->command('LOGOUT', 'LOGOUT');
        } catch (MailException) {
        }
        $this->connection->close();
    }

    /**
     * @param string|list<string> $command
     * @return array{list<array{string, string}>, string}
     */
    private function expect(string|array $command): array {
        $first = is_string($command) ? $command : ($command[0] ?? '');
        $result = $this->command($command, explode(' ', $first)[0]);
        if (!str_starts_with($result[1], 'OK')) {
            throw new MailException('IMAP: ' . mb_substr($result[1], 0, 200));
        }
        return $result;
    }

    /**
     * Sends a command (pieces after a literal wait for the server's "+"), reads untagged answers (each: its text and the
     * literals it carried) until the tagged one.
     *
     * @param string|list<string> $command
     * @return array{list<array{string, string}>, string} the untagged answers and the tagged status ("OK …", "NO …")
     */
    private function command(string|array $command, string $label): array {
        $tag = 'm' . (++$this->tag);
        $parts = is_string($command) ? [$command] : $command;
        $first = array_shift($parts) ?? '';
        $this->connection->write($tag . ' ' . $first . "\r\n");
        foreach ($parts as $part) {
            $go = $this->connection->readLine() ?? '';
            if (!str_starts_with($go, '+')) {
                throw new MailException('IMAP: сервер не принял строку поиска.');
            }
            $this->connection->write($part . "\r\n");
        }
        $untagged = [];
        while (true) {
            $line = $this->connection->readLine() ?? throw new MailException('IMAP-сервер закрыл соединение (' . $label . ').');
            if (str_starts_with($line, $tag . ' ')) {
                return [$untagged, substr($line, strlen($tag) + 1)];
            }
            $text = $line;
            $literal = '';
            while (preg_match('~\{(\d+)\}$~', $line, $m) === 1) {
                $literal .= $this->connection->read((int) $m[1]);
                $next = $this->connection->readLine();
                if ($next === null) {
                    throw new MailException('IMAP-сервер закрыл соединение посреди ответа.');
                }
                $line = $next;
                $text .= ' ' . $line;
            }
            if (str_starts_with($text, '*')) {
                $untagged[] = [$text, $literal];
            }
        }
    }

    public static function quote(string $value): string {
        if (preg_match('~[\r\n\x00]~', $value) === 1) {
            throw new MailException('Недопустимые символы в строке IMAP.');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private static function unquote(string $value): string {
        $value = trim($value);
        return str_starts_with($value, '"') ? stripcslashes(substr($value, 1, -1)) : $value;
    }

    private static function utf7(string $name): string {
        return self::or(mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP'), $name);
    }

    private static function toUtf7(string $name): string {
        return self::or(mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8'), $name);
    }

    /** A string result of a PHP function, or the fallback: the analysers disagree about "string or false". */
    private static function or(mixed $value, string $fallback): string {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
