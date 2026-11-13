<?php

declare(strict_types=1);

/**
 * Check that tenant text inserted into Laravel Markdown mail lines renders literally.
 *
 * Uses Laravel's own mail Markdown renderer, so the first case documents the threat these helpers remove: an
 * unescaped mail line turns `[text](url)` into a clickable link.
 */

use App\Services\Mail\MarkdownText;
use Illuminate\Mail\Markdown;

/** Text a hostile sender could choose as a team name before registration restricted its characters. */
const MARKDOWN_HOSTILE_NAME = '[Claim your refund](https://attacker.example/r) **now** ![x](https://attacker.example/i.png) `code` ~x~ a|b';

it('renders Markdown links in unescaped mail lines, which is the risk being removed', function (): void {
    $html = (string) Markdown::parse('You have been invited to join '.MARKDOWN_HOSTILE_NAME.'.');

    expect($html)->toContain('href="https://attacker.example/r"');
});

it('renders an escaped name as the literal text that was typed', function (): void {
    $html = (string) Markdown::parse('You have been invited to join '.MarkdownText::literal(MARKDOWN_HOSTILE_NAME).'.');

    expect($html)->not->toContain('<a ')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('<strong>')
        ->and($html)->not->toContain('<code>')
        ->and($html)->toContain('[Claim your refund](https://attacker.example/r) **now**');
});

it('leaves ordinary team names unchanged, so plain-text mail shows no backslashes', function (string $name): void {
    expect(MarkdownText::literal($name))->toBe($name);
})->with([
    'Acme Inc.',
    'Kub-Lang & Sons (EU)',
    'Café Zürich 2',
    'O’Brien, Ltd',
]);

it('reduces control characters and line breaks to single spaces', function (): void {
    $text = "  Ops\r\n# Heading\u{2028}- item\t\x00end  ";

    expect(MarkdownText::singleLine($text))->toBe('Ops # Heading - item end')
        ->and(MarkdownText::literal($text))->toBe('Ops # Heading - item end');
});
