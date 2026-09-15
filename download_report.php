<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header('Location: index.php');
    exit();
}
require_priv('reports');

$list_type = isset($_GET['list_type']) ? (string) $_GET['list_type'] : 'all';
if (!in_array($list_type, ['all', 'volunteers', 'participants', 'joinees'], true)) {
    $list_type = 'all';
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'docx')));
if (!in_array($format, ['docx', 'pdf'], true)) {
    $format = 'docx';
}

$event_ids = [];
if (isset($_GET['event_id'])) {
    $event_ids = [intval($_GET['event_id'])];
} elseif (isset($_GET['event_ids'])) {
    $event_ids = array_map('intval', explode(',', (string) $_GET['event_ids']));
}
$event_ids = array_values(array_filter($event_ids, static fn ($id) => $id > 0));

if (empty($event_ids)) {
    die('No events selected for report generation.');
}

function report_esc_xml($text): string
{
    return htmlspecialchars((string) $text, ENT_XML1, 'UTF-8');
}

function report_esc_html($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/** Public base URL for files under /admin (this script lives in admin root). */
function report_public_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $adminBase = dirname($script);
    if ($adminBase === '/' || $adminBase === '.' || $adminBase === '') {
        $adminBase = '';
    }
    return $scheme . '://' . $host . rtrim($adminBase, '/');
}

function report_public_file_url(?string $file_path): string
{
    if ($file_path === null || trim($file_path) === '') {
        return '';
    }
    $file_path = str_replace('\\', '/', trim($file_path));
    if (preg_match('#^https?://#i', $file_path)) {
        return $file_path;
    }
    $file_path = ltrim($file_path, '/');
    $base = report_public_base_url();
    return $base === '' ? $file_path : ($base . '/' . $file_path);
}

function report_resolve_local_path(?string $file_path): ?string
{
    if ($file_path === null || trim($file_path) === '') {
        return null;
    }
    $rel = str_replace('\\', '/', ltrim(trim($file_path), '/'));
    if (preg_match('#^https?://#i', $rel)) {
        return null;
    }
    $candidates = [
        __DIR__ . '/' . $rel,
        __DIR__ . '/../' . $rel,
    ];
    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function report_is_image_file(?string $file_path, ?string $file_type = null): bool
{
    if ($file_type && strpos(strtolower($file_type), 'image') !== false) {
        return true;
    }
    return (bool) preg_match('/\.(jpe?g|png|gif|webp)$/i', (string) $file_path);
}

function report_is_pdf_file(?string $file_path, ?string $file_type = null): bool
{
    if ($file_type && strpos(strtolower($file_type), 'pdf') !== false) {
        return true;
    }
    return (bool) preg_match('/\.pdf$/i', (string) $file_path);
}

function report_attendance_label($attended): string
{
    if ($attended === null || $attended === '') {
        return 'Unmarked';
    }
    return ((int) $attended === 1) ? 'Present' : 'Absent';
}

function report_multiline_plain(?string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    $parts = preg_split("/\n/", $text) ?: [''];
    return array_map(static fn ($line) => (string) $line, $parts);
}

/**
 * Event banners are saved under admin/uploads/events.
 */
function report_resolve_banner_file_path(string $fn): ?string
{
    $candidates = [
        __DIR__ . '/uploads/events/' . $fn,
        __DIR__ . '/../uploads/events/' . $fn,
    ];
    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * @return array{bytes: string, content_type: string, ext: string}|null
 */
function report_normalize_image_for_docx(string $bytes, string $extFromName): ?array
{
    $ext = strtolower($extFromName);
    if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($bytes);
        if ($info !== false && !empty($info['mime'])) {
            $mime = (string) $info['mime'];
            $mimeExt = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];
            if (isset($mimeExt[$mime])) {
                $ext = $mimeExt[$mime];
            }
        }
    }

    $direct = [
        'jpg' => ['image/jpeg', 'jpeg'],
        'jpeg' => ['image/jpeg', 'jpeg'],
        'png' => ['image/png', 'png'],
        'gif' => ['image/gif', 'gif'],
    ];
    if (isset($direct[$ext])) {
        return ['bytes' => $bytes, 'content_type' => $direct[$ext][0], 'ext' => $direct[$ext][1]];
    }

    if ($ext === 'webp' && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return null;
        }
        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($im);
        }
        imagealphablending($im, true);
        imagesavealpha($im, false);
        ob_start();
        imagejpeg($im, null, 90);
        $jpg = ob_get_clean();
        imagedestroy($im);
        if ($jpg === false || $jpg === '') {
            return null;
        }
        return ['bytes' => $jpg, 'content_type' => 'image/jpeg', 'ext' => 'jpeg'];
    }

    return null;
}

/**
 * @return array{bytes: string, content_type: string, ext: string}|null
 */
