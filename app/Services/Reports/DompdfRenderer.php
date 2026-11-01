<?php

declare(strict_types=1);

/** Render report documents with Dompdf, with no access to the network or to embedded code. */

namespace App\Services\Reports;

use App\Contracts\Reports\PdfRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Monitor and team names reach the markup, so every capability that could turn it into a request or into code stays off. */
final class DompdfRenderer implements PdfRenderer
{
    /** Bundled font covering accented and many non-Latin names; CJK text is a declared limit. */
    private const string FONT = 'DejaVu Sans';

    /** @return Options Explicit restrictions, independent of the library's defaults. */
    public static function options(): Options
    {
        $options = new Options();

        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols(['file://']);
        $options->setIsFontSubsettingEnabled(true);
        $options->setDefaultFont(self::FONT);

        return $options;
    }

    /**
     * @param string $html Complete document produced by an application view.
     * @return string Binary PDF document on landscape A4.
     */
    public function render(string $html): string
    {
        $dompdf = new Dompdf(self::options());

        $dompdf->setPaper('A4', 'landscape');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
