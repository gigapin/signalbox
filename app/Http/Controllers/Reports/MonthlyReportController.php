<?php

declare(strict_types=1);

/** Deliver a team's monthly report as a PDF download. */

namespace App\Http\Controllers\Reports;

use App\Contracts\Reports\PdfRenderer;
use App\Data\Reports\ReportMonth;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ShowMonthlyReportRequest;
use App\Models\Team;
use App\Services\Reports\BuildMonthlyReport;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/** Membership, verification and tenant context are established before the request and the services execute. */
final class MonthlyReportController extends Controller
{
    /**
     * @param ShowMonthlyReportRequest $request Authorized read.
     * @param BuildMonthlyReport $reports Report contents.
     * @param PdfRenderer $renderer Document rendering.
     * @param int $team Authorized route tenant.
     * @param string $month Month in YYYY-MM form, guaranteed by the route pattern.
     * @return Response PDF attachment; the file name is built from identifiers only, never from user text.
     */
    public function __invoke(ShowMonthlyReportRequest $request, BuildMonthlyReport $reports, PdfRenderer $renderer, int $team, string $month): Response
    {
        $identity = Team::query()->findOrFail($team);
        $reportMonth = ReportMonth::parse($month, $identity->timezone);

        abort_unless($reportMonth->hasStarted(CarbonImmutable::now('UTC')), 404);

        $document = view('reports.monthly', ['report' => $reports->build($identity, $reportMonth)])->render();
        $filename = "signalbox-report-{$identity->id}-{$reportMonth->identifier()}.pdf";

        return response($renderer->render($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
