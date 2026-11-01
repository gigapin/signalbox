{{--
    Monthly availability report of one team, rendered to PDF with no network access.
    Every value goes through escaped output, because monitor and team names are user text.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Monthly report — {{ $report->month->label() }}</title>
    <style>
        @page { margin: 18mm 14mm; }
        body { color: #1e293b; font-family: "DejaVu Sans", sans-serif; font-size: 9pt; }
        h1 { font-size: 16pt; margin: 0 0 2mm; }
        .meta { color: #475569; margin: 0 0 1mm; }
        .notice { background: #fef3c7; margin: 4mm 0 0; padding: 2mm 3mm; }
        table { border-collapse: collapse; margin-top: 6mm; width: 100%; }
        th { border-bottom: 1px solid #334155; text-align: left; }
        th, td { padding: 1.5mm 2mm; }
        td { border-bottom: 1px solid #e2e8f0; }
        .number { text-align: right; white-space: nowrap; }
        .notes { color: #475569; font-size: 8pt; margin-top: 6mm; }
        .notes p { margin: 0 0 1mm; }
    </style>
</head>
<body>
    <h1>Monthly report — {{ $report->month->label() }}</h1>
    <p class="meta">Team: {{ $report->teamName }}</p>
    <p class="meta">Period: {{ $report->periodStart() }} to {{ $report->periodEnd() }} ({{ $report->month->timezone }})</p>
    <p class="meta">Generated: {{ $report->generated() }}</p>

    @if ($report->isPartial())
        <p class="notice">This month is still in progress: values cover the period up to the generation time and can change.</p>
    @endif

    @if ($report->rows === [])
        <p class="notice">This team has no monitors.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th scope="col">Monitor</th>
                    <th scope="col" class="number">SLA</th>
                    <th scope="col" class="number">Observed uptime</th>
                    <th scope="col" class="number">Coverage</th>
                    <th scope="col" class="number">Down</th>
                    <th scope="col" class="number">Unknown</th>
                    <th scope="col" class="number">Excluded</th>
                    <th scope="col" class="number">Incidents</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report->rows as $row)
                    <tr>
                        <td>{{ $row->monitorName }}</td>
                        <td class="number">{{ $row->sla() }}</td>
                        <td class="number">{{ $row->observed() }}</td>
                        <td class="number">{{ $row->coverage() }}</td>
                        <td class="number">{{ $row->down() }}</td>
                        <td class="number">{{ $row->unknown() }}</td>
                        <td class="number">{{ $row->excluded() }}</td>
                        <td class="number">{{ $row->incidents }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="notes">
        <p>SLA is determined only when all eligible time has a known state. Observed uptime is up time over up and down time. Coverage is the share of eligible time with a known state.</p>
        <p>Excluded time covers pauses, plan suspensions and maintenance windows, and is not part of eligible time. Incidents are those that started in the period.</p>
        <p>n/a: the data exists but the value cannot be determined. Unavailable: the retained data cannot reconstruct this period.</p>
    </div>
</body>
</html>
