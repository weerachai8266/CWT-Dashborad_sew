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
function calculateManHours(PDO $dbConnection, string $productionLine, string $startDate, string $endDate, string $workShift): array {
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
        return ['man_power' => 0.0, 'working_hours' => 0.0, 'man_hours' => 0.0];
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
        
        $manCount = 0;
        $workingHours = 0.0;
        $man_hours = 0.0;
        while ($manHourRecord = $statement->fetch(PDO::FETCH_ASSOC)) {
            $manCount = (int)($manHourRecord['man_count'] ?? 0);
            $workingHours = (float)($manHourRecord['thour'] ?? 1.0);
            $man_hours += $manCount * $workingHours;
        }
        
        // return $totalManHours;
        return ['man_power' => $manCount, 'working_hours' => $workingHours, 'man_hours' => $man_hours];

    } catch(Exception $e) {
        return ['man_power' => 0, 'working_hours' => 0.0, 'man_hours' => 0.0];
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
    $summarySheet->setCellValue('E4', 'DayTime Man-Hours');
    $summarySheet->setCellValue('F4', 'OT Man-Hours');
    // $summarySheet->setCellValue('G4', 'DayTime Man-Power');
    // $summarySheet->setCellValue('H4', 'OT Man-Power');
    $summarySheet->setCellValue('I4', 'Total Man-Hours');
    // $summarySheet->setCellValue('J4', 'Total OT Man-Power');

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
        $summarySheet->setCellValue('A' . $summaryRowIndex, $productionLineNames[$lineCode]);                                           // col A Line Name
        $summarySheet->setCellValue('B' . $summaryRowIndex, $lineData['total_qty']);                                                    // col B Total Qty
        $summarySheet->setCellValue('C' . $summaryRowIndex, $qualityControlTotal);                                                      // col C QC Total
        $summarySheet->setCellValue('D' . $summaryRowIndex, $lineData['unique_items']);                                                 // col D Models Total
        $summarySheet->setCellValue('E' . $summaryRowIndex, $morningManHours['man_hours'] + $afternoonManHours['man_hours']);   // col E DayTime working hrs
        $summarySheet->setCellValue('F' . $summaryRowIndex, $overtimeManHours['man_hours']);                                        // col F OT working hrs
        // $summarySheet->setCellValue('G' . $summaryRowIndex, round(($morningManHours['man_power'] + $afternoonManHours['man_power']) / 2));     // col G DayTime
        // $summarySheet->setCellValue('H' . $summaryRowIndex, $overtimeManHours['man_power']);                                            // col H OT Man-Power
        $summarySheet->setCellValue('I' . $summaryRowIndex, "=SUM(E{$summaryRowIndex}:F{$summaryRowIndex})");                           // col I Total working hrs
        // $summarySheet->setCellValue('J' . $summaryRowIndex, "=SUM(G{$summaryRowIndex}:H{$summaryRowIndex})");                           // col J Total Man-Power

        $summaryRowIndex++;

    }
        // Auto-size columns
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $columnLetter) {
            $summarySheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }
        // Bold headers
        $summarySheet->getStyle('A4:J4')->getFont()->setBold(true)->getColor()->setRGB($textheader);

        // Add header background color
        $summarySheet->getStyle("A4:J4") // แถวหัวตาราง
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
            $summarySheet->getStyle("A{$i}:J{$i}")
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
    $manHourSheet->setCellValue('C4', 'DayTime (ชั่วโมง)');
    $manHourSheet->setCellValue('D4', 'OT (ชั่วโมง)');
    $manHourSheet->setCellValue('E4', 'DayTime (คน)');
    $manHourSheet->setCellValue('F4', 'OT (คน)');

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
            $dailyMorningHours = calculateManHours($conn, $lineCode, $targetDate, $targetDate, 'เช้า');
            $dailyAfternoonHours = calculateManHours($conn, $lineCode, $targetDate, $targetDate, 'บ่าย');
            $dailyOvertimeHours = calculateManHours($conn, $lineCode, $targetDate, $targetDate, 'OT');
            $dailyTotalHours = $dailyMorningHours + $dailyAfternoonHours + $dailyOvertimeHours;

            // Skip rows with no data
            if ($dailyTotalHours == 0) {
                continue;
            }

            // Fill man-hour data
            $manHourSheet->setCellValue("A{$manHourRowIndex}", $targetDate);
            $manHourSheet->setCellValue("B{$manHourRowIndex}", $lineName);
            $manHourSheet->setCellValue("C{$manHourRowIndex}", $dailyMorningHours['working_hours'] + $dailyAfternoonHours['working_hours']);
            $manHourSheet->setCellValue("D{$manHourRowIndex}", $dailyOvertimeHours['working_hours']);
            $manHourSheet->setCellValue("E{$manHourRowIndex}", round(($dailyMorningHours['man_power'] + $dailyAfternoonHours['man_power']) / 2));
            $manHourSheet->setCellValue("F{$manHourRowIndex}", $dailyOvertimeHours['man_power']);
            $manHourRowIndex++;
        }
    }

    // Format header
    $manHourSheet->getStyle("A4:F4")->getFont()->setBold(true)->getColor()->setRGB($textheader);

    // Add header background color
    $manHourSheet->getStyle("A4:F4") // แถวหัวตาราง
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
    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $columnLetter) {
        $manHourSheet->getColumnDimension($columnLetter)->setAutoSize(true);
    }


    // สลับสีแถว (striping rows)
    for ($i = 5; $i < $manHourRowIndex; $i += 2) {
        $manHourSheet->getStyle("A{$i}:F{$i}")
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

        // Summary headers for QC
        $detailSheet->setCellValue('Q1', 'วันที่');
        $detailSheet->setCellValue('R1', 'โมเดล');
        $detailSheet->setCellValue('S1', 'ชิ้นส่วน');
        $detailSheet->setCellValue('T1', 'ชื่อ');
        $detailSheet->setCellValue('U1', 'สรุปยอด');
        $detailSheet->setCellValue('V1', 'DayTime (วินาทีรวม)');
        $detailSheet->setCellValue('W1', 'Overtime (วินาทีรวม)');

        // ========== Raw Fill Production Detail Data (Column A-D) ==========
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
        // ========== Raw Fill QC Detail Data (Column F-K) ==========
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

        // ========== Fill Production Summary (Column M-O) ==========
        $summaryRowIndex = 2;
        foreach ($productionSummaryByModel as $prodSummary) {
            $totalQuantity = $prodSummary['total_qty'];
            $modelDate = $prodSummary['date'];            
            
            $detailSheet->setCellValue("M{$summaryRowIndex}", $prodSummary['date']);
            $detailSheet->setCellValue("N{$summaryRowIndex}", $prodSummary['model']);
            $detailSheet->setCellValue("O{$summaryRowIndex}", $totalQuantity);
            $summaryRowIndex++;
        }
        
        // ========== Create QC Summary ==========
        $qcSummaryByComponent = [];
        foreach ($qualityControlRecords as $qcRecord) {
            $recordDateTime = !empty($qcRecord['created_at']) ? new DateTime($qcRecord['created_at']) : null;
            $recordDate = $recordDateTime ? $recordDateTime->format('Y-m-d') : '';
            $recordTime = $recordDateTime ? $recordDateTime->format('H:i:s') : '';
            $componentName = $qcRecord['item'] ?? '';
            $quantity = (int)($qcRecord['qty'] ?? 1);
            
            $componentWithPrefix = (strpos($componentName, 'AS-') === 0) ? $componentName : 'AS-' . $componentName;
            $componentWithoutPrefix = str_replace('AS-', '', $componentName);
            
            $modelName = $itemMapping[$componentWithPrefix]['model'] ?? 
                        $itemMapping[$componentWithoutPrefix]['model'] ?? '';
            $nickName = $itemMapping[$componentWithPrefix]['nickname'] ?? 
                        $itemMapping[$componentWithoutPrefix]['nickname'] ?? '';
            
            // แยก DayTime (08:00-17:00) และ OT (นอกเวลา)
            $hour = $recordDateTime ? (int)$recordDateTime->format('H') : 0;
            $isDayTime = ($hour >= 8 && $hour < 17);
            
            if ($componentName && $recordDate) {
                $summaryKey = $recordDate . '|' . $componentName;
                if (!isset($qcSummaryByComponent[$summaryKey])) {
                    $qcSummaryByComponent[$summaryKey] = [
                        'date' => $recordDate,
                        'model' => $modelName,
                        'component' => $componentWithPrefix,
                        'nickname' => $nickName,
                        'total_qty' => 0,
                        'qty_daytime' => 0,
                        'qty_ot' => 0,
                        'records_daytime' => [],
                        'records_ot' => []
                    ];
                }
                $qcSummaryByComponent[$summaryKey]['total_qty'] += $quantity;
                
                if ($isDayTime) {
                    $qcSummaryByComponent[$summaryKey]['qty_daytime'] += $quantity;
                    if ($recordDateTime) {
                        $qcSummaryByComponent[$summaryKey]['records_daytime'][] = $recordDateTime;
                    }
                } else {
                    $qcSummaryByComponent[$summaryKey]['qty_ot'] += $quantity;
                    if ($recordDateTime) {
                        $qcSummaryByComponent[$summaryKey]['records_ot'][] = $recordDateTime;
                    }
                }
            }
        }
       
        // Fill QC summary (columns Q-W)
        $qcSummaryRowIndex = 2;
        foreach ($qcSummaryByComponent as $qcSummary) {
            $modelDate = $qcSummary['date'];
            
            // ดึงเวลาทำงานจาก sewing_man_act (ใช้แค่ thour ไม่คูณจำนวนคน)
            try {
                // DayTime = เช้า + บ่าย
                $dayTimeSql = "SELECT SUM(thour) as total_hours FROM sewing_man_act 
                              WHERE DATE(created_at) = :target_date 
                              AND shift IN ('เช้า', 'บ่าย')";
                $dayTimeStmt = $conn->prepare($dayTimeSql);
                $dayTimeStmt->execute([':target_date' => $modelDate]);
                $dayTimeResult = $dayTimeStmt->fetch(PDO::FETCH_ASSOC);
                $dayTimeHours = (float)($dayTimeResult['total_hours'] ?? 0.0);
                
                // OT
                $otSql = "SELECT SUM(thour) as total_hours FROM sewing_man_act 
                         WHERE DATE(created_at) = :target_date 
                         AND shift = 'OT'";
                $otStmt = $conn->prepare($otSql);
                $otStmt->execute([':target_date' => $modelDate]);
                $otResult = $otStmt->fetch(PDO::FETCH_ASSOC);
                $overtimeHours = (float)($otResult['total_hours'] ?? 0.0);
            } catch(Exception $e) {
                $dayTimeHours = 0.0;
                $overtimeHours = 0.0;
            }
            
            // หาจำนวนชิ้นรวมทั้งหมดในวันนั้นของไลน์นี้ (แยก DayTime และ OT)
            $totalQcDayTimeOnDate = 0;
            $totalQcOTOnDate = 0;
            foreach ($qcSummaryByComponent as $tempSummary) {
                if ($tempSummary['date'] === $modelDate) {
                    $totalQcDayTimeOnDate += $tempSummary['qty_daytime'];
                    $totalQcOTOnDate += $tempSummary['qty_ot'];
                }
            }
            
            // กระจายเวลาตามสัดส่วนจำนวนชิ้นของ component นี้
            $proportionalDayTimeHours = 0;
            if ($totalQcDayTimeOnDate > 0) {
                $proportionalDayTimeHours = ($qcSummary['qty_daytime'] / $totalQcDayTimeOnDate) * $dayTimeHours;
            }
            
            $proportionalOTHours = 0;
            if ($totalQcOTOnDate > 0) {
                $proportionalOTHours = ($qcSummary['qty_ot'] / $totalQcOTOnDate) * $overtimeHours;
            }
            
            // แปลงเป็นวินาที (เวลารวมที่ใช้ผลิต component นี้)
            $totalSecondsDayTime = '';
            if ($proportionalDayTimeHours > 0) {
                $totalSecondsDayTime = round($proportionalDayTimeHours * 3600, 2);
            }
            
            $totalSecondsOT = '';
            if ($proportionalOTHours > 0) {
                $totalSecondsOT = round($proportionalOTHours * 3600, 2);
            }

            
            $detailSheet->setCellValue("Q{$qcSummaryRowIndex}", $qcSummary['date']);
            $detailSheet->setCellValue("R{$qcSummaryRowIndex}", $qcSummary['model']);
            $detailSheet->setCellValue("S{$qcSummaryRowIndex}", $qcSummary['component']);
            $detailSheet->setCellValue("T{$qcSummaryRowIndex}", $qcSummary['nickname']);
            $detailSheet->setCellValue("U{$qcSummaryRowIndex}", $qcSummary['total_qty']);
            $detailSheet->setCellValue("V{$qcSummaryRowIndex}", $totalSecondsDayTime);
            $detailSheet->setCellValue("W{$qcSummaryRowIndex}", $totalSecondsOT);
            $qcSummaryRowIndex++;
        }      

        // Format detail sheet
        foreach (['A', 'B', 'C', 'D', 'F', 'G', 'H', 'I', 'J', 'K', 'M', 'N', 'O', 'Q', 'R', 'S','T', 'U', 'V', 'W'] as $columnLetter) {
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

        $detailSheet->getStyle("M1:O1")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB($colorheader);

        $detailSheet->getStyle("Q1:W1")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB($colorheader);
            
        // สลับสีแถว (striping rows)
        for ($i = 2; $i < $detailRowIndex; $i += 2) {
            $detailSheet->getStyle("A{$i}:D{$i}")
                ->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($colorrow); 
        }
        for ($i = 2; $i < $qcDetailRowIndex; $i += 2) {
            $detailSheet->getStyle("F{$i}:K{$i}")
                ->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($colorrow); 
        }
        for ($i = 2; $i < $summaryRowIndex; $i += 2) {
            $detailSheet->getStyle("M{$i}:O{$i}")
                ->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($colorrow); 
        }
        for ($i = 2; $i < $qcSummaryRowIndex; $i += 2) {
            $detailSheet->getStyle("Q{$i}:W{$i}")
                ->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($colorrow); 
        }
        // Bold all headers
        $detailSheet->getStyle('A1:D1')->getFont()->setBold(true)->getColor()->setRGB($textheader);
        $detailSheet->getStyle('F1:K1')->getFont()->setBold(true)->getColor()->setRGB($textheader);
        $detailSheet->getStyle('M1:O1')->getFont()->setBold(true)->getColor()->setRGB($textheader);
        $detailSheet->getStyle('Q1:W1')->getFont()->setBold(true)->getColor()->setRGB($textheader);

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