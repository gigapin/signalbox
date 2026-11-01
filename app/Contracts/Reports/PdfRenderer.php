<?php

declare(strict_types=1);

/** Turn trusted application markup into a PDF document, independently of the library that does it. */

namespace App\Contracts\Reports;

interface PdfRenderer
{
    /**
     * @param string $html Complete document produced by an application view, with user text already escaped.
     * @return string Binary PDF document.
     */
    public function render(string $html): string;
}