function report_banner_image_payload(array $event): ?array
{
    $raw = $event['banners'] ?? '[]';
    $banners = json_decode((string) $raw, true);
    if (!is_array($banners) || empty($banners[0])) {
        return null;
    }
    $first = trim((string) $banners[0]);
    $first = preg_replace('/[#?].*$/', '', $first) ?? $first;
    $fn = basename(str_replace('\\', '/', $first));
    if ($fn === '' || $fn === '.' || $fn === '..') {
        return null;
    }
    $path = report_resolve_banner_file_path($fn);
    if ($path === null) {
        return null;
    }
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return null;
    }
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));

    return report_normalize_image_for_docx($bytes, $ext);
}

/**
 * @return array{cx: int, cy: int}
 */
function report_docx_image_extent_emu(string $bytes): array
{
    $maxW = 400;
    $fallbackH = 260;
    if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($bytes);
        if ($info !== false && !empty($info[0]) && !empty($info[1])) {
            $w = (int) $info[0];
            $h = (int) $info[1];
            if ($w > $maxW) {
                $h = (int) round($h * ($maxW / $w));
                $w = $maxW;
            }
            return [
                'cx' => (int) round($w * 9525),
                'cy' => (int) round($h * 9525),
            ];
        }
    }
    return ['cx' => (int) round($maxW * 9525), 'cy' => (int) round($fallbackH * 9525)];
}

function report_docx_banner_paragraph(string $embedRid, int $cxEmu, int $cyEmu, int $docPrId): string
{
    return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="200"/></w:pPr><w:r><w:drawing>'
        . '<wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<wp:extent cx="' . $cxEmu . '" cy="' . $cyEmu . '"/>'
        . '<wp:docPr id="' . $docPrId . '" name="Event image"/>'
        . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
        . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="Image"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . report_esc_xml($embedRid) . '"/>'
        . '<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cxEmu . '" cy="' . $cyEmu . '"/></a:xfrm>'
        . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
        . '</pic:pic></a:graphicData></a:graphic>'
        . '</wp:inline></w:drawing></w:r></w:p>';
}

function report_docx_heading(string $text, int $size = 28): string
{
    return '<w:p><w:pPr><w:spacing w:before="360" w:after="160"/></w:pPr>'
        . '<w:r><w:rPr><w:b/><w:sz w:val="' . $size . '"/><w:color w:val="FF5F15"/></w:rPr>'
        . '<w:t>' . report_esc_xml($text) . '</w:t></w:r></w:p>';
}

function report_docx_paragraphs(?string $text, string $emptyFallback = ''): string
{
    $lines = report_multiline_plain($text);
    if ($text === null || trim((string) $text) === '') {
        if ($emptyFallback === '') {
            return '';
        }
        return '<w:p><w:r><w:rPr><w:i/><w:color w:val="888888"/></w:rPr><w:t>' . report_esc_xml($emptyFallback) . '</w:t></w:r></w:p>';
    }
    $out = '';
    foreach ($lines as $line) {
        $out .= '<w:p><w:r><w:t xml:space="preserve">' . report_esc_xml($line) . '</w:t></w:r></w:p>';
    }
    return $out;
}

function report_docx_key_value_table(array $rows): string
{
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>
        <w:tblBorders>
            <w:top w:val="single" w:sz="4" w:color="CCCCCC"/>
            <w:left w:val="single" w:sz="4" w:color="CCCCCC"/>
            <w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>
            <w:right w:val="single" w:sz="4" w:color="CCCCCC"/>
            <w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>
            <w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>
        </w:tblBorders></w:tblPr>';
    foreach ($rows as $row) {
        $xml .= '<w:tr>
            <w:tc><w:tcPr><w:shd w:fill="FFF3EC"/><w:tcW w:w="1800" w:type="dxa"/></w:tcPr>
                <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . report_esc_xml($row[0]) . '</w:t></w:r></w:p></w:tc>
            <w:tc><w:tcPr><w:tcW w:w="4200" w:type="dxa"/></w:tcPr>
                <w:p><w:r><w:t xml:space="preserve">' . report_esc_xml($row[1]) . '</w:t></w:r></w:p></w:tc>
        </w:tr>';
    }
    $xml .= '</w:tbl>';
    return $xml;
}

function report_docx_data_table(array $headers, array $rows): string
{
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>
        <w:tblBorders>
            <w:top w:val="single" w:sz="4" w:color="CCCCCC"/>
            <w:left w:val="single" w:sz="4" w:color="CCCCCC"/>
            <w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>
            <w:right w:val="single" w:sz="4" w:color="CCCCCC"/>
            <w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>
            <w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>
        </w:tblBorders></w:tblPr>';
    $xml .= '<w:tr>';
    foreach ($headers as $h) {
        $xml .= '<w:tc><w:tcPr><w:shd w:fill="FF5F15"/></w:tcPr>
            <w:p><w:r><w:rPr><w:b/><w:color w:val="FFFFFF"/></w:rPr><w:t>' . report_esc_xml($h) . '</w:t></w:r></w:p></w:tc>';
    }
    $xml .= '</w:tr>';
    foreach ($rows as $cells) {
        $xml .= '<w:tr>';
        foreach ($cells as $c) {
            $xml .= '<w:tc><w:p><w:r><w:t xml:space="preserve">' . report_esc_xml($c) . '</w:t></w:r></w:p></w:tc>';
        }
        $xml .= '</w:tr>';
    }
    $xml .= '</w:tbl>';
    return $xml;
}

