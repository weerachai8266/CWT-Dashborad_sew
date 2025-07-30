<?php
class get_db {
    private $conn;
    private $tables = [
        'fc' => 'sewing_fc',
        'fb' => 'sewing_fb',
        'rc' => 'sewing_rc',
        'rb' => 'sewing_rb',
        'third' => 'sewing_3rd',
        'sub' => 'sewing_sub'
    ];

    public function __construct($db) {
        $this->conn = $db;
    }

    // ดึงข้อมูลเวลาพักเบรคที่ active
    private function getBreakTimes() {
        try {
            $query = "SELECT break_name, start_time, end_time, duration_minutes
                      FROM break_times
                      WHERE is_active = 1
                      ORDER BY start_time";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching break times: " . $e->getMessage());
            return [];
        }
    }

    // คำนวณเวลาทำงานจริงในแต่ละชั่วโมง (หักเวลาพักเบรค)
    private function getActualWorkingMinutes($hour) {
        $break_times = $this->getBreakTimes();
        $total_break_minutes = 0;
        
        foreach ($break_times as $break) {
            $start_hour = (int)date('H', strtotime($break['start_time']));
            $end_hour = (int)date('H', strtotime($break['end_time']));
            $start_minute = (int)date('i', strtotime($break['start_time']));
            $end_minute = (int)date('i', strtotime($break['end_time']));
            
            // ตรวจสอบว่าเวลาพักเบรคอยู่ในชั่วโมงนี้หรือไม่
            if ($start_hour == $hour) {
                // เบรคเริ่มในชั่วโมงนี้
                if ($end_hour == $hour) {
                    // เบรคเริ่มและจบในชั่วโมงเดียวกัน
                    $total_break_minutes += ($end_minute - $start_minute);
                } else {
                    // เบรคเริ่มในชั่วโมงนี้แต่จบในชั่วโมงถัดไป
                    $total_break_minutes += (60 - $start_minute);
                }
            } elseif ($end_hour == $hour && $start_hour < $hour) {
                // เบรคเริ่มในชั่วโมงก่อนหน้าแต่จบในชั่วโมงนี้
                $total_break_minutes += $end_minute;
            } elseif ($start_hour < $hour && $end_hour > $hour) {
                // เบรคครอบคลุมทั้งชั่วโมง
                $total_break_minutes += 60;
            }
        }
        
        return max(0, 60 - $total_break_minutes);
    }

    // ดึงข้อมูลเป้าหมายจากตาราง sewing_target ตามวันที่ที่กำหนด
    private function getTargets($date = null) {
        try {
            if ($date === null) {
                $date = date('Y-m-d');
            }
            
            // ลองหาเป้าหมายในวันที่ที่กำหนดก่อน
            $query = "SELECT fc, fb, rc, rb, `3rd`, sub
                      FROM sewing_target
                      WHERE DATE(created_at) = :date
                      ORDER BY created_at DESC
                      LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':date', $date);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'fc' => (int)$result['fc'],
                    'fb' => (int)$result['fb'],
                    'rc' => (int)$result['rc'],
                    'rb' => (int)$result['rb'],
                    'third' => (int)$result['3rd'],
                    'sub' => (int)$result['sub']
                ];
            }
            
            // ถ้าไม่มีเป้าหมายในวันนั้น หาเป้าหมายล่าสุดก่อนวันที่นั้น
            $query = "SELECT fc, fb, rc, rb, `3rd`, sub
                      FROM sewing_target
                      WHERE DATE(created_at) <= :date
                      ORDER BY created_at DESC
                      LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':date', $date);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'fc' => (int)$result['fc'],
                    'fb' => (int)$result['fb'],
                    'rc' => (int)$result['rc'],
                    'rb' => (int)$result['rb'],
                    'third' => (int)$result['3rd'],
                    'sub' => (int)$result['sub']
                ];
            } else {
                // ถ้าไม่มีเป้าหมายเลย ใช้ค่าเริ่มต้น
                return [
                    'fc' => 10, 'fb' => 10, 'rc' => 10,
                    'rb' => 10, 'third' => 10, 'sub' => 10
                ];
            }
        } catch (PDOException $e) {
            error_log("Error fetching targets for date $date: " . $e->getMessage());
            // ค่าเริ่มต้นเมื่อเกิดข้อผิดพลาด
            return [
                'fc' => 10, 'fb' => 10, 'rc' => 10,
                'rb' => 10, 'third' => 10, 'sub' => 10
            ];
        }
    }

    // ดึงช่วงเวลาการทำงานจริงจากข้อมูล
    private function getWorkingHours($start_date, $end_date) {
        $all_hours = [];
        
        foreach ($this->tables as $line => $table_name) {
            // ตรวจสอบว่าตารางมีอยู่จริง
            $check_table = "SHOW TABLES LIKE :table_name";
            $stmt = $this->conn->prepare($check_table);
            $stmt->bindParam(':table_name', $table_name);
            $stmt->execute();
            
            if ($stmt->rowCount() == 0) {
                error_log("Table $table_name does not exist");
                continue;
            }

            $query = "SELECT DISTINCT HOUR(created_at) as hour 
                      FROM " . $table_name . " 
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date ORDER BY hour";
            
            try {
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->execute();
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $all_hours[] = (int)$row['hour'];
                }
            } catch (PDOException $e) {
                error_log("Error querying table $table_name: " . $e->getMessage());
            }
        }
        
        // ลบ duplicate และ sort
        $all_hours = array_unique($all_hours);
        sort($all_hours);
        
        // ถ้าไม่มีข้อมูล ให้ใช้เวลามาตรฐาน
        if (empty($all_hours)) {
            $all_hours = range(8, 16); // 8:00-16:00 เป็นค่าเริ่มต้น
        }
        
        return $all_hours;
    }

    // ดึงข้อมูลรายชั่วโมงแบบ Dynamic
    public function getHourlyReport($start_date, $end_date, $display_type = 'pieces') {
        $result = [];
        $working_hours = $this->getWorkingHours($start_date, $end_date);
        
        // สร้าง labels สำหรับแกน x
        $labels = [];
        foreach ($working_hours as $hour) {
            $labels[] = sprintf('%02d:00', $hour);
        }
        $result['labels'] = $labels;
        
        // ดึงเป้าหมายสำหรับการคำนวณเปอร์เซ็นต์ ตามวันที่เริ่มต้น
        $targets = $this->getTargets($start_date);
        
        foreach ($this->tables as $line => $table_name) {
            // ตรวจสอบว่าตารางมีอยู่จริง
            $check_table = "SHOW TABLES LIKE :table_name";
            $stmt = $this->conn->prepare($check_table);
            $stmt->bindParam(':table_name', $table_name);
            $stmt->execute();
            
            if ($stmt->rowCount() == 0) {
                error_log("Table $table_name does not exist");
                $result[$line] = array_fill(0, count($working_hours), 0);
                continue;
            }

            $query = "SELECT
                        HOUR(created_at) as hour,
                        SUM(qty) as total_qty,
                        COUNT(*) as total_items
                      FROM " . $table_name . "
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date GROUP BY HOUR(created_at) ORDER BY hour";
            
            try {
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->execute();
                
                $hourly_data = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $hourly_data[$row['hour']] = (int)$row['total_qty'];
                }
                
                // จัดเรียงข้อมูลตามช่วงเวลาที่หา
                $line_data = [];
                foreach ($working_hours as $hour) {
                    $actual_qty = isset($hourly_data[$hour]) ? $hourly_data[$hour] : 0;
                    
                    if ($display_type === 'percentage') {
                        // คำนวณเปอร์เซ็นต์
                        $actual_working_minutes = $this->getActualWorkingMinutes($hour);
                        $hourly_target = round(($targets[$line] * $actual_working_minutes) / 60);

                        if ($hourly_target > 0) {
                            $percentage = ($actual_qty / $hourly_target) * 100;
                            $line_data[] = round($percentage, 1);
                        } else {
                            $line_data[] = 0;
                        }
                    } else {
                        // แสดงจำนวนชิ้นจริง
                        $line_data[] = $actual_qty;
                    }
                }
                
                $result[$line] = $line_data;
                
            } catch (PDOException $e) {
                error_log("Error querying $table_name: " . $e->getMessage());
                $result[$line] = array_fill(0, count($working_hours), 0);
            }
        }
        
        return $result;
    }

    // ดึงข้อมูลรายวันตามช่วงวันที่
    public function getDailyReport($start_date, $end_date) {
        $result = [];
        $labels = [];
        
        // สร้างช่วงวันที่
        $period = new DatePeriod(
            new DateTime($start_date),
            new DateInterval('P1D'),
            new DateTime($end_date . ' +1 day')
        );
        
        foreach ($period as $date) {
            $labels[] = $date->format('d/m');
        }
        $result['labels'] = $labels;
        
        foreach ($this->tables as $line => $table_name) {
            // ตรวจสอบว่าตารางมีอยู่จริง
            $check_table = "SHOW TABLES LIKE :table_name";
            $stmt = $this->conn->prepare($check_table);
            $stmt->bindParam(':table_name', $table_name);
            $stmt->execute();
            
            if ($stmt->rowCount() == 0) {
                $result[$line] = array_fill(0, count($labels), 0);
                continue;
            }

            $query = "SELECT 
                        DATE(created_at) as date,
                        SUM(qty) as total_qty,
                        COUNT(*) as total_items
                      FROM " . $table_name . " 
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date GROUP BY DATE(created_at) ORDER BY date";
            
            try {
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->execute();
                
                $daily_data = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $daily_data[$row['date']] = (int)$row['total_qty'];
                }
                
                // จัดเรียงข้อมูลตามวันที่
                $line_data = [];
                foreach ($period as $date) {
                    $date_str = $date->format('Y-m-d');
                    $line_data[] = isset($daily_data[$date_str]) ? $daily_data[$date_str] : 0;
                }
                
                $result[$line] = $line_data;
                
            } catch (PDOException $e) {
                error_log("Error querying $table_name: " . $e->getMessage());
                $result[$line] = array_fill(0, count($labels), 0);
            }
        }
        
        return $result;
    }

    // ดึงข้อมูลสรุปรวม
    public function getSummaryReport($start_date, $end_date, $display_type = 'pieces') {
        $result = [];
        $targets = $this->getTargets($start_date);
        
        foreach ($this->tables as $line => $table_name) {
            // ตรวจสอบว่าตารางมีอยู่จริง
            $check_table = "SHOW TABLES LIKE :table_name";
            $stmt = $this->conn->prepare($check_table);
            $stmt->bindParam(':table_name', $table_name);
            $stmt->execute();
            
            if ($stmt->rowCount() == 0) {
                $result[$line] = [
                    'total_qty' => 0,
                    'total_items' => 0,
                    'unique_items' => 0,
                    'percentage' => 0,
                    'target' => $targets[$line]
                ];
                continue;
            }

            $query = "SELECT
                        SUM(qty) as total_qty,
                        COUNT(*) as total_items,
                        COUNT(DISTINCT item) as unique_items
                      FROM " . $table_name . "
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date";
            
            try {
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->execute();
                
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $total_qty = (int)($row['total_qty'] ?? 0);
                
                // คำนวณเปอร์เซ็นต์แบบรายชั่วโมงแล้วเฉลี่ย
                $percentage = $this->calculateHourlyAveragePercentage($start_date, $end_date, $line, $table_name, $targets[$line]);
                
                // คำนวณเป้าหมายรวมสำหรับทั้งวัน (หักเวลาพักเบรค)
                $total_working_minutes = 0;
                for ($hour = 8; $hour <= 17; $hour++) {
                    $total_working_minutes += $this->getActualWorkingMinutes($hour);
                }
                $daily_target = ($targets[$line] * $total_working_minutes) / 60;
                
                $result[$line] = [
                    'total_qty' => $total_qty,
                    'total_items' => (int)($row['total_items'] ?? 0),
                    'unique_items' => (int)($row['unique_items'] ?? 0),
                    'percentage' => round($percentage, 1),
                    'target' => $targets[$line],
                    'daily_target' => round($daily_target, 0)
                ];
                
            } catch (PDOException $e) {
                error_log("Error querying $table_name: " . $e->getMessage());
                $result[$line] = [
                    'total_qty' => 0,
                    'total_items' => 0,
                    'unique_items' => 0,
                    'percentage' => 0,
                    'target' => $targets[$line],
                    'daily_target' => 0
                ];
            }
        }
        
        return $result;
    }

    // คำนวณเปอร์เซ็นต์แบบรายชั่วโมงแล้วเฉลี่ย
    private function calculateHourlyAveragePercentage($start_date, $end_date, $line, $table_name, $hourly_target) {
        try {
            // ดึงข้อมูลรายชั่วโมงที่มีการผลิต
            $query = "SELECT
                        HOUR(created_at) as hour,
                        SUM(qty) as total_qty
                      FROM " . $table_name . "
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date
                      GROUP BY HOUR(created_at)
                      HAVING total_qty > 0
                      ORDER BY hour";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            
            $hourly_percentages = [];
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $hour = (int)$row['hour'];
                $actual_qty = (int)$row['total_qty'];
                
                // คำนวณเป้าหมายรายชั่วโมง (หักเวลาพักเบรค)
                $actual_working_minutes = $this->getActualWorkingMinutes($hour);
                $adjusted_hourly_target = (int) round(($hourly_target * $actual_working_minutes) / 60);
                // var_dump($adjusted_hourly_target);

                // คำนวณเปอร์เซ็นต์รายชั่วโมง
                if ($adjusted_hourly_target > 0) {
                    $hourly_percentage = ($actual_qty / $adjusted_hourly_target) * 100;
                    // var_dump($hourly_percentage);
                    $hourly_percentages[] = $hourly_percentage;
                }
            }
            
            // คำนวณค่าเฉลี่ยของเปอร์เซ็นต์รายชั่วโมง
            if (count($hourly_percentages) > 0) {
                return array_sum($hourly_percentages) / count($hourly_percentages);
            } else {
                return 0;
            }
            
        } catch (PDOException $e) {
            error_log("Error calculating hourly average percentage for $table_name: " . $e->getMessage());
            return 0;
        }
    }

    // ดึงข้อมูลส่งออก excel รายละเอียด
    public function getDetailReport($start_date, $end_date) {
        $result = [];

        foreach ($this->tables as $line => $table_name) {
            $query = "SELECT 
                        item, qty, 
                        DATE(created_at) as date, 
                        TIME(created_at) as time
                    FROM $table_name
                    WHERE DATE(created_at) BETWEEN :start_date AND :end_date 
                    ORDER BY created_at ASC";

            try {
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->execute();

                $result[$line] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $result[$line] = [];
            }
        }

        return $result;
    }


    // Debug function - ตรวจสอบข้อมูลในตาราง
    public function debugTableData($start_date, $end_date) {
        $debug_info = [];
        
        foreach ($this->tables as $line => $table_name) {
            try {
                // ตรวจสอบว่าตารางมีอยู่จริง
                $check_table = "SHOW TABLES LIKE :table_name";
                $stmt = $this->conn->prepare($check_table);
                $stmt->bindParam(':table_name', $table_name);
                $stmt->execute();
                
                if ($stmt->rowCount() == 0) {
                    $debug_info[$line] = ['error' => 'Table does not exist'];
                    continue;
                }

                // นับจำนวนข้อมูลทั้งหมด
                $count_query = "SELECT COUNT(*) as total FROM " . $table_name;
                $stmt = $this->conn->prepare($count_query);
                $stmt->execute();
                $total_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

                // นับจำนวนข้อมูลในช่วงวันที่
                $date_count_query = "SELECT COUNT(*) as date_count FROM " . $table_name . " 
                                    WHERE DATE(created_at) BETWEEN :start_date AND :end_date";
                $stmt = $this->conn->prepare($date_count_query);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->execute();
                $date_count = $stmt->fetch(PDO::FETCH_ASSOC)['date_count'];

                // ดูข้อมูลตัวอย่าง
                $sample_query = "SELECT * FROM " . $table_name . " 
                                WHERE DATE(created_at) BETWEEN :start_date AND :end_date LIMIT 3";
                $stmt = $this->conn->prepare($sample_query);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->execute();
                $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $debug_info[$line] = [
                    'table_exists' => true,
                    'total_records' => $total_count,
                    'date_range_records' => $date_count,
                    'sample_data' => $samples
                ];

            } catch (PDOException $e) {
                $debug_info[$line] = ['error' => $e->getMessage()];
            }
        }
        
        return $debug_info;
    }
}
?>