/** Check actual CSS token pairs against WCAG relative luminance thresholds for text and controls. */
import { readFileSync } from 'node:fs';
import { expect, test } from 'vitest';

/** Opaque six-digit semantic colors read from the production stylesheet, not a copied palette. */
const stylesheet = readFileSync('resources/css/app.css', 'utf8');
/** Explicit palette blocks prevent later light overrides from masking dark regressions. */
const palettes = {
    dark: stylesheet.split('/* Explicit application appearance')[0] ?? '',
    light: stylesheet.split("html[data-ui-theme='light'] {")[1]?.split('}')[0] ?? '',
};

/**
 * @param token Semantic color name in the checked-in stylesheet.
 * @param theme Explicit application palette.
 * @returns WCAG relative luminance of the sRGB token.
 * @throws Error When a required opaque token is missing.
 */
function luminance(token: string, theme: keyof typeof palettes): number {
    const tokens = new Map(
        [...palettes[theme].matchAll(/--color-ui-([a-z-]+):\s*(#[0-9a-f]{6});/g)].map((match) => [
            match[1],
            match[2],
        ]),
    );
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
 * @param theme Explicit application palette.
 * @returns Contrast ratio, with one representing identical luminance.
 */
function contrast(foreground: string, background: string, theme: keyof typeof palettes): number {
    const first = luminance(foreground, theme);
    const second = luminance(background, theme);
    return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
}

test.each(['dark', 'light'] as const)(
    'normal-size text meets AA on every supported %s surface',
    (theme) => {
        for (const surface of ['canvas', 'surface', 'raised']) {
            for (const foreground of ['text', 'muted', 'accent', 'danger', 'success', 'warning']) {
                expect(
                    contrast(foreground, surface, theme),
                    `${foreground} on ${surface}`,
                ).toBeGreaterThanOrEqual(4.5);
            }
        }
        for (const tone of ['success', 'warning', 'danger', 'info']) {
            expect(contrast(tone, `${tone}-bg`, theme), `${tone} notice`).toBeGreaterThanOrEqual(
                4.5,
            );
        }
        for (const background of ['accent', 'accent-hover']) {
            expect(
                contrast('on-accent', background, theme),
                `primary on ${background}`,
            ).toBeGreaterThanOrEqual(4.5);
        }
    },
);

test.each(['dark', 'light'] as const)(
    'control boundaries and focus remain distinguishable in %s',
    (theme) => {
        for (const surface of [
            'canvas',
            'surface',
            'raised',
            'success-bg',
            'warning-bg',
            'danger-bg',
            'info-bg',
        ]) {
            expect(contrast('focus', surface, theme), `focus on ${surface}`).toBeGreaterThanOrEqual(
                3,
            );
        }
        for (const surface of ['canvas', 'surface', 'raised']) {
            expect(
                contrast('control-border', surface, theme),
                `control border on ${surface}`,
            ).toBeGreaterThanOrEqual(3);
        }
    },
);
