<?php
declare(strict_types=1);

/*
 * Report exports: CSV and PDF built from the same rows the screen shows.
 * Both return a string, so the download page and the email can share them.
 * PDF uses FPDF 1.9 (vendored in app\lib\fpdf, pure PHP, no extensions needed).
 */
require_once __DIR__ . '/../lib/fpdf/fpdf.php';

const REPORT_TZ = 'Australia/Melbourne';

function report_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(REPORT_TZ));
}

/** e.g. ctt_safety_20261022-1405.csv */
function export_filename(array $req, string $ext): string
{
    return 'ctt_' . $req['def']['key'] . '_' . report_now()->format('Ymd-Hi') . '.' . $ext;
}

/* ---------------------------------------------------------------- CSV */

/**
 * A cell that starts with = + - @ (or a tab/CR) could run as a formula when
 * the CSV is opened in Excel ("CSV injection"). Prefix it with ' so it stays text.
 * Negative numbers are left alone so numeric columns still sort as numbers.
 */
function csv_safe(string $v): string
{
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) {
        return "'" . $v;
    }
    return $v;
}

function export_csv(array $req, array $rows, ?array $totals): string
{
    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows names like O'Connor correctly
    $cols = $req['cols'];
    fputcsv($out, array_map(fn($c) => $c['label'], $cols), ',', '"', '');
    foreach (array_merge($rows, $totals ? [$totals] : []) as $r) {
        $line = [];
        foreach ($cols as $k => $c) {
            $line[] = csv_safe(report_cell($c, $r[$k] ?? null));
        }
        fputcsv($out, $line, ',', '"', '');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

/* ---------------------------------------------------------------- PDF */

/** FPDF's core fonts are Windows-1252, so convert from UTF-8 first. */
function pdf_text(string $s): string
{
    $c = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    return $c === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $c;
}

final class ReportPdf extends FPDF
{
    public string $reportTitle = '';
    public string $stamp = '';
    public string $banner = '';
    public array $headCols = [];    // [label, width, align]

    public function Header(): void
    {
        $this->SetFont('Helvetica', 'B', 13);
        $this->Cell(0, 7, pdf_text(APP_NAME . ': ' . $this->reportTitle), 0, 1);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(90, 100, 110);
        $this->Cell(0, 4, pdf_text($this->stamp), 0, 1);
        $this->SetTextColor(150, 40, 30);
        $this->Cell(0, 4, pdf_text($this->banner), 0, 1);
        $this->SetTextColor(0);
        $this->Ln(2);
        if ($this->headCols) {
            $this->tableHead();
        }
    }

    public function Footer(): void
    {
        $this->SetY(-10);
        $this->SetFont('Helvetica', 'I', 7);
        $this->SetTextColor(120);
        $this->Cell(0, 5, pdf_text('Synthetic data for COS20015 coursework only. No real people.'), 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
        $this->SetTextColor(0);
    }

    /** Header row of the table; repeated at the top of every page. */
    public function tableHead(): void
    {
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(232, 242, 246);
        $h = 3.6 * max(array_map(fn($c) => $this->lines($c[1], $c[0]), $this->headCols));
        $this->row(array_column($this->headCols, 0), $h, true, true);
        $this->SetFont('Helvetica', '', 7);
    }

    /** Number of wrapped lines a text needs in a column of width $w. */
    public function lines(float $w, string $txt): int
    {
        $max = $w - 2 * $this->cMargin;
        $n = 1;
        $line = '';
        foreach (preg_split('/\s+/', trim($txt)) as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($this->GetStringWidth($try) <= $max || $line === '') {
                $line = $try;
                // a single word wider than the column wraps by characters
                while ($this->GetStringWidth($line) > $max && strlen($line) > 1) {
                    $n++;
                    $line = substr($line, (int)floor(strlen($line) * $max / $this->GetStringWidth($line)));
                }
            } else {
                $n++;
                $line = $word;
            }
        }
        return $n;
    }

    /** One table row whose cells wrap; all cells share the tallest cell's height. */
    public function row(array $cells, float $h, bool $fill = false, bool $isHead = false): void
    {
        $x0 = $this->GetX();
        $y0 = $this->GetY();
        foreach ($this->headCols as $i => [, $w, $align]) {
            $x = $this->GetX();
            $this->Rect($x, $y0, $w, $h, $fill ? 'DF' : 'D');
            $this->MultiCell($w, 3.6, $cells[$i], 0, $isHead ? 'L' : $align);
            $this->SetXY($x + $w, $y0);
        }
        $this->SetXY($x0, $y0 + $h);
    }

    public function ensureSpace(float $h): void
    {
        if ($this->GetY() + $h > $this->GetPageHeight() - 14) {
            $this->AddPage($this->CurOrientation);
        }
    }
}

function export_pdf(array $req, array $rows, ?array $totals, string $summary, array $user): string
{
    $pdf = new ReportPdf('L', 'mm', 'A4');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false);   // rows are placed by hand so a row never splits across pages
    $pdf->AliasNbPages();
    $pdf->SetTitle(APP_NAME . ': ' . $req['def']['title'], true);
    $pdf->SetAuthor($user['name'] . ' (' . $user['role'] . ')', true);
    $pdf->SetCreator('COS20015 Phase-05 web app (FPDF ' . FPDF::VERSION . ')', true);

    $pdf->reportTitle = $req['def']['title'];
    $pdf->stamp = 'Generated ' . report_now()->format('D j M Y, H:i T') . ' by ' . $user['name'] . ' (' . $user['role'] . ')';
    $pdf->banner = $req['identified']
        ? 'CONTAINS IDENTIFIABLE DATA (subject names). Admin export: handle under the study privacy plan.'
        : 'Pseudonymised: subjects are shown by code only.';

    // Scale the defined column widths so the table fills the printable width.
    $avail = $pdf->GetPageWidth() - 20;
    $sum = array_sum(array_map(fn($c) => $c['w'] ?? 15, $req['cols']));
    $head = [];
    foreach ($req['cols'] as $c) {
        $head[] = [pdf_text($c['label']), ($c['w'] ?? 15) * $avail / $sum, !empty($c['num']) ? 'R' : 'L'];
    }

    // First page: filters and summary, then the table.
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 8);
    $filters = [];
    foreach (report_active_filters($req) as $label => $v) {
        $filters[] = "$label: $v";
    }
    $pdf->MultiCell(0, 4, pdf_text('Filters: ' . implode('; ', $filters)), 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->MultiCell(0, 4, pdf_text($summary), 0, 'L');
    $pdf->Ln(2);
    $pdf->headCols = $head;
    $pdf->tableHead();

    $all = array_merge($rows, $totals ? [$totals] : []);
    $last = count($all) - 1;
    foreach ($all as $i => $r) {
        $isTotal = $totals && $i === $last;
        $cells = [];
        $h = 0;
        $pdf->SetFont('Helvetica', $isTotal ? 'B' : '', 7);
        foreach (array_keys($req['cols']) as $j => $k) {
            $cells[$j] = pdf_text(report_cell($req['cols'][$k], $r[$k] ?? null));
            $h = max($h, 3.6 * $pdf->lines($head[$j][1], $cells[$j]));
        }
        $pdf->ensureSpace($h);                          // may start a new page (and reset the font)
        $pdf->SetFont('Helvetica', $isTotal ? 'B' : '', 7);
        if ($isTotal) {
            $pdf->SetFillColor(232, 242, 246);
        }
        $pdf->row($cells, $h, $isTotal);
    }
    if (!$rows) {
        $pdf->Cell(0, 6, 'No rows match these filters.', 1, 1);
    }

    // Definitions under the table, so the PDF explains itself without the app.
    $pdf->headCols = [];
    $pdf->ensureSpace(8 + 4 * count($req['def']['notes']));
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->Cell(0, 4, 'How this report is calculated', 0, 1);
    $pdf->SetFont('Helvetica', '', 7);
    foreach ($req['def']['notes'] as $n) {
        $pdf->MultiCell(0, 3.6, pdf_text('- ' . $n), 0, 'L');
    }
    return $pdf->Output('S');
}
