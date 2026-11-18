/** Render the private account image with Unicode initials as a fallback. */

import { useState } from 'react';

/**
 * @param name Account display name, possibly empty or containing repeated whitespace.
 * @returns Uppercase first/last initials, one initial for a single word, or a question mark for an empty name.
 */
export function accountInitials(name: string): string {
    const words = name.normalize('NFC').trim().split(/\s+/u).filter(Boolean);
    const selected = words.length > 1 ? [words[0] ?? '', words.at(-1) ?? ''] : words;
    const graphemes = new Intl.Segmenter(undefined, { granularity: 'grapheme' });
    return (
        selected
            .map((word) => graphemes.segment(word)[Symbol.iterator]().next().value?.segment ?? '')
            .join('')
            .toUpperCase() || '?'
    );
}

/** @param props Safe account display name. @returns Decorative initials; the containing control owns its accessible name. */
export default function AccountAvatar({
    name,
    avatarUrl,
}: {
    name: string;
    avatarUrl?: string | null | undefined;
}) {
    const [failed, setFailed] = useState(false);
    return (
        <span className="ui-account-avatar" aria-hidden="true">
            {avatarUrl && !failed ? (
                <img
                    src={avatarUrl}
                    alt=""
                    className="h-full w-full rounded-full object-contain"
                    onError={() => setFailed(true)}
                />
            ) : (
                accountInitials(name)
            )}
        </span>
    );
}
