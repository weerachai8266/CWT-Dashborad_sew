<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once '../vendor/autoload.php'; // สำหรับ PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

include(__DIR__ . "/../connect.php"); // เชื่อมต่อฐานข้อมูล
require_once(__DIR__ . '/data.class.php');

function cleanSheetTitle($title) {
    // แทนอักขระต้องห้ามด้วย "-"
    return substr(preg_replace('/[\\/?*\[\]:]/', '-', $title), 0, 31);
}

// ตรวจสอบว่าเป็นคำขอ GET หรือไม่
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // ใช้ $conn จากไฟล์ connect.php ที่ include มาแล้ว
        $report = new get_db($conn);

        $start_date = $_GET['start_date'] ?? date('Y-m-d');
        $end_date = $_GET['end_date'] ?? date('Y-m-d');
        
        $data = $report->getDetailReport($start_date, $end_date);
        $summary = $report->getSummaryReport($start_date, $end_date);

        $spreadsheet = new Spreadsheet();
        
        // สร้างแผ่นสรุปรวม
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('สรุปรวม');
        
        $sheet->setCellValue('A1', 'รายงานสรุปการเย็บ');
        $sheet->setCellValue('A2', 'ช่วงวันที่: ' . $start_date . ' ถึง ' . $end_date);
        
        $sheet->setCellValue('A4', 'ไลน์การผลิต');
        $sheet->setCellValue('B4', 'จำนวนชิ้น');
        $sheet->setCellValue('C4', 'รายการทั้งหมด');
        $sheet->setCellValue('D4', 'โมเดลทั้งหมด');
        
        $line_names = [
            'fc' => 'F/C',
            'fb' => 'F/B', 
            'rc' => 'R/C',
            'rb' => 'R/B',
            'third' => '3RD',
            'sub' => 'Sub'
        ];
        
        $row = 5;
        foreach ($summary as $line => $totals) {
            $sheet->setCellValue('A' . $row, $line_names[$line]);
            $sheet->setCellValue('B' . $row, $totals['total_qty']);
            $sheet->setCellValue('C' . $row, $totals['total_items']);
            $sheet->setCellValue('D' . $row, $totals['unique_items']);
            $row++;
        }

        // สร้างแผ่นรายละเอียดสำหรับแต่ละไลน์
        foreach ($data as $line => $records) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle(cleanSheetTitle($line_names[$line]));
            
            // $sheet->setCellValue('A1', 'ID');
            $sheet->setCellValue('A1', 'รายการ');
            $sheet->setCellValue('B1', 'จำนวน');
            // $sheet->setCellValue('D1', 'สถานะ');
            $sheet->setCellValue('C1', 'วันที่');
            $sheet->setCellValue('D1', 'เวลา');
            
            $row = 2;
            foreach ($records as $record) {
                // $sheet->setCellValue('A' . $row, $record['id']);
                $sheet->setCellValue('A' . $row, $record['item']);
                $sheet->setCellValue('B' . $row, $record['qty']);
                // $sheet->setCellValue('D' . $row, $record['status']);
                $sheet->setCellValue('C' . $row, $record['date']);
                $sheet->setCellValue('D' . $row, $record['time']);
                $row++;
            }
        }

        // ส่งออกไฟล์
        $filename = 'sewing_report_' . $start_date . '_to_' . $end_date . '.xlsx';
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        
    } catch (Exception $e) {
        echo 'Error: ' . $e->getMessage();
    }
}
?>
