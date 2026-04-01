<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}
require_priv('reports');

// Determine if single or bulk download
$event_ids = [];
if (isset($_GET['event_id'])) {
    $event_ids = [intval($_GET['event_id'])];
} elseif (isset($_GET['event_ids'])) {
    $event_ids = array_map('intval', explode(',', $_GET['event_ids']));
}

if (empty($event_ids)) {
    die("No events selected for report generation.");
}

// Fetch event data
$events_data = [];
foreach ($event_ids as $event_id) {
    // Get event details
    $event_query = $conn->query("
        SELECT e.*, u.full_name as organizer_name, u.email as organizer_email, u.phone as organizer_phone
        FROM events e 
        JOIN users u ON e.organizer_id = u.id 
        WHERE e.id = $event_id
    ");
    
    if ($event_query->num_rows == 0) continue;
    
    $event = $event_query->fetch_assoc();
    
    // Get attendees
    $attendees_query = $conn->query("
        SELECT u.full_name, u.email, u.phone, a.joined_at 
        FROM attendees a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.event_id = $event_id 
        ORDER BY a.joined_at ASC
    ");
    
    $attendees = [];
    while ($attendee = $attendees_query->fetch_assoc()) {
        $attendees[] = $attendee;
    }
    
    // Get volunteers
    $volunteers_query = $conn->query("
        SELECT u.full_name, u.email, u.phone, v.role, v.status 
        FROM volunteers v 
        JOIN users u ON v.user_id = u.id 
        WHERE v.event_id = $event_id 
        ORDER BY u.full_name ASC
    ");
    
    $volunteers = [];
    while ($volunteer = $volunteers_query->fetch_assoc()) {
        $volunteers[] = $volunteer;
    }
    
    $events_data[] = [
        'event' => $event,
        'attendees' => $attendees,
        'volunteers' => $volunteers
    ];
}

if (empty($events_data)) {
    die("No valid events found.");
}

// Generate filename
$timestamp = date('Y-m-d_H-i-s');
if (count($events_data) == 1) {
    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $events_data[0]['event']['title']) . '_Report_' . $timestamp . '.docx';
} else {
    $filename = 'Events_Report_' . $timestamp . '.docx';
}

// Create DOCX using PHP ZipArchive
function createDocx($events_data, $filename) {
    $zip = new ZipArchive();
    $temp_file = sys_get_temp_dir() . '/' . $filename;
    
    if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        return false;
    }
    
    // Create [Content_Types].xml
    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $content_types);
    
    // Create _rels/.rels
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);
    
    // Create word/_rels/document.xml.rels
    $doc_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
    $zip->addFromString('word/_rels/document.xml.rels', $doc_rels);
    
    // Create word/styles.xml
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:styleId="Heading1">
        <w:name w:val="Heading 1"/>
        <w:basedOn w:val="Normal"/>
        <w:pPr>
            <w:spacing w:before="240" w:after="240"/>
        </w:pPr>
        <w:rPr>
            <w:b/>
            <w:sz w:val="32"/>
            <w:color w:val="FF5F15"/>
        </w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Heading2">
        <w:name w:val="Heading 2"/>
        <w:basedOn w:val="Normal"/>
        <w:pPr>
            <w:spacing w:before="180" w:after="180"/>
        </w:pPr>
        <w:rPr>
            <w:b/>
            <w:sz w:val="28"/>
        </w:rPr>
    </w:style>
