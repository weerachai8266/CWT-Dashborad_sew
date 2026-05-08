<?php
class get_db {
    private $conn;
    private array $tables = [
        'fc'    => 'sewing_fc',
        'fb'    => 'sewing_fb',
        'rc'    => 'sewing_rc',
        'rb'    => 'sewing_rb',
        'third' => 'sewing_3rd',
        'sub'   => 'sewing_sub',
    ];

    // Per-instance caches — eliminates N+1 DB queries
    private ?array $breakTimesCache     = null;
    private array  $minutesCache        = [];
    private array  $targetsCache        = [];
    private ?array $existingTablesCache = null;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Break times: 1 DB query per object lifetime (was 1 per getActualWorkingMinutes call)
    private function getBreakTimes(): array {
        if ($this->breakTimesCache !== null) {
            return $this->breakTimesCache;
        }
        try {
            $stmt = $this->conn->prepare(
                "SELECT break_name, start_time, end_time, duration_minutes
                 FROM break_times WHERE is_active = 1 ORDER BY start_time"
            );
            $stmt->execute();
            $this->breakTimesCache = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching break times: " . $e->getMessage());
            $this->breakTimesCache = [];
        }
        return $this->breakTimesCache;
    }

    // Working minutes: 1 calculation per unique hour value (max 24 total)
    public function getActualWorkingMinutes(int $hour): int {
        if (isset($this->minutesCache[$hour])) {
            return $this->minutesCache[$hour];
        }
        $break_times         = $this->getBreakTimes();
        $total_break_minutes = 0;

        foreach ($break_times as $break) {
            $start_hour   = (int)date('H', strtotime($break['start_time']));
            $end_hour     = (int)date('H', strtotime($break['end_time']));
            $start_minute = (int)date('i', strtotime($break['start_time']));
            $end_minute   = (int)date('i', strtotime($break['end_time']));

            if ($start_hour === $hour) {
                $total_break_minutes += ($end_hour === $hour)
                    ? ($end_minute - $start_minute)
                    : (60 - $start_minute);
            } elseif ($end_hour === $hour && $start_hour < $hour) {
                $total_break_minutes += $end_minute;
            } elseif ($start_hour < $hour && $end_hour > $hour) {
                $total_break_minutes += 60;
            }
        }

        $result = max(0, 60 - $total_break_minutes);
        $this->minutesCache[$hour] = $result;
        return $result;
    }

    // Elapsed net minutes within $hour — Today filter only, for current incomplete hour
    private function getElapsedNetMinutesInHour(int $hour): int {
        $now       = new DateTimeImmutable('now');
        $today     = $now->format('Y-m-d');
        $hourStart = new DateTimeImmutable(sprintf('%s %02d:00:00', $today, $hour));

        $elapsedSecs = max(0, $now->getTimestamp() - $hourStart->getTimestamp());
        if ($elapsedSecs <= 0) return 0;

        $breakSecs = 0;
        foreach ($this->getBreakTimes() as $break) {
            $bs = new DateTimeImmutable("$today " . $break['start_time']);
            $be = new DateTimeImmutable("$today " . $break['end_time']);
            $os = max($hourStart->getTimestamp(), $bs->getTimestamp());
            $oe = min($now->getTimestamp(),       $be->getTimestamp());
            if ($oe > $os) $breakSecs += ($oe - $os);
        }

        return max(0, (int)round(($elapsedSecs - $breakSecs) / 60));
    }

    // Targets: 1 DB query per unique date (was 1-2 queries per call, no cross-method cache)
    public function getTargets(?string $date = null): array {
        $date  = $date ?? date('Y-m-d');
        $empty = ['fc' => 0, 'fb' => 0, 'rc' => 0, 'rb' => 0, 'third' => 0, 'sub' => 0];

        if (isset($this->targetsCache[$date])) {
            return $this->targetsCache[$date];
        }

        try {
            // Single query covers both "exact date" and "latest before date" cases
            $stmt = $this->conn->prepare(
                "SELECT fc, fb, rc, rb, `3rd`, sub FROM sewing_target
                 WHERE DATE(created_at) <= :date ORDER BY created_at DESC LIMIT 1"
            );
            $stmt->bindParam(':date', $date);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $targets = $row ? [
                'fc'    => (int)$row['fc'],
                'fb'    => (int)$row['fb'],
                'rc'    => (int)$row['rc'],
                'rb'    => (int)$row['rb'],
                'third' => (int)$row['3rd'],
                'sub'   => (int)$row['sub'],
            ] : $empty;
        } catch (PDOException $e) {
            error_log("Error fetching targets for date $date: " . $e->getMessage());
            $targets = $empty;
        }

        $this->targetsCache[$date] = $targets;
        return $targets;
    }

