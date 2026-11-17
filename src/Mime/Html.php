<?php

declare(strict_types=1);

namespace Mercurius\MailMcp\Mime;

/**
 * HTML of a letter as plain text: scripts and styles dropped, blocks and line breaks kept, links shown with their address.
 */
final class Html {
    public static function toText(string $html): string {
        $html = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $html = preg_replace('~<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is', '$2 ($1)', $html) ?? $html;
        $html = preg_replace('~<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>~i', "\n", $html) ?? $html;
        $html = preg_replace('~<li\b[^>]*>~i', '• ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~[ \t\x{00A0}]+~u', ' ', $text) ?? $text;
        $text = preg_replace('~\n\s*\n\s*\n+~', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
