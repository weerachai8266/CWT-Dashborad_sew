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
    $type = $_GET['type'] ?? 'daily';
    $action = $_GET['action'] ?? 'kpis';
    
    // Validate dates
    if (!strtotime($start_date) || !strtotime($end_date)) {
        throw new Exception('Invalid date format');
    }
    
    // สร้าง instance ของ get_db class
    $db_handler = new get_db($conn);
    
    // ✅ ฟังก์ชันช่วยคำนวณ target หลายวัน
    function computeLineTarget($db_handler, $hourly_target_rate, $start_date, $end_date) {
        if ($hourly_target_rate <= 0) return 0;
        $line_target = 0;
        $d1 = new DateTime($start_date);
        $d2 = new DateTime($end_date);
        while ($d1 <= $d2) {
            $day = $d1->format('Y-m-d');
            $hours = $db_handler->getWorkingHours($day, $day); // ชั่วโมงของวันนั้น (หักเบรก)
            foreach ($hours as $h) {
                $mins = $db_handler->getActualWorkingMinutes($h);
                $line_target += round(($hourly_target_rate * $mins) / 60);
            }
            $d1->modify('+1 day');
        }
        return $line_target;
    }
    /**
     * คำนวณ Man-Hours ที่ถูกต้องสำหรับ Productivity โดยคำนึงถึงค่า thour และเวลาพัก
     * @param PDO $conn Database connection
     * @param string $start_date Start date in YYYY-MM-DD format
     * @param string $end_date End date in YYYY-MM-DD format
     * @return float Total man-hours after adjusting for actual working time
     */
    function calculateActualManHours(PDO $conn, string $start_date, string $end_date): float {
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
                    
                    // หักเวลาพักตามกะการทำงาน
                    $shift = $r['shift'] ?? 'เช้า';  // ถ้าไม่มีข้อมูล shift ให้เป็นเช้า
                    
                    if ($shift == 'เช้า' || $shift == 'บ่าย') {
                        // หักพัก 20 นาทีต่อ 4 ชั่วโมง (สัดส่วน 220/240 = 0.9167)
                        $actual_hours = $hours * (220/240);
                    } else {
                        // OT ไม่ต้องหักพัก
                        $actual_hours = $hours;
                    }
                    
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
                $total_actual = 0;
                $total_target = 0;

                // // ✅ ดึง manpower (เหมือนเดิม)
                // $total_employees_man_hours = 0;
                // try {
                //     $sql_manpower = "SELECT DATE(created_at) as d, thour,
                //                             fc_act, fb_act, rc_act, rb_act, 3rd_act as third_act, sub_act
                //                     FROM sewing_man_act
                //                     WHERE DATE(created_at) BETWEEN ? AND ?
                //                     ORDER BY d, thour";
                //     $stmt = $conn->prepare($sql_manpower);
                //     $stmt->execute([$start_date, $end_date]);
                //     $mp_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                //     foreach ($mp_rows as $r) {
                //         $sum = (int)$r['fc_act'] + (int)$r['fb_act'] + (int)$r['rc_act'] + (int)$r['rb_act']
                //             + (int)$r['third_act'] + (int)$r['sub_act'];
                //         if ($sum > 0) $total_employees_man_hours += $sum; // man-hours (1 ชั่วโมงต่อแถว)
                //     }
                // } catch (PDOException $e) {
                //     error_log("Manpower fallback: ".$e->getMessage());
                // }

                foreach ($summary_data as $line => $data) {
                    $actual = (int)($data['total_qty'] ?? 0);
                    $total_actual += $actual;

                    $hourly_target_rate = (int)($data['target'] ?? 0);
                    if ($hourly_target_rate == 0) $hourly_target_rate = 49; // fallback

                    // ✅ ใช้ฟังก์ชันใหม่สำหรับหลายวัน
                    $line_target = computeLineTarget($db_handler, $hourly_target_rate, $start_date, $end_date);
                    $total_target += $line_target;
                }

                if ($total_target > 0) {
                    $eff = ($total_actual / $total_target) * 100;
                    $kpis['overall_efficiency'] = round($eff, 2);
                }

                // Productivity = Output / Man-Hours
                $actual_man_hours = calculateActualManHours($conn, $start_date, $end_date);
                if ($actual_man_hours > 0) {
                    $kpis['productivity_rate'] = round($total_actual / $actual_man_hours, 2);
                } elseif ($total_employees_man_hours > 0) {
                    // fallback ถ้ามีปัญหากับการคำนวณแบบใหม่
                    $kpis['productivity_rate'] = round($total_actual / $total_employees_man_hours, 2);
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
    
    // ดึงข้อมูล Efficiency Trend โดยใช้ data.class.php
    function getEfficiencyTrend($db_handler, $start_date, $end_date, $type) {
        $trend_data = [];
        
        try {
            if ($type === 'monthly') {
                $start_month = date('Y-m-01', strtotime($start_date));
                $end_month = date('Y-m-t', strtotime($end_date));
                $query_start = $start_month;
                $query_end = $end_month;
            } else {
                $query_start = date('Y-m-01', strtotime($start_date));
                $query_end = $end_date;
            }
            
            // สร้างรายการวันที่จาก start ถึง end
            $current_date = $query_start;
            while (strtotime($current_date) <= strtotime($query_end)) {
                // ✅ ใช้ getSummaryReport สำหรับแต่ละวัน
                $daily_summary = $db_handler->getSummaryReport($current_date, $current_date, 'pieces');
                
                $period_actual = 0;
                $period_target = 0;
                
                if ($daily_summary) {
                    // ✅ ดึงชั่วโมงทำงานจริงของวันนี้
                    $working_hours = $db_handler->getWorkingHours($current_date, $current_date);
                    // $hours_count = count($working_hours);

                    // ✅ คำนวณ: actual/ชั่วโมง × จำนวนชั่วโมงทำงาน
                    foreach ($daily_summary as $line => $data) {
                        $period_actual += (int)($data['total_qty'] ?? 0);
                    
                        // ✅ คำนวณเป้าหมายจากเวลาทำงานจริง (หักเบรกแล้ว)
                        $hourly_target_rate = (int)($data['target'] ?? 0);
                        $line_target = 0;
                        
                        foreach ($working_hours as $hour) {
                            $actual_working_minutes = $db_handler->getActualWorkingMinutes($hour);
                            $line_target += round(($hourly_target_rate * $actual_working_minutes) / 60);
                        }

                        $period_target += $line_target;
                    }
                }
                
                // เพิ่มข้อมูลเฉพาะวันที่มีการทำงาน
                if ($period_actual > 0 || $period_target > 0) {
                    $efficiency = 0;
                    if ($period_target > 0) {
                        $efficiency = ($period_actual / $period_target) * 100;
                    }
                    
                    $day_of_week = date('w', strtotime($current_date));
                    
                    $trend_data[] = [
                        'period' => $current_date,
                        'period_raw' => $current_date,
                        'efficiency' => round($efficiency, 2),
                        'actual' => $period_actual,
                        'target' => round($period_target),
                        'is_weekend' => ($day_of_week == 0 || $day_of_week == 7)
                    ];
                }
                
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
            }
            
        } catch (Exception $e) {
            error_log("Error getting efficiency trend: " . $e->getMessage());
        }
        
        return $trend_data;
    }
    
    // ดึงข้อมูล Line Performance โดยใช้ data.class.php
    function getLinePerformance($db_handler, $start_date, $end_date) {
        $line_performance = [];
        try {
            $summary_data = $db_handler->getSummaryReport($start_date, $end_date, 'pieces');
            if ($summary_data) {
                foreach ($summary_data as $line => $data) {
                    $hourly_target_rate = (int)($data['target'] ?? 0);
                    if ($hourly_target_rate == 0) $hourly_target_rate = 49;
                    $line_target = computeLineTarget($db_handler, $hourly_target_rate, $start_date, $end_date);

                    $actual = (int)($data['total_qty'] ?? 0);
                    $eff = $line_target > 0 ? ($actual / $line_target) * 100 : 0;

                    $line_performance[] = [
                        'process' => strtoupper($line === 'third' ? '3RD' : $line),
                        'actual_qty' => $actual,
                        'target_qty' => $line_target,
                        'efficiency' => round($eff, 2)
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("Line perf error: ".$e->getMessage());
        }
        return $line_performance;
    }
    
    // ตรวจสอบว่ามีฟังก์ชันใน data.class.php หรือไม่
    function hasMethod($db_handler, $method_name) {
        return method_exists($db_handler, $method_name);
    }
    
    // จัดการ request ตาม action
    switch ($action) {
        case 'kpis':
            $response['data'] = calculatePerformanceKPIs($db_handler, $conn, $start_date, $end_date);
            break;
            
        case 'efficiency_trend':
            $response['data'] = getEfficiencyTrend($db_handler, $start_date, $end_date, $type);
            break;
            
        case 'line_performance':
            $response['data'] = getLinePerformance($db_handler, $start_date, $end_date);
            break;
            
        case 'all':
            $response['data'] = [
                'kpis' => calculatePerformanceKPIs($db_handler, $conn, $start_date, $end_date),
                'efficiency_trend' => getEfficiencyTrend($db_handler, $start_date, $end_date, $type),
                'line_performance' => getLinePerformance($db_handler, $start_date, $end_date)
            ];
            break;
            
        case 'methods':
            // ตรวจสอบว่ามีฟังก์ชันอะไรใน data.class.php บ้าง
            $methods = get_class_methods($db_handler);
            $response['data'] = [
                'available_methods' => $methods,
                'has_getSummaryReport' => hasMethod($db_handler, 'getSummaryReport'),
                'has_getHourlyReport' => hasMethod($db_handler, 'getHourlyReport'),
                'has_getDailyReport' => hasMethod($db_handler, 'getDailyReport'),
                'has_getDetailReport' => hasMethod($db_handler, 'getDetailReport'),
                'has_debugTableData' => hasMethod($db_handler, 'debugTableData')
            ];
            break;
            
        case 'debug':
            // Debug การคำนวณ target แบบละเอียด
            break;
            
        default:
            throw new Exception('Invalid action parameter. Available actions: kpis, efficiency_trend, line_performance, all, methods, debug');
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