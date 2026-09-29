<?php
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/StockMonitoringReport.php';

if (!Auth::check()) {
    http_response_code(401);
    exit('Authentication required.');
}

try {
    $report = StockMonitoringReport::load($_GET);
} catch (Throwable $e) {
    http_response_code(422);
    exit('Unable to export Stock Monitoring right now.');
}

$isOwner = (bool)$report['is_owner'];
$rows = [];
$title = $isOwner ? 'Overall Stock Monitoring' : 'Stock Monitoring';
$scope = $isOwner ? (string)$report['selected_branch_name'] : (string)(Auth::user()['branch_name'] ?? 'Assigned Branch');
$rows[] = [['v' => $title, 's' => 3]];
$rows[] = [['v' => 'Date', 's' => 1], ['v' => (string)$report['formatted_date']], ['v' => 'Scope', 's' => 1], ['v' => $scope]];
$rows[] = [];

if ($isOwner) {
    $headers = [['v' => 'Brand', 's' => 1], ['v' => 'Model', 's' => 1]];
    foreach ($report['display_branches'] as $branch) $headers[] = ['v' => (string)$branch['name'], 's' => 1];
    $headers[] = ['v' => 'Overall Total', 's' => 1];
    $rows[] = $headers;

    foreach ($report['groups'] as $groupName => $groupRows) {
        $groupBranchTotals = [];
        foreach ($report['display_branches'] as $branch) $groupBranchTotals[(int)$branch['id']] = 0;
        $groupOverall = 0;
        foreach ($groupRows as $row) {
            $line = [['v' => (string)$groupName], ['v' => StockMonitoringReport::displayModel($row)]];
            $rowOverall = 0;
            foreach ($report['display_branches'] as $branch) {
                $bid = (int)$branch['id'];
                $qty = (int)($report['branch_ending'][(int)$row['product_id']][$bid] ?? 0);
                $groupBranchTotals[$bid] += $qty;
                $rowOverall += $qty;
                $line[] = ['v' => $qty];
            }
            $line[] = ['v' => $rowOverall, 's' => 2];
            $groupOverall += $rowOverall;
            $rows[] = $line;
        }
        $totalLine = [['v' => strtoupper((string)$groupName) . ' TOTAL', 's' => 2], ['v' => '', 's' => 2]];
        foreach ($report['display_branches'] as $branch) $totalLine[] = ['v' => (int)($groupBranchTotals[(int)$branch['id']] ?? 0), 's' => 2];
        $totalLine[] = ['v' => $groupOverall, 's' => 2];
        $rows[] = $totalLine;
    }

    $grand = [['v' => 'GRAND TOTAL (All Brands)', 's' => 4], ['v' => '', 's' => 4]];
    foreach ($report['display_branches'] as $branch) $grand[] = ['v' => (int)($report['branch_totals'][(int)$branch['id']] ?? 0), 's' => 4];
    $grand[] = ['v' => (int)$report['totals']['ending'], 's' => 4];
    $rows[] = $grand;
} else {
    $rows[] = [
        ['v' => 'Brand', 's' => 1],
        ['v' => 'Model', 's' => 1],
        ['v' => 'Opening Stock', 's' => 1],
        ['v' => 'IN', 's' => 1],
        ['v' => 'OUT', 's' => 1],
        ['v' => 'SOLD', 's' => 1],
        ['v' => 'Ending Stock', 's' => 1],
    ];

    foreach ($report['groups'] as $groupName => $groupRows) {
        $groupTotal = ['opening'=>0,'in'=>0,'out'=>0,'sold'=>0,'ending'=>0];
        foreach ($groupRows as $row) {
            $groupTotal['opening'] += (int)$row['opening_stock'];
            $groupTotal['in'] += (int)$row['in_qty'];
            $groupTotal['out'] += (int)$row['out_qty'];
            $groupTotal['sold'] += (int)$row['sold_qty'];
            $groupTotal['ending'] += (int)$row['ending_stock'];
            $rows[] = [
                ['v' => (string)$groupName],
                ['v' => StockMonitoringReport::displayModel($row)],
                ['v' => (int)$row['opening_stock']],
                ['v' => (int)$row['in_qty']],
                ['v' => (int)$row['out_qty']],
                ['v' => (int)$row['sold_qty']],
                ['v' => (int)$row['ending_stock'], 's' => 2],
            ];
        }
        $rows[] = [
            ['v' => strtoupper((string)$groupName) . ' TOTAL', 's' => 2],
            ['v' => '', 's' => 2],
            ['v' => $groupTotal['opening'], 's' => 2],
            ['v' => $groupTotal['in'], 's' => 2],
            ['v' => $groupTotal['out'], 's' => 2],
            ['v' => $groupTotal['sold'], 's' => 2],
            ['v' => $groupTotal['ending'], 's' => 2],
        ];
    }
    $rows[] = [
        ['v' => 'GRAND TOTAL (All Brands)', 's' => 4],
        ['v' => '', 's' => 4],
        ['v' => (int)$report['totals']['opening'], 's' => 4],
        ['v' => (int)$report['totals']['in'], 's' => 4],
        ['v' => (int)$report['totals']['out'], 's' => 4],
        ['v' => (int)$report['totals']['sold'], 's' => 4],
        ['v' => (int)$report['totals']['ending'], 's' => 4],
    ];
}

