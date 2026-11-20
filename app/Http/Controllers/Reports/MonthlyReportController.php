<?php

declare(strict_types=1);

/** Present monthly report selection and deliver authorized PDF downloads. */

namespace App\Http\Controllers\Reports;

use App\Contracts\Reports\PdfRenderer;
use App\Data\Reports\ReportMonth;
use App\Data\Reports\ReportPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\DownloadReportRequest;
use App\Http\Requests\Reports\ShowMonthlyReportRequest;
use App\Models\Team;
use App\Services\Reports\BuildMonthlyReport;
use App\Services\Reports\ReportCalendar;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/** Membership, verification and tenant context are established before the request and the services execute. */
final class MonthlyReportController extends Controller
{
    /** Present the existing month selector without generating a PDF.
     * @param ShowMonthlyReportRequest $request Authorized member read with established tenant context.
     * @param int $team Explicit route team, independent of the account preference.
     * @return InertiaResponse Minimal report calendar and identity projection.
     */
    public function index(ShowMonthlyReportRequest $request, int $team): InertiaResponse
    {
        $identity = Team::query()->findOrFail($team);
        return Inertia::render('Reports/Index', [
            'team' => ['id' => $identity->id, 'name' => $identity->name],
            'reportTimezone' => $identity->timezone,
            'reportCalendar' => app(ReportCalendar::class)->forTeam($identity),
        ]);
    }

    /** Download an inclusive range of complete local dates.
     * @param DownloadReportRequest $request Authorized member request.
     * @param int $team Explicit tenant identity.
     * @param BuildMonthlyReport $reports Shared metric aggregation.
     * @param PdfRenderer $renderer Trusted document renderer.
     * @return Response Private PDF attachment.
     */
    public function download(DownloadReportRequest $request, int $team, BuildMonthlyReport $reports, PdfRenderer $renderer): Response
    {
        $identity = Team::query()->findOrFail($team);
        $dates = $request->validated();
        $period = new ReportPeriod((string) $dates['from'], (string) $dates['to'], $identity->timezone);
        $document = view('reports.monthly', ['report' => $reports->build($identity, $period)])->render();
        $filename = "signalbox-report-{$identity->id}-{$period->identifier()}.pdf";
        return response($renderer->render($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

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
