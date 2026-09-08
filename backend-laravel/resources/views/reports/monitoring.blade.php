{{--
    MUWASCO Monthly Monitoring Report (M13) — PDF layout.
    Preserves the legacy TCPDF-generated layout:
      - fixed institutional header
      - one table header row (NUMBER | PARAMETERS | <month>+)
      - data rows grouped by category (no section title rows), ordered by
        category display_order then parameter code
      - missing values render as '-'
      - mid-document signature break after the (configurable) N-th category
      - final signatories driven from config/reports.php
--}}
@php
    $monthsCount = $periods->count();
    $monthCol    = $monthsCount > 0 ? (55 / $monthsCount) : 10;

    $labels = $periods->map(fn ($p) => strtoupper(
        \Illuminate\Support\Carbon::parse($p->month_year)->format('F Y')
    ))->all();

    $agency    = config('reports.agency');
    $title     = config('reports.document_title');
    $scope     = config('reports.document_scope');
    $wsp       = config('reports.wsp_name');
    $breakAt   = (int) config('reports.after_category_break', 5);
    $midSig    = config('reports.signatories.mid');
    $finalSig  = config('reports.signatories.final', []);

    $headerRow = '<tr style="background-color:#f2f2f2;">'
        .'<th style="width:10%;">NUMBER</th>'
        .'<th style="width:35%;">PARAMETERS</th>'
        .collect($labels)->map(fn ($l) => '<th style="width:'.$monthCol.'%;">'.e($l).'</th>')->implode('')
        .'</tr>';

    $tableOpen = false;
    $catCount  = 0;
    $parts     = [];

    foreach ($categories as $category) {
        if (empty($category['parameters'])) continue; // legacy: skip empty categories
        $catCount++;

        if (! $tableOpen) {
            $parts[] = '<table cellpadding="4" style="border-collapse:collapse;width:100%;font-size:8pt;">'.$headerRow;
            $tableOpen = true;
        }

        foreach ($category['parameters'] as $parameter) {
            $parts[] = '<tr>'
                .'<td><strong style="color:#0066cc;">'.e($parameter['code']).'</strong></td>'
                .'<td>'.e($parameter['label']).'</td>';
            foreach ($periods as $i => $period) {
                $parts[] = '<td>'.e($parameter['values'][$i] ?? '-').'</td>';
            }
            $parts[] = '</tr>';
        }

        // Mid-document signature break (legacy: after the 5th category, Water Quality).
        if ($catCount === $breakAt && $tableOpen) {
            $parts[] = '</table>';
            $parts[] = '<div style="margin:20px 0;page-break-after:always;">'
                .'<p><strong>'.$midSig['label'].'</strong></p>'
                .'<p><strong>'.$midSig['name'].'</strong></p>'
                .'<p>SIGN&nbsp;......................................&nbsp;&nbsp;DATE&nbsp;......................................</p>'
                .'</div>';
            $tableOpen = false;
        }
    }

    if ($tableOpen) {
        $parts[] = '</table>';
    }

    $finalParts = '<div style="margin-top:20px;">'
        .'<p><strong>Data verified by:</strong></p>';
    foreach ($finalSig as $signatory) {
        $finalParts .= '<p><strong>'.e($signatory['name']).'</strong></p>'
            .'<p>Sign&nbsp;......................................................&nbsp;&nbsp;Date&nbsp;....................................................</p>'
            .'<br>';
    }
    $finalParts .= '</div>';

    $html = '<div style="text-align:center;margin-bottom:15px;">'
        .'<h2 style="margin:0 0 5px;">'.e($agency).'</h2>'
        .'<h3 style="margin:0 0 5px;">'.e($title).'</h3>'
        .'<h4 style="margin:0 0 5px;">'.e($scope).'</h4>'
        .'<p style="margin:0;"><strong>WSP&apos;S NAME: '.e($wsp).' &nbsp;&nbsp; DATE OF MONITORING: '.e(now()->format('F Y')).'</strong></p>'
        .'</div>'
        .implode('', $parts)
        .$finalParts
        .'<p style="margin-top:10px;font-size:7pt;color:#666;">Generated: '.e($generated_at).'</p>';
@endphp
{!! $html !!}