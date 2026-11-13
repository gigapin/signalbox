<?php

declare(strict_types=1);

/** Turn tenant-chosen text into a literal fragment for Laravel Markdown mail lines and plain headers. */

namespace App\Services\Mail;

/**
 * Laravel renders `MailMessage` lines through Blade escaping and then CommonMark, so text such as
 * `[Refund](https://attacker.example)` becomes a clickable link. These helpers keep such text literal.
 *
 * Only inline Markdown syntax is escaped. Blade already turns `& ' " < >` into entities, which also blocks HTML and
 * `<…>` autolinks; escaping those characters again would print the entity text. Block syntax (headings, lists) only
 * applies at the start of a line, which `singleLine()` prevents by removing line breaks.
 */
final class MarkdownText
{
    /** @var string Character class of the inline Markdown punctuation that is backslash-escaped. */
    private const string INLINE_SYNTAX = '/[\\\\`*_\[\]!~|]/';

    /**
     * @param string $text Untrusted tenant text, such as a team name.
     * @return string Single-line text that CommonMark renders exactly as typed, without links or emphasis.
     */
    public static function literal(string $text): string
    {
        return (string) preg_replace(self::INLINE_SYNTAX, '\\\\$0', self::singleLine($text));
    }

    /**
     * @param string $text Untrusted text for a header such as a subject, or a Markdown line.
     * @return string Text with control characters and line breaks replaced by single spaces, trimmed.
     */
    public static function singleLine(string $text): string
    {
        $spaced = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $text);

        return trim((string) preg_replace('/ {2,}/', ' ', $spaced));
    }
}