/**
 * Append hyperlink relationship + paragraph. Mutates $doc_rels_lines / $next_rid by reference.
 */
function report_docx_append_link_paragraph(
    string &$document,
    array &$doc_rels_lines,
    int &$next_rid,
    string $label,
    string $url
): void {
    if ($url === '') {
        $document .= '<w:p><w:r><w:t>' . report_esc_xml($label) . '</w:t></w:r></w:p>';
        return;
    }
    $rid = 'rId' . $next_rid;
    $next_rid++;
    $doc_rels_lines[] = '    <Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . report_esc_xml($url) . '" TargetMode="External"/>';
    $document .= '<w:p><w:hyperlink r:id="' . $rid . '">'
        . '<w:r><w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr>'
        . '<w:t>' . report_esc_xml($label) . '</w:t></w:r>'
        . '</w:hyperlink></w:p>';
}

function report_enrich_attachment(array $row): array
{
    $path = (string) ($row['file_path'] ?? '');
    $type = (string) ($row['file_type'] ?? '');
    $name = trim((string) ($row['original_name'] ?? ''));
    if ($name === '') {
        $name = $path !== '' ? basename($path) : 'Attachment';
    }
    $url = report_public_file_url($path);
    return [
        'name' => $name,
        'file_path' => $path,
        'file_type' => $type,
        'url' => $url,
        'is_image' => report_is_image_file($path, $type),
        'is_pdf' => report_is_pdf_file($path, $type),
        'local_path' => report_resolve_local_path($path),
    ];
}

