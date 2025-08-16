<?php
/**
 * Quality Defect Export (4 Sheets, NO charts)
 *
 * Sheets:
 *  1) Summary (Line / Problem / Part)
 *  2) Line Trend (dates as columns)
 *  3) Problem Trend (dates as columns)
 *  4) Part Trend (Line + Part + Model + Nickname, dates as columns)
 *
 * Data sources:
 *  - qc_ng(process: F/C,F/B,R/C,R/B,3RD,SUB | part | detail | qty | created_at)
 *  - qc_fc, qc_fb, qc_rc, qc_rb, qc_3rd (or qc_third), qc_sub (item | qty | created_at)
 *  - item (item | model | nickname)  (qc_ng.part already contains AS- and matches item.item)
 *
 * GET params:
 *  start_date=YYYY-MM-DD
 *  end_date=YYYY-MM-DD
 *
 * Notes:
 *  - lot / status not used
 *  - No charts (user request)
 */

ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);

require_once __DIR__ . '/../vendor/autoload.php';
include __DIR__ . '/../config/db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Border;

$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate   = $_GET['end_date'] ?? $startDate;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    http_response_code(400);
    echo "Invalid date format.";
    exit;
}
if ($endDate < $startDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

try {
    // ---------- CONFIG / MAPPING ----------
    // External (display / incoming qc_ng.process) => internal key
    $processToKey = [
        'F/C' => 'fc',
        'F/B' => 'fb',
        'R/C' => 'rc',
        'R/B' => 'rb',
        '3RD' => 'third',
        'SUB' => 'sub'
    ];
    // Internal key => display
    $keyToDisplay = [
        'fc' => 'F/C',
        'fb' => 'F/B',
        'rc' => 'R/C',
        'rb' => 'R/B',
        'third' => '3RD',
        'sub' => 'SUB'
    ];
    $lineOrder = ['fc','fb','rc','rb','third','sub'];

    // Inspection line -> table name (support both qc_3rd & qc_third)
    $inspectionTables = [
        'fc'    => 'qc_fc',
        'fb'    => 'qc_fb',
        'rc'    => 'qc_rc',
        'rb'    => 'qc_rb',
        'third' => 'qc_3rd',   // change to qc_third if your DB uses that
        'sub'   => 'qc_sub'
    ];

    // ---------- HELPERS ----------
    function safePercent(float $a, float $b): float { return $b > 0 ? $a / $b * 100 : 0; }

    function fetchAllAssoc(PDO $conn, string $sql, array $params = []): array {
        $st = $conn->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    function tableExists(PDO $conn, string $table): bool {
        try {
            $conn->query("SELECT 1 FROM {$table} LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // Adjust third table name if fallback needed
    if (!tableExists($conn, $inspectionTables['third'])) {
        if (tableExists($conn, 'qc_third')) {
            $inspectionTables['third'] = 'qc_third';
        }
    }

    // Items mapping
    $items = fetchAllAssoc($conn, "SELECT item, model, nickname FROM item");
    $itemMap = [];
    foreach ($items as $it) {
        $itemMap[$it['item']] = [
            'model' => $it['model'] ?? '',
            'nickname' => $it['nickname'] ?? ''
        ];
    }

    // Defects
    $defects = fetchAllAssoc(
        $conn,
        "SELECT process, part, detail, lot,qty, created_at
         FROM qc_ng
         WHERE DATE(created_at) BETWEEN :s AND :e",
        [':s'=>$startDate, ':e'=>$endDate]
    );
    $rawDefects = $defects; // ทำสำเนา นำไปใช้งานใน sheet 5

    // Normalize process -> internal key
    foreach ($defects as &$d) {
        $p = strtoupper(trim($d['process']));
        $d['process'] = $processToKey[$p] ?? '_skip';
    }
    unset($d);

    // Inspections (per line)
    $inspectionsByLine = [];
    foreach ($lineOrder as $ln) {
        $tbl = $inspectionTables[$ln] ?? null;
        if (!$tbl || !tableExists($conn, $tbl)) {
            $inspectionsByLine[$ln] = [];
            continue;
        }
        $inspectionsByLine[$ln] = fetchAllAssoc(
            $conn,
            "SELECT item, qty, created_at
             FROM {$tbl}
             WHERE DATE(created_at) BETWEEN :s AND :e",
            [':s'=>$startDate, ':e'=>$endDate]
        );
    }

    // ---------- COLLECT DATES ----------
    $dateSet = [];
    foreach ($defects as $r) {
        if ($r['process'] === '_skip') continue;
        $dateSet[substr($r['created_at'],0,10)] = true;
    }
    foreach ($inspectionsByLine as $rows) {
        foreach ($rows as $r) {
            $dateSet[substr($r['created_at'],0,10)] = true;
        }
    }
    $dates = array_keys($dateSet);
    sort($dates);

    // ---------- DATA STRUCTURES ----------
    $ngByLine = $inspectByLine = [];
    $ngByProblem = [];
    $ngByPart = [];             // [line][part] => ng sum
    $inspectByPart = [];        // [line][part] => inspect sum
    $ngLineDate = [];           // [line][date] => ng
    $ngProblemDate = [];        // [problem][date] => ng
    $ngPartDate = [];           // [line][part][date] => ng
    $inspectPartDate = [];      // [line][part][date] => inspected

    foreach ($lineOrder as $ln) {
        foreach ($dates as $d) {
            $ngLineDate[$ln][$d] = 0;
        }
    }

    // ---------- AGGREGATE DEFECTS ----------
    foreach ($defects as $r) {
        $ln = $r['process'];
        if (!in_array($ln, $lineOrder, true)) continue;
        $part = $r['part'];
        $prob = $r['detail'];
        $qty  = (float)$r['qty'];
        $date = substr($r['created_at'],0,10);

        $ngByLine[$ln] = ($ngByLine[$ln] ?? 0) + $qty;
        $ngLineDate[$ln][$date] = ($ngLineDate[$ln][$date] ?? 0) + $qty;

        $ngByProblem[$prob] = ($ngByProblem[$prob] ?? 0) + $qty;
        $ngProblemDate[$prob][$date] = ($ngProblemDate[$prob][$date] ?? 0) + $qty;

        $ngByPart[$ln][$part] = ($ngByPart[$ln][$part] ?? 0) + $qty;
        $ngPartDate[$ln][$part][$date] = ($ngPartDate[$ln][$part][$date] ?? 0) + $qty;
    }

    // Fill zeros (problems)
    foreach ($ngProblemDate as $prob => $arr) {
        foreach ($dates as $d) {
            if (!isset($ngProblemDate[$prob][$d])) {
                $ngProblemDate[$prob][$d] = 0;
            }
        }
    }
    // Fill zeros (parts)
    foreach ($ngPartDate as $ln => $parts) {
        foreach ($parts as $p => $dArr) {
            foreach ($dates as $d) {
                if (!isset($ngPartDate[$ln][$p][$d])) {
                    $ngPartDate[$ln][$p][$d] = 0;
                }
            }
        }
    }

    // ---------- AGGREGATE INSPECTIONS ----------
    foreach ($inspectionsByLine as $ln => $rows) {
        foreach ($rows as $r) {
            $part = $r['item'];
            $qty  = (float)$r['qty'];
            $date = substr($r['created_at'],0,10);

            $inspectByLine[$ln] = ($inspectByLine[$ln] ?? 0) + $qty;
            $inspectByPart[$ln][$part] = ($inspectByPart[$ln][$part] ?? 0) + $qty;
            $inspectPartDate[$ln][$part][$date] = ($inspectPartDate[$ln][$part][$date] ?? 0) + $qty;
        }
    }
    // Fill zeros for inspection part dates
    foreach ($inspectPartDate as $ln => $parts) {
        foreach ($parts as $p => $dArr) {
            foreach ($dates as $d) {
                if (!isset($inspectPartDate[$ln][$p][$d])) {
                    $inspectPartDate[$ln][$p][$d] = 0;
                }
            }
        }
    }

    // ---------- CREATE SPREADSHEET ----------
    $spreadsheet = new Spreadsheet();

    // Set color for alternating rows
    $colorrow = 'E0E0E0'; //เทา
    $colorheader = '404040'; //ฟ้า
    $textheader = 'FFFFFF'; //ขาว

    // ===== SHEET 1: SUMMARY =====
    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('Summary');
    $row = 1;

    // Line Summary
    $sheet1->fromArray(['Line','NG Qty','Inspect Qty','% Defect'], null, "A{$row}");
    $row++;
    $lineRows = [];
    foreach ($lineOrder as $ln) {
        $ng  = $ngByLine[$ln] ?? 0;
        $ins = $inspectByLine[$ln] ?? 0;
        if ($ng == 0 && $ins == 0) continue;
        $lineRows[] = [
            'ln'=>$ln,
            'ng'=>$ng,
            'ins'=>$ins,
            'pct'=>safePercent($ng,$ins)
        ];
    }
    usort($lineRows, fn($a,$b)=> $b['ng'] <=> $a['ng']);
    foreach ($lineRows as $lr) {
        $sheet1->setCellValue("A{$row}", $keyToDisplay[$lr['ln']] ?? $lr['ln']);
        $sheet1->setCellValue("B{$row}", $lr['ng']);
        $sheet1->setCellValue("C{$row}", $lr['ins']);
        $sheet1->setCellValue("D{$row}", $lr['pct']/100);
        $row++;
    }
    $lineEnd = $row - 1;
    if ($lineEnd >= 2) {
        $sheet1->setCellValue("A{$row}", 'TOTAL');
        $sheet1->setCellValue("B{$row}", "=SUM(B2:B{$lineEnd})");
        $sheet1->setCellValue("C{$row}", "=SUM(C2:C{$lineEnd})");
        $sheet1->setCellValue("D{$row}", "=IF(C{$row}>0,B{$row}/C{$row},0)");
        $row += 2;
    } else {
        $row++;
    }

    // Problem Summary
    $problemHeader = $row;
    $sheet1->fromArray(['Problem','NG Qty','% Share','Cumulative %'], null, "A{$row}");
    $row++;
    $probRows = [];
    foreach ($ngByProblem as $p => $ng) {
        if ($ng <= 0) continue;
        $probRows[] = ['p'=>$p,'ng'=>$ng];
    }
    usort($probRows, fn($a,$b)=> $b['ng'] <=> $a['ng']);
    $probTotal = array_sum(array_column($probRows,'ng'));
    foreach ($probRows as $i=>$pr) {
        $share = safePercent($pr['ng'], $probTotal)/100;
        $sheet1->setCellValue("A{$row}", $pr['p']);
        $sheet1->setCellValue("B{$row}", $pr['ng']);
        $sheet1->setCellValue("C{$row}", $share);
        if ($i === 0) {
            $sheet1->setCellValue("D{$row}", "=C{$row}");
        } else {
            $sheet1->setCellValue("D{$row}", "=D".($row-1)."+C{$row}");
        }
        $row++;
    }
    $problemEnd = $row - 1;
    $row += 2;

    // Model Summary
    $modelHeader = $row;
    $sheet1->fromArray(['Model','NG Qty','Inspect Qty','% Defect'], null, "A{$row}");
    $row++;

    // รวบรวมข้อมูลตาม (model)
    $modelAgg = [];              // key model => ['model'=>..., 'ng'=>..., 'ins'=>...]
    $missingItemParts = [];      // parts ไม่มีในตาราง item

    // NG
    foreach ($ngByPart as $ln => $parts) {
        foreach ($parts as $part => $ng) {
            if ($ng <= 0) continue;
            if (!isset($itemMap[$part])) $missingItemParts[$part] = true;
            $model = ($itemMap[$part]['model'] ?? '') ?: $part; // fallback
            if (!isset($modelAgg[$model])) {
                $modelAgg[$model] = ['model'=>$model,'ng'=>0,'ins'=>0];
            }
            $modelAgg[$model]['ng'] += $ng;
        }
    }
    // Inspect
    foreach ($inspectByPart as $ln => $parts) {
        foreach ($parts as $part => $ins) {
            if ($ins <= 0) continue;

            // Normalize key for item table (ensure starts with AS-)
            $lookupPart = (stripos($part, 'AS-') === 0) ? $part : 'AS-' . $part;

            if (!isset($itemMap[$lookupPart])) $missingItemParts[$lookupPart] = true;
            $model = ($itemMap[$lookupPart]['model'] ?? '') ?: $lookupPart;
            if (!isset($modelAgg[$model])) {
                $modelAgg[$model] = ['model'=>$model,'ng'=>0,'ins'=>0];
            }
            $modelAgg[$model]['ins'] += $ins;
        }
    }

    $modelRows = array_values($modelAgg);
    usort($modelRows, fn($a,$b)=> $b['ng'] <=> $a['ng']);

    foreach ($modelRows as $mr) {
        if ($mr['ng']==0 && $mr['ins']==0) continue;
        $sheet1->setCellValue("A{$row}", $mr['model']);
        $sheet1->setCellValue("B{$row}", $mr['ng']);
        $sheet1->setCellValue("C{$row}", $mr['ins']);
        $sheet1->setCellValue("D{$row}", safePercent($mr['ng'],$mr['ins'])/100);
        $row++;
    }
    $modelEnd = $row - 1;

    // ----- Formatting Summary (Line + Problem + Model) -----
    foreach (range('A','D') as $c) {
        $sheet1->getColumnDimension($c)->setAutoSize(true);
    }

    // Line table formatting (unchanged)
    if ($lineEnd >= 2) {
        $sheet1->getStyle("A1:D1")->getFont()->setBold(true);
        $sheet1->getStyle("D2:D{$lineEnd}")
            ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
        $sheet1->getStyle("A1:D{$lineEnd}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
    // Problem table formatting (unchanged)
    if ($problemEnd >= $problemHeader+1) {
        $sheet1->getStyle("A{$problemHeader}:D{$problemHeader}")->getFont()->setBold(true);
        $sheet1->getStyle("C".($problemHeader+1).":D{$problemEnd}")
            ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
        $sheet1->getStyle("A{$problemHeader}:D{$problemEnd}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
    // Model table formatting
    if ($modelEnd >= $modelHeader+1) {
        $sheet1->getStyle("A{$modelHeader}:D{$modelHeader}")->getFont()->setBold(true);
        $sheet1->getStyle("D".($modelHeader+1).":D{$modelEnd}")
            ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
        $sheet1->getStyle("A{$modelHeader}:D{$modelEnd}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
    // --- เพิ่มชีท DEBUG แสดง part ที่ไม่มีใน item (ช่วยตรวจยอดหาย) ---
    // if (!empty($missingItemParts)) {
    //     $dbgSheet = $spreadsheet->createSheet()->setTitle('DEBUG_MISSING_ITEM');
    //     $dbgSheet->fromArray(['Part (not in item table)'], null, 'A1');
    //     $rDbg = 2;
    //     foreach (array_keys($missingItemParts) as $p) {
    //         $dbgSheet->setCellValue("A{$rDbg}", $p);
    //         $rDbg++;
    //     }
    //     $dbgSheet->getColumnDimension('A')->setAutoSize(true);
    //     $dbgSheet->getStyle("A1:A1")->getFont()->setBold(true);
    //     $dbgSheet->getStyle("A1:A".($rDbg-1))
    //         ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    // }

    
    // ===== SHEET 2: CROSS-TAB NICKNAME x DETAIL =====
    $sheet2 = $spreadsheet->createSheet()->setTitle('Cross-Part');
    
    // รวบรวมข้อมูลที่จำเป็นสำหรับ Cross-tab
    $allDetails = array_keys($ngByProblem);
    uasort($ngByProblem, function($a, $b) {
        return $b <=> $a;
    });
    $allDetails = array_keys($ngByProblem);
    
    // รวบรวม nickname ที่มีใช้งาน
    $allNicknames = [];
    foreach ($ngByPart as $ln => $parts) {
        foreach ($parts as $part => $ng) {
            if ($ng <= 0) continue;
            $lookupPart = (stripos($part, 'AS-') === 0) ? $part : 'AS-' . $part;
            $nick = $itemMap[$lookupPart]['nickname'] ?? ($itemMap[$part]['nickname'] ?? '');
            if ($nick) $allNicknames[$nick] = true;
        }
    }
    $allNicknames = array_keys($allNicknames);
    sort($allNicknames);
    
    // สร้างโครงสร้างข้อมูล [detail][nickname] => qty
    $detailNickQty = [];
    $nickTotalNG = array_fill_keys($allNicknames, 0);
    $nickTotalInspect = array_fill_keys($allNicknames, 0);
    $detailTotalNG = array_fill_keys($allDetails, 0);
    
    foreach ($rawDefects as $d) {
        $detail = $d['detail'];
        $part = $d['part'];
        $qty = (float)$d['qty'];
        if ($qty <= 0) continue;
        
        $lookupPart = (stripos($part, 'AS-') === 0) ? $part : 'AS-' . $part;
        $nick = $itemMap[$lookupPart]['nickname'] ?? ($itemMap[$part]['nickname'] ?? '');
        if (!$nick) continue; // ข้ามถ้าไม่มี nickname
        
        // สะสมข้อมูลตาม detail และ nickname
        if (!isset($detailNickQty[$detail])) {
            $detailNickQty[$detail] = array_fill_keys($allNicknames, 0);
        }
        $detailNickQty[$detail][$nick] += $qty;
        
        // สะสมยอดรวมแยกตาม nickname และ detail
        $nickTotalNG[$nick] += $qty;
        $detailTotalNG[$detail] += $qty;
    }
    
    // รวมยอดตรวจทั้งหมดแยกตาม nickname
    foreach ($inspectByPart as $ln => $parts) {
        foreach ($parts as $part => $qty) {
            if ($qty <= 0) continue;
            $lookupPart = (stripos($part, 'AS-') === 0) ? $part : 'AS-' . $part;
            $nick = $itemMap[$lookupPart]['nickname'] ?? ($itemMap[$part]['nickname'] ?? '');
            if (!$nick) continue;
            $nickTotalInspect[$nick] += $qty;
        }
    }
    
    // เตรียมข้อมูลสำหรับแสดงในตาราง
    $grandTotalNG = array_sum($nickTotalNG);
    $grandTotalInspect = array_sum($nickTotalInspect);
    
    // เริ่มสร้างตาราง
    // Header row
    $sheet2->setCellValue('A1', 'Detail\Nickname');
    $col = 2;
    foreach ($allNicknames as $nick) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
        $sheet2->setCellValue($colLetter.'1', $nick);
    }
    
    // รวมเสียคอลัมน์สุดท้าย
    $totalColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
    $percentColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
    $sheet2->setCellValue($totalColLetter.'1', 'รวมเสีย');
    $sheet2->setCellValue($percentColLetter.'1', 'สัดส่วน %');
    
    // Detail rows
    $row = 2;
    foreach ($allDetails as $detail) {
        if (!isset($detailNickQty[$detail])) continue;
        
        $sheet2->setCellValue("A$row", $detail);
        $col = 2;
        $rowSum = 0;
        
        foreach ($allNicknames as $nick) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
            $qty = $detailNickQty[$detail][$nick] ?? 0;
            $sheet2->setCellValue($colLetter.$row, $qty);
            $rowSum += $qty;
        }
        
        // รวมแต่ละแถว และ % ของปัญหา
        $sheet2->setCellValue($totalColLetter.$row, $rowSum);
        if ($grandTotalNG > 0) {
            $sheet2->setCellValue($percentColLetter.$row, $rowSum/$grandTotalNG);
        } else {
            $sheet2->setCellValue($percentColLetter.$row, 0);
        }
        
        $row++;
    }
    // Blank row
    $row +=2;

    // Summary rows
    $sumRow = $row;
    $sheet2->setCellValue('A'.$row, 'รวมเสีย(ชิ้น)');
    $col = 2;
    foreach ($allNicknames as $nick) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
        $sheet2->setCellValue($colLetter.$row, $nickTotalNG[$nick]);
    }
    $sheet2->setCellValue($totalColLetter.$row, $grandTotalNG);
    $sheet2->setCellValue($percentColLetter.$row, 1);
    $row++;
    
    // จำนวนที่ตรวจ
    $sheet2->setCellValue('A'.$row, 'จำนวนที่ตรวจ');
    $col = 2;
    foreach ($allNicknames as $nick) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
        $sheet2->setCellValue($colLetter.$row, $nickTotalInspect[$nick]);
    }
    $sheet2->setCellValue($totalColLetter.$row, $grandTotalInspect);
    $row++;
    
    // % ของเสีย
    $sheet2->setCellValue('A'.$row, '% ของเสีย');
    $col = 2;
    foreach ($allNicknames as $nick) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
        if ($nickTotalInspect[$nick] > 0) {
            $sheet2->setCellValue($colLetter.$row, $nickTotalNG[$nick] / $nickTotalInspect[$nick]); // NG / Total
        } else {
            $sheet2->setCellValue($colLetter.$row, 0);
        }
    }
    if ($grandTotalInspect > 0) {
        $sheet2->setCellValue($totalColLetter.$row, $grandTotalNG / $grandTotalInspect);
    } else {
        $sheet2->setCellValue($totalColLetter.$row, 0);
    }
    $row++;
    
    // ชิ้นงาน OK
    $sheet2->setCellValue('A'.$row, 'ชิ้นงาน OK');
    $col = 2;
    foreach ($allNicknames as $nick) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
        $sheet2->setCellValue($colLetter.$row, $nickTotalInspect[$nick] - $nickTotalNG[$nick]);
    }
    $sheet2->setCellValue($totalColLetter.$row, $grandTotalInspect - $grandTotalNG);
    $lastRow = $row;
    
    // จัดรูปแบบตาราง
    $lastColLetter = $percentColLetter;
    // Auto-size columns
    foreach (range('A', $lastColLetter) as $col) {
        $sheet2->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Bold header row and first column
    $sheet2->getStyle("A1:{$lastColLetter}1")->getFont()->setBold(true);
    // $sheet2->getStyle("A1:A{$lastRow}")->getFont()->setBold(true);
    
    // Set percent format for percentage column and row
    $sheet2->getStyle("{$percentColLetter}2:{$percentColLetter}{$sumRow}")
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);        
    $defectRow = $sumRow+2;
    $sheet2->getStyle("B{$defectRow}:{$totalColLetter}{$defectRow}")
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
    // เพิ่มการจัดรูปแบบที่คอลัมน์ % สำหรับแถวรวม
    $sheet2->getStyle("{$percentColLetter}{$sumRow}")
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
    
    // Border all cells in table
    $dataEndRow = $sumRow - 3; // ตำแหน่งแถวสุดท้ายของข้อมูล (ก่อนเว้น 2 บรรทัด)
    $sheet2->getStyle("A1:{$lastColLetter}{$dataEndRow}")
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // Border ส่วนล่างตาราง (ส่วนสรุป)
    $sheet2->getStyle("A{$sumRow}:{$lastColLetter}{$lastRow}")
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    
    // ===== SHEET 3: CROSS-TAB MODEL x DETAIL =====
    $sheet3 = $spreadsheet->createSheet()->setTitle('Cross-Model');
    
    // รวบรวม model ที่มีใช้งาน
    $allModels = [];
    foreach ($ngByPart as $ln => $parts) {
        foreach ($parts as $part => $ng) {
            if ($ng <= 0) continue;
            $lookupPart = (stripos($part, 'AS-') === 0) ? $part : 'AS-' . $part;
            $model = $itemMap[$lookupPart]['model'] ?? ($itemMap[$part]['model'] ?? '');
            if ($model) $allModels[$model] = true;
        }
    }
    $allModels = array_keys($allModels);
    sort($allModels);
    
    // สร้างโครงสร้างข้อมูล [detail][model] => qty
    $detailModelQty = [];
    $modelTotalNG = array_fill_keys($allModels, 0);
    $modelTotalInspect = array_fill_keys($allModels, 0);
    
    foreach ($rawDefects as $d) {
        $detail = $d['detail'];
        $part = $d['part'];
        $qty = (float)$d['qty'];
        if ($qty <= 0) continue;
        
        $lookupPart = (stripos($part, 'AS-') === 0) ? $part : 'AS-' . $part;
        $model = $itemMap[$lookupPart]['model'] ?? ($itemMap[$part]['model'] ?? '');
        if (!$model) continue;
        
        // สะสมข้อมูลตาม detail และ model
        if (!isset($detailModelQty[$detail])) {
            $detailModelQty[$detail] = array_fill_keys($allModels, 0);
        }
        $detailModelQty[$detail][$model] += $qty;
        
        // สะสมยอดรวมแยกตาม model
        $modelTotalNG[$model] += $qty;
    }
    
    // รวมยอดตรวจทั้งหมดแยกตาม model
    foreach ($inspectByPart as $ln => $parts) {
        foreach ($parts as $part => $qty) {
            if ($qty <= 0) continue;
            $lookupPart = (stripos($part, 'AS-') === 0) ? $part : 'AS-' . $part;
            $model = $itemMap[$lookupPart]['model'] ?? ($itemMap[$part]['model'] ?? '');
            if (!$model) continue;
            $modelTotalInspect[$model] += $qty;
        }
    }
    
    // เตรียมข้อมูลสำหรับแสดงในตาราง
    $grandTotalNG_Model = array_sum($modelTotalNG);
    $grandTotalInspect_Model = array_sum($modelTotalInspect);
    
    // เริ่มสร้างตาราง
    // Header row
    $sheet3->setCellValue('A1', 'Detail\Model');
    $col = 2;
    foreach ($allModels as $model) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
        $sheet3->setCellValue($colLetter.'1', $model);
    }
    
    // รวมเสียคอลัมน์สุดท้าย
    $totalColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
    $percentColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
    $sheet3->setCellValue($totalColLetter.'1', 'รวมเสีย');
    $sheet3->setCellValue($percentColLetter.'1', 'สัดส่วน %');
    
    // Detail rows
    $row = 2;
    foreach ($allDetails as $detail) {
        if (!isset($detailModelQty[$detail])) continue;
        
        $sheet3->setCellValue("A$row", $detail);
        $col = 2;
        $rowSum = 0;
        
        foreach ($allModels as $model) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
            $qty = $detailModelQty[$detail][$model] ?? 0;
            $sheet3->setCellValue($colLetter.$row, $qty);
            $rowSum += $qty;
        }
        
        // รวมแต่ละแถว และ % ของปัญหา
        $sheet3->setCellValue($totalColLetter.$row, $rowSum);
        if ($grandTotalNG_Model > 0) {
            $sheet3->setCellValue($percentColLetter.$row, $rowSum/$grandTotalNG_Model);
        } else {
            $sheet3->setCellValue($percentColLetter.$row, 0);
        }
        
        $row++;
    }
    // Blank row
    $row +=2;

    // Summary rows
    $sumRow = $row;
    $sheet3->setCellValue('A'.$row, 'รวมเสีย(ชิ้น)');
    $col = 2;
    foreach ($allModels as $model) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
        $sheet3->setCellValue($colLetter.$row, $modelTotalNG[$model]);
    }
    $sheet3->setCellValue($totalColLetter.$row, $grandTotalNG_Model);
    $sheet3->setCellValue($percentColLetter.$row, 1);
    $row++;
    
    // จำนวนที่ตรวจ
    $sheet3->setCellValue('A'.$row, 'จำนวนที่ตรวจ');
    $col = 2;
    foreach ($allModels as $model) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
        $sheet3->setCellValue($colLetter.$row, $modelTotalInspect[$model]);
    }
    $sheet3->setCellValue($totalColLetter.$row, $grandTotalInspect_Model);
    $row++;
    
    // % ของเสีย
    $sheet3->setCellValue('A'.$row, '% ของเสีย');
    $col = 2;
    foreach ($allModels as $model) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
        if ($modelTotalInspect[$model] > 0) {
            $sheet3->setCellValue($colLetter.$row, $modelTotalNG[$model] / $modelTotalInspect[$model]);
        } else {
            $sheet3->setCellValue($colLetter.$row, 0);
        }
    }
    if ($grandTotalInspect_Model > 0) {
        $sheet3->setCellValue($totalColLetter.$row, $grandTotalNG_Model / $grandTotalInspect_Model);
    } else {
        $sheet3->setCellValue($totalColLetter.$row, 0);
    }
    $row++;
    
    // ชิ้นงาน OK
    $sheet3->setCellValue('A'.$row, 'ชิ้นงาน OK');
    $col = 2;
    foreach ($allModels as $model) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
        $sheet3->setCellValue($colLetter.$row, $modelTotalInspect[$model] - $modelTotalNG[$model]);
    }
    $sheet3->setCellValue($totalColLetter.$row, $grandTotalInspect_Model - $grandTotalNG_Model);
    $lastRow = $row;
    
    // จัดรูปแบบตาราง
    $lastColLetter = $percentColLetter;
    // Auto-size columns
    foreach (range('A', $lastColLetter) as $col) {
        $sheet3->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Bold header row and first column
    $sheet3->getStyle("A1:{$lastColLetter}1")->getFont()->setBold(true);
    // $sheet3->getStyle("A1:A{$lastRow}")->getFont()->setBold(true);
    
    // Set percent format for percentage column and row
    $sheet3->getStyle("{$percentColLetter}2:{$percentColLetter}{$sumRow}")
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
    
    // Border all cells in table
    // Border ส่วนบนตาราง (ส่วนหัวและรายการ Detail)
    $dataEndRow = $sumRow - 3; // ตำแหน่งแถวสุดท้ายของข้อมูล (ก่อนเว้น 2 บรรทัด)
    $sheet3->getStyle("A1:{$lastColLetter}{$dataEndRow}")
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // Border ส่วนล่างตาราง (ส่วนสรุป)
    $sheet3->getStyle("A{$sumRow}:{$lastColLetter}{$lastRow}")
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $defectRow = $sumRow+2;
    $sheet3->getStyle("B{$defectRow}:{$totalColLetter}{$defectRow}")
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
        
    // ===== SHEET 4: LINE TREND =====
    $sheet4 = $spreadsheet->createSheet()->setTitle('Line Trend');
    $sheet4->setCellValue('A1','Line');
    $ci = 2;
    foreach ($dates as $d) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci++);
        $sheet4->setCellValue($col.'1', $d);
    }
    $ngCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci++);
    $insCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci++);
    $pctCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci++);
    $sheet4->setCellValue($ngCol.'1','Total NG');
    $sheet4->setCellValue($insCol.'1','Total Inspect');
    $sheet4->setCellValue($pctCol.'1','% Defect');

    $r = 2;
    foreach ($lineOrder as $ln) {
        $sheet4->setCellValue("A{$r}", $keyToDisplay[$ln] ?? $ln);
        $refs = [];
        $ci2 = 2;
        foreach ($dates as $d) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci2++);
            $v = $ngLineDate[$ln][$d] ?? 0;
            $sheet4->setCellValue($col.$r, $v);
            $refs[] = "{$col}{$r}";
        }
        if ($refs) {
            $sheet4->setCellValue($ngCol.$r, '=SUM('.implode(',',$refs).')');
        }
        $sheet4->setCellValue($insCol.$r, $inspectByLine[$ln] ?? 0);
        $sheet4->setCellValue($pctCol.$r, "=IF({$insCol}{$r}>0,{$ngCol}{$r}/{$insCol}{$r},0)");
        $r++;
    }
    foreach (range('A',$pctCol) as $c) $sheet4->getColumnDimension($c)->setAutoSize(true);
    $sheet4->getStyle("A1:{$pctCol}1")->getFont()->setBold(true);
    $sheet4->getStyle("{$pctCol}2:{$pctCol}".($r-1))
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
    $sheet4->getStyle("A1:{$pctCol}".($r-1))
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // ===== SHEET 5: PROBLEM TREND =====
    $sheet5 = $spreadsheet->createSheet()->setTitle('Problem Trend');
    $sheet5->setCellValue('A1','Problem');
    $ci = 2;
    foreach ($dates as $d) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci++);
        $sheet5->setCellValue($col.'1', $d);
    }
    $totCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci++);
    $shareCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci++);
    $sheet5->setCellValue($totCol.'1','Total NG');
    $sheet5->setCellValue($shareCol.'1','% Share');

    $problemTotals = [];
    foreach ($ngProblemDate as $p => $vals) {
        $problemTotals[$p] = array_sum($vals);
    }
    arsort($problemTotals);

    $r = 2;
    $grandProblem = array_sum($problemTotals);
    foreach ($problemTotals as $p => $tot) {
        if ($tot <= 0) continue;
        $sheet5->setCellValue("A{$r}", $p);
        $refs = [];
        $ci2 = 2;
        foreach ($dates as $d) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci2++);
            $v = $ngProblemDate[$p][$d] ?? 0;
            $sheet5->setCellValue($col.$r, $v);
            $refs[] = "{$col}{$r}";
        }
        $sheet5->setCellValue($totCol.$r, '=SUM('.implode(',',$refs).')');
        $sheet5->setCellValue($shareCol.$r, "=IF({$totCol}{$r}>0,{$totCol}{$r}/".($grandProblem>0?$grandProblem:1).",0)");
        $r++;
    }
    foreach (range('A',$shareCol) as $c) $sheet5->getColumnDimension($c)->setAutoSize(true);
    $sheet5->getStyle("A1:{$shareCol}1")->getFont()->setBold(true);
    $sheet5->getStyle("{$shareCol}2:{$shareCol}".($r-1))
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
    $sheet5->getStyle("A1:{$shareCol}".($r-1))
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // ===== SHEET 6: PART TREND =====
    $sheet6 = $spreadsheet->createSheet()->setTitle('Part Trend');
    $sheet6->setCellValue('A1','Model');
    $ci = 2;
    foreach ($dates as $d) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci++);
        $sheet6->setCellValue($col.'1', $d);
    }
    $totNgColIndex  = $ci++;
    $totInsColIndex = $ci++;
    $pctDefColIndex = $ci++;

    $totNgCol  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totNgColIndex);
    $totInsCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totInsColIndex);
    $pctDefCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($pctDefColIndex);

    $sheet6->setCellValue($totNgCol.'1','Total NG');
    $sheet6->setCellValue($totInsCol.'1','Total Inspect');
    $sheet6->setCellValue($pctDefCol.'1','% Defect');

    // Build model-date NG + total inspect
    $modelDateNg = [];        // [model][date] => ng
    $modelTotalNg = [];       // [model] => total ng
    $modelTotalIns = [];      // [model] => total inspect
    $missingItemParts = $missingItemParts ?? [];

    // NG per date (from $ngPartDate)
    foreach ($ngPartDate as $ln => $parts) {
        foreach ($parts as $part => $dateVals) {
            $model = ($itemMap[$part]['model'] ?? '') ?: $part;
            foreach ($dateVals as $d => $q) {
                if ($q == 0) continue;
                $modelDateNg[$model][$d] = ($modelDateNg[$model][$d] ?? 0) + $q;
                $modelTotalNg[$model] = ($modelTotalNg[$model] ?? 0) + $q;
            }
        }
    }
    // Inspect totals (no per-date display required)
    foreach ($inspectPartDate as $ln => $parts) {
        foreach ($parts as $part => $dateVals) {
            $lookupPart = (stripos($part,'AS-') === 0) ? $part : 'AS-'.$part;
            $model = ($itemMap[$lookupPart]['model'] ?? '') ?: $lookupPart;
            $insSum = array_sum($dateVals);
            if ($insSum == 0) continue;
            $modelTotalIns[$model] = ($modelTotalIns[$model] ?? 0) + $insSum;
        }
    }

    // Ensure every model has all date keys (zero fill)
    foreach ($modelDateNg as $m => $dArr) {
        foreach ($dates as $d) {
            if (!isset($modelDateNg[$m][$d])) $modelDateNg[$m][$d] = 0;
        }
    }
    // Sort models by total NG desc
    $models = array_keys($modelDateNg);
    usort($models, function($a,$b) use ($modelTotalNg) {
        return ($modelTotalNg[$b] ?? 0) <=> ($modelTotalNg[$a] ?? 0);
    });

    $r = 2;
    foreach ($models as $model) {
        $sheet6->setCellValue("A{$r}", $model);
        $refs = [];
        $ci2 = 2;
        foreach ($dates as $d) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci2++);
            $val = $modelDateNg[$model][$d] ?? 0;
            $sheet6->setCellValue($col.$r, $val);
            $refs[] = $col.$r;
        }
        if ($refs) {
            $sheet6->setCellValue($totNgCol.$r, '=SUM('.implode(',',$refs).')');
        } else {
            $sheet6->setCellValue($totNgCol.$r, 0);
        }
        $sheet6->setCellValue($totInsCol.$r, $modelTotalIns[$model] ?? 0);
        $sheet6->setCellValue($pctDefCol.$r, "=IF({$totInsCol}{$r}>0,{$totNgCol}{$r}/{$totInsCol}{$r},0)");
        $r++;
    }

    // Column sizing & formatting
    foreach (range('A',$pctDefCol) as $c) {
        $sheet6->getColumnDimension($c)->setAutoSize(true);
    }
    $sheet6->getStyle("A1:{$pctDefCol}1")->getFont()->setBold(true);
    $sheet6->getStyle("{$pctDefCol}2:{$pctDefCol}".($r-1))
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
    $sheet6->getStyle("A1:{$pctDefCol}".($r-1))
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // ===== SHEET 7: RAW DATA =====
    $sheet7 = $spreadsheet->createSheet()->setTitle('Raw');
    $sheet7->fromArray(['Date', 'Model','Part','Detail','Lot','Process','nickname','Qty'], null, 'A1');
    $r = 2;
    foreach ($rawDefects as $rowRaw) {
        $part = $rowRaw['part'];
        // normalize part for lookup (ensure AS- prefix if your item table uses it)
        $lookupPart = (stripos($part,'AS-') === 0) ? $part : 'AS-'.$part;
        $model = $itemMap[$lookupPart]['model'] ?? ($itemMap[$part]['model'] ?? '');
        $nick  = $itemMap[$lookupPart]['nickname'] ?? ($itemMap[$part]['nickname'] ?? '');
        $sheet7->setCellValue("A{$r}", date('Y-m-d', strtotime($rowRaw['created_at'])));
        $sheet7->setCellValue("B{$r}", $model);
        $sheet7->setCellValue("C{$r}", $part);
        $sheet7->setCellValue("D{$r}", $rowRaw['detail']);
        $sheet7->setCellValue("E{$r}", $rowRaw['lot']);
        $sheet7->setCellValue("F{$r}", strtoupper($rowRaw['process']));
        $sheet7->setCellValue("G{$r}", $nick);
        $sheet7->setCellValue("H{$r}", $rowRaw['qty']);
        $r++;
    }
    // auto size columns
    foreach (range('A','H') as $c) {
        $sheet7->getColumnDimension($c)->setAutoSize(true);
    }
    // Set background color for specific columns
    $sheet7->getStyle("A1:H1")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB($colorheader);

    // Set header colors
    $sheet7->getStyle("A1:H1")->getFont()->setBold(true)->getColor()->setRGB($textheader);
    
    // สลับสีแถว (striping rows)
    for ($i = 2; $i < $r; $i += 2) {
        $sheet7->getStyle("A{$i}:H{$i}")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB($colorrow);
    }

    // Set Borders for the entire table
    // $sheet7->getStyle("A1:H".($r-1))
    //     ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    

    // ---------- OUTPUT ----------
    $filename = "quality_report_{$startDate}_to_{$endDate}.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo "Error: ".$e->getMessage();
    exit;
}
?>