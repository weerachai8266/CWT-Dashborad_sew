<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

include(__DIR__ . "/../config/db.php"); // เชื่อมต่อฐานข้อมูล
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
            
        case 'model_summary':
            $date = $_GET['start_date'] ?? date('Y-m-d');
            
            try {
                // สร้าง array เก็บข้อมูลโมเดล
                $models = [];
                $totals = [
                    'fc' => 0, 'fb' => 0, 'rc' => 0, 
                    'rb' => 0, '3rd' => 0, 'sub' => 0
                ];
                
                // ดึงข้อมูลจากแต่ละตาราง (แต่ละไลน์)
                $lines = ['fc', 'fb', 'rc', 'rb', '3rd', 'sub'];
                
                foreach ($lines as $line) {
                    $tableName = "sewing_{$line}";
                    
                    // ข้ามไลน์ที่อาจไม่มีตาราง
                    try {
                        $stmt = $conn->prepare("
                            SELECT item, SUM(qty) as total
                            FROM {$tableName}
                            WHERE DATE(created_at) = ? AND status = 10
                            GROUP BY item
                        ");
                        $stmt->execute([$date]);
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($rows as $row) {
                            $modelName = $row['item'];
                            $qty = (int)$row['total'];
                            
                            if (!isset($models[$modelName])) {
                                $models[$modelName] = [
                                    'name' => $modelName,
                                    'fc' => 0, 'fb' => 0, 'rc' => 0, 
                                    'rb' => 0, '3rd' => 0, 'sub' => 0,
                                    'total' => 0
                                ];
                            }
                            
                            $models[$modelName][$line] += $qty;
                            $models[$modelName]['total'] += $qty;
                            $totals[$line] += $qty;
                        }
                    } catch (Exception $tableError) {
                        // ถ้าเกิดข้อผิดพลาดกับตารางไหน (เช่น ไม่มีตาราง) ให้ข้ามไป
                        error_log("Error querying table {$tableName}: " . $tableError->getMessage());
                        continue;
                    }
                }
                
                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'models' => array_values($models),
                        'totals' => $totals
                    ]
                ]);
                exit; // สำคัญ! ไม่ให้ทำงานต่อไปที่ echo json_encode ด้านล่าง
            } catch (Exception $e) {
                error_log("Model summary error: " . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'Database error: ' . $e->getMessage()
                ]);
                exit;
            }
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
