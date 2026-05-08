<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../config/app.php';
header('Content-Type: application/json; charset=utf-8');
setCorsHeaders();

include __DIR__ . '/../config/db.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > 2100) {
    echo json_encode(['success' => false, 'message' => 'Invalid year']);
    exit;
}

try {
    if (!$conn) throw new PDOException("Database connection failed");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Production qty per month (รวมทุก line, status=10) ────────────────────
    $prod_tables = ['sewing_fc', 'sewing_fb', 'sewing_rc', 'sewing_rb', 'sewing_3rd', 'sewing_sub'];
    $union_parts = array_map(
        fn($t) => "SELECT qty, created_at FROM `$t` WHERE status='10' AND YEAR(created_at)=?",
        $prod_tables
    );
    $union_sql = implode(' UNION ALL ', $union_parts);
    $prod_sql  = "SELECT MONTH(created_at) AS m, SUM(qty) AS prod_qty
                  FROM ($union_sql) AS prod
                  GROUP BY MONTH(created_at)";

    $stmt = $conn->prepare($prod_sql);
    $stmt->execute(array_fill(0, count($prod_tables), $year));
    $prod_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $prod_data[(int)$row['m']] = (int)$row['prod_qty'];
    }

    // ── Defect qty per month (qc_ng) ─────────────────────────────────────────
    $defect_sql = "SELECT MONTH(created_at) AS m, SUM(qty) AS defect_qty
                   FROM qc_ng
                   WHERE YEAR(created_at) = ?
                   GROUP BY MONTH(created_at)";
    $stmt = $conn->prepare($defect_sql);
    $stmt->execute([$year]);
    $defect_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $defect_data[(int)$row['m']] = (int)$row['defect_qty'];
    }

    // ── สร้างผลลัพธ์ 12 เดือน ─────────────────────────────────────────────
    $month_labels = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
                     'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $prod_qty   = $prod_data[$m]   ?? 0;
        $defect_qty = $defect_data[$m] ?? 0;
        $dr = $prod_qty > 0
            ? round($defect_qty / $prod_qty * 100, 2)
            : null;   // null = ยังไม่มีข้อมูล
        $months[] = [
            'month'      => $m,
            'label'      => $month_labels[$m - 1],
            'dr'         => $dr,
            'prod_qty'   => $prod_qty,
            'defect_qty' => $defect_qty,
        ];
    }

    echo json_encode(['success' => true, 'year' => $year, 'months' => $months]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