// ——— Collect event data ———
$events_data = [];
foreach ($event_ids as $event_id) {
    $event_query = $conn->query("
        SELECT e.*, u.full_name as organizer_name, u.email as organizer_email, u.phone as organizer_phone
        FROM events e
        JOIN users u ON e.organizer_id = u.id
        WHERE e.id = $event_id
    ");
    if (!$event_query || $event_query->num_rows === 0) {
        continue;
    }
    $event = $event_query->fetch_assoc();

    $attendees = [];
    if ($list_type === 'all' || $list_type === 'joinees') {
        $attendees_query = $conn->query("
            SELECT u.full_name, u.email, u.phone, a.joined_at
            FROM attendees a
            JOIN users u ON a.user_id = u.id
            WHERE a.event_id = $event_id
            ORDER BY a.joined_at ASC
        ");
        if ($attendees_query) {
            while ($attendee = $attendees_query->fetch_assoc()) {
                $attendees[] = $attendee;
            }
        }
    }

    $volunteers = [];
    if ($list_type === 'all' || $list_type === 'volunteers') {
        $volunteers_query = $conn->query("
            SELECT u.full_name, u.email, u.phone, v.role, v.status, v.attended, v.attendance_marked_at
            FROM volunteers v
            JOIN users u ON v.user_id = u.id
            WHERE v.event_id = $event_id
            ORDER BY u.full_name ASC
        ");
        if ($volunteers_query) {
            while ($volunteer = $volunteers_query->fetch_assoc()) {
                $volunteers[] = $volunteer;
            }
        }
    }

    $participants = [];
    if ($list_type === 'all' || $list_type === 'participants') {
        $participants_query = $conn->query("
            SELECT u.full_name, u.email, u.phone, p.department_class, p.status, p.attended, p.attendance_marked_at
            FROM participant p
            JOIN users u ON p.user_id = u.id
            WHERE p.event_id = $event_id
            ORDER BY u.full_name ASC
        ");
        if ($participants_query) {
            while ($p = $participants_query->fetch_assoc()) {
                $participants[] = $p;
            }
        }
    }

    $review_files = [];
    $rf_res = @$conn->query("SELECT original_name, file_type, file_path FROM event_review_files WHERE event_id = $event_id ORDER BY uploaded_at ASC");
    if ($rf_res) {
        while ($r = $rf_res->fetch_assoc()) {
            $review_files[] = report_enrich_attachment($r);
        }
    }

    $meeting_minutes = [];
    $mm_res = @$conn->query(
        "SELECT mm.*, u.full_name AS submitted_by_name
         FROM meeting_minutes mm
         LEFT JOIN users u ON u.id = mm.submitted_by
         WHERE mm.event_id = $event_id
         ORDER BY mm.created_at DESC, mm.id DESC"
    );
    if ($mm_res) {
        while ($r = $mm_res->fetch_assoc()) {
            $attach = null;
            if (!empty($r['file_path'])) {
                $attach = report_enrich_attachment([
                    'original_name' => basename((string) $r['file_path']),
                    'file_type' => '',
                    'file_path' => $r['file_path'],
                ]);
            }
            $meeting_minutes[] = [
                'content' => (string) ($r['content'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
                'submitted_by_name' => (string) ($r['submitted_by_name'] ?? ''),
                'created_at' => $r['created_at'] ?? null,
                'reviewed_at' => $r['reviewed_at'] ?? null,
                'attachment' => $attach,
            ];
        }
    }

    $events_data[] = [
        'event' => $event,
        'attendees' => $attendees,
        'volunteers' => $volunteers,
        'participants' => $participants,
        'review_files' => $review_files,
        'meeting_minutes' => $meeting_minutes,
    ];
}

if (empty($events_data)) {
    die('No valid events found.');
}

$list_label = [
    'all' => 'Full Event Report',
    'volunteers' => 'Volunteers Report',
    'participants' => 'Participants Report',
    'joinees' => 'Joinees (Attendees) Report',
];
$label_map = ['all' => '', 'volunteers' => '_Volunteers', 'participants' => '_Participants', 'joinees' => '_Joinees'];
$timestamp = date('Y-m-d_H-i-s');
$base_name = count($events_data) === 1
    ? (preg_replace('/[^A-Za-z0-9_\-]/', '_', $events_data[0]['event']['title']) . '_Report' . $label_map[$list_type] . '_' . $timestamp)
    : ('Events_Report' . $label_map[$list_type] . '_' . $timestamp);

function report_build_html(array $events_data, string $list_type, array $list_label): string
{
    $generated = date('M d, Y h:i A');
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; line-height: 1.45; }
        h1 { color: #FF5F15; font-size: 20px; margin: 0 0 4px; }
        h2 { color: #FF5F15; font-size: 14px; margin: 18px 0 8px; border-bottom: 1px solid #ffd5c2; padding-bottom: 4px; }
        h3 { font-size: 12px; margin: 14px 0 6px; color: #0f172a; }
        .subtitle { color: #64748b; margin-bottom: 14px; }
        .meta { color: #64748b; font-size: 10px; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0 14px; }
        th, td { border: 1px solid #dbe3ef; padding: 6px 8px; vertical-align: top; text-align: left; }
        th { background: #FF5F15; color: #fff; font-size: 10px; }
        td.label { width: 28%; background: #fff3ec; font-weight: bold; }
        .block { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; margin: 8px 0 12px; }
        .block.review { background: #f0fdf4; border-color: #bbf7d0; }
        .block.minutes { background: #eff6ff; border-color: #bfdbfe; }
        .muted { color: #64748b; font-size: 10px; }
        a { color: #0563C1; text-decoration: underline; word-break: break-all; }
        .poster { max-width: 220px; max-height: 280px; display: block; margin: 8px auto 16px; }
        .page-break { page-break-after: always; }
        .badge { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .badge.approved { background: #dcfce7; color: #166534; }
        .badge.pending { background: #fef3c7; color: #92400e; }
        .badge.rejected { background: #fee2e2; color: #991b1b; }
        ul.attach { margin: 6px 0 0 16px; padding: 0; }
    </style></head><body>';

    foreach ($events_data as $index => $data) {
        $event = $data['event'];
        $html .= '<div' . ($index < count($events_data) - 1 ? ' class="page-break"' : '') . '>';
        $html .= '<h1>' . report_esc_html($event['title']) . '</h1>';
        $html .= '<div class="subtitle">' . report_esc_html($list_label[$list_type]) . '</div>';
        $html .= '<div class="meta">Generated: ' . report_esc_html($generated) . '</div>';

        $banner = report_banner_image_payload($event);
        if ($banner !== null) {
            $html .= '<img class="poster" src="data:' . report_esc_html($banner['content_type']) . ';base64,' . base64_encode($banner['bytes']) . '" alt="Event poster">';
        }

        $event_when = date('F d, Y - h:i A', strtotime($event['event_date']));
        $eer = $event['event_end_date'] ?? null;
        if (!empty($eer) && $eer !== '0000-00-00 00:00:00') {
            $event_when .= ' → ' . date('F d, Y - h:i A', strtotime($eer));
        }
        $reg = $event['registration_deadline'] ?? null;
        $regText = (!empty($reg) && $reg !== '0000-00-00 00:00:00')
            ? date('F d, Y - h:i A', strtotime($reg))
            : 'Not set';

        $html .= '<h2>1. Event Details</h2><table>';
        $details = [
            ['Event Title', $event['title']],
            ['Category', $event['category']],
            ['Event date(s)', $event_when],
            ['Registration closes', $regText],
            ['Venue', $event['venue']],
            ['Status', strtoupper((string) $event['status'])],
            ['Organizer', $event['organizer_name']],
            ['Organizer Email', $event['organizer_email']],
            ['Organizer Phone', $event['organizer_phone']],
            ['Interest Count', $event['interest_count']],
        ];
        foreach ($details as $row) {
            $html .= '<tr><td class="label">' . report_esc_html($row[0]) . '</td><td>' . report_esc_html($row[1]) . '</td></tr>';
        }
        $html .= '</table>';

        $html .= '<h2>2. Description</h2><div class="block">'
            . nl2br(report_esc_html(trim((string) ($event['description'] ?? '')) ?: 'No description provided.'))
            . '</div>';

        $rules = trim((string) ($event['rules'] ?? ''));
        if ($rules !== '') {
            $html .= '<h2>3. Event Rules</h2><div class="block">' . nl2br(report_esc_html($rules)) . '</div>';
        }

        $sectionNo = $rules !== '' ? 4 : 3;
        $html .= '<h2>' . $sectionNo . '. Organizer Review &amp; Attachments</h2>';
        if (!empty($event['organizer_review'])) {
            $html .= '<div class="block review">' . nl2br(report_esc_html($event['organizer_review']));
            if (!empty($event['organizer_review_at'])) {
                $html .= '<div class="muted" style="margin-top:8px;">Submitted: ' . report_esc_html(date('M d, Y h:i A', strtotime($event['organizer_review_at']))) . '</div>';
            }
            $html .= '</div>';
        } else {
            $html .= '<p class="muted">No organizer review submitted.</p>';
        }
        if (!empty($data['review_files'])) {
            $html .= '<h3>Review attachments</h3><ul class="attach">';
            foreach ($data['review_files'] as $rf) {
                $label = $rf['name'] . ($rf['is_pdf'] ? ' (PDF)' : ($rf['is_image'] ? ' (Image)' : ''));
                if ($rf['url'] !== '') {
                    $html .= '<li><a href="' . report_esc_html($rf['url']) . '">' . report_esc_html($label) . '</a>'
                        . '<div class="muted">' . report_esc_html($rf['url']) . '</div></li>';
                } else {
                    $html .= '<li>' . report_esc_html($label) . '</li>';
                }
            }
            $html .= '</ul>';
        }

        $sectionNo++;
        $html .= '<h2>' . $sectionNo . '. Minutes of Meeting</h2>';
        if (empty($data['meeting_minutes'])) {
            $html .= '<p class="muted">No meeting minutes available.</p>';
        } else {
            foreach ($data['meeting_minutes'] as $i => $mm) {
                $status = strtolower((string) ($mm['status'] ?? 'pending'));
                if (!in_array($status, ['approved', 'pending', 'rejected'], true)) {
                    $status = 'pending';
                }
                $html .= '<div class="block minutes">';
                $html .= '<div style="margin-bottom:6px;"><strong>Minutes #' . ($i + 1) . '</strong> '
                    . '<span class="badge ' . report_esc_html($status) . '">' . report_esc_html($status) . '</span></div>';
                $content = trim((string) $mm['content']);
                if ($content !== '' && strcasecmp($content, '(See attached minutes file)') !== 0) {
                    $html .= '<div>' . nl2br(report_esc_html($content)) . '</div>';
                }
                if (!empty($mm['attachment']) && $mm['attachment']['url'] !== '') {
                    $att = $mm['attachment'];
                    $label = $att['name'] . ($att['is_pdf'] ? ' (PDF)' : '');
                    $html .= '<div style="margin-top:8px;"><strong>Attachment:</strong> '
                        . '<a href="' . report_esc_html($att['url']) . '">' . report_esc_html($label) . '</a>'
                        . '<div class="muted">' . report_esc_html($att['url']) . '</div></div>';
                }
                $metaBits = [];
                if ($mm['submitted_by_name'] !== '') {
                    $metaBits[] = 'Submitted by ' . $mm['submitted_by_name'];
                }
                $when = $mm['reviewed_at'] ?: $mm['created_at'];
                if (!empty($when)) {
                    $metaBits[] = date('M d, Y h:i A', strtotime((string) $when));
                }
                if ($metaBits) {
                    $html .= '<div class="muted" style="margin-top:8px;">' . report_esc_html(implode(' · ', $metaBits)) . '</div>';
                }
                $html .= '</div>';
            }
        }

        if (!empty($data['volunteers'])) {
            $sectionNo++;
            $html .= '<h2>' . $sectionNo . '. Volunteers (' . count($data['volunteers']) . ')</h2><table><tr>';
            foreach (['#', 'Name', 'Email', 'Phone', 'Role', 'Attendance', 'Status'] as $h) {
                $html .= '<th>' . report_esc_html($h) . '</th>';
            }
            $html .= '</tr>';
            foreach ($data['volunteers'] as $idx => $vol) {
                $html .= '<tr><td>' . ($idx + 1) . '</td>'
                    . '<td>' . report_esc_html($vol['full_name']) . '</td>'
                    . '<td>' . report_esc_html($vol['email']) . '</td>'
                    . '<td>' . report_esc_html($vol['phone']) . '</td>'
                    . '<td>' . report_esc_html($vol['role'] ?: 'N/A') . '</td>'
                    . '<td>' . report_esc_html(report_attendance_label($vol['attended'] ?? null)) . '</td>'
                    . '<td>' . report_esc_html(strtoupper((string) $vol['status'])) . '</td></tr>';
            }
            $html .= '</table>';
        }

        if (!empty($data['participants'])) {
            $sectionNo++;
            $html .= '<h2>' . $sectionNo . '. Participants (' . count($data['participants']) . ')</h2><table><tr>';
            foreach (['#', 'Name', 'Email', 'Phone', 'Dept/Class', 'Attendance', 'Status'] as $h) {
                $html .= '<th>' . report_esc_html($h) . '</th>';
            }
            $html .= '</tr>';
            foreach ($data['participants'] as $idx => $part) {
                $html .= '<tr><td>' . ($idx + 1) . '</td>'
                    . '<td>' . report_esc_html($part['full_name']) . '</td>'
                    . '<td>' . report_esc_html($part['email']) . '</td>'
                    . '<td>' . report_esc_html($part['phone']) . '</td>'
                    . '<td>' . report_esc_html($part['department_class'] ?: 'N/A') . '</td>'
                    . '<td>' . report_esc_html(report_attendance_label($part['attended'] ?? null)) . '</td>'
                    . '<td>' . report_esc_html(strtoupper((string) $part['status'])) . '</td></tr>';
            }
            $html .= '</table>';
        }

        if (!empty($data['attendees'])) {
            $sectionNo++;
            $html .= '<h2>' . $sectionNo . '. Joinees / Attendees (' . count($data['attendees']) . ')</h2><table><tr>';
            foreach (['#', 'Name', 'Email', 'Phone', 'Joined At'] as $h) {
                $html .= '<th>' . report_esc_html($h) . '</th>';
            }
            $html .= '</tr>';
            foreach ($data['attendees'] as $idx => $att) {
                $html .= '<tr><td>' . ($idx + 1) . '</td>'
                    . '<td>' . report_esc_html($att['full_name']) . '</td>'
                    . '<td>' . report_esc_html($att['email']) . '</td>'
                    . '<td>' . report_esc_html($att['phone']) . '</td>'
                    . '<td>' . report_esc_html(date('M d, Y h:i A', strtotime($att['joined_at']))) . '</td></tr>';
            }
            $html .= '</table>';
        }

        $html .= '</div>';
    }

    $html .= '</body></html>';
    return $html;
}

function createDocx(array $events_data, string $filename, string $list_type, array $list_label)
{
    $zip = new ZipArchive();
    $temp_file = sys_get_temp_dir() . '/' . $filename;

    if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);

    $doc_rels_lines = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
        '    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
    ];
    $next_rid = 2;
    $ct_overrides = [];
    $media_num = 0;
    $doc_pr_seq = 1;

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:styleId="Normal">
        <w:name w:val="Normal"/>
        <w:rPr><w:sz w:val="20"/></w:rPr>
    </w:style>
</w:styles>';
    $zip->addFromString('word/styles.xml', $styles);

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <w:body>';

    foreach ($events_data as $index => $data) {
        $event = $data['event'];
        $review_files = $data['review_files'];
        $meeting_minutes = $data['meeting_minutes'];

        $document .= '
        <w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="80"/></w:pPr>
            <w:r><w:rPr><w:b/><w:sz w:val="36"/><w:color w:val="FF5F15"/></w:rPr>
                <w:t>' . report_esc_xml($event['title']) . '</w:t></w:r></w:p>
        <w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="80"/></w:pPr>
            <w:r><w:rPr><w:sz w:val="22"/></w:rPr>
                <w:t>' . report_esc_xml($list_label[$list_type]) . '</w:t></w:r></w:p>
        <w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="300"/></w:pPr>
            <w:r><w:rPr><w:sz w:val="18"/><w:color w:val="888888"/></w:rPr>
                <w:t>Generated: ' . report_esc_xml(date('M d, Y h:i A')) . '</w:t></w:r></w:p>';

        $banner = report_banner_image_payload($event);
        if ($banner !== null) {
            $media_num++;
            $zip->addFromString('word/media/banner_' . $media_num . '.' . $banner['ext'], $banner['bytes']);
            $embed_rid = 'rId' . $next_rid;
            $doc_rels_lines[] = '    <Relationship Id="' . $embed_rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/banner_' . $media_num . '.' . $banner['ext'] . '"/>';
            $ct_overrides[] = '    <Override PartName="/word/media/banner_' . $media_num . '.' . $banner['ext'] . '" ContentType="' . report_esc_xml($banner['content_type']) . '"/>';
            $ext_emu = report_docx_image_extent_emu($banner['bytes']);
            $document .= report_docx_banner_paragraph($embed_rid, $ext_emu['cx'], $ext_emu['cy'], $doc_pr_seq);
            $doc_pr_seq++;
            $next_rid++;
        }

        $event_when = date('F d, Y - h:i A', strtotime($event['event_date']));
        $eer = $event['event_end_date'] ?? null;
        if (!empty($eer) && $eer !== '0000-00-00 00:00:00') {
            $event_when .= ' → ' . date('F d, Y - h:i A', strtotime($eer));
        }
        $reg = $event['registration_deadline'] ?? null;
        $regText = (!empty($reg) && $reg !== '0000-00-00 00:00:00')
            ? date('F d, Y - h:i A', strtotime($reg))
            : 'Not set';

        $document .= report_docx_heading('1. Event Details');
        $document .= report_docx_key_value_table([
            ['Event Title', $event['title']],
            ['Category', $event['category']],
            ['Event date(s)', $event_when],
            ['Registration closes', $regText],
            ['Venue', $event['venue']],
            ['Status', strtoupper((string) $event['status'])],
            ['Organizer', $event['organizer_name']],
            ['Organizer Email', $event['organizer_email']],
            ['Organizer Phone', $event['organizer_phone']],
            ['Interest Count', $event['interest_count']],
        ]);

        $document .= report_docx_heading('2. Description');
        $document .= report_docx_paragraphs($event['description'] ?? '', 'No description provided.');

        $rules = trim((string) ($event['rules'] ?? ''));
        $sectionNo = 3;
        if ($rules !== '') {
            $document .= report_docx_heading($sectionNo . '. Event Rules');
            $document .= report_docx_paragraphs($rules);
            $sectionNo++;
        }

        $document .= report_docx_heading($sectionNo . '. Organizer Review & Attachments');
        if (!empty($event['organizer_review'])) {
            $document .= report_docx_paragraphs($event['organizer_review']);
            if (!empty($event['organizer_review_at'])) {
                $document .= '<w:p><w:r><w:rPr><w:i/><w:sz w:val="18"/><w:color w:val="888888"/></w:rPr>
                    <w:t>Submitted: ' . report_esc_xml(date('M d, Y h:i A', strtotime($event['organizer_review_at']))) . '</w:t></w:r></w:p>';
            }
        } else {
            $document .= '<w:p><w:r><w:rPr><w:i/><w:color w:val="888888"/></w:rPr><w:t>No organizer review submitted.</w:t></w:r></w:p>';
        }

        if (!empty($review_files)) {
            $document .= '<w:p><w:pPr><w:spacing w:before="160" w:after="80"/></w:pPr>
                <w:r><w:rPr><w:b/></w:rPr><w:t>Review attachments (' . count($review_files) . ')</w:t></w:r></w:p>';
            foreach ($review_files as $idx => $rf) {
                $label = ($idx + 1) . '. ' . $rf['name'] . ($rf['is_pdf'] ? ' (PDF)' : ($rf['is_image'] ? ' (Image)' : ''));
                if ($rf['is_image'] && !empty($rf['local_path'])) {
                    $bytes = @file_get_contents($rf['local_path']);
                    $norm = $bytes !== false ? report_normalize_image_for_docx($bytes, pathinfo($rf['local_path'], PATHINFO_EXTENSION)) : null;
                    if ($norm !== null) {
                        $document .= '<w:p><w:r><w:t>' . report_esc_xml($label) . '</w:t></w:r></w:p>';
                        $media_num++;
                        $zip->addFromString('word/media/review_' . $media_num . '.' . $norm['ext'], $norm['bytes']);
                        $embed_rid = 'rId' . $next_rid;
                        $doc_rels_lines[] = '    <Relationship Id="' . $embed_rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/review_' . $media_num . '.' . $norm['ext'] . '"/>';
                        $ct_overrides[] = '    <Override PartName="/word/media/review_' . $media_num . '.' . $norm['ext'] . '" ContentType="' . report_esc_xml($norm['content_type']) . '"/>';
                        $ext_emu = report_docx_image_extent_emu($norm['bytes']);
                        $document .= report_docx_banner_paragraph($embed_rid, $ext_emu['cx'], $ext_emu['cy'], $doc_pr_seq);
                        $doc_pr_seq++;
                        $next_rid++;
                        if ($rf['url'] !== '') {
                            report_docx_append_link_paragraph($document, $doc_rels_lines, $next_rid, 'Open attachment link', $rf['url']);
                        }
                        continue;
                    }
                }
                // PDF / other files → clickable link
                if ($rf['url'] !== '') {
                    report_docx_append_link_paragraph($document, $doc_rels_lines, $next_rid, $label, $rf['url']);
                    $document .= '<w:p><w:r><w:rPr><w:sz w:val="16"/><w:color w:val="888888"/></w:rPr><w:t>' . report_esc_xml($rf['url']) . '</w:t></w:r></w:p>';
                } else {
                    $document .= '<w:p><w:r><w:t>' . report_esc_xml($label) . '</w:t></w:r></w:p>';
                }
            }
        }
        $sectionNo++;

        $document .= report_docx_heading($sectionNo . '. Minutes of Meeting');
        if (empty($meeting_minutes)) {
            $document .= '<w:p><w:r><w:rPr><w:i/><w:color w:val="888888"/></w:rPr><w:t>No meeting minutes available.</w:t></w:r></w:p>';
        } else {
            foreach ($meeting_minutes as $i => $mm) {
                $status = strtoupper((string) ($mm['status'] ?: 'pending'));
                $document .= '<w:p><w:pPr><w:spacing w:before="200" w:after="60"/></w:pPr>
                    <w:r><w:rPr><w:b/></w:rPr><w:t>Minutes #' . ($i + 1) . ' — ' . report_esc_xml($status) . '</w:t></w:r></w:p>';
                $content = trim((string) $mm['content']);
                if ($content !== '' && strcasecmp($content, '(See attached minutes file)') !== 0) {
                    $document .= report_docx_paragraphs($content);
                }
                if (!empty($mm['attachment'])) {
                    $att = $mm['attachment'];
                    $label = 'Attachment: ' . $att['name'] . ($att['is_pdf'] ? ' (PDF)' : '');
                    if ($att['url'] !== '') {
                        report_docx_append_link_paragraph($document, $doc_rels_lines, $next_rid, $label, $att['url']);
                        $document .= '<w:p><w:r><w:rPr><w:sz w:val="16"/><w:color w:val="888888"/></w:rPr><w:t>' . report_esc_xml($att['url']) . '</w:t></w:r></w:p>';
                    } else {
                        $document .= '<w:p><w:r><w:t>' . report_esc_xml($label) . '</w:t></w:r></w:p>';
                    }
                }
                $metaBits = [];
                if ($mm['submitted_by_name'] !== '') {
                    $metaBits[] = 'Submitted by ' . $mm['submitted_by_name'];
                }
                $when = $mm['reviewed_at'] ?: $mm['created_at'];
                if (!empty($when)) {
                    $metaBits[] = date('M d, Y h:i A', strtotime((string) $when));
                }
                if ($metaBits) {
                    $document .= '<w:p><w:r><w:rPr><w:i/><w:sz w:val="18"/><w:color w:val="888888"/></w:rPr>
                        <w:t>' . report_esc_xml(implode(' · ', $metaBits)) . '</w:t></w:r></w:p>';
                }
            }
        }

        if (!empty($data['volunteers'])) {
            $sectionNo++;
            $document .= report_docx_heading($sectionNo . '. Volunteers (' . count($data['volunteers']) . ')');
            $rows = [];
            foreach ($data['volunteers'] as $idx => $vol) {
                $rows[] = [
                    $idx + 1,
                    $vol['full_name'],
                    $vol['email'],
                    $vol['phone'],
                    $vol['role'] ?: 'N/A',
                    report_attendance_label($vol['attended'] ?? null),
                    strtoupper((string) $vol['status']),
                ];
            }
            $document .= report_docx_data_table(['#', 'Name', 'Email', 'Phone', 'Role', 'Attendance', 'Status'], $rows);
        }

        if (!empty($data['participants'])) {
            $sectionNo++;
            $document .= report_docx_heading($sectionNo . '. Participants (' . count($data['participants']) . ')');
            $rows = [];
            foreach ($data['participants'] as $idx => $part) {
                $rows[] = [
                    $idx + 1,
                    $part['full_name'],
                    $part['email'],
                    $part['phone'],
                    $part['department_class'] ?: 'N/A',
                    report_attendance_label($part['attended'] ?? null),
                    strtoupper((string) $part['status']),
                ];
            }
            $document .= report_docx_data_table(['#', 'Name', 'Email', 'Phone', 'Dept/Class', 'Attendance', 'Status'], $rows);
        }

        if (!empty($data['attendees'])) {
            $sectionNo++;
            $document .= report_docx_heading($sectionNo . '. Joinees / Attendees (' . count($data['attendees']) . ')');
            $rows = [];
            foreach ($data['attendees'] as $idx => $att) {
                $rows[] = [
                    $idx + 1,
                    $att['full_name'],
                    $att['email'],
                    $att['phone'],
                    date('M d, Y h:i A', strtotime($att['joined_at'])),
                ];
            }
            $document .= report_docx_data_table(['#', 'Name', 'Email', 'Phone', 'Joined At'], $rows);
        }

        if ($index < count($events_data) - 1) {
            $document .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        }
    }

    $document .= '
    </w:body>
</w:document>';

    $doc_rels_lines[] = '</Relationships>';
    $doc_rels = implode("\n", $doc_rels_lines);

    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Default Extension="jpeg" ContentType="image/jpeg"/>
    <Default Extension="jpg" ContentType="image/jpeg"/>
    <Default Extension="png" ContentType="image/png"/>
    <Default Extension="gif" ContentType="image/gif"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
' . implode("\n", $ct_overrides) . '
</Types>';

    $zip->addFromString('[Content_Types].xml', $content_types);
    $zip->addFromString('word/_rels/document.xml.rels', $doc_rels);
    $zip->addFromString('word/document.xml', $document);
    $zip->close();

    return $temp_file;
}

function createPdf(array $events_data, string $list_type, array $list_label): ?string
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return null;
    }
    require_once $autoload;

    $html = report_build_html($events_data, $list_type, $list_label);
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $temp = sys_get_temp_dir() . '/event_report_' . uniqid('', true) . '.pdf';
    $bytes = $dompdf->output();
    if ($bytes === null || $bytes === '') {
        return null;
    }
    if (@file_put_contents($temp, $bytes) === false) {
        return null;
    }
    return $temp;
}

if ($format === 'pdf') {
    $pdf_file = createPdf($events_data, $list_type, $list_label);
    $filename = $base_name . '.pdf';
    if ($pdf_file && is_file($pdf_file)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($pdf_file));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        readfile($pdf_file);
        unlink($pdf_file);
        exit;
    }
    die('Error generating PDF report. Please ensure Dompdf is installed (composer install).');
}

$filename = $base_name . '.docx';
$docx_file = createDocx($events_data, $filename, $list_type, $list_label);

if ($docx_file && is_file($docx_file)) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($docx_file));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    readfile($docx_file);
    unlink($docx_file);
    exit;
}

echo 'Error generating document.';
