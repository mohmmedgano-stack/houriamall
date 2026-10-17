<?php
declare(strict_types=1);

/* Minimal XLSX + CSV read/write without external libraries. */

/** Read the first worksheet of an .xlsx into an array of rows (arrays of strings). */
function xlsx_read(string $path, int $maxRows = 200000): array {
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new InvalidArgumentException('الملف مش Excel صالح (.xlsx)');
    $shared = [];
    if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = simplexml_load_string($ss, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        foreach ($x->si as $si) {
            if (isset($si->t)) { $shared[] = (string)$si->t; continue; }
            $s = ''; foreach ($si->r as $r) $s .= (string)$r->t; $shared[] = $s;
        }
    }
    // first sheet in workbook order
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $z->getFromName('xl/workbook.xml'); $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb && $rels) {
        $wbx = simplexml_load_string($wb, 'SimpleXMLElement', LIBXML_NONET);
        $first = $wbx->sheets->sheet[0] ?? null;
        if ($first) {
            $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $rx = simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
            foreach ($rx->Relationship as $rel) if ((string)$rel['Id'] === $rid) {
                $t = ltrim((string)$rel['Target'], '/');
                $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
            }
        }
    }
    $xml = $z->getFromName($sheetPath);
    $z->close();
    if ($xml === false) throw new InvalidArgumentException('مفيش شيت في ملف الـ Excel');
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $out = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = $ref !== '' ? xlsx_col_index(preg_replace('/\d+/', '', $ref)) : count($out);
            $t = (string)$c['t'];
            if ($t === 's') $v = $shared[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr') $v = (string)($c->is->t ?? '');
            else $v = (string)$c->v;
            $out[$col] = $v;
        }
        if ($out) { $max = max(array_keys($out)); $rows[] = array_map(fn($i) => $out[$i] ?? '', range(0, $max)); }
        else $rows[] = [];
        if (count($rows) >= $maxRows) break;
    }
    return $rows;
}
function xlsx_col_index(string $letters): int {
    $n = 0; foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}
function xlsx_col_letter(int $i): string {
    $s = ''; $i++;
    while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
    return $s;
}
/** Excel serial date → Y-m-d */
function excel_date(string $v): ?string {
    if (!is_numeric($v) || (float)$v < 20000 || (float)$v > 80000) return null;
    return gmdate('Y-m-d', (int)round(((float)$v - 25569) * 86400));
}

/** Write rows to an .xlsx file (first row bold header, right-to-left sheet). */
function xlsx_write(string $path, array $rows, string $sheetName = 'Sheet1', bool $rtl = true): void {
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"' . ($rtl ? ' rightToLeft="1"' : '') . '><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
    foreach (array_values($rows) as $ri => $row) {
        $sheet .= '<row r="' . ($ri + 1) . '">';
        foreach (array_values($row) as $ci => $v) {
            $ref = xlsx_col_letter($ci) . ($ri + 1);
            $style = $ri === 0 ? ' s="1"' : '';
            if ($v === null || $v === '') continue;
            if ((is_int($v) || is_float($v)) && is_finite((float)$v)) $sheet .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
            else $sheet .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $esc((string)$v) . '</t></is></c>';
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('cannot write xlsx');
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
    $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $z->close();
}

/** Read a CSV/TSV with delimiter + encoding detection (Ads Manager exports, Excel "CSV UTF-8", UTF-16). */
function csv_read(string $path, int $maxRows = 200000): array {
    $raw = (string)file_get_contents($path);
    if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1256');
    $first = strtok($raw, "\n") ?: '';
    $delim = ',';
    foreach (["\t", ';', ','] as $d) if (substr_count($first, $d) > substr_count($first, $delim)) $delim = $d;
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $rows[] = $r === [null] ? [] : $r;
        if (count($rows) >= $maxRows) break;
    }
    fclose($fh);
    return $rows;
}
function read_table_file(string $path, string $originalName): array {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') return xlsx_read($path);
    if (in_array($ext, ['csv', 'tsv', 'txt'], true)) return csv_read($path);
    if ($ext === 'xls') throw new InvalidArgumentException('صيغة .xls القديمة مش مدعومة. احفظ الملف .xlsx أو CSV');
    throw new InvalidArgumentException('ارفع ملف CSV أو XLSX');
}

/** Prevent spreadsheet formula injection when exporting user-provided text. */
function csv_safe(string $v): string { return preg_match('/^[=+\-@\t\r]/', $v) && !is_numeric($v) ? "'" . $v : $v; }

function send_csv(string $filename, array $rows): never {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel shows Arabic correctly
    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => is_string($v) ? csv_safe($v) : $v, $r), ',', '"', '');
    fclose($out);
    exit;
}
function send_xlsx(string $filename, array $rows, string $sheet = 'Data'): never {
    $tmp = tempnam(sys_get_temp_dir(), 'x');
    xlsx_write($tmp, $rows, $sheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp);
    exit;
}
