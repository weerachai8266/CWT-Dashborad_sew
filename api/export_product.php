<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

include __DIR__ . '/../config/db.php';
require_once __DIR__ . '/data.class.php';

/**
 * Clean sheet title for Excel compatibility
 * @param string $title Original title
 * @return string Cleaned title (max 31 chars, no special chars)
 */
function cleanSheetTitle(string $title): string {
    return substr(preg_replace('/[\\/?*\[\]:]/', '', $title), 0, 31);
}

/**
 * Fetch QC items from quality control tables
 * @param PDO $pdo Database connection
 * @param string $productionLine Production line code (fc, fb, rc, rb, third, sub)
 * @param string $startDate Start date (Y-m-d)
 * @param string $endDate End date (Y-m-d)
 * @return array QC records with item, qty, created_at
 */
function fetchQualityControlItems(PDO $pdo, string $productionLine, string $startDate, string $endDate): array {
    // Mapping production lines to QC table names
    $qualityControlTables = [
        'fc' => 'qc_fc',
        'fb' => 'qc_fb', 
        'rc' => 'qc_rc',
        'rb' => 'qc_rb',
        'third' => 'qc_3rd'
        // Note: 'sub' doesn't have corresponding QC table
    ];
    
    // Return empty if line doesn't have QC table
    if (!isset($qualityControlTables[$productionLine])) {
        return [];
    }
    
    $tableName = $qualityControlTables[$productionLine];
    
    try {
        // Check if table exists
        $tableCheckQuery = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tableName));
        if ($tableCheckQuery->rowCount() === 0) {
            return [];
        }
        
        // Fetch QC data within date range
        $sql = "SELECT item, qty, created_at 
                FROM {$tableName}
                WHERE DATE(created_at) BETWEEN :start_date AND :end_date and status = '10'
                ORDER BY created_at ASC";
                
        $statement = $pdo->prepare($sql);
        $statement->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
    } catch(Exception $e) {
        return [];
    }
}

/**
 * Calculate man-hours for specific line, date range, and shift
 * @param PDO $dbConnection Database connection
 * @param string $productionLine Production line code
 * @param string $startDate Start date
 * @param string $endDate End date  
 * @param string $workShift Work shift (เช้า, บ่าย, OT)
 * @return float Total man-hours
 */
