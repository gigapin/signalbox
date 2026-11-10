/** Check actual CSS token pairs against WCAG relative luminance thresholds for text and controls. */
import { readFileSync } from 'node:fs';
import { expect, test } from 'vitest';

/** Opaque six-digit semantic colors read from the production stylesheet, not a copied palette. */
const tokens = new Map(
    [
        ...readFileSync('resources/css/app.css', 'utf8').matchAll(
            /--color-ui-([a-z-]+):\s*(#[0-9a-f]{6});/g,
        ),
    ].map((match) => [match[1] ?? '', match[2] ?? '']),
);

/**
 * @param token Semantic color name in the checked-in stylesheet.
 * @returns WCAG relative luminance of the sRGB token.
 * @throws Error When a required opaque token is missing.
 */
function luminance(token: string): number {
    const hex = tokens.get(token);
    if (!hex) throw new Error(`Missing CSS color: ${token}`);
    const channels = [1, 3, 5].map((offset) => {
        const value = Number.parseInt(hex.slice(offset, offset + 2), 16) / 255;
        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });

    return (channels[0] ?? 0) * 0.2126 + (channels[1] ?? 0) * 0.7152 + (channels[2] ?? 0) * 0.0722;
}

/**
 * @param foreground Semantic text, indicator or border token.
 * @param background Opaque surface token.
 * @returns Contrast ratio, with one representing identical luminance.
 */
function contrast(foreground: string, background: string): number {
    const first = luminance(foreground);
    const second = luminance(background);
    return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
}

test('normal-size text meets AA on every supported dark surface including disabled readable controls', () => {
    for (const surface of ['canvas', 'surface', 'raised']) {
        for (const foreground of ['text', 'muted', 'accent', 'danger']) {
            expect(
                contrast(foreground, surface),
                `${foreground} on ${surface}`,
            ).toBeGreaterThanOrEqual(4.5);
        }
    }
    for (const tone of ['success', 'warning', 'danger', 'info']) {
        expect(contrast(tone, `${tone}-bg`), `${tone} notice`).toBeGreaterThanOrEqual(4.5);
    }
    for (const background of ['accent', 'accent-hover']) {
        expect(
            contrast('on-accent', background),
            `primary on ${background}`,
        ).toBeGreaterThanOrEqual(4.5);
    }
});

test('control boundaries and offset focus indicators remain distinguishable on their adjacent surfaces', () => {
    for (const surface of [
        'canvas',
        'surface',
        'raised',
        'success-bg',
        'warning-bg',
        'danger-bg',
        'info-bg',
    ]) {
        expect(contrast('focus', surface), `focus on ${surface}`).toBeGreaterThanOrEqual(3);
    }
    for (const surface of ['canvas', 'surface', 'raised']) {
        expect(contrast('border', surface), `border on ${surface}`).toBeGreaterThanOrEqual(3);
    }
});
