<?php

declare(strict_types=1);

/** Record report markup instead of rendering it, because the text inside a real PDF is compressed. */

namespace Tests\Support;

use App\Contracts\Reports\PdfRenderer;

final class RecordingPdfRenderer implements PdfRenderer
{
    /** @var string|null Markup of the last document, or null before any. */
    public ?string $html = null;

    /**
     * @param string $html Complete document.
     * @return string Minimal stand-in for a PDF.
     */
    public function render(string $html): string
    {
        $this->html = $html;

        return '%PDF-recorded';
    }
}
