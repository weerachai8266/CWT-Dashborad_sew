<?php
header('Content-Type: application/json');
include __DIR__ . '/../config/db.php';

try {
    $start_date = $_GET['start_date'] ?? date('Y-m-d');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    
    // เพิ่ม error logging และ validation
    if (!validateDate($start_date) || !validateDate($end_date)) {
        throw new Exception("Invalid date format. Use YYYY-MM-DD");
    }
    
    // กำหนดลำดับ process ตามที่ต้องการ
    $processOrder = [
        'F/C' => 1,
        'F/B' => 2,
        'R/C' => 3,
        'R/B' => 4,
        '3RD' => 5,
        'SUB' => 6
    ];

    // ดึงข้อมูล defects จากตาราง
    $stmt = $conn->prepare("
        SELECT process, part, detail, lot, qty, created_at
        FROM qc_ng
        WHERE DATE(created_at) BETWEEN ? AND ?
        ORDER BY id
    ");
    $stmt->execute([$start_date, $end_date]);
    $defects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูล item mapping ครั้งเดียว
    $itemsStmt = $conn->prepare("SELECT item, model FROM item WHERE model != ''");
    $itemsStmt->execute();
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // สร้าง lookup table สำหรับ item mapping ที่มีประสิทธิภาพมากขึ้น
    $itemMap = [];
    foreach ($items as $it) {
        $itemMap[$it['item']] = $it['model'] ?? '';
    }
    
    // เตรียมโครงสร้างข้อมูล
    $processData = [
        'details' => [],
        'processes' => [],
        'data' => []
    ];
    
    $modelData = [
        'details' => [],
        'models' => [],
        'data' => []
    ];
    
    // ตัวแปรสำหรับนับว่าพบข้อมูลที่ใช้ได้จริงกี่รายการ
    $validDataCount = 0;
    $unmatchedParts = [];
    
    // วนลูปเพื่อสร้าง Cross-tab
    foreach ($defects as $defect) {
        $detail = trim($defect['detail']);
        $part = trim($defect['part']);
        $process = trim($defect['process']);
        $qty = (int)$defect['qty'];
        
        // ข้ามถ้าไม่มี detail หรือ part หรือ qty เป็น 0
        if (empty($detail) || empty($part) || $qty <= 0) {
            continue;
        }
        
        // ตรวจสอบว่าส่วน part มี item mapping หรือไม่
        $lookupPart = (stripos($part, 'AS-') === 0) ? $part : 'AS-' . $part;
        $model = $itemMap[$lookupPart] ?? $itemMap[$part] ?? '';
        
        // บันทึกส่วนที่ไม่พบข้อมูลตรงกัน (สำหรับการ debug)
        if (empty($model) && !empty($part)) {
            $unmatchedParts[$part] = ($unmatchedParts[$part] ?? 0) + 1;
        }
        
        $validDataCount++;
        
        // เพิ่ม detail ถ้ายังไม่มี
        if (!in_array($detail, $processData['details'])) {
            $processData['details'][] = $detail;
        }
        if (!in_array($detail, $modelData['details'])) {
            $modelData['details'][] = $detail;
        }
        
        // เพิ่ม process และ model ถ้ายังไม่มี
        if (!in_array($process, $processData['processes'])) {
            $processData['processes'][] = $process;
        }
        if (!empty($model) && !in_array($model, $modelData['models'])) {
            $modelData['models'][] = $model;
        }
        
        // เตรียมโครงสร้างข้อมูลสำหรับ process ถ้ายังไม่มี
        if (!isset($processData['data'][$detail])) {
            $processData['data'][$detail] = [];
        }
        if (!isset($processData['data'][$detail][$process])) {
            $processData['data'][$detail][$process] = 0;
        }
        $processData['data'][$detail][$process] += $qty;
        
        // เตรียมโครงสร้างข้อมูลสำหรับ model ถ้ายังไม่มี
        if (!empty($model)) {
            if (!isset($modelData['data'][$detail])) {
                $modelData['data'][$detail] = [];
            }
            if (!isset($modelData['data'][$detail][$model])) {
                $modelData['data'][$detail][$model] = 0;
            }
            $modelData['data'][$detail][$model] += $qty;
        }
    }
    
    // จัดเรียงข้อมูลตามจำนวนของเสีย
    $detailTotals = [];
    
    // คำนวณผลรวมสำหรับแต่ละ detail
    foreach ($processData['details'] as $detail) {
        $total = 0;
        foreach ($processData['processes'] as $process) {
            $total += $processData['data'][$detail][$process] ?? 0;
        }
        $detailTotals[$detail] = $total;
    }
    
    // เรียงลำดับ details ตามจำนวนมากไปน้อย
    arsort($detailTotals);
    
    // จัดเรียง details ใหม่ตามลำดับที่เรียงแล้ว
    $processData['details'] = array_keys($detailTotals);
    $modelData['details'] = array_keys($detailTotals);
    
    // จัดเรียงลำดับ nicknames และ models เพื่อให้อ่านง่ายขึ้น
    // sort($processData['processes']);
    usort($processData['processes'], function($a, $b) use ($processOrder) {
        $orderA = $processOrder[$a] ?? 999;
        $orderB = $processOrder[$b] ?? 999;
        return $orderA <=> $orderB;
    });
    sort($modelData['models']);
    
    // ส่งข้อมูลกลับ
    echo json_encode([
        'success' => true,
        'process_data' => $processData,
        'model_data' => $modelData,
        'summary' => [
            'total_defects' => count($defects),
            'valid_data' => $validDataCount,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'unmatched_parts_count' => count($unmatchedParts)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Cross tab error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

// Helper function to validate date format
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}
?>
        