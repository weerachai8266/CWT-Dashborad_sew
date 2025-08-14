<?php
/**
 * Production Report Export to Excel
 * Exports production data, QC data, and man-hour data to Excel file
 * 
 * Features:
 * - Summary sheet with production, QC, and man-hour totals
 * - Detail sheets per production line
 * - Daily man-hour breakdown sheet
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
                WHERE DATE(created_at) BETWEEN :start_date AND :end_date
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
    $summarySheet->setTitle('สรุปรวม');

    // Summary sheet headers
    $summarySheet->setCellValue('A1', 'รายงานสรุปการเย็บ (Production)');
    $summarySheet->setCellValue('A2', 'ช่วงวันที่: ' . $reportStartDate . 
        ($reportStartDate === $reportEndDate ? '' : ' ถึง ' . $reportEndDate));
    
    // Column headers
    $summarySheet->setCellValue('A4', 'ไลน์การผลิต');
    $summarySheet->setCellValue('B4', 'จำนวนชิ้นผลิต');
    $summarySheet->setCellValue('C4', 'จำนวนชิ้นตรวจ');
    $summarySheet->setCellValue('D4', 'โมเดลทั้งหมด');
    $summarySheet->setCellValue('E4', 'Man-Hour เช้า');
    $summarySheet->setCellValue('F4', 'Man-Hour บ่าย');
    $summarySheet->setCellValue('G4', 'Man-Hour OT');
    $summarySheet->setCellValue('H4', 'Man-Hour Total');

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
        $summarySheet->setCellValue('E' . $summaryRowIndex, $morningManHours);
        $summarySheet->setCellValue('F' . $summaryRowIndex, $afternoonManHours);
        $summarySheet->setCellValue('G' . $summaryRowIndex, $overtimeManHours);
        $summarySheet->setCellValue('H' . $summaryRowIndex, "=SUM(E{$summaryRowIndex}:G{$summaryRowIndex})");
        
        $summaryRowIndex++;

    }
        // Auto-size columns
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $columnLetter) {
            $summarySheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }

        // Add borders
        $summarySheet->getStyle("A4:H" . ($summaryRowIndex - 1))
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // Bold headers
        $summarySheet->getStyle('A4:H4')->getFont()->setBold(true);
    

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
    $manHourSheet->setCellValue('C4', 'เช้า');
    $manHourSheet->setCellValue('D4', 'บ่าย');
    $manHourSheet->setCellValue('E4', 'OT');
    $manHourSheet->setCellValue('F4', 'รวม');

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
            $manHourSheet->setCellValue("C{$manHourRowIndex}", $dailyMorningHours);
            $manHourSheet->setCellValue("D{$manHourRowIndex}", $dailyAfternoonHours);
            $manHourSheet->setCellValue("E{$manHourRowIndex}", $dailyOvertimeHours);
            $manHourSheet->setCellValue("F{$manHourRowIndex}", $dailyTotalHours);
            $manHourRowIndex++;
        }
    }

    // Format man-hour sheet
    $manHourSheet->getStyle("A4:F4")->getFont()->setBold(true);
    
    if ($manHourRowIndex > 5) {
        // Add borders to data area
        $manHourSheet->getStyle("A4:F" . ($manHourRowIndex - 1))
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    }

    // Auto-size columns
    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $columnLetter) {
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
        $manHourSheet->setCellValue("F{$totalsRowIndex}", "=SUM(F5:F" . ($totalsRowIndex - 1) . ")");
        
        // Format totals row
        $manHourSheet->getStyle("A{$totalsRowIndex}:F{$totalsRowIndex}")->getFont()->setBold(true);
        $manHourSheet->getStyle("A{$totalsRowIndex}:F{$totalsRowIndex}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK);
    }

    // ==================== DETAIL SHEETS PER LINE ====================
    
    foreach ($productionDetail as $lineCode => $productionRecords) {
        if (!isset($productionLineNames[$lineCode])) {
            continue;
        }

        // Create new sheet for this production line
        $detailSheet = $excelWorkbook->createSheet();
        $detailSheet->setTitle(cleanSheetTitle($productionLineNames[$lineCode]));

        // Detail sheet headers
        $detailSheet->setCellValue('A1', 'โมเดล');
        $detailSheet->setCellValue('B1', 'ชิ้นส่วน');
        $detailSheet->setCellValue('C1', 'จำนวน');
        $detailSheet->setCellValue('D1', 'วันที่');
        $detailSheet->setCellValue('E1', 'เวลา');

        // Summary headers for production
        $detailSheet->setCellValue('G1', 'วันที่');
        $detailSheet->setCellValue('H1', 'โมเดล');
        $detailSheet->setCellValue('I1', 'สรุปยอด');
        $detailSheet->setCellValue('J1', 'รวม Man-Hour');

        // Summary headers for QC
        $detailSheet->setCellValue('L1', 'วันที่');
        $detailSheet->setCellValue('M1', 'โมเดล');
        $detailSheet->setCellValue('N1', 'ชิ้นส่วน');
        $detailSheet->setCellValue('O1', 'ชื่อ');
        $detailSheet->setCellValue('P1', 'สรุปยอด');

        $detailRowIndex = 2;
        
        // Get QC data for this line
        $qualityControlRecords = fetchQualityControlItems($conn, $lineCode, $reportStartDate, $reportEndDate);
        
        // Create item mapping for QC data (ชิ้นส่วน -> โมเดล และ nickname)
        $itemMapping = [];
        try {
            $itemCodes = array_values(array_unique(array_column($qualityControlRecords, 'item')));
            
            // Check if we have item codes before creating query
            if (!empty($itemCodes)) {
                // Add AS- prefix to item codes for database lookup
                $itemCodesWithPrefix = array_map(function($itemCode) {
                    return 'AS-' . $itemCode;
                }, $itemCodes);                
                
                // Create placeholders for prepared statement
                $placeholders = str_repeat('?,', count($itemCodesWithPrefix) - 1) . '?';
                $itemQuery = "SELECT item, model, nickname FROM item WHERE item IN ({$placeholders})";
                
                $itemStmt = $conn->prepare($itemQuery);
                $itemStmt->execute($itemCodesWithPrefix);
                
                $foundItems = [];
                $rowCount = 0;
                while ($itemRow = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
                    $rowCount++;
                    $foundItems[] = $itemRow['item'];
                    $itemMapping[$itemRow['item']] = [
                        'model' => $itemRow['model'] ?? '',
                        'nickname' => $itemRow['nickname'] ?? ''
                    ];
                }                
            }
        } catch (Exception $e) {
            // If item mapping fails, continue with empty mapping
            $itemMapping = [];
        }

        // Group QC records by date
        $qcRecordsByDate = [];
        foreach ($qualityControlRecords as $qcRecord) {
            $recordDateTime = !empty($qcRecord['created_at']) ? new DateTime($qcRecord['created_at']) : null;
            $recordDate = $recordDateTime ? $recordDateTime->format('Y-m-d') : '';
            $recordTime = $recordDateTime ? $recordDateTime->format('H:i:s') : '';
            
            if ($recordDate) {
                if (!isset($qcRecordsByDate[$recordDate])) {
                    $qcRecordsByDate[$recordDate] = [];
                }
                $qcRecordsByDate[$recordDate][] = [
                    'component' => $qcRecord['item'] ?? '',
                    'qty' => (int)($qcRecord['qty'] ?? 1),
                    'time' => $recordTime,
                    'datetime' => $qcRecord['created_at'] ?? ''
                ];
            }
        }
        
        // Group production records by date
        $productionRecordsByDate = [];
        foreach ($productionRecords as $prodRecord) {
            $modelName = $prodRecord['model'] ?? $prodRecord['item'] ?? '';
            $quantity = $prodRecord['qty'] ?? 0;
            $recordDate = $prodRecord['date'] ?? '';
            $recordTime = $prodRecord['time'] ?? '';
            
            if ($recordDate) {
                if (!isset($productionRecordsByDate[$recordDate])) {
                    $productionRecordsByDate[$recordDate] = [];
                }
                $productionRecordsByDate[$recordDate][] = [
                    'model' => $modelName,
                    'qty' => $quantity,
                    'time' => $recordTime,
                    'datetime' => $recordDate . ' ' . $recordTime
                ];
            }
        }
        
        // Get all dates that have either production or QC data
        $allProcessingDates = array_unique(array_merge(
            array_keys($productionRecordsByDate), 
            array_keys($qcRecordsByDate)
        ));
        sort($allProcessingDates);
        
        // Process each date
        foreach ($allProcessingDates as $processingDate) {
            $dailyProductionRecords = $productionRecordsByDate[$processingDate] ?? [];
            $dailyQCRecords = $qcRecordsByDate[$processingDate] ?? [];
            
            // Pair production and QC records for the same date
            $maxDailyRecords = max(count($dailyProductionRecords), count($dailyQCRecords));
            
            for ($recordIndex = 0; $recordIndex < $maxDailyRecords; $recordIndex++) {
                // Get production data
                $modelName = isset($dailyProductionRecords[$recordIndex]) ? $dailyProductionRecords[$recordIndex]['model'] : '';
                $productionQuantity = isset($dailyProductionRecords[$recordIndex]) ? $dailyProductionRecords[$recordIndex]['qty'] : '';
                $productionTime = isset($dailyProductionRecords[$recordIndex]) ? $dailyProductionRecords[$recordIndex]['time'] : '';
                
                // Get QC data
                $componentName = isset($dailyQCRecords[$recordIndex]) ? $dailyQCRecords[$recordIndex]['component'] : '';
                $qcQuantity = isset($dailyQCRecords[$recordIndex]) ? $dailyQCRecords[$recordIndex]['qty'] : '';
                $qcTime = isset($dailyQCRecords[$recordIndex]) ? $dailyQCRecords[$recordIndex]['time'] : '';
                
                // Use production data first, fallback to QC data
                $finalQuantity = $productionQuantity ?: $qcQuantity;
                $finalTime = $productionTime ?: $qcTime;
                
                // Fill detail sheet row
                $detailSheet->setCellValue("A{$detailRowIndex}", $modelName);
                $detailSheet->setCellValue("B{$detailRowIndex}", $componentName);
                $detailSheet->setCellValue("C{$detailRowIndex}", $finalQuantity);
                $detailSheet->setCellValue("D{$detailRowIndex}", $processingDate);
                $detailSheet->setCellValue("E{$detailRowIndex}", $finalTime);
                $detailRowIndex++;
            }
        }

        // Create production summary by date and model
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

        // Create QC summary by date and component
        $qcSummaryByComponent = [];
        foreach ($qualityControlRecords as $qcRecord) {
            $recordDateTime = !empty($qcRecord['created_at']) ? new DateTime($qcRecord['created_at']) : null;
            $recordDate = $recordDateTime ? $recordDateTime->format('Y-m-d') : '';
            $componentName = $qcRecord['item'] ?? '';
            $quantity = (int)($qcRecord['qty'] ?? 1);
            
            // Get model and nickname from item mapping (use AS- prefixed key)
            $prefixedComponentName = 'AS-' . $componentName;
            $modelName = isset($itemMapping[$prefixedComponentName]) ? $itemMapping[$prefixedComponentName]['model'] : '';
            $nickName = isset($itemMapping[$prefixedComponentName]) ? $itemMapping[$prefixedComponentName]['nickname'] : '';
            
            if ($componentName && $recordDate) {
                $summaryKey = $recordDate . '|' . $componentName;
                if (!isset($qcSummaryByComponent[$summaryKey])) {
                    $qcSummaryByComponent[$summaryKey] = [
                        'date' => $recordDate,
                        'model' => $modelName,
                        // 'component' => $componentName, // ไม่มี AS-
                        'component' => $prefixedComponentName, // มี AS-
                        'nickname' => $nickName,
                        'total_qty' => 0
                    ];
                }
                $qcSummaryByComponent[$summaryKey]['total_qty'] += $quantity;
            }
        }

        // Fill production summary with Man-Hour calculation (columns G, H, I, J)
        $summaryRowIndex = 2;
        foreach ($productionSummaryByModel as $prodSummary) {
            $totalQuantity = $prodSummary['total_qty'];
            $modelDate = $prodSummary['date'];
            
            // Calculate total man-hours for this date (all shifts combined)
            $totalManHours = calculateDailyManHours($conn, $lineCode, $modelDate, 'เช้า') +
                           calculateDailyManHours($conn, $lineCode, $modelDate, 'บ่าย') +
                           calculateDailyManHours($conn, $lineCode, $modelDate, 'OT');
            
            // If there are multiple models on the same date, proportionally distribute man-hours
            $totalProductionOnDate = 0;
            foreach ($productionSummaryByModel as $tempSummary) {
                if ($tempSummary['date'] === $modelDate) {
                    $totalProductionOnDate += $tempSummary['total_qty'];
                }
            }
            
            // Distribute man-hours proportionally based on production quantity
            $proportionalManHours = $totalProductionOnDate > 0 ? 
                ($totalManHours * $totalQuantity / $totalProductionOnDate) : 0;
            
            $detailSheet->setCellValue("G{$summaryRowIndex}", $prodSummary['date']);
            $detailSheet->setCellValue("H{$summaryRowIndex}", $prodSummary['model']);
            $detailSheet->setCellValue("I{$summaryRowIndex}", $totalQuantity);
            $detailSheet->setCellValue("J{$summaryRowIndex}", round($proportionalManHours, 2));
            $summaryRowIndex++;
        }

        // Fill QC summary (columns L, M, N, O, P)
        $qcSummaryRowIndex = 2;
        foreach ($qcSummaryByComponent as $qcSummary) {
            $detailSheet->setCellValue("L{$qcSummaryRowIndex}", $qcSummary['date']);
            $detailSheet->setCellValue("M{$qcSummaryRowIndex}", $qcSummary['model']);      // โมเดล
            $detailSheet->setCellValue("N{$qcSummaryRowIndex}", $qcSummary['component']);  // ชิ้นส่วน
            $detailSheet->setCellValue("O{$qcSummaryRowIndex}", $qcSummary['nickname']);   // ชื่อ
            $detailSheet->setCellValue("P{$qcSummaryRowIndex}", $qcSummary['total_qty']);  // สรุปยอด
            $qcSummaryRowIndex++;
        }

        // Format detail sheet
        foreach (['A', 'B', 'C', 'D', 'E', 'G', 'H', 'I', 'J', 'L', 'M', 'N', 'O', 'P'] as $columnLetter) {
            $detailSheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }

        // Add borders to main data
        $detailSheet->getStyle("A1:E" . ($detailRowIndex - 1))
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // Add borders to production summary (with Man-Hour)
        if ($summaryRowIndex > 2) {
            $detailSheet->getStyle("G1:J" . ($summaryRowIndex - 1))
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        }

        // Add borders to QC summary
        if ($qcSummaryRowIndex > 2) {
            $detailSheet->getStyle("L1:P" . ($qcSummaryRowIndex - 1))
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        }

        // Bold all headers
        $detailSheet->getStyle('A1:E1')->getFont()->setBold(true);
        $detailSheet->getStyle('G1:J1')->getFont()->setBold(true);
        $detailSheet->getStyle('L1:P1')->getFont()->setBold(true);
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