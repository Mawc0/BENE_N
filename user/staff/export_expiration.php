<?php
// Enable error reporting (so you see what’s wrong if it fails)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session (if needed)
session_start();

// Database connection
include "../../db.php";

// Set timezone
date_default_timezone_set('Asia/Manila');

// Query: Get medicines expiring in the next 30 days
$query = "
    SELECT 
        name, 
        type, 
        batch_date, 
        expired_date, 
        quantity,
        CASE 
            WHEN expired_date < CURDATE() THEN 'Expired'
            WHEN expired_date = CURDATE() THEN 'Expires Today'
            ELSE CONCAT('In ', DATEDIFF(expired_date, CURDATE()), ' days')
        END as status,
        DATEDIFF(expired_date, CURDATE()) as days_left
    FROM medicines 
    WHERE expired_date <= CURDATE() + INTERVAL 30 DAY
    ORDER BY expired_date ASC
";

$result = $conn->query($query);
$medicines = [];
while ($row = $result->fetch_assoc()) {
    $medicines[] = $row;
}

// Get format: pdf or excel
$format = $_GET['format'] ?? 'excel';

// ============= EXPORT TO PDF =============
if ($format === 'pdf') {
    // Load TCPDF
    require_once('tcpdf/tcpdf.php');

    // Create new PDF document
    $pdf = new TCPDF();

    // Add a page
    $pdf->AddPage();

    // Set title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'BENE MediCon - Expiration Inventory Report', 0, 1, 'C');

    // Set subtitle
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'Generated on: ' . date('F j, Y, g:i a'), 0, 1, 'C');
    $pdf->Ln(10); // Add space

    // Create HTML table
    $html = '
    <table border="1" cellpadding="6" style="border-collapse: collapse; width: 100%;">
        <thead>
            <tr style="background-color: #e0e0e0; font-weight: bold;">
                <th>Medicine Name</th>
                <th>Category</th>
                <th>Batch Date</th>
                <th>Expiry Date</th>
                <th>Quantity</th>
                <th>Status</th>
                <th>Days Left</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($medicines as $m) {
        $days = $m['days_left'];
        $daysText = $days < 0 ? 'Expired' : ($days == 0 ? 'Today' : "$days days");
        $html .= "
        <tr>
            <td>{$m['name']}</td>
            <td>{$m['type']}</td>
            <td>" . date('M d, Y', strtotime($m['batch_date'])) . "</td>
            <td>" . date('M d, Y', strtotime($m['expired_date'])) . "</td>
            <td>{$m['quantity']}</td>
            <td>{$m['status']}</td>
            <td>$daysText</td>
        </tr>";
    }

    $html .= '</tbody></table>';

    // Write HTML to PDF
    $pdf->writeHTML($html, true, false, true, false, '');

    // Output PDF (download)
    $pdf->Output('expiration_report_' . date('Y-m-d') . '.pdf', 'D');
}

// ============= EXPORT TO EXCEL =============
elseif ($format === 'excel') {
    // Set headers to force download as Excel file
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename=expiration_report_' . date('Y-m-d') . '.xls');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Start HTML output
    echo '<html><head><meta charset="UTF-8"><title>Expiration Report</title></head><body>';
    echo '<h2>BENE MediCon - Expiration Inventory Report</h2>';
    echo '<p><strong>Generated on:</strong> ' . date('F j, Y, g:i a') . '</p>';
    echo '<table border="1" cellpadding="5">';
    echo '
    <tr style="background-color: #e0e0e0; font-weight: bold;">
        <th>Medicine Name</th>
        <th>Category</th>
        <th>Batch Date</th>
        <th>Expiry Date</th>
        <th>Quantity</th>
        <th>Status</th>
        <th>Days Left</th>
    </tr>';

    foreach ($medicines as $m) {
        $days = $m['days_left'];
        $daysText = $days < 0 ? 'Expired' : ($days == 0 ? 'Today' : "$days days");
        echo "<tr>
            <td>{$m['name']}</td>
            <td>{$m['type']}</td>
            <td>" . date('M d, Y', strtotime($m['batch_date'])) . "</td>
            <td>" . date('M d, Y', strtotime($m['expired_date'])) . "</td>
            <td>{$m['quantity']}</td>
            <td>{$m['status']}</td>
            <td>$daysText</td>
        </tr>";
    }

    echo '</table></body></html>';
    exit;
}
?>