</w:styles>';
    $zip->addFromString('word/styles.xml', $styles);
    
    // Create word/document.xml
    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>';
    
    foreach ($events_data as $index => $data) {
        $event = $data['event'];
        $attendees = $data['attendees'];
        $volunteers = $data['volunteers'];
        
        // Event Title (Centered, Large)
        $document .= '
        <w:p>
            <w:pPr>
                <w:jc w:val="center"/>
                <w:spacing w:after="400"/>
            </w:pPr>
            <w:r>
                <w:rPr>
                    <w:b/>
                    <w:sz w:val="36"/>
                    <w:color w:val="FF5F15"/>
                </w:rPr>
                <w:t>' . htmlspecialchars($event['title']) . '</w:t>
            </w:r>
        </w:p>
        
        <w:p>
            <w:pPr>
                <w:jc w:val="center"/>
                <w:spacing w:after="600"/>
            </w:pPr>
            <w:r>
                <w:rPr><w:sz w:val="24"/></w:rPr>
                <w:t>Event Report</w:t>
            </w:r>
        </w:p>';
        
        // Event Details Heading
        $document .= '
        <w:p>
            <w:pPr><w:spacing w:before="400" w:after="200"/></w:pPr>
            <w:r>
                <w:rPr>
                    <w:b/>
                    <w:sz w:val="28"/>
                </w:rPr>
                <w:t>Event Details</w:t>
            </w:r>
        </w:p>';
        
        // Event Details Table
        $document .= '<w:tbl>
            <w:tblPr>
                <w:tblW w:w="5000" w:type="pct"/>
                <w:tblBorders>
                    <w:top w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:left w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:right w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>
                    <w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>
                </w:tblBorders>
            </w:tblPr>';
        
        // Table rows
        $details = [
            ['Event Title', $event['title']],
            ['Category', $event['category']],
            ['Event Date', date('F d, Y - h:i A', strtotime($event['event_date']))],
            ['Venue', $event['venue']],
            ['Status', strtoupper($event['status'])],
            ['Organizer', $event['organizer_name']],
            ['Organizer Email', $event['organizer_email']],
            ['Organizer Phone', $event['organizer_phone']],
            ['Interest Count', $event['interest_count']]
        ];
        
        foreach ($details as $detail) {
            $document .= '<w:tr>
                <w:tc>
                    <w:tcPr>
                        <w:shd w:fill="D5E8F0"/>
                        <w:tcW w:w="2000" w:type="dxa"/>
                    </w:tcPr>
                    <w:p>
                        <w:r>
                            <w:rPr><w:b/></w:rPr>
                            <w:t>' . htmlspecialchars($detail[0]) . '</w:t>
                        </w:r>
                    </w:p>
                </w:tc>
                <w:tc>
                    <w:tcPr>
                        <w:tcW w:w="4000" w:type="dxa"/>
                    </w:tcPr>
                    <w:p>
                        <w:r>
                            <w:t>' . htmlspecialchars($detail[1]) . '</w:t>
                        </w:r>
                    </w:p>
                </w:tc>
            </w:tr>';
        }
        
        $document .= '</w:tbl>';
        
        // Description
        $document .= '
        <w:p>
            <w:pPr><w:spacing w:before="400" w:after="200"/></w:pPr>
            <w:r>
                <w:rPr>
                    <w:b/>
                    <w:sz w:val="28"/>
                </w:rPr>
                <w:t>Description</w:t>
            </w:r>
        </w:p>
        <w:p>
            <w:r>
                <w:t>' . htmlspecialchars($event['description'] ?: 'No description provided.') . '</w:t>
            </w:r>
        </w:p>';
        
        // Attendees Section
        if (!empty($attendees)) {
            $document .= '
            <w:p>
                <w:pPr><w:spacing w:before="400" w:after="200"/></w:pPr>
                <w:r>
                    <w:rPr>
                        <w:b/>
                        <w:sz w:val="28"/>
                    </w:rPr>
                    <w:t>Attendees (' . count($attendees) . ')</w:t>
                </w:r>
            </w:p>';
            
            $document .= '<w:tbl>
                <w:tblPr>
                    <w:tblW w:w="5000" w:type="pct"/>
                    <w:tblBorders>
                        <w:top w:val="single" w:sz="4" w:color="CCCCCC"/>
                        <w:left w:val="single" w:sz="4" w:color="CCCCCC"/>
                        <w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>
                        <w:right w:val="single" w:sz="4" w:color="CCCCCC"/>
                        <w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>
                        <w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>
                    </w:tblBorders>
                </w:tblPr>';
            
            // Header row
            $document .= '<w:tr>
                <w:tc>
                    <w:tcPr>
                        <w:shd w:fill="FF5F15"/>
                        <w:tcW w:w="500" w:type="dxa"/>
                    </w:tcPr>
                    <w:p>
                        <w:r>
                            <w:rPr>
                                <w:b/>
                                <w:color w:val="FFFFFF"/>
                            </w:rPr>
                            <w:t>#</w:t>
                        </w:r>
                    </w:p>
                </w:tc>
                <w:tc>
                    <w:tcPr>
                        <w:shd w:fill="FF5F15"/>
                        <w:tcW w:w="2000" w:type="dxa"/>
                    </w:tcPr>
                    <w:p>
                        <w:r>
                            <w:rPr>
                                <w:b/>
                                <w:color w:val="FFFFFF"/>
                            </w:rPr>
                            <w:t>Name</w:t>
                        </w:r>
                    </w:p>
                </w:tc>
                <w:tc>
                    <w:tcPr>
                        <w:shd w:fill="FF5F15"/>
                        <w:tcW w:w="1500" w:type="dxa"/>
                    </w:tcPr>
                    <w:p>
                        <w:r>
                            <w:rPr>
                                <w:b/>
                                <w:color w:val="FFFFFF"/>
                            </w:rPr>
                            <w:t>Email</w:t>
                        </w:r>
                    </w:p>
                </w:tc>
                <w:tc>
                    <w:tcPr>
                        <w:shd w:fill="FF5F15"/>
                        <w:tcW w:w="1500" w:type="dxa"/>
                    </w:tcPr>
                    <w:p>
                        <w:r>
                            <w:rPr>
                                <w:b/>
                                <w:color w:val="FFFFFF"/>
                            </w:rPr>
                            <w:t>Joined At</w:t>
                        </w:r>
                    </w:p>
                </w:tc>
            </w:tr>';
            
            foreach ($attendees as $idx => $attendee) {
                $document .= '<w:tr>
                    <w:tc>
                        <w:tcPr><w:tcW w:w="500" w:type="dxa"/></w:tcPr>
                        <w:p><w:r><w:t>' . ($idx + 1) . '</w:t></w:r></w:p>
                    </w:tc>
                    <w:tc>
                        <w:tcPr><w:tcW w:w="2000" w:type="dxa"/></w:tcPr>
                        <w:p><w:r><w:t>' . htmlspecialchars($attendee['full_name']) . '</w:t></w:r></w:p>
                    </w:tc>
                    <w:tc>
                        <w:tcPr><w:tcW w:w="1500" w:type="dxa"/></w:tcPr>
                        <w:p><w:r><w:t>' . htmlspecialchars($attendee['email']) . '</w:t></w:r></w:p>
                    </w:tc>
                    <w:tc>
                        <w:tcPr><w:tcW w:w="1500" w:type="dxa"/></w:tcPr>
                        <w:p><w:r><w:t>' . date('M d, Y h:i A', strtotime($attendee['joined_at'])) . '</w:t></w:r></w:p>
                    </w:tc>
                </w:tr>';
            }
            
            $document .= '</w:tbl>';
        }
        
        // Volunteers Section
        if (!empty($volunteers)) {
            $document .= '
            <w:p>
                <w:pPr><w:spacing w:before="400" w:after="200"/></w:pPr>
                <w:r>
                    <w:rPr>
                        <w:b/>
                        <w:sz w:val="28"/>
                    </w:rPr>
                    <w:t>Volunteers (' . count($volunteers) . ')</w:t>
                </w:r>
            </w:p>';
            
            $document .= '<w:tbl>
                <w:tblPr>
                    <w:tblW w:w="5000" w:type="pct"/>
                    <w:tblBorders>
                        <w:top w:val="single" w:sz="4" w:color="CCCCCC"/>
                        <w:left w:val="single" w:sz="4" w:color="CCCCCC"/>
                        <w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>
                        <w:right w:val="single" w:sz="4" w:color="CCCCCC"/>
                        <w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>
                        <w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>
                    </w:tblBorders>
                </w:tblPr>';
            
            // Header row
            $document .= '<w:tr>
                <w:tc>
                    <w:tcPr>
                        <w:shd w:fill="FF5F15"/>
                        <w:tcW w:w="500" w:type="dxa"/>
                    </w:tcPr>
                    <w:p>
                        <w:r>
                            <w:rPr>
                                <w:b/>
                                <w:color w:val="FFFFFF"/>
                            </w:rPr>
                            <w:t>#</w:t>
                        </w:r>
                    </w:p>
                </w:tc>
                <w:tc>
                    <w:tcPr>
                        <w:shd w:fill="FF5F15"/>
                        <w:tcW w:w="1800" w:type="dxa"/>
                    </w:tcPr>
                    <w:p>
                        <w:r>
                            <w:rPr>
                                <w:b/>
                                <w:color w:val="FFFFFF"/>
                            </w:rPr>
                            <w:t>Name</w:t>
                        </w:r>
                    </w:p>
                </w:tc>
                <w:tc>
                    <w:tcPr>
                        <w:shd w:fill="FF5F15"/>
                        <w:tcW w:w="1500" w:type="dxa"/>
                    </w:tcPr>
                    <w:p>
                        <w:r>
                            <w:rPr>
                                <w:b/>
                                <w:color w:val="FFFFFF"/>
                            </w:rPr>
                            <w:t>Role</w:t>
                        </w:r>
                    </w:p>
                </w:tc>
                <w:tc>
                    <w:tcPr>
                        <w:shd w:fill="FF5F15"/>
                        <w:tcW w:w="800" w:type="dxa"/>
                    </w:tcPr>
                    <w:p>
                        <w:r>
                            <w:rPr>
                                <w:b/>
                                <w:color w:val="FFFFFF"/>
                            </w:rPr>
                            <w:t>Status</w:t>
                        </w:r>
                    </w:p>
                </w:tc>
            </w:tr>';
            
            foreach ($volunteers as $idx => $volunteer) {
                $document .= '<w:tr>
                    <w:tc>
                        <w:tcPr><w:tcW w:w="500" w:type="dxa"/></w:tcPr>
                        <w:p><w:r><w:t>' . ($idx + 1) . '</w:t></w:r></w:p>
                    </w:tc>
                    <w:tc>
                        <w:tcPr><w:tcW w:w="1800" w:type="dxa"/></w:tcPr>
                        <w:p><w:r><w:t>' . htmlspecialchars($volunteer['full_name']) . '</w:t></w:r></w:p>
                    </w:tc>
                    <w:tc>
                        <w:tcPr><w:tcW w:w="1500" w:type="dxa"/></w:tcPr>
                        <w:p><w:r><w:t>' . htmlspecialchars($volunteer['role'] ?: 'N/A') . '</w:t></w:r></w:p>
                    </w:tc>
                    <w:tc>
                        <w:tcPr><w:tcW w:w="800" w:type="dxa"/></w:tcPr>
                        <w:p><w:r><w:t>' . strtoupper($volunteer['status']) . '</w:t></w:r></w:p>
                    </w:tc>
                </w:tr>';
            }
            
            $document .= '</w:tbl>';
        }
        
        // Page break if not last event
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

// Generate DOCX
$docx_file = createDocx($events_data, $filename);

if ($docx_file && file_exists($docx_file)) {
    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($docx_file));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    
    // Output file content
    readfile($docx_file);
    
    // Delete temporary file
    unlink($docx_file);
    exit;
} else {
    echo "Error generating document.";
}
?>