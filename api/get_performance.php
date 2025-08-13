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
    include(__DIR__ . "/../connect.php");
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
    
    // คำนวณ Performance KPIs โดยใช้ data.class.php + การคำนวณคุณภาพเดิม
    function calculatePerformanceKPIs($db_handler, $conn, $start_date, $end_date) {
        $kpis = [
            'overall_efficiency' => 0,
            'quality_rate' => 0,
            'productivity_rate' => 0,
            'defect_rate' => 0
        ];
        
        try {
            // ✅ ใช้ getSummaryReport จาก data.class.php
            $summary_data = $db_handler->getSummaryReport($start_date, $end_date, 'pieces');
            
            if ($summary_data) {
                $total_actual = 0;
                $total_target = 0;

                // ✅ ดึงชั่วโมงทำงานจริง
                $working_hours = $db_handler->getWorkingHours($start_date, $end_date);
                $hours_count = count($working_hours); // จำนวนชั่วโมงทำงาน
                
                // ✅ ดึงจำนวนพนักงานจริงจากตาราง sewing_man_act
                $total_employees = 0;
                $total_working_hours = 0;
                
                try {
                    $sql_manpower = "SELECT fc_act, fb_act, rc_act, rb_act, 3rd_act as third_act, sub_act 
                                FROM sewing_man_act 
                                WHERE DATE(created_at) BETWEEN ? AND ?
                                ORDER BY created_at DESC";
                    
                    $stmt = $conn->prepare($sql_manpower);
                    $stmt->execute([$start_date, $end_date]);
                    $manpower_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // คำนวณจำนวนพนักงาน × ชั่วโมง รวม
                    foreach ($manpower_data as $mp) {
                        $hour_employees = (int)($mp['fc_act'] ?? 0) + 
                                        (int)($mp['fb_act'] ?? 0) + 
                                        (int)($mp['rc_act'] ?? 0) + 
                                        (int)($mp['rb_act'] ?? 0) + 
                                        (int)($mp['third_act'] ?? 0) + 
                                        (int)($mp['sub_act'] ?? 0);
                        
                        if ($hour_employees > 0) {
                            $total_employees += $hour_employees;
                            $total_working_hours += 1; // 1 ชั่วโมง
                        }                        
                    }
                    
                } catch (PDOException $e) {
                    error_log("Error getting manpower data: " . $e->getMessage());
                    // ใช้ค่า default ถ้าไม่มีข้อมูล
                    $total_employees = 4 * count($working_hours); // 4 คน × 9 ชั่วโมง
                    $total_working_hours = count($working_hours);
                }

                // รวมข้อมูลจากทุกไลน์
                foreach ($summary_data as $line => $data) {
                    $total_actual += (int)($data['total_qty'] ?? 0);

                    // ✅ คำนวณเป้าหมายจากเวลาทำงานจริง (หักเบรกแล้ว)
                    $hourly_target_rate = (int)($data['target'] ?? 0); // 10 ชิ้น/ชั่วโมง
                    $line_target = 0;
                    
                    foreach ($working_hours as $hour) {
                        // ดึงเวลาทำงานจริงในชั่วโมงนี้ (หักเบรกแล้ว)
                        $actual_working_minutes = $db_handler->getActualWorkingMinutes($hour);
                        // คำนวณเป้าหมายสำหรับชั่วโมงนี้
                        $line_target += round(($hourly_target_rate * $actual_working_minutes) / 60);
                    }

                    $total_target += $line_target;
                }
                // คำนวณ Overall Efficiency
                if ($total_target > 0) {
                    $overall_efficiency = ($total_actual / $total_target) * 100;
                    $kpis['overall_efficiency'] = round($overall_efficiency, 2);
                }
                // ✅ คำนวณ Productivity = Output ÷ Total Man-Hours
                if ($total_employees > 0) {
                    $productivity = $total_actual / $total_employees;
                    $kpis['productivity_rate'] = round($productivity, 2);
                    
                    error_log("Productivity calculation:");
                    error_log("- Total Output: $total_actual");
                    error_log("- Total Man-Hours: $total_employees");
                    error_log("- Productivity (Output/Man-Hour): " . $kpis['productivity_rate']);
                } else {
                    $kpis['productivity_rate'] = 0;
                }
                // ✅ ใช้การคำนวณคุณภาพแบบเดิม
                try {
                    $sql_defects = "SELECT SUM(qty) as defect_count FROM qc_ng 
                                   WHERE DATE(created_at) BETWEEN ? AND ?";
                    $stmt = $conn->prepare($sql_defects);
                    $stmt->execute([$start_date, $end_date]);
                    $defects_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    $total_defects = (int)($defects_data['defect_count'] ?? 0);
                    
                    if ($total_actual > 0) {
                        $kpis['defect_rate'] = round(($total_defects / $total_actual) * 100, 2);
                        $kpis['quality_rate'] = round((($total_actual - $total_defects) / $total_actual) * 100, 2);
                    } else {
                        $kpis['quality_rate'] = 100;
                        $kpis['defect_rate'] = 0;
                    }
                } catch (PDOException $e) {
                    error_log("Error getting defect data: " . $e->getMessage());
                    $kpis['quality_rate'] = 100;
                    $kpis['defect_rate'] = 0;
                }
            }
            
        } catch (Exception $e) {
            error_log("Error calculating KPIs: " . $e->getMessage());
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
                $query_start = $start_date;
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
                        'is_weekend' => ($day_of_week == 0 || $day_of_week == 6)
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
            // ✅ ใช้ getSummaryReport จาก data.class.php
            $summary_data = $db_handler->getSummaryReport($start_date, $end_date, 'pieces');
            
            if ($summary_data) {
                // ✅ ดึงชั่วโมงทำงานจริง
                $working_hours = $db_handler->getWorkingHours($start_date, $end_date);
                $hours_count = count($working_hours); // จำนวนชั่วโมงทำงาน
            
                foreach ($summary_data as $line => $data) {
                    $process_name = strtoupper($line === 'third' ? '3RD' : $line);
                    
                    // ✅ คำนวณเป้าหมายจากเวลาทำงานจริง (หักเบรกแล้ว)
                    $hourly_target_rate = (int)($data['target'] ?? 0);
                    $line_target = 0;
                    
                    foreach ($working_hours as $hour) {
                        $actual_working_minutes = $db_handler->getActualWorkingMinutes($hour);
                        $line_target += round(($hourly_target_rate * $actual_working_minutes) / 60);
                    }

                    $line_performance[] = [
                        'process' => $process_name,
                        'actual_qty' => (int)($data['total_qty'] ?? 0),
                        'target_qty' => $line_target, // ✅ ใช้ค่าที่คำนวณจากเวลาจริง
                        'efficiency' => round($data['percentage'] ?? 0, 2)
                    ];
                }
            }
            
        } catch (Exception $e) {
            error_log("Error getting line performance: " . $e->getMessage());
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
            try {
                $summary_data = $db_handler->getSummaryReport($start_date, $end_date, 'pieces');
                $debug_data = $db_handler->debugTableData($start_date, $end_date);
                $working_hours = $db_handler->getWorkingHours($start_date, $end_date);
                
                $response['data'] = [
                    'summary_report' => $summary_data,
                    'debug_table' => $debug_data,
                    'working_hours' => $working_hours,
                    'target_calculation' => [],
                    'total_summary' => [
                        'total_actual' => 0,
                        'total_target' => 0,
                        'your_calculation' => 315,
                        'program_calculation' => 0,
                        'difference' => 0
                    ]
                ];
                
                // Debug การคำนวณ target แต่ละไลน์
                if ($summary_data && $working_hours) {
                    $total_actual = 0;
                    $total_target = 0;
                    
                    foreach ($summary_data as $line => $data) {
                        $actual_qty = (int)($data['total_qty'] ?? 0);
                        $hourly_target_rate = (int)($data['target'] ?? 0);
                        // ✅ ถ้า target = 0 ให้ใช้ค่า default
                        if ($hourly_target_rate == 0) {
                            $hourly_target_rate = 49; // ค่าเป้าหมาย/ชั่วโมงที่คุณบอก
                            error_log("Target for line $line is 0, using default: $hourly_target_rate");
                        }
                        $line_target = 0;
                        $hour_details = [];
                        
                        // คำนวณแต่ละชั่วโมง
                        foreach ($working_hours as $hour) {
                            $actual_working_minutes = $db_handler->getActualWorkingMinutes($hour);
                            $hour_target = ($hourly_target_rate * $actual_working_minutes) / 60;
                            $hour_target_rounded = round($hour_target);
                            
                            $line_target += $hour_target;
                            
                            $hour_details[] = [
                                'hour' => $hour,
                                'working_minutes' => $actual_working_minutes,
                                'target_rate_per_hour' => $hourly_target_rate,
                                'calculated_target' => round($hour_target, 4),
                                'rounded_target' => $hour_target_rounded
                            ];
                        }
                        
                        $line_target_rounded = round($line_target);
                        $total_actual += $actual_qty;
                        $total_target += $line_target_rounded;
                        
                        // เก็บข้อมูล debug ของแต่ละไลน์
                        $response['data']['target_calculation'][$line] = [
                            'process' => strtoupper($line === 'third' ? '3RD' : $line),
                            'actual_qty' => $actual_qty,
                            'hourly_target_rate' => $hourly_target_rate,
                            'line_target_before_round' => round($line_target, 4),
                            'line_target_after_round' => $line_target_rounded,
                            'hour_by_hour' => $hour_details,
                            'total_working_minutes' => array_sum(array_column($hour_details, 'working_minutes')),
                            'manual_calculation' => [
                                'formula' => '(target_rate × working_minutes) ÷ 60',
                                'per_hour' => array_map(function($detail) {
                                    return $detail['target_rate_per_hour'] . ' × ' . $detail['working_minutes'] . ' ÷ 60 = ' . $detail['calculated_target'];
                                }, $hour_details)
                            ]
                        ];
                    }
                    
                    // สรุปรวม
                    $program_calculation = $total_target;
                    $your_calculation = 315;
                    $difference = $program_calculation - $your_calculation;
                    
                    $response['data']['total_summary'] = [
                        'total_actual' => $total_actual,
                        'total_target_program' => $program_calculation,
                        'your_calculation' => $your_calculation,
                        'difference' => $difference,
                        'working_hours_count' => count($working_hours),
                        'lines_count' => count($summary_data),
                        'calculation_method' => 'Sum of (target_rate × actual_working_minutes ÷ 60) for each hour, then round per line'
                    ];
                    
                    // เพิ่มข้อมูล available fields
                    $response['data']['available_fields'] = [];
                    foreach ($summary_data as $line => $data) {
                        $response['data']['available_fields'][$line] = array_keys($data);
                    }
                }
                
            } catch (Exception $e) {
                $response['data'] = [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ];
                error_log("Debug error: " . $e->getMessage());
            }
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