function calculateManHours(PDO $dbConnection, string $productionLine, string $startDate, string $endDate, string $workShift): float {
    // Map production lines to database column names
    $manpowerColumns = [
        'fc' => 'fc_act',
        'fb' => 'fb_act', 
        'rc' => 'rc_act',
        'rb' => 'rb_act',
        'third' => '3rd_act',
        'sub' => 'sub_act'
    ];
    
    if (!isset($manpowerColumns[$productionLine])) {
        return 0.0;
    }
    
    $manCountColumn = $manpowerColumns[$productionLine];
    
    try {
        $sql = "SELECT thour, {$manCountColumn} as man_count
                FROM sewing_man_act 
                WHERE shift = :work_shift 
                AND DATE(created_at) BETWEEN :start_date AND :end_date 
                AND {$manCountColumn} > 0
                ORDER BY created_at, thour";
                
        $statement = $dbConnection->prepare($sql);
        $statement->execute([
            ':work_shift' => $workShift,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        $totalManHours = 0.0;
        while ($manHourRecord = $statement->fetch(PDO::FETCH_ASSOC)) {
            $manCount = (int)($manHourRecord['man_count'] ?? 0);
            $workingHours = (float)($manHourRecord['thour'] ?? 1.0);
            $totalManHours += $manCount * $workingHours;
        }
        
        return $totalManHours;
        
    } catch(Exception $e) {
        return 0.0;
    }
}
/**
 * Get shift based on time
 * @param string $time Time in format H:i:s
 * @return string Shift name (เช้า, บ่าย, OT)
 */
function getShiftFromTime(string $time): string {
    if (empty($time)) {
        return 'เช้า'; // default shift
    }
    
    $hour = (int)date('H', strtotime($time));
    $minute = (int)date('i', strtotime($time));
    $totalMinutes = ($hour * 60) + $minute;
    
    // Convert time ranges to minutes
    $morningStart = 8 * 60;      // 08:00
    $morningEnd = 12 * 60 + 30;  // 12:30
    $afternoonEnd = 17 * 60;     // 17:00
    $nightEnd = 24 * 60;         // 24:00 (00:00 next day)
    
    if ($totalMinutes >= $morningStart && $totalMinutes < $morningEnd) {
        return 'เช้า';
    } elseif ($totalMinutes >= $morningEnd && $totalMinutes < $afternoonEnd) {
        return 'บ่าย';
    } else {
        return 'OT';
    }
}
function calculateCombinedRegularManHours(PDO $dbConnection, string $productionLine, string $startDate, string $endDate): float {
    $morningHours = calculateManHours($dbConnection, $productionLine, $startDate, $endDate, 'เช้า');
    $afternoonHours = calculateManHours($dbConnection, $productionLine, $startDate, $endDate, 'บ่าย');
    return $morningHours + $afternoonHours;
}
/**
 * Calculate daily man-hours for specific line, date, and shift
 * @param PDO $dbConnection Database connection
 * @param string $productionLine Production line code
 * @param string $targetDate Target date (Y-m-d)
 * @param string $workShift Work shift
 * @return float Daily man-hours
 */
function calculateDailyManHours(PDO $dbConnection, string $productionLine, string $targetDate, string $workShift): float {
    $manpowerColumns = [
        'fc' => 'fc_act',
        'fb' => 'fb_act', 
        'rc' => 'rc_act',
        'rb' => 'rb_act',
        'third' => '3rd_act',
        'sub' => 'sub_act'
    ];
    
    if (!isset($manpowerColumns[$productionLine])) {
        return 0.0;
    }
    
    $manCountColumn = $manpowerColumns[$productionLine];
    
    try {
        $sql = "SELECT SUM(thour * {$manCountColumn}) as total_hours
                FROM sewing_man_act 
                WHERE shift = :work_shift 
                AND DATE(created_at) = :target_date 
                AND {$manCountColumn} > 0";
                
        $statement = $dbConnection->prepare($sql);
        $statement->execute([
            ':work_shift' => $workShift,
            ':target_date' => $targetDate
        ]);
        
        $result = $statement->fetch(PDO::FETCH_ASSOC);
        return (float)($result['total_hours'] ?? 0.0);
        
    } catch(Exception $e) {
        return 0.0;
    }
}
function calculateCombinedDailyRegularManHours(PDO $dbConnection, string $productionLine, string $targetDate): float {
    $morningHours = calculateDailyManHours($dbConnection, $productionLine, $targetDate, 'เช้า');
    $afternoonHours = calculateDailyManHours($dbConnection, $productionLine, $targetDate, 'บ่าย');
    return $morningHours + $afternoonHours;
}
/**
 * Calculate man-hours for specific item at specific time
 * @param PDO $dbConnection Database connection
 * @param string $productionLine Production line code
 * @param string $targetDate Target date (Y-m-d)
 * @param string $targetTime Target time (H:i:s)
 * @return float Man-hours for that specific time
 */
function calculateItemManHours(PDO $dbConnection, string $productionLine, string $targetDate, string $targetTime): float {
    $manpowerColumns = [
        'fc' => 'fc_act',
        'fb' => 'fb_act', 
        'rc' => 'rc_act',
        'rb' => 'rb_act',
        'third' => '3rd_act',
        'sub' => 'sub_act'
    ];
    
    if (!isset($manpowerColumns[$productionLine])) {
        return 0.0;
    }
    
    $manCountColumn = $manpowerColumns[$productionLine];
    
    // Get shift from time
    $workShift = getShiftFromTime($targetTime);
    
    try {
        // Get man-hour data for the specific date and shift
        $sql = "SELECT thour, {$manCountColumn} as man_count
                FROM sewing_man_act 
                WHERE shift = :work_shift 
                AND DATE(created_at) = :target_date 
                AND {$manCountColumn} > 0
                ORDER BY created_at, thour";
                
        $statement = $dbConnection->prepare($sql);
        $statement->execute([
            ':work_shift' => $workShift,
            ':target_date' => $targetDate
        ]);
        
        $totalManHours = 0.0;
        $recordCount = 0;
        
        while ($manHourRecord = $statement->fetch(PDO::FETCH_ASSOC)) {
            $manCount = (int)($manHourRecord['man_count'] ?? 0);
            $workingHours = (float)($manHourRecord['thour'] ?? 1.0);
            $totalManHours += $manCount * $workingHours;
            $recordCount++;
        }
        
        // If we have multiple records for the same shift, average them
        // return $recordCount > 0 ? $totalManHours / $recordCount : 0.0;
        return $totalManHours;
        
    } catch(Exception $e) {
        return 0.0;
    }
}

// ==================== MAIN PROCESSING ====================

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

try {
    // Validate database connection
    if (!isset($conn) || !($conn instanceof PDO)) {
        throw new Exception('PDO database connection is missing');
    }

    // Initialize data handler
    $dataHandler = new get_db($conn);

    // Set color for alternating rows
    $colorrow = 'E0E0E0'; //เทา
    $colorheader = '404040'; //ฟ้า
    $textheader = 'FFFFFF'; //ขาว

    // Get and validate date parameters
    $reportStartDate = $_GET['start_date'] ?? date('Y-m-d');
    $reportEndDate = $_GET['end_date'] ?? $reportStartDate;

    $startDateObj = DateTime::createFromFormat('Y-m-d', $reportStartDate);
    $endDateObj = DateTime::createFromFormat('Y-m-d', $reportEndDate);
    
    if (!$startDateObj || !$endDateObj || $endDateObj < $startDateObj) {
        throw new Exception('Invalid date range provided');
    }

    // Fetch report data
    $productionDetail = $dataHandler->getDetailReport($reportStartDate, $reportEndDate);
    $productionSummary = $dataHandler->getSummaryReport($reportStartDate, $reportEndDate);

    // Production line configuration
    $productionLineNames = [
        'fc' => 'F/C',
        'fb' => 'F/B',
        'rc' => 'R/C',
        'rb' => 'R/B',
        'third' => '3RD',
        'sub' => 'Sub'
    ];

    // Create Excel workbook
    $excelWorkbook = new Spreadsheet();

    // ==================== SUMMARY SHEET ====================
    
    $summarySheet = $excelWorkbook->getActiveSheet();
    $summarySheet->setTitle('Summary');

    // Summary sheet headers
    $summarySheet->setCellValue('A1', 'รายงานสรุปการเย็บ (Production)');
    $summarySheet->setCellValue('A2', 'ช่วงวันที่: ' . $reportStartDate . 
        ($reportStartDate === $reportEndDate ? '' : ' ถึง ' . $reportEndDate));
    
    // Column headers
    $summarySheet->setCellValue('A4', 'ไลน์การผลิต');
    $summarySheet->setCellValue('B4', 'จำนวนชิ้นผลิต');
    $summarySheet->setCellValue('C4', 'จำนวนชิ้นตรวจ');
    $summarySheet->setCellValue('D4', 'โมเดลทั้งหมด');
    $summarySheet->setCellValue('E4', 'DayTime M-H');
    $summarySheet->setCellValue('F4', 'OT M-H');
    $summarySheet->setCellValue('G4', 'Total M-H');

    // Fill summary data
    $summaryRowIndex = 5;
    foreach ($productionSummary as $lineCode => $lineData) {
        if (!isset($productionLineNames[$lineCode])) {
            continue;
        }

        // Get QC totals for this line
        $qualityControlRecords = fetchQualityControlItems($conn, $lineCode, $reportStartDate, $reportEndDate);
        $qualityControlTotal = 0;
        foreach ($qualityControlRecords as $qcRecord) {
            $qualityControlTotal += (int)($qcRecord['qty'] ?? 1);
        }
        
        // Calculate man-hours for each shift
        $morningManHours = calculateManHours($conn, $lineCode, $reportStartDate, $reportEndDate, 'เช้า');
        $afternoonManHours = calculateManHours($conn, $lineCode, $reportStartDate, $reportEndDate, 'บ่าย');
        $overtimeManHours = calculateManHours($conn, $lineCode, $reportStartDate, $reportEndDate, 'OT');

        // Fill row data
        $summarySheet->setCellValue('A' . $summaryRowIndex, $productionLineNames[$lineCode]);
        $summarySheet->setCellValue('B' . $summaryRowIndex, $lineData['total_qty']);
        $summarySheet->setCellValue('C' . $summaryRowIndex, $qualityControlTotal);
        $summarySheet->setCellValue('D' . $summaryRowIndex, $lineData['unique_items']);
        $summarySheet->setCellValue('E' . $summaryRowIndex, $morningManHours + $afternoonManHours);
        $summarySheet->setCellValue('F' . $summaryRowIndex, $overtimeManHours);
        $summarySheet->setCellValue('G' . $summaryRowIndex, "=SUM(E{$summaryRowIndex}:F{$summaryRowIndex})");

        $summaryRowIndex++;

    }
        // Auto-size columns
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $columnLetter) {
            $summarySheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }
        // Bold headers
        $summarySheet->getStyle('A4:G4')->getFont()->setBold(true)->getColor()->setRGB($textheader);

        // Add header background color
        $summarySheet->getStyle("A4:G4") // แถวหัวตาราง
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB($colorheader);

        // กรอบนอกหนา แถวหัวตารางหนา เส้นในเป็นเส้นบาง
        // $summarySheet->getStyle("A4:G" . ($summaryRowIndex - 1))
        //     ->getBorders()->getOutline()    // กรอบนอก
        //     ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM);

        // $summarySheet->getStyle("A4:G4")
        //     ->getBorders()->getBottom() // กรอบล่าง
        //     ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM);

        // $summarySheet->getStyle("A4:G" . ($summaryRowIndex - 1))
        //     ->getBorders()->getHorizontal() // แนวนอน
        //     ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // $summarySheet->getStyle("A4:G" . ($summaryRowIndex - 1))
        //     ->getBorders()->getVertical()   // แนวตั้ง
        //     ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_DOTTED);

        // สลับสีแถว (striping rows)
        for ($i = 5; $i < $summaryRowIndex; $i += 2) {
            $summarySheet->getStyle("A{$i}:G{$i}")
                ->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($colorrow);
            }
        
    // ==================== DAILY MAN-HOUR SHEET ====================
    
    $manHourSheet = $excelWorkbook->createSheet();
    $manHourSheet->setTitle('Man-Hour รายวัน');

    // Man-hour sheet headers
    $manHourSheet->setCellValue('A1', 'สรุป Man-Hour รายวัน');
    $manHourSheet->setCellValue('A2', 'ช่วงวันที่: ' . $reportStartDate . 
        ($reportStartDate === $reportEndDate ? '' : ' ถึง ' . $reportEndDate));
    
    // Column headers
    $manHourSheet->setCellValue('A4', 'วันที่');
    $manHourSheet->setCellValue('B4', 'ไลน์');
    $manHourSheet->setCellValue('C4', 'DayTime');
    $manHourSheet->setCellValue('D4', 'OT');
    $manHourSheet->setCellValue('E4', 'รวม');

    // Generate date range
    $dateRange = [];
    $currentDate = clone $startDateObj;
    while ($currentDate <= $endDateObj) {
        $dateRange[] = $currentDate->format('Y-m-d');
        $currentDate->modify('+1 day');
    }

    // Fill daily man-hour data
    $manHourRowIndex = 5;
    foreach ($dateRange as $targetDate) {
        foreach ($productionLineNames as $lineCode => $lineName) {
            // Calculate man-hours for each shift
            $dailyMorningHours = calculateDailyManHours($conn, $lineCode, $targetDate, 'เช้า');
            $dailyAfternoonHours = calculateDailyManHours($conn, $lineCode, $targetDate, 'บ่าย');
            $dailyOvertimeHours = calculateDailyManHours($conn, $lineCode, $targetDate, 'OT');
            $dailyTotalHours = $dailyMorningHours + $dailyAfternoonHours + $dailyOvertimeHours;

            // Skip rows with no data
            if ($dailyTotalHours == 0) {
                continue;
            }

            // Fill man-hour data
            $manHourSheet->setCellValue("A{$manHourRowIndex}", $targetDate);
            $manHourSheet->setCellValue("B{$manHourRowIndex}", $lineName);
            $manHourSheet->setCellValue("C{$manHourRowIndex}", $dailyMorningHours + $dailyAfternoonHours);
            $manHourSheet->setCellValue("D{$manHourRowIndex}", $dailyOvertimeHours);
            $manHourSheet->setCellValue("E{$manHourRowIndex}", $dailyTotalHours);
            $manHourRowIndex++;
        }
    }

    // Format header
    $manHourSheet->getStyle("A4:E4")->getFont()->setBold(true)->getColor()->setRGB($textheader);

    // Add header background color
    $manHourSheet->getStyle("A4:E4") // แถวหัวตาราง
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()
        ->setRGB($colorheader);

    // if ($manHourRowIndex > 5) {
    //     // Add borders to data area
    //     $manHourSheet->getStyle("A4:E" . ($manHourRowIndex - 1))
    //         ->getBorders()
    //         ->getAllBorders()
    //         ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    // }

    // Auto-size columns
    foreach (['A', 'B', 'C', 'D', 'E'] as $columnLetter) {
        $manHourSheet->getColumnDimension($columnLetter)->setAutoSize(true);
    }

    // Add totals row if data exists
    if ($manHourRowIndex > 5) {
        $totalsRowIndex = $manHourRowIndex;
        $manHourSheet->setCellValue("A{$totalsRowIndex}", 'รวมทั้งหมด');
        $manHourSheet->setCellValue("B{$totalsRowIndex}", '');
        $manHourSheet->setCellValue("C{$totalsRowIndex}", "=SUM(C5:C" . ($totalsRowIndex - 1) . ")");
        $manHourSheet->setCellValue("D{$totalsRowIndex}", "=SUM(D5:D" . ($totalsRowIndex - 1) . ")");
        $manHourSheet->setCellValue("E{$totalsRowIndex}", "=SUM(E5:E" . ($totalsRowIndex - 1) . ")");
        
        // Format totals row
        $manHourSheet->getStyle("A{$totalsRowIndex}:E{$totalsRowIndex}")->getFont()->setBold(true);
        // $manHourSheet->getStyle("A{$totalsRowIndex}:E{$totalsRowIndex}")
        //     ->getBorders()
        //     ->getAllBorders()
        //     ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK);
    }
    // สลับสีแถว (striping rows)
    for ($i = 5; $i < $manHourRowIndex; $i += 2) {
        $manHourSheet->getStyle("A{$i}:E{$i}")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB($colorrow);
    }

    // ==================== DETAIL SHEETS PER LINE ====================
    
    foreach ($productionDetail as $lineCode => $productionRecords) {
        if (!isset($productionLineNames[$lineCode])) {
            continue;
        }

        // Create new sheet for this production line
        $detailSheet = $excelWorkbook->createSheet();
        $detailSheet->setTitle(cleanSheetTitle($productionLineNames[$lineCode]));

        // Production Detail Headers
        $detailSheet->setCellValue('A1', 'โมเดล (Production)');
        $detailSheet->setCellValue('B1', 'จำนวน');
        $detailSheet->setCellValue('C1', 'วันที่');
        $detailSheet->setCellValue('D1', 'เวลา');

        // QC Detail Headers
        $detailSheet->setCellValue('F1', 'โมเดล (QC)');
        $detailSheet->setCellValue('G1', 'ชิ้นส่วน');
        $detailSheet->setCellValue('H1', 'ชื่อ');
        $detailSheet->setCellValue('I1', 'จำนวน');
        $detailSheet->setCellValue('J1', 'วันที่');
        $detailSheet->setCellValue('K1', 'เวลา');

        // Summary headers for production
        $detailSheet->setCellValue('M1', 'วันที่');
        $detailSheet->setCellValue('N1', 'โมเดล');
        $detailSheet->setCellValue('O1', 'สรุปยอด');
        $detailSheet->setCellValue('P1', 'DayTime M-H'); // เปลี่ยนชื่อคอลัมน์
        $detailSheet->setCellValue('Q1', 'OT M-H');      // เพิ่มคอลัมน์ใหม่
        $detailSheet->setCellValue('R1', 'รวม M-H');

        // Summary headers for QC
        $detailSheet->setCellValue('T1', 'วันที่');
        $detailSheet->setCellValue('U1', 'โมเดล');
        $detailSheet->setCellValue('V1', 'ชิ้นส่วน');
        $detailSheet->setCellValue('W1', 'ชื่อ');
        $detailSheet->setCellValue('X1', 'สรุปยอด');        
                
        // Get QC data for this line
        $qualityControlRecords = fetchQualityControlItems($conn, $lineCode, $reportStartDate, $reportEndDate);
        
        // Create item mapping for QC data (ชิ้นส่วน -> โมเดล และ nickname)
        $itemMapping = [];
            try {
                $itemCodes = array_values(array_unique(array_column($qualityControlRecords, 'item')));
                
                if (!empty($itemCodes)) {
                    $itemCodesWithPrefix = array_map(function($itemCode) {
                        return (strpos($itemCode, 'AS-') === 0) ? $itemCode : 'AS-' . $itemCode;
                    }, $itemCodes);
                    
                    $placeholders = str_repeat('?,', count($itemCodesWithPrefix) - 1) . '?';
                    $itemQuery = "SELECT item, model, nickname FROM item WHERE item IN ({$placeholders})";
                    
                    $itemStmt = $conn->prepare($itemQuery);
                    $itemStmt->execute($itemCodesWithPrefix);
                    
                    while ($itemRow = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
                        $itemWithPrefix = $itemRow['item'];
                        $itemWithoutPrefix = str_replace('AS-', '', $itemWithPrefix);
                        
                        $mappingData = [
                            'model' => $itemRow['model'] ?? '',
                            'nickname' => $itemRow['nickname'] ?? ''
                        ];
                        
                        $itemMapping[$itemWithPrefix] = $mappingData;
                        $itemMapping[$itemWithoutPrefix] = $mappingData;
                    }
                }
            } catch (Exception $e) {
                $itemMapping = [];
            }
        
        // ========== Fill Production Detail Data (Column A-D) ==========
        $detailRowIndex = 2;
        foreach ($productionRecords as $prodRecord) {
            $modelName = $prodRecord['model'] ?? $prodRecord['item'] ?? '';
            $quantity = $prodRecord['qty'] ?? 0;
            $recordDate = $prodRecord['date'] ?? '';
            $recordTime = $prodRecord['time'] ?? '';
            
            $detailSheet->setCellValue("A{$detailRowIndex}", $modelName);
            $detailSheet->setCellValue("B{$detailRowIndex}", $quantity);
            $detailSheet->setCellValue("C{$detailRowIndex}", $recordDate);
            $detailSheet->setCellValue("D{$detailRowIndex}", $recordTime);
            $detailRowIndex++;
        }    
        
        // ========== Fill QC Detail Data (Column F-K) ==========
        $qcDetailRowIndex = 2;
        foreach ($qualityControlRecords as $qcRecord) {
            $recordDateTime = !empty($qcRecord['created_at']) ? new DateTime($qcRecord['created_at']) : null;
            $recordDate = $recordDateTime ? $recordDateTime->format('Y-m-d') : '';
            $recordTime = $recordDateTime ? $recordDateTime->format('H:i:s') : '';
            $componentName = $qcRecord['item'] ?? '';
            $quantity = (int)($qcRecord['qty'] ?? 1);
            
            // Get model and nickname from mapping
            $componentWithPrefix = (strpos($componentName, 'AS-') === 0) ? $componentName : 'AS-' . $componentName;
            $componentWithoutPrefix = str_replace('AS-', '', $componentName);
            
            $modelName = $itemMapping[$componentWithPrefix]['model'] ?? 
                        $itemMapping[$componentWithoutPrefix]['model'] ?? '';
            $nickName = $itemMapping[$componentWithPrefix]['nickname'] ?? 
                        $itemMapping[$componentWithoutPrefix]['nickname'] ?? '';
            
            $detailSheet->setCellValue("F{$qcDetailRowIndex}", $modelName);
            $detailSheet->setCellValue("G{$qcDetailRowIndex}", $componentWithPrefix);
            $detailSheet->setCellValue("H{$qcDetailRowIndex}", $nickName);
            $detailSheet->setCellValue("I{$qcDetailRowIndex}", $quantity);
            $detailSheet->setCellValue("J{$qcDetailRowIndex}", $recordDate);
            $detailSheet->setCellValue("K{$qcDetailRowIndex}", $recordTime);
            $qcDetailRowIndex++;
        }

        // ========== Create Production Summary ==========
        $productionSummaryByModel = [];
        foreach ($productionRecords as $prodRecord) {
            $modelName = $prodRecord['model'] ?? $prodRecord['item'] ?? '';
            $quantity = $prodRecord['qty'] ?? 0;
            $recordDate = $prodRecord['date'] ?? '';
            
            if ($modelName && $recordDate) {
                $summaryKey = $recordDate . '|' . $modelName;
                if (!isset($productionSummaryByModel[$summaryKey])) {
                    $productionSummaryByModel[$summaryKey] = [
                        'date' => $recordDate,
                        'model' => $modelName,
                        'total_qty' => 0
                    ];
                }
                $productionSummaryByModel[$summaryKey]['total_qty'] += $quantity;
            }
        }

        // ========== Create QC Summary ==========
        $qcSummaryByComponent = [];
        foreach ($qualityControlRecords as $qcRecord) {
            $recordDateTime = !empty($qcRecord['created_at']) ? new DateTime($qcRecord['created_at']) : null;
            $recordDate = $recordDateTime ? $recordDateTime->format('Y-m-d') : '';
            $componentName = $qcRecord['item'] ?? '';
            $quantity = (int)($qcRecord['qty'] ?? 1);
            
            $componentWithPrefix = (strpos($componentName, 'AS-') === 0) ? $componentName : 'AS-' . $componentName;
            $componentWithoutPrefix = str_replace('AS-', '', $componentName);
            
            $modelName = $itemMapping[$componentWithPrefix]['model'] ?? 
                        $itemMapping[$componentWithoutPrefix]['model'] ?? '';
            $nickName = $itemMapping[$componentWithPrefix]['nickname'] ?? 
                        $itemMapping[$componentWithoutPrefix]['nickname'] ?? '';
            
            if ($componentName && $recordDate) {
                $summaryKey = $recordDate . '|' . $componentName;
                if (!isset($qcSummaryByComponent[$summaryKey])) {
                    $qcSummaryByComponent[$summaryKey] = [
                        'date' => $recordDate,
                        'model' => $modelName,
                        'component' => $componentWithPrefix,
                        'nickname' => $nickName,
                        'total_qty' => 0
                    ];
                }
                $qcSummaryByComponent[$summaryKey]['total_qty'] += $quantity;
            }
        }
       

        // ========== Fill Production Summary (Column M-R) ==========
        $summaryRowIndex = 2;
        foreach ($productionSummaryByModel as $prodSummary) {
            $totalQuantity = $prodSummary['total_qty'];
            $modelDate = $prodSummary['date'];
            
            // แยกคำนวณ man-hours สำหรับ DayTime และ OT
            $dayTimeManHours = calculateDailyManHours($conn, $lineCode, $modelDate, 'เช้า') +
                            calculateDailyManHours($conn, $lineCode, $modelDate, 'บ่าย');
            $overtimeManHours = calculateDailyManHours($conn, $lineCode, $modelDate, 'OT');
            $totalManHours = $dayTimeManHours + $overtimeManHours;
            
            // If there are multiple models on the same date, proportionally distribute man-hours
            $totalProductionOnDate = 0;
            foreach ($productionSummaryByModel as $tempSummary) {
                if ($tempSummary['date'] === $modelDate) {
                    $totalProductionOnDate += $tempSummary['total_qty'];
                }
            }
            
            // Distribute man-hours proportionally based on production quantity
            $proportionFactor = $totalProductionOnDate > 0 ? ($totalQuantity / $totalProductionOnDate) : 0;
            $proportionalDayTimeHours = $dayTimeManHours * $proportionFactor;
            $proportionalOTHours = $overtimeManHours * $proportionFactor;
            $proportionalTotalHours = $proportionalDayTimeHours + $proportionalOTHours;
            
            $detailSheet->setCellValue("M{$summaryRowIndex}", $prodSummary['date']);
            $detailSheet->setCellValue("N{$summaryRowIndex}", $prodSummary['model']);
            $detailSheet->setCellValue("O{$summaryRowIndex}", $totalQuantity);
            $detailSheet->setCellValue("P{$summaryRowIndex}", round($proportionalDayTimeHours, 2));
            $detailSheet->setCellValue("Q{$summaryRowIndex}", round($proportionalOTHours, 2));
            $detailSheet->setCellValue("R{$summaryRowIndex}", round($proportionalTotalHours, 2));
            $summaryRowIndex++;
        }

        // Fill QC summary (columns L, M, N, O, P)
        $qcSummaryRowIndex = 2;
        foreach ($qcSummaryByComponent as $qcSummary) {
            $detailSheet->setCellValue("T{$qcSummaryRowIndex}", $qcSummary['date']);
            $detailSheet->setCellValue("U{$qcSummaryRowIndex}", $qcSummary['model']);
            $detailSheet->setCellValue("V{$qcSummaryRowIndex}", $qcSummary['component']);
            $detailSheet->setCellValue("W{$qcSummaryRowIndex}", $qcSummary['nickname']);
            $detailSheet->setCellValue("X{$qcSummaryRowIndex}", $qcSummary['total_qty']);
            $qcSummaryRowIndex++;
        }      

        // Format detail sheet
        foreach (['A', 'B', 'C', 'D', 'F', 'G', 'H', 'I', 'J', 'K', 'M', 'N', 'O', 'P', 'Q', 'R', 'T', 'U', 'V', 'W', 'X'] as $columnLetter) {
            $detailSheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }
        // Add header background color
        $detailSheet->getStyle("A1:D1")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB($colorheader);

        $detailSheet->getStyle("F1:K1")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB($colorheader);

        $detailSheet->getStyle("M1:R1")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB($colorheader);

        $detailSheet->getStyle("T1:X1")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB($colorheader);
            
        // สลับสีแถว (striping rows)
        // for ($i = 2; $i < $detailRowIndex; $i += 2) {
        //     $detailSheet->getStyle("A{$i}:D{$i}")
        //         ->getFill()
        //         ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        //         ->getStartColor()
        //         ->setRGB($colorrow); 
        // }
        // for ($i = 2; $i < $qcDetailRowIndex; $i += 2) {
        //     $detailSheet->getStyle("F{$i}:K{$i}")
        //         ->getFill()
        //         ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        //         ->getStartColor()
        //         ->setRGB($colorrow); 
        // }
        for ($i = 2; $i < $summaryRowIndex; $i += 2) {
            $detailSheet->getStyle("M{$i}:R{$i}")
                ->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($colorrow); 
        }
        for ($i = 2; $i < $qcSummaryRowIndex; $i += 2) {
            $detailSheet->getStyle("T{$i}:X{$i}")
                ->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($colorrow); 
        }
        // Bold all headers
        $detailSheet->getStyle('A1:D1')->getFont()->setBold(true)->getColor()->setRGB($textheader);
        $detailSheet->getStyle('F1:K1')->getFont()->setBold(true)->getColor()->setRGB($textheader);
        $detailSheet->getStyle('M1:R1')->getFont()->setBold(true)->getColor()->setRGB($textheader);
        $detailSheet->getStyle('T1:X1')->getFont()->setBold(true)->getColor()->setRGB($textheader);

        // Add borders to main data
        // $detailSheet->getStyle("A1:E" . ($detailRowIndex - 1))
        //     ->getBorders()
        //     ->getAllBorders()
        //     ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);


        // Add borders to production summary (with Man-Hour)
        // if ($summaryRowIndex > 2) {
        //     $detailSheet->getStyle("G1:L" . ($summaryRowIndex - 1))
        //         ->getBorders()
        //         ->getAllBorders()
        //         ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        // }

        // Add borders to QC summary
        // if ($qcSummaryRowIndex > 2) {
        //     $detailSheet->getStyle("N1:R" . ($qcSummaryRowIndex - 1))
        //         ->getBorders()
        //         ->getAllBorders()
        //         ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        // }
        
    }

    // ==================== EXPORT EXCEL FILE ====================
    
    $exportFilename = 'production_report_' . $reportStartDate . 
        ($reportStartDate === $reportEndDate ? '' : '_to_' . $reportEndDate) . '.xlsx';

    // Set HTTP headers for Excel download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $exportFilename . '"');
    header('Cache-Control: max-age=0');

    // Output Excel file
    $excelWriter = new Xlsx($excelWorkbook);
    $excelWriter->save('php://output');
    exit;

} catch (Exception $exception) {
    http_response_code(500);
    echo 'Export error: ' . $exception->getMessage();
    exit;
}
?>