$slugScope = preg_replace('/[^a-z0-9]+/i', '-', strtolower($scope));
$slugScope = trim((string)$slugScope, '-') ?: 'stock';
$baseName = 'malbcoff-stock-monitoring-' . $slugScope . '-' . $report['date'];

if (class_exists('ZipArchive')) {
    send_stock_monitoring_xlsx($rows, $baseName . '.xlsx', $title);
}
send_stock_monitoring_excel_html($rows, $baseName . '.xls', $title);

function send_stock_monitoring_xlsx(array $rows, string $filename, string $title): never
{
    $tmp = tempnam(sys_get_temp_dir(), 'malbcoff-xlsx-');
    if ($tmp === false) send_stock_monitoring_excel_html($rows, preg_replace('/\.xlsx$/', '.xls', $filename), $title);

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        send_stock_monitoring_excel_html($rows, preg_replace('/\.xlsx$/', '.xls', $filename), $title);
    }

    $sheetXml = stock_monitoring_sheet_xml($rows);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Stock Monitoring" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/styles.xml', stock_monitoring_styles_xml());
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . stock_monitoring_xml($title) . '</dc:title><dc:creator>Malbcoff Trading</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created></cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Malbcoff Trading POS &amp; Inventory System</Application></Properties>');
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function stock_monitoring_sheet_xml(array $rows): string
{
    $body = '';
    $maxCols = 1;
    foreach ($rows as $rIndex => $row) {
        $rowNo = $rIndex + 1;
        $maxCols = max($maxCols, count($row));
        $cells = '';
        foreach ($row as $cIndex => $cell) {
            $col = stock_monitoring_excel_col($cIndex + 1);
            $ref = $col . $rowNo;
            $value = $cell['v'] ?? '';
            $style = (int)($cell['s'] ?? 0);
            if (is_int($value) || is_float($value)) {
                $cells .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
            } else {
                $cells .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . stock_monitoring_xml((string)$value) . '</t></is></c>';
            }
        }
        $body .= '<row r="' . $rowNo . '">' . $cells . '</row>';
    }

    $lastCol = stock_monitoring_excel_col($maxCols);
    $lastRow = max(1, count($rows));
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols><col min="1" max="1" width="18" customWidth="1"/><col min="2" max="2" width="40" customWidth="1"/><col min="3" max="' . max(3, $maxCols) . '" width="15" customWidth="1"/></cols>'
        . '<sheetData>' . $body . '</sheetData>'
        . '<autoFilter ref="A4:' . $lastCol . $lastRow . '"/>'
        . '</worksheet>';
}

function stock_monitoring_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF1557B0"/><name val="Calibri"/></font></fonts>'
        . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1769E8"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEEF5FF"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE3EEFF"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        . '</styleSheet>';
}

function stock_monitoring_excel_col(int $number): string
{
    $label = '';
    while ($number > 0) {
        $number--;
        $label = chr(65 + ($number % 26)) . $label;
        $number = intdiv($number, 26);
    }
    return $label;
}

function stock_monitoring_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function send_stock_monitoring_excel_html(array $rows, string $filename, string $title): never
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="utf-8"><title>' . e($title) . '</title><style>table{border-collapse:collapse;font-family:Arial,sans-serif;font-size:11px}td{border:1px solid #d9e2f0;padding:5px 8px}.s1{font-weight:bold;background:#1769e8;color:#fff}.s2{font-weight:bold;background:#eef5ff;color:#1557b0}.s3{font-weight:bold;font-size:16px;color:#1557b0}.s4{font-weight:bold;background:#e3eeff;color:#123c78}</style></head><body><table>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            $style = (int)($cell['s'] ?? 0);
            echo '<td class="s' . $style . '">' . e((string)($cell['v'] ?? '')) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}
