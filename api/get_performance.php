<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');

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
    
    function isTodayFilter(string $start_date, string $end_date): bool {
        $today = date('Y-m-d');
        return $start_date === $today && $end_date === $today;
    }

    function loadShiftTimeConfig(): array {
        $defaults = [
            'เช้า' => ['start' => '08:00', 'end' => '12:00'],
            'บ่าย' => ['start' => '13:00', 'end' => '17:00'],
            'OT' => ['start' => '17:30', 'end' => '21:00'],
        ];

        $file = __DIR__ . '/../config/shift_times.json';
        if (!file_exists($file)) {
            return $defaults;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return $defaults;
        }

        foreach ($defaults as $shift => $times) {
            $data[$shift]['start'] = $data[$shift]['start'] ?? $times['start'];
            $data[$shift]['end'] = $data[$shift]['end'] ?? $times['end'];
        }

        return $data;
    }

    function getActiveBreakTimes(PDO $conn): array {
        $stmt = $conn->prepare("SELECT start_time, end_time FROM break_times WHERE is_active = 1");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    function buildTimeRange(string $date, string $start_time, string $end_time): array {
        $start = new DateTimeImmutable($date . ' ' . substr($start_time, 0, 5));
        $end = new DateTimeImmutable($date . ' ' . substr($end_time, 0, 5));
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }
        return [$start, $end];
    }

    function overlapSeconds(DateTimeImmutable $start_a, DateTimeImmutable $end_a, DateTimeImmutable $start_b, DateTimeImmutable $end_b): int {
        $start = max($start_a->getTimestamp(), $start_b->getTimestamp());
        $end = min($end_a->getTimestamp(), $end_b->getTimestamp());
        return max(0, $end - $start);
    }

    function calculateElapsedNetHoursForShift(string $date, string $shift, DateTimeImmutable $now, array $shift_times, array $break_times): float {
        if (!isset($shift_times[$shift])) {
            return 0;
        }

        [$shift_start, $shift_end] = buildTimeRange($date, $shift_times[$shift]['start'], $shift_times[$shift]['end']);
        if ($now <= $shift_start) {
            return 0;
        }

        $elapsed_end = $now < $shift_end ? $now : $shift_end;
        $gross_seconds = max(0, $elapsed_end->getTimestamp() - $shift_start->getTimestamp());
        $break_seconds = 0;

        foreach ($break_times as $break) {
            [$break_start, $break_end] = buildTimeRange($date, $break['start_time'], $break['end_time']);
            $break_seconds += overlapSeconds($shift_start, $elapsed_end, $break_start, $break_end);
        }

        return max(0, ($gross_seconds - $break_seconds) / 3600);
    }

    /**
     * คำนวณ Man-Hours สำหรับ Productivity
     * - ฟิลเตอร์วันนี้: ใช้เวลาเริ่ม/จบกะจาก config และหัก break_times ตามเวลาที่ผ่านไปจริง
     * - ฟิลเตอร์หลายวัน/วันอื่น: ใช้ thour จากฐานข้อมูลตามสูตรเดิม
     */
    function calculateActualManHours(PDO $conn, string $start_date, string $end_date): float {
        $total_man_hours = 0;
        try {
            $use_today_elapsed = isTodayFilter($start_date, $end_date);
            $shift_times = $use_today_elapsed ? loadShiftTimeConfig() : [];
            $break_times = $use_today_elapsed ? getActiveBreakTimes($conn) : [];
            $now = new DateTimeImmutable('now');

            $sql_manpower = "SELECT DATE(created_at) as d, shift, thour,
                            fc_act, fb_act, rc_act, rb_act, 3rd_act as third_act, sub_act
                    FROM sewing_man_act
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    ORDER BY d, shift";
            $stmt = $conn->prepare($sql_manpower);
            $stmt->execute([$start_date, $end_date]);
            $mp_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($mp_rows as $r) {
                $sum = (int)$r['fc_act'] + (int)$r['fb_act'] + (int)$r['rc_act'] + (int)$r['rb_act']
                    + (int)$r['third_act'] + (int)$r['sub_act'];
                    
                if ($sum > 0) {
                    if ($use_today_elapsed) {
                        $hours = calculateElapsedNetHoursForShift($r['d'], trim((string)($r['shift'] ?? '')), $now, $shift_times, $break_times);
                    } else {
                        // thour เป็นชั่วโมงสุทธิหลังหักเบรคแล้ว จึงไม่ต้องหักเบรคซ้ำ
                        $hours = (float)$r['thour'];
                    }

                    $total_man_hours += $sum * $hours;
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
                $actual_man_hours = calculateActualManHours($conn, $start_date, $end_date);
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
