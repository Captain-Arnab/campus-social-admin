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

function createDocx($events_data, $filename, $list_type) {
    $zip = new ZipArchive();
    $temp_file = sys_get_temp_dir() . '/' . $filename;
    
    if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        return false;
    }
    
    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $content_types);
    
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);
    
    $doc_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
    $zip->addFromString('word/_rels/document.xml.rels', $doc_rels);
    
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
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
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
