<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$response = ['success' => false, 'data' => [], 'message' => ''];

try {
    include(__DIR__ . "/../config/db.php");
    require_once(__DIR__ . '/data.class.php');

    // ตรวจสอบการเชื่อมต่อฐานข้อมูล
    if (!isset($conn)) {
        throw new Exception('Database connection not found');
    }
    
    // ทดสอบการเชื่อมต่อ
    $conn->query("SELECT 1");
    
    // รับพารามิเตอร์
    $start_date = $_GET['start_date'] ?? date('Y-m-d');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $action = $_GET['action'] ?? 'kpis';
    
    // Validate dates
    if (!strtotime($start_date) || !strtotime($end_date)) {
        throw new Exception('Invalid date format');
    }
    
    // สร้าง instance ของ get_db class
    $db_handler = new get_db($conn);
    
    /**
     * คำนวณ Man-Hours ที่ถูกต้องสำหรับ Productivity โดยคำนึงถึงค่า thour และเวลาพัก
     * @param PDO $conn Database connection
     * @param string $start_date Start date in YYYY-MM-DD format
     * @param string $end_date End date in YYYY-MM-DD format
     * @return float Total man-hours after adjusting for actual working time
     */
    function calculateActualManHours(PDO $conn, get_db $db_handler, string $start_date, string $end_date): float {
        $total_man_hours = 0;
        try {
            $sql_manpower = "SELECT DATE(created_at) as d, thour, shift,
                            fc_act, fb_act, rc_act, rb_act, 3rd_act as third_act, sub_act
                    FROM sewing_man_act
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    ORDER BY d, thour";
            $stmt = $conn->prepare($sql_manpower);
            $stmt->execute([$start_date, $end_date]);
            $mp_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($mp_rows as $r) {
                $sum = (int)$r['fc_act'] + (int)$r['fb_act'] + (int)$r['rc_act'] + (int)$r['rb_act']
                    + (int)$r['third_act'] + (int)$r['sub_act'];
                    
                if ($sum > 0) {
                    // นำค่า thour มาคำนวณเวลาทำงานจริง
                    $hours = (float)$r['thour'];
                    
                    $shift = $r['shift'] ?? 'เช้า';  // ถ้าไม่มีข้อมูล shift ให้เป็นเช้า
                    $actual_hours = $db_handler->getActualWorkingHoursForShift($shift, $hours);
                    
                    $total_man_hours += $sum * $actual_hours;
                }
            }
        } catch (PDOException $e) {
            error_log("Actual Man-hours calculation error: ".$e->getMessage());
        }
        
        return $total_man_hours;
    }

    // คำนวณ Performance KPIs โดยใช้ data.class.php + การคำนวณคุณภาพเดิม
    function calculatePerformanceKPIs($db_handler, $conn, $start_date, $end_date) {
        $kpis = [
            'overall_efficiency' => 0,
            'quality_rate' => 0,
            'productivity_rate' => 0,
            'defect_rate' => 0
        ];
        try {
            $summary_data = $db_handler->getSummaryReport($start_date, $end_date, 'pieces');
            if ($summary_data) {
                $total_actual    = 0;
                $line_percentages = [];

                // ใช้สูตรเดียวกับหน้า Production:
                // overall_efficiency = average ของ % รายชั่วโมงของแต่ละ line
                // (ค่า percentage ใน getSummaryReport คำนวณจาก calculateHourlyAveragePercentage)
                foreach ($summary_data as $line => $data) {
                    $total_actual += (int)($data['total_qty'] ?? 0);
                    if (isset($data['percentage'])) {
                        $line_percentages[] = (float)$data['percentage'];
                    }
                }

                if (count($line_percentages) > 0) {
                    $kpis['overall_efficiency'] = round(
                        array_sum($line_percentages) / count($line_percentages), 2
                    );
                }

                // Productivity = Output / Man-Hours
                $actual_man_hours = calculateActualManHours($conn, $db_handler, $start_date, $end_date);
                if ($actual_man_hours > 0) {
                    $kpis['productivity_rate'] = round($total_actual / $actual_man_hours, 2);
                }

                // Defects & Quality
                try {
                    $sql_defects = "SELECT SUM(qty) as defect_count FROM qc_ng 
                                    WHERE DATE(created_at) BETWEEN ? AND ?";
                    $stmt = $conn->prepare($sql_defects);
                    $stmt->execute([$start_date, $end_date]);
                    $def = (int)($stmt->fetch(PDO::FETCH_ASSOC)['defect_count'] ?? 0);
                    if ($total_actual > 0) {
                        $kpis['defect_rate'] = round(($def / $total_actual) * 100, 2);
                        $kpis['quality_rate'] = round((($total_actual - $def) / $total_actual) * 100, 2);
                    } else {
                        $kpis['quality_rate'] = 100;
                    }
                } catch (PDOException $e) {
                    error_log("Defects error: ".$e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log("KPI calc error: ".$e->getMessage());
        }
        return $kpis;
    }
    // จัดการ request ตาม action
    switch ($action) {
        case 'kpis':
            $response['data'] = calculatePerformanceKPIs($db_handler, $conn, $start_date, $end_date);
            break;

        default:
            throw new Exception('Invalid action parameter. Available actions: kpis');
    }
    
    $response['success'] = true;
    $response['message'] = 'Data retrieved successfully';
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log("Performance API Error: " . $e->getMessage());
} catch (PDOException $e) {
    $response['message'] = 'Database Error: ' . $e->getMessage();
    error_log("Performance API Database Error: " . $e->getMessage());
}

// ส่งข้อมูل JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
?>