    // Table existence: 1 SHOW TABLES per object lifetime (was 1 SHOW TABLES LIKE per table per method)
    private function tableExists(string $table_name): bool {
        if ($this->existingTablesCache === null) {
            try {
                $stmt = $this->conn->query("SHOW TABLES");
                $this->existingTablesCache = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (PDOException $e) {
                error_log("Error fetching table list: " . $e->getMessage());
                $this->existingTablesCache = [];
            }
        }
        return in_array($table_name, $this->existingTablesCache, true);
    }

    public function getWorkingHours(string $start_date, string $end_date): array {
        $all_hours = [];

        foreach ($this->tables as $line => $table_name) {
            if (!$this->tableExists($table_name)) {
                error_log("Table $table_name does not exist");
                continue;
            }

            $query = "SELECT DISTINCT HOUR(created_at) AS hour
                      FROM `{$table_name}`
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date
                        AND status = '10'
                      ORDER BY hour";
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

        $all_hours = array_unique($all_hours);
        sort($all_hours);
        return $all_hours ?: range(8, 16);
    }

    public function getHourlyReport(string $start_date, string $end_date, string $display_type = 'pieces'): array {
        $result        = [];
        $working_hours = $this->getWorkingHours($start_date, $end_date);
        $targets       = $this->getTargets($start_date);

        $labels = [];
        foreach ($working_hours as $hour) {
            $labels[] = sprintf('%02d:00', $hour);
        }
        $result['labels'] = $labels;

        foreach ($this->tables as $line => $table_name) {
            if (!$this->tableExists($table_name)) {
                $result[$line] = array_fill(0, count($working_hours), 0);
                continue;
            }

            $query = "SELECT HOUR(created_at) AS hour, SUM(qty) AS total_qty
                      FROM `{$table_name}`
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date AND status = '10'
                      GROUP BY HOUR(created_at)
                      ORDER BY hour";
            try {
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->execute();

                $hourly_data = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $hourly_data[$row['hour']] = (int)$row['total_qty'];
                }

                $isToday     = ($start_date === $end_date && $start_date === date('Y-m-d'));
                $currentHour = $isToday ? (int)date('H') : -1;

                $line_data = [];
                foreach ($working_hours as $hour) {
                    $actual_qty = $hourly_data[$hour] ?? 0;
                    if ($display_type === 'percentage') {
                        $actual_working_minutes = ($isToday && $hour === $currentHour)
                            ? $this->getElapsedNetMinutesInHour($hour)
                            : $this->getActualWorkingMinutes($hour);
                        $hourly_target = (int)round(($targets[$line] * $actual_working_minutes) / 60);
                        $line_data[] = $hourly_target > 0
                            ? round(($actual_qty / $hourly_target) * 100, 2)
                            : 0;
                    } else {
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

    public function getDailyReport(string $start_date, string $end_date, string $display_type = 'pieces'): array {
        $result = [];
        $labels = [];

        $period = new DatePeriod(
            new DateTime($start_date),
            new DateInterval('P1D'),
            new DateTime($end_date . ' +1 day')
        );
        foreach ($period as $date) {
            $labels[] = $date->format('d/m');
        }
        $result['labels'] = $labels;

        // Pre-compute daily working minutes once — shared by all lines
        $single_day_minutes = 0;
        for ($h = 8; $h <= 17; $h++) {
            $single_day_minutes += $this->getActualWorkingMinutes($h);
        }

        foreach ($this->tables as $line => $table_name) {
            if (!$this->tableExists($table_name)) {
                $result[$line] = array_fill(0, count($labels), 0);
                continue;
            }

            $query = "SELECT DATE(created_at) AS date, SUM(qty) AS total_qty
                      FROM `{$table_name}`
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

                $line_data = [];
                foreach ($period as $date) {
                    $date_str = $date->format('Y-m-d');
                    if ($display_type === 'percentage') {
                        $day_target  = $this->getTargets($date_str)[$line] ?? 0;
                        $pct         = $this->calculateHourlyAveragePercentage(
                            $date_str, $date_str, $line, $table_name, $day_target
                        );
                        $line_data[] = round($pct, 1);
                    } else {
                        $line_data[] = $daily_data[$date_str] ?? 0;
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

    public function getSummaryReport(string $start_date, string $end_date, string $display_type = 'pieces'): array {
        $result  = [];
        $targets = $this->getTargets($start_date);

        // Pre-compute daily working minutes once
        $single_day_minutes = 0;
        for ($h = 8; $h <= 17; $h++) {
            $single_day_minutes += $this->getActualWorkingMinutes($h);
        }

        foreach ($this->tables as $line => $table_name) {
            if (!$this->tableExists($table_name)) {
                $result[$line] = [
                    'total_qty' => 0, 'total_items' => 0, 'unique_items' => 0,
                    'percentage' => 0, 'target' => $targets[$line], 'daily_target' => 0,
                ];
                continue;
            }

            $query = "SELECT SUM(qty) AS total_qty, COUNT(*) AS total_items,
                             COUNT(DISTINCT item) AS unique_items
                      FROM `{$table_name}`
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date AND status = '10'";
            try {
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->execute();

                $row        = $stmt->fetch(PDO::FETCH_ASSOC);
                $total_qty  = (int)($row['total_qty'] ?? 0);
                $percentage = $this->calculateHourlyAveragePercentage(
                    $start_date, $end_date, $line, $table_name, $targets[$line]
                );

                // Production dates for daily_target accumulation
                $dates_stmt = $this->conn->prepare(
                    "SELECT DISTINCT DATE(created_at) AS prod_date
                     FROM `{$table_name}`
                     WHERE DATE(created_at) BETWEEN :s AND :e AND status = '10'
                     ORDER BY prod_date"
                );
                $dates_stmt->execute([':s' => $start_date, ':e' => $end_date]);
                $prod_dates = $dates_stmt->fetchAll(PDO::FETCH_COLUMN);

                $daily_target = 0;
                foreach ($prod_dates as $prod_date) {
                    $day_t = $this->getTargets($prod_date)[$line] ?? 0;
                    $daily_target += (int)round($day_t * $single_day_minutes / 60);
                }

                $result[$line] = [
                    'total_qty'    => $total_qty,
                    'total_items'  => (int)($row['total_items']  ?? 0),
                    'unique_items' => (int)($row['unique_items'] ?? 0),
                    'percentage'   => round($percentage, 2),
                    'target'       => $targets[$line],
                    'daily_target' => round($daily_target, 0),
                ];
            } catch (PDOException $e) {
                error_log("Error querying $table_name: " . $e->getMessage());
                $result[$line] = [
                    'total_qty' => 0, 'total_items' => 0, 'unique_items' => 0,
                    'percentage' => 0, 'target' => $targets[$line], 'daily_target' => 0,
                ];
            }
        }

        return $result;
    }

    // Uses class-level caches — no extra DB calls for targets/break-times already fetched
    private function calculateHourlyAveragePercentage(
        string $start_date, string $end_date, string $line, string $table_name, float $hourly_target
    ): float {
        try {
            $query = "SELECT DATE(created_at) AS date, HOUR(created_at) AS hour, SUM(qty) AS total_qty
                      FROM `{$table_name}`
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date AND status = '10'
                      GROUP BY DATE(created_at), HOUR(created_at)
                      HAVING total_qty > 0
                      ORDER BY date, hour";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();

            $isToday     = ($start_date === $end_date && $start_date === date('Y-m-d'));
            $currentHour = $isToday ? (int)date('H') : -1;
            $todayStr    = $isToday ? date('Y-m-d') : '';

            $hourly_percentages = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $hour       = (int)$row['hour'];
                $actual_qty = (int)$row['total_qty'];
                $row_date   = $row['date'];

                $day_hourly_target      = $this->getTargets($row_date)[$line] ?? $hourly_target;
                $actual_working_minutes = ($isToday && $row_date === $todayStr && $hour === $currentHour)
                    ? $this->getElapsedNetMinutesInHour($hour)
                    : $this->getActualWorkingMinutes($hour);
                $adjusted_target        = (int)round($day_hourly_target * $actual_working_minutes / 60);

                if ($adjusted_target > 0) {
                    $hourly_percentages[] = ($actual_qty / $adjusted_target) * 100;
                }
            }

            return count($hourly_percentages)
                ? array_sum($hourly_percentages) / count($hourly_percentages)
                : 0.0;
        } catch (PDOException $e) {
            error_log("Error calculating hourly average percentage for $table_name: " . $e->getMessage());
            return 0.0;
        }
    }

    public function getDetailReport(string $start_date, string $end_date): array {
        $result = [];
        foreach ($this->tables as $line => $table_name) {
            $query = "SELECT item, qty, DATE(created_at) AS date, TIME(created_at) AS time
                      FROM `{$table_name}`
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date AND status = '10'
                      ORDER BY created_at ASC";
            try {
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->execute();
                $result[$line] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Error querying $table_name: " . $e->getMessage());
                $result[$line] = [];
            }
        }
        return $result;
    }
}
