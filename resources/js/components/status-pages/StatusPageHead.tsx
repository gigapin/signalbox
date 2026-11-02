/** Keep client navigation metadata aligned with the escaped server head, including removal of obsolete social tags. */
import { Head } from '@inertiajs/react';
import type { StatusPageMetadata } from './types';

/** Restricted fallback prevents incomplete client props from advertising protected content. */
interface StatusPageHeadProps {
    /** Server decision; missing props fail closed until a complete page is loaded. */
    metadata: StatusPageMetadata | undefined;
    /** Generic fallback title, excluding page title, mailbox and capability coordinates. */
    fallbackTitle: string;
}

/** Render keys matching the initial Blade head so Inertia replaces rather than duplicates metadata. */
export default function StatusPageHead({ metadata, fallbackTitle }: StatusPageHeadProps) {
    const document = metadata ?? {
        title: fallbackTitle,
        description: 'Signalbox status page.',
        robots: 'noindex, nofollow, nosnippet',
        canonicalUrl: null,
        siteName: 'Signalbox',
    };

    return (
        <Head title={document.title}>
            <meta head-key="description" name="description" content={document.description} />
            <meta head-key="robots" name="robots" content={document.robots} />
            {document.canonicalUrl !== null && (
                <link head-key="canonical" rel="canonical" href={document.canonicalUrl} />
            )}
            {document.canonicalUrl !== null && (
                <meta head-key="og:title" property="og:title" content={document.title} />
            )}
            {document.canonicalUrl !== null && (
                <meta
                    head-key="og:description"
                    property="og:description"
                    content={document.description}
                />
            )}
            {document.canonicalUrl !== null && (
                <meta head-key="og:url" property="og:url" content={document.canonicalUrl} />
            )}
            {document.canonicalUrl !== null && (
                <meta head-key="og:type" property="og:type" content="website" />
            )}
            {document.canonicalUrl !== null && (
                <meta head-key="og:site_name" property="og:site_name" content={document.siteName} />
            )}
            {document.canonicalUrl !== null && (
                <meta head-key="twitter:card" name="twitter:card" content="summary" />
            )}
            {document.canonicalUrl !== null && (
                <meta head-key="twitter:title" name="twitter:title" content={document.title} />
            )}
            {document.canonicalUrl !== null && (
                <meta
                    head-key="twitter:description"
                    name="twitter:description"
                    content={document.description}
                />
            )}
        </Head>
    );
}
