<?php
class get_db {
    private $conn;
    private $breakTimesCache = null;
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
        if ($this->breakTimesCache !== null) {
            return $this->breakTimesCache;
        }

        try {
            $query = "SELECT break_name, start_time, end_time, duration_minutes
                      FROM break_times
                      WHERE is_active = 1
                      ORDER BY start_time";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $this->breakTimesCache = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching break times: " . $e->getMessage());
            $this->breakTimesCache = [];
        }

        return $this->breakTimesCache;
    }

    private function timeToMinutes($time) {
        if (empty($time)) {
            return null;
        }

        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return null;
        }

        return ((int)$parts[0] * 60) + (int)$parts[1];
    }

    private function getShiftStartMinutes($shift) {
        $shift = trim((string)$shift);
        if (strtoupper($shift) === 'OT') {
            $shift = 'OT';
        }

        switch ($shift) {
            case 'บ่าย':
                return (12 * 60) + 30;
            case 'OT':
                return 17 * 60;
            case 'เช้า':
            default:
                return 8 * 60;
        }
    }

    private function calculateBreakOverlapMinutes($workStart, $workEnd) {
        $total = 0;

        foreach ($this->getBreakTimes() as $break) {
            $breakStart = $this->timeToMinutes($break['start_time'] ?? null);
            $breakEnd = $this->timeToMinutes($break['end_time'] ?? null);

            if ($breakStart === null || $breakEnd === null) {
                continue;
            }

            if ($breakEnd <= $breakStart) {
                $breakEnd += 24 * 60;
            }

            foreach ([0, 24 * 60] as $dayOffset) {
                $start = $breakStart + $dayOffset;
                $end = $breakEnd + $dayOffset;
                $overlap = min($workEnd, $end) - max($workStart, $start);

                if ($overlap > 0) {
                    $total += $overlap;
                }
            }
        }

        return $total;
    }

    public function getActualWorkingHoursForShift($shift, $hours) {
        $hours = max(0, (float)$hours);
        if ($hours <= 0) {
            return 0.0;
        }

        $workStart = $this->getShiftStartMinutes($shift ?: 'เช้า');
        $workEnd = $workStart + (int)round($hours * 60);
        $breakMinutes = $this->calculateBreakOverlapMinutes($workStart, $workEnd);

        return max(0, (($hours * 60) - $breakMinutes) / 60);
    }

    // คำนวณเวลาทำงานจริงในแต่ละชั่วโมง (หักเวลาพักเบรค)
    public function getActualWorkingMinutes($hour) {
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
    public function getTargets($date = null) {
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
            }else {
                // ถ้าไม่มีเป้าหมายเลย ให้ใช้ค่าเริ่มต้น
                return [
                    'fc' => 0,
                    'fb' => 0,
                    'rc' => 0,
                    'rb' => 0,
                    'third' => 0,
                    'sub' => 0
                ];
            }
        } catch (PDOException $e) {
            error_log("Error fetching targets for date $date: " . $e->getMessage());
            return [
                'fc' => 0,
                'fb' => 0,
                'rc' => 0,
                'rb' => 0,
                'third' => 0,
                'sub' => 0
            ];           
        }
    }

    // ดึงช่วงเวลาการทำงานจริงจากข้อมูล
    public function getWorkingHours($start_date, $end_date) {
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
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date and status = '10' ORDER BY hour";
            
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
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date and status = '10' GROUP BY HOUR(created_at) ORDER BY hour";

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
                        $hourly_target = (int) round(($targets[$line] * $actual_working_minutes) / 60);

                        if ($hourly_target > 0) {
                            $percentage = ($actual_qty / $hourly_target) * 100;
                            $line_data[] = round($percentage, 2);
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
    public function getDailyReport($start_date, $end_date, $display_type = 'pieces') {
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

        // คำนวณนาทีทำงานต่อวัน (หักเบรค) — ใช้ร่วมกันทุก line
        $single_day_minutes = 0;
        for ($hour = 8; $hour <= 17; $hour++) {
            $single_day_minutes += $this->getActualWorkingMinutes($hour);
        }
        $targets_cache = [];
        
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
                        SUM(qty) as total_qty
                      FROM " . $table_name . " 
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date AND status = '10' 
                      GROUP BY DATE(created_at) 
                      ORDER BY date";
            
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
                    $qty = isset($daily_data[$date_str]) ? $daily_data[$date_str] : 0;

                    if ($display_type === 'percentage') {
                        // ใช้ calculateHourlyAveragePercentage เพื่อให้ตรงกับ Summary
                        if (!isset($targets_cache[$date_str])) {
                            $targets_cache[$date_str] = $this->getTargets($date_str);
                        }
                        $day_hourly_target = $targets_cache[$date_str][$line] ?? 0;
                        $pct = $this->calculateHourlyAveragePercentage($date_str, $date_str, $line, $table_name, $day_hourly_target);
                        $line_data[] = round($pct, 1);
                    } else {
                        $line_data[] = $qty;
                    }
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
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date and status = '10'";

            try {
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->execute();
                
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $total_qty = (int)($row['total_qty'] ?? 0);
                
                // คำนวณเปอร์เซ็นต์แบบรายชั่วโมงแล้วเฉลี่ย
                $percentage = $this->calculateHourlyAveragePercentage($start_date, $end_date, $line, $table_name, $targets[$line]);
                
                // ดึง distinct วันที่มีการผลิตจริง
                $dates_stmt = $this->conn->prepare(
                    "SELECT DISTINCT DATE(created_at) as prod_date FROM " . $table_name .
                    " WHERE DATE(created_at) BETWEEN :s AND :e AND status = '10' ORDER BY prod_date"
                );
                $dates_stmt->execute([':s' => $start_date, ':e' => $end_date]);
                $prod_dates = $dates_stmt->fetchAll(PDO::FETCH_COLUMN);

                // คำนวณเวลาทำงานต่อวัน (หักเบรค)
                $single_day_minutes = 0;
                for ($hour = 8; $hour <= 17; $hour++) {
                    $single_day_minutes += $this->getActualWorkingMinutes($hour);
                }

                // คำนวณเป้าหมายรวม per-day (แต่ละวันใช้ target ของวันนั้น)
                $daily_target = 0;
                foreach ($prod_dates as $prod_date) {
                    $day_targets = $this->getTargets($prod_date);
                    $daily_target += (int) round($day_targets[$line] * $single_day_minutes / 60);
                }
                
                $result[$line] = [
                    'total_qty' => $total_qty,
                    'total_items' => (int)($row['total_items'] ?? 0),
                    'unique_items' => (int)($row['unique_items'] ?? 0),
                    'percentage' => round($percentage, 2),
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
            // ดึงข้อมูลรายชั่วโมงที่มีการผลิต (GROUP BY วันและชั่วโมง เพื่อไม่ให้รวมข้ามวัน)
            $query = "SELECT
                        DATE(created_at) as date,
                        HOUR(created_at) as hour,
                        SUM(qty) as total_qty
                      FROM " . $table_name . "
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date and status = '10'
                      GROUP BY DATE(created_at), HOUR(created_at)
                      HAVING total_qty > 0
                      ORDER BY date, hour";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            
            $hourly_percentages = [];
            $targets_cache = []; // cache target ต่อวัน เพื่อลด DB call
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $hour = (int)$row['hour'];
                $actual_qty = (int)$row['total_qty'];
                $row_date = $row['date'];

                // ดึง target ของวันนั้น (per-day) โดย cache ไว้
                if (!isset($targets_cache[$row_date])) {
                    $targets_cache[$row_date] = $this->getTargets($row_date);
                }
                $day_hourly_target = $targets_cache[$row_date][$line] ?? $hourly_target;

                // คำนวณเป้าหมายรายชั่วโมง (หักเวลาพักเบรค)
                $actual_working_minutes = $this->getActualWorkingMinutes($hour);
                $adjusted_hourly_target = (int) round(($day_hourly_target * $actual_working_minutes) / 60);

                // คำนวณเปอร์เซ็นต์รายชั่วโมง
                if ($adjusted_hourly_target > 0) {
                    $hourly_percentage = ($actual_qty / $adjusted_hourly_target) * 100;
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
                    WHERE DATE(created_at) BETWEEN :start_date AND :end_date and status = '10'
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
}
?>
