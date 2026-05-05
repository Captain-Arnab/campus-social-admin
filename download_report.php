<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}
require_priv('reports');

// list_type: all | volunteers | participants | joinees
$list_type = isset($_GET['list_type']) ? $_GET['list_type'] : 'all';
if (!in_array($list_type, ['all', 'volunteers', 'participants', 'joinees'])) {
    $list_type = 'all';
}

$event_ids = [];
if (isset($_GET['event_id'])) {
    $event_ids = [intval($_GET['event_id'])];
} elseif (isset($_GET['event_ids'])) {
    $event_ids = array_map('intval', explode(',', $_GET['event_ids']));
}

if (empty($event_ids)) {
    die("No events selected for report generation.");
}

$events_data = [];
foreach ($event_ids as $event_id) {
    $event_query = $conn->query("
        SELECT e.*, u.full_name as organizer_name, u.email as organizer_email, u.phone as organizer_phone
        FROM events e 
        JOIN users u ON e.organizer_id = u.id 
        WHERE e.id = $event_id
    ");
    
    if ($event_query->num_rows == 0) continue;
    
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
        while ($attendee = $attendees_query->fetch_assoc()) {
            $attendees[] = $attendee;
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
        while ($volunteer = $volunteers_query->fetch_assoc()) {
            $volunteers[] = $volunteer;
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
        while ($p = $participants_query->fetch_assoc()) {
            $participants[] = $p;
        }
    }

    $review_files = [];
    $rf_res = @$conn->query("SELECT original_name, file_type, file_path FROM event_review_files WHERE event_id = $event_id ORDER BY uploaded_at ASC");
    if ($rf_res) {
        while ($r = $rf_res->fetch_assoc()) { $review_files[] = $r; }
    }
    
    $events_data[] = [
        'event' => $event,
        'attendees' => $attendees,
        'volunteers' => $volunteers,
        'participants' => $participants,
        'review_files' => $review_files,
    ];
}

if (empty($events_data)) {
    die("No valid events found.");
}

$label_map = ['all' => '', 'volunteers' => '_Volunteers', 'participants' => '_Participants', 'joinees' => '_Joinees'];
$timestamp = date('Y-m-d_H-i-s');
if (count($events_data) == 1) {
    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $events_data[0]['event']['title']) . '_Report' . $label_map[$list_type] . '_' . $timestamp . '.docx';
} else {
    $filename = 'Events_Report' . $label_map[$list_type] . '_' . $timestamp . '.docx';
}

function esc($text) {
    return htmlspecialchars((string) $text, ENT_XML1, 'UTF-8');
}

/**
 * Event banners are saved under admin/uploads/events (see admin/api/events.php), not campus_social/uploads/events.
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
 * Word displays JPEG/PNG/GIF reliably; WebP is converted to JPEG when GD is available.
 *
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
 * First event banner file on disk for the DOCX embed.
 *
 * @return array{bytes: string, content_type: string, ext: string}|null
 */
function report_banner_image_payload(array $event): ?array
{
    $raw = $event['banners'] ?? '[]';
    $banners = json_decode($raw, true);
    if (!is_array($banners) || empty($banners[0])) {
        return null;
    }
    $first = trim((string) $banners[0]);
    $first = preg_replace('/[#?].*$/', '', $first);
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
    return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="400"/></w:pPr><w:r><w:drawing>'
        . '<wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<wp:extent cx="' . $cxEmu . '" cy="' . $cyEmu . '"/>'
        . '<wp:docPr id="' . $docPrId . '" name="Event thumbnail"/>'
        . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
        . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="Banner"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . esc($embedRid) . '"/>'
        . '<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cxEmu . '" cy="' . $cyEmu . '"/></a:xfrm>'
        . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
        . '</pic:pic></a:graphicData></a:graphic>'
        . '</wp:inline></w:drawing></w:r></w:p>';
}

function createDocx($events_data, $filename, $list_type) {
    $zip = new ZipArchive();
    $temp_file = sys_get_temp_dir() . '/' . $filename;
    
    if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
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
    <w:style w:type="paragraph" w:styleId="Heading1">
        <w:name w:val="Heading 1"/>
        <w:basedOn w:val="Normal"/>
        <w:pPr><w:spacing w:before="240" w:after="240"/></w:pPr>
        <w:rPr><w:b/><w:sz w:val="32"/><w:color w:val="FF5F15"/></w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Heading2">
        <w:name w:val="Heading 2"/>
        <w:basedOn w:val="Normal"/>
        <w:pPr><w:spacing w:before="180" w:after="180"/></w:pPr>
        <w:rPr><w:b/><w:sz w:val="28"/></w:rPr>
    </w:style>
</w:styles>';
    $zip->addFromString('word/styles.xml', $styles);
    
    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <w:body>';
    
    $list_label = ['all' => 'Full Report', 'volunteers' => 'Volunteers List', 'participants' => 'Participants List', 'joinees' => 'Joinees (Attendees) List'];

    foreach ($events_data as $index => $data) {
        $event = $data['event'];
        $attendees = $data['attendees'];
        $volunteers = $data['volunteers'];
        $participants = $data['participants'];
        $review_files = $data['review_files'];
        
        $document .= '
        <w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="200"/></w:pPr>
            <w:r><w:rPr><w:b/><w:sz w:val="36"/><w:color w:val="FF5F15"/></w:rPr>
                <w:t>' . esc($event['title']) . '</w:t></w:r></w:p>
        <w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="600"/></w:pPr>
            <w:r><w:rPr><w:sz w:val="24"/></w:rPr>
                <w:t>' . esc($list_label[$list_type]) . '</w:t></w:r></w:p>';

        $banner = report_banner_image_payload($event);
        if ($banner !== null) {
            $media_num++;
            $zip->addFromString('word/media/banner_' . $media_num . '.' . $banner['ext'], $banner['bytes']);
            $embed_rid = 'rId' . $next_rid;
            $doc_rels_lines[] = '    <Relationship Id="' . $embed_rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/banner_' . $media_num . '.' . $banner['ext'] . '"/>';
            $ct_overrides[] = '    <Override PartName="/word/media/banner_' . $media_num . '.' . $banner['ext'] . '" ContentType="' . esc($banner['content_type']) . '"/>';
            $ext_emu = report_docx_image_extent_emu($banner['bytes']);
            $document .= report_docx_banner_paragraph($embed_rid, $ext_emu['cx'], $ext_emu['cy'], $doc_pr_seq);
            $doc_pr_seq++;
            $next_rid++;
        }
        
        // Event Details Table
        $document .= '
        <w:p><w:pPr><w:spacing w:before="400" w:after="200"/></w:pPr>
            <w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t>Event Details</w:t></w:r></w:p>';
        
        $event_when = date('F d, Y - h:i A', strtotime($event['event_date']));
        $eer = $event['event_end_date'] ?? null;
        if (!empty($eer) && $eer !== '0000-00-00 00:00:00') {
            $event_when .= ' → ' . date('F d, Y - h:i A', strtotime($eer));
        }
        $details = [
            ['Event Title', $event['title']],
            ['Category', $event['category']],
            ['Event date(s)', $event_when],
            ['Venue', $event['venue']],
            ['Status', strtoupper($event['status'])],
            ['Organizer', $event['organizer_name']],
            ['Organizer Email', $event['organizer_email']],
            ['Organizer Phone', $event['organizer_phone']],
            ['Interest Count', $event['interest_count']]
        ];
        
        $document .= '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>
            <w:tblBorders>
                <w:top w:val="single" w:sz="4" w:color="CCCCCC"/>
                <w:left w:val="single" w:sz="4" w:color="CCCCCC"/>
                <w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>
                <w:right w:val="single" w:sz="4" w:color="CCCCCC"/>
                <w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>
                <w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>
            </w:tblBorders></w:tblPr>';
        
        foreach ($details as $detail) {
            $document .= '<w:tr>
                <w:tc><w:tcPr><w:shd w:fill="D5E8F0"/><w:tcW w:w="2000" w:type="dxa"/></w:tcPr>
                    <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . esc($detail[0]) . '</w:t></w:r></w:p></w:tc>
                <w:tc><w:tcPr><w:tcW w:w="4000" w:type="dxa"/></w:tcPr>
                    <w:p><w:r><w:t>' . esc($detail[1]) . '</w:t></w:r></w:p></w:tc>
            </w:tr>';
        }
        $document .= '</w:tbl>';
        
        // Description
        $document .= '
        <w:p><w:pPr><w:spacing w:before="400" w:after="200"/></w:pPr>
            <w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t>Description</w:t></w:r></w:p>
        <w:p><w:r><w:t>' . esc($event['description'] ?: 'No description provided.') . '</w:t></w:r></w:p>';

        // Organizer Review
        if (!empty($event['organizer_review'])) {
            $document .= '
            <w:p><w:pPr><w:spacing w:before="400" w:after="200"/></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t>Organizer Review</w:t></w:r></w:p>
            <w:p><w:r><w:t>' . esc($event['organizer_review']) . '</w:t></w:r></w:p>';
            if (!empty($event['organizer_review_at'])) {
                $document .= '<w:p><w:r><w:rPr><w:i/><w:sz w:val="18"/><w:color w:val="888888"/></w:rPr>
                    <w:t>Submitted: ' . esc(date('M d, Y h:i A', strtotime($event['organizer_review_at']))) . '</w:t></w:r></w:p>';
            }
        }

        // Review file attachments list
        if (!empty($review_files)) {
            $document .= '
            <w:p><w:pPr><w:spacing w:before="300" w:after="100"/></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr><w:t>Review Attachments (' . count($review_files) . ')</w:t></w:r></w:p>';
            foreach ($review_files as $idx => $rf) {
                $document .= '<w:p><w:r><w:t>' . ($idx + 1) . '. ' . esc($rf['original_name'] ?: $rf['file_path']) . ' (' . esc($rf['file_type'] ?? 'file') . ')</w:t></w:r></w:p>';
            }
        }

        // Volunteers Section
        if (!empty($volunteers)) {
            $document .= '
            <w:p><w:pPr><w:spacing w:before="400" w:after="200"/></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t>Volunteers (' . count($volunteers) . ')</w:t></w:r></w:p>';
            
            $document .= '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>
                <w:tblBorders>
                    <w:top w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:left w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:right w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>
                </w:tblBorders></w:tblPr>';

            $document .= '<w:tr>';
            foreach (['#', 'Name', 'Email', 'Phone', 'Role', 'Attendance', 'Status'] as $h) {
                $document .= '<w:tc><w:tcPr><w:shd w:fill="FF5F15"/></w:tcPr>
                    <w:p><w:r><w:rPr><w:b/><w:color w:val="FFFFFF"/></w:rPr><w:t>' . $h . '</w:t></w:r></w:p></w:tc>';
            }
            $document .= '</w:tr>';
            
            foreach ($volunteers as $idx => $vol) {
                $att = ($vol['attended'] === null || $vol['attended'] === '') ? 'Unmarked' : ((int)$vol['attended'] === 1 ? 'Present' : 'Absent');
                $cells = [$idx + 1, $vol['full_name'], $vol['email'], $vol['phone'], $vol['role'] ?: 'N/A', $att, strtoupper($vol['status'])];
                $document .= '<w:tr>';
                foreach ($cells as $c) {
                    $document .= '<w:tc><w:p><w:r><w:t>' . esc($c) . '</w:t></w:r></w:p></w:tc>';
                }
                $document .= '</w:tr>';
            }
            $document .= '</w:tbl>';
        }

        // Participants Section
        if (!empty($participants)) {
            $document .= '
            <w:p><w:pPr><w:spacing w:before="400" w:after="200"/></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t>Participants (' . count($participants) . ')</w:t></w:r></w:p>';
            
            $document .= '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>
                <w:tblBorders>
                    <w:top w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:left w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:right w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>
                </w:tblBorders></w:tblPr>';

            $document .= '<w:tr>';
            foreach (['#', 'Name', 'Email', 'Phone', 'Dept/Class', 'Attendance', 'Status'] as $h) {
                $document .= '<w:tc><w:tcPr><w:shd w:fill="FF5F15"/></w:tcPr>
                    <w:p><w:r><w:rPr><w:b/><w:color w:val="FFFFFF"/></w:rPr><w:t>' . $h . '</w:t></w:r></w:p></w:tc>';
            }
            $document .= '</w:tr>';
            
            foreach ($participants as $idx => $part) {
                $att = ($part['attended'] === null || $part['attended'] === '') ? 'Unmarked' : ((int)$part['attended'] === 1 ? 'Present' : 'Absent');
                $cells = [$idx + 1, $part['full_name'], $part['email'], $part['phone'], $part['department_class'] ?: 'N/A', $att, strtoupper($part['status'])];
                $document .= '<w:tr>';
                foreach ($cells as $c) {
                    $document .= '<w:tc><w:p><w:r><w:t>' . esc($c) . '</w:t></w:r></w:p></w:tc>';
                }
                $document .= '</w:tr>';
            }
            $document .= '</w:tbl>';
        }

        // Attendees (Joinees) Section
        if (!empty($attendees)) {
            $document .= '
            <w:p><w:pPr><w:spacing w:before="400" w:after="200"/></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t>Joinees / Attendees (' . count($attendees) . ')</w:t></w:r></w:p>';
            
            $document .= '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>
                <w:tblBorders>
                    <w:top w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:left w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:right w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>
                </w:tblBorders></w:tblPr>';

            $document .= '<w:tr>';
            foreach (['#', 'Name', 'Email', 'Phone', 'Joined At'] as $h) {
                $document .= '<w:tc><w:tcPr><w:shd w:fill="FF5F15"/></w:tcPr>
                    <w:p><w:r><w:rPr><w:b/><w:color w:val="FFFFFF"/></w:rPr><w:t>' . $h . '</w:t></w:r></w:p></w:tc>';
            }
            $document .= '</w:tr>';
            
            foreach ($attendees as $idx => $att) {
                $cells = [$idx + 1, $att['full_name'], $att['email'], $att['phone'], date('M d, Y h:i A', strtotime($att['joined_at']))];
                $document .= '<w:tr>';
                foreach ($cells as $c) {
                    $document .= '<w:tc><w:p><w:r><w:t>' . esc($c) . '</w:t></w:r></w:p></w:tc>';
                }
                $document .= '</w:tr>';
            }
            $document .= '</w:tbl>';
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

$docx_file = createDocx($events_data, $filename, $list_type);

if ($docx_file && file_exists($docx_file)) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($docx_file));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    readfile($docx_file);
    unlink($docx_file);
    exit;
} else {
    echo "Error generating document.";
}
?>
