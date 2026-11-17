<?php

declare(strict_types=1);

namespace Mercurius\MailMcp;

/**
 * Whether the From domain is authenticated. From is written by the sender; the only trustworthy signal is the FIRST
 * Authentication-Results header stamped by the receiving server, pinned to its authserv-id: DMARC pass, or SPF or DKIM
 * pass aligned with the From domain.
 *
 * ref: references/hermes-agent/plugins/platforms/email/adapter.py@e96221fa:L327-L427 (_ar_clauses,
 *      _verify_sender_authentication) — the first header only, the pin, clauses split outside quotes and comments,
 *      relaxed alignment
 */
final class SenderCheck {
    /** @return array{bool, string} authenticated, and why */
    public static function verify(string $from, ?string $authenticationResults, string $authservId): array {
        $domain = self::domain($from);
        if ($domain === '') {
            return [false, 'нет домена отправителя'];
        }
        if ($authenticationResults === null || trim($authenticationResults) === '') {
            return [false, 'нет Authentication-Results'];
        }
        $clauses = self::clauses(preg_replace('~\s+~', ' ', $authenticationResults) ?? '');
        if ($clauses === null) {
            return [false, 'Authentication-Results не разобрать'];
        }
        if (strtolower(trim($clauses[0])) !== strtolower($authservId)) {
            return [false, 'Authentication-Results не от своего сервера (' . trim($clauses[0]) . ')'];
        }
        /** @var array<string, list<array{string, list<array{string, string}>}>> $results */
        $results = ['dmarc' => [], 'spf' => [], 'dkim' => []];
        foreach (array_slice($clauses, 1) as $clause) {
            if (preg_match('~^\s*(dmarc|spf|dkim)\s*=\s*(\w+)~i', $clause, $m) === 1) {
                preg_match_all('~([\w.-]+)\s*=\s*("[^"]*"|[^\s;]+)~', $clause, $props, PREG_SET_ORDER);
                $pairs = [];
                foreach ($props as $p) {
                    $pairs[] = [strtolower($p[1]), trim($p[2], '"')];
                }
                $results[strtolower($m[1])][] = [strtolower($m[2]), $pairs];
            }
        }
        if (count($results['dmarc']) === 1 && $results['dmarc'][0][0] === 'pass' && self::aligned($domain, $results['dmarc'][0][1], ['header.from'], false)) {
            return [true, 'dmarc=pass'];
        }
        if (count($results['spf']) === 1 && $results['spf'][0][0] === 'pass' && self::aligned($domain, $results['spf'][0][1], ['smtp.mailfrom', 'smtp.from', 'envelope-from'], true)) {
            return [true, 'spf=pass'];
        }
        foreach ($results['dkim'] as [$result, $pairs]) {
            if ($result === 'pass' && self::aligned($domain, $pairs, ['header.d'], true)) {
                return [true, 'dkim=pass'];
            }
        }
        return [false, 'подлинность не подтверждена'];
    }

    /**
     * @param list<array{string, string}> $pairs
     * @param list<string> $names
     */
    private static function aligned(string $domain, array $pairs, array $names, bool $required): bool {
        $domains = [];
        foreach ($pairs as [$name, $value]) {
            if (in_array($name, $names, true)) {
                $domains[] = self::domain($value);
            }
        }
        if ($domains === []) {
            return !$required;
        }
        foreach ($domains as $d) {
            if (!self::alignedDomains($d, $domain)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Clauses split on ";" outside quotes and comments, comments dropped; null when unbalanced.
     *
     * @return list<string>|null
     */
    public static function clauses(string $text): ?array {
        $clauses = [];
        $current = '';
        $depth = 0;
        $quoted = false;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $c = $text[$i];
            if ($c === '\\' && ($quoted || $depth > 0)) {
                if ($quoted) {
                    $current .= substr($text, $i, 2);
                }
                $i++;
                continue;
            }
            if ($quoted) {
                $quoted = $c !== '"';
                $current .= $c;
            } elseif ($depth > 0) {
                $depth += $c === '(' ? 1 : ($c === ')' ? -1 : 0);
                if ($depth === 0) {
                    $current .= ' ';
                }
            } elseif ($c === '(') {
                $depth = 1;
            } elseif ($c === '"') {
                $quoted = true;
                $current .= $c;
            } elseif ($c === ')') {
                return null;
            } elseif ($c === ';') {
                $clauses[] = $current;
                $current = '';
            } else {
                $current .= $c;
            }
        }
        if ($quoted || $depth > 0) {
            return null;
        }
        $clauses[] = $current;
        return $clauses;
    }

    public static function domain(string $address): string {
        if (preg_match('~<([^>]+)>~', $address, $m) === 1) {
            $address = $m[1];
        }
        $at = strrpos($address, '@');
        return strtolower(rtrim(trim($at === false ? $address : substr($address, $at + 1)), '.'));
    }

    private static function alignedDomains(string $a, string $b): bool {
        return $a !== '' && $b !== '' && ($a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a));
    }
}
