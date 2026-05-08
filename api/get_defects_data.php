<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/app.php';
header('Content-Type: application/json; charset=utf-8');
setCorsHeaders();

include __DIR__ . '/../config/db.php';

$start_date = !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date   = !empty($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-d');

if (!validateDate($start_date) || !validateDate($end_date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

try {
    // ทดสอบการเชื่อมต่อ Database
    if (!$conn) {
        throw new PDOException("Database connection failed");
    }
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Query สำหรับของเสียแยกตามไลน์
    $sql_line = "SELECT process, SUM(qty) as count 
                 FROM qc_ng 
                 WHERE DATE(created_at) BETWEEN ? AND ?
                 GROUP BY process 
                 ORDER BY count DESC";

    // Query สำหรับของเสียแยกตามปัญหา
    $sql_problem = "SELECT detail, SUM(qty) as count 
                   FROM qc_ng 
                   WHERE DATE(created_at) BETWEEN ? AND ?
                   GROUP BY detail 
                   ORDER BY count DESC";

    // Query สำหรับของเสียแยกตามโมเดล
    $sql_model = "SELECT i.model, SUM(qty) as count 
                 FROM qc_ng q
                 JOIN item i ON q.part = i.item
                 WHERE DATE(q.created_at) BETWEEN ? AND ?
                 GROUP BY i.model
                 ORDER BY count DESC";

    // Query สำหรับแนวโน้มของเสียตามวันที่
    $sql_timeline = "SELECT 
                    DATE(created_at) as defect_date, 
                    SUM(qty) as total_defects, 
                    GROUP_CONCAT(DISTINCT process) as processes
                FROM qc_ng 
                WHERE DATE(created_at) BETWEEN ? AND ? 
                GROUP BY DATE(created_at)
                ORDER BY defect_date";

    $stmt = $conn->prepare($sql_line);
    $stmt->execute([$start_date, $end_date]);
    $line_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare($sql_problem);
    $stmt->execute([$start_date, $end_date]);
    $problem_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare($sql_model);
    $stmt->execute([$start_date, $end_date]);
    $model_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare($sql_timeline);
    $stmt->execute([date('Y-m-01', strtotime($start_date)), $end_date]);
    $timeline_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ตรวจสอบข้อมูลว่างเปล่า
    if (empty($line_data) && empty($problem_data) && empty($model_data)) {
        echo json_encode([
            'success' => true,
            'line_data' => [],
            'problem_data' => [],
            'model_data' => [],
            'message' => 'No data found for the selected date range'
        ]);
        exit;
    }
    // แปลงข้อมูลวันที่ใน timeline_data ให้เป็นรูปแบบที่เหมาะสม
    echo json_encode([
        'success' => true,
        'line_data' => $line_data,
        'problem_data' => $problem_data,
        'model_data' => $model_data,
        'timeline_data' => $timeline_data
    ]);

} catch(PDOException $e) {
    // ส่งข้อความ error ที่ละเอียดขึ้น
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'General error: ' . $e->getMessage()
    ]);
}