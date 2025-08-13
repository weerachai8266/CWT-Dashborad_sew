<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

include(__DIR__ . "/../connect.php"); // เชื่อมต่อฐานข้อมูล
require_once(__DIR__ . '/data.class.php');

// Handle API Requests
try {
    $report = new get_db($conn);

    $start_date = $_GET['start_date'] ?? date('Y-m-d');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $type = $_GET['type'] ?? 'hourly';
    $display_type = $_GET['display_type'] ?? 'pieces'; // 'pieces' หรือ 'percentage'

    switch ($type) {
        case 'hourly':
            $data = $report->getHourlyReport($start_date, $end_date, $display_type);
            break;
            
        case 'daily':
            $data = $report->getDailyReport($start_date, $end_date);
            break;
            
        case 'summary':
            $data = $report->getSummaryReport($start_date, $end_date, $display_type);
            break;
            
        case 'debug':
            $data = $report->debugTableData($start_date, $end_date);
            break;
            
        default:
            throw new Exception('Invalid report type');
    }

    echo json_encode([
        'success' => true,
        'data' => $data,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'type' => $type,
        'display_type' => $display_type
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => $e->getFile()
    ]);
}

?>
