<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
include __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

const CONFIG_PAGE_PASSWORD = 'cwtconfig';
const SHIFT_CONFIG_FILE = __DIR__ . '/../config/shift_times.json';
const KPI_CONFIG_FILE = __DIR__ . '/../config/kpi_thresholds.json';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizeTimeValue($value) {
    if (!preg_match('/^\d{2}:\d{2}$/', $value ?? '')) {
        throw new Exception('รูปแบบเวลาไม่ถูกต้อง');
    }
    return $value;
}

function loadShiftTimes() {
    $defaults = [
        'เช้า' => ['start' => '08:00', 'end' => '12:00'],
        'บ่าย' => ['start' => '13:00', 'end' => '17:00'],
        'OT' => ['start' => '17:30', 'end' => '21:00'],
    ];

    if (!file_exists(SHIFT_CONFIG_FILE)) {
        return $defaults;
    }

    $data = json_decode(file_get_contents(SHIFT_CONFIG_FILE), true);
    if (!is_array($data)) {
        return $defaults;
    }

    foreach ($defaults as $shift => $times) {
        $data[$shift]['start'] = $data[$shift]['start'] ?? $times['start'];
        $data[$shift]['end'] = $data[$shift]['end'] ?? $times['end'];
    }

    return $data;
}

function saveShiftTimes($shift_times) {
    $json = json_encode($shift_times, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents(SHIFT_CONFIG_FILE, $json . PHP_EOL, LOCK_EX) === false) {
        throw new Exception('ไม่สามารถบันทึกไฟล์ config เวลาได้');
    }
}

function loadKpiThresholds() {
    $defaults = [
        'dr_threshold' => 1.7,
        'dr_warn_threshold' => 2.0,
        'qr_good_threshold' => 98,
        'qr_warn_threshold' => 96,
        'perf_threshold_excellent' => 101,
        'perf_threshold_good' => 95,
        'perf_threshold_warning' => 85,
        'prod_gauge_max' => 5.0,
        'prod_target' => 4.0,
    ];

    if (!file_exists(KPI_CONFIG_FILE)) {
        return $defaults;
    }

    $data = json_decode(file_get_contents(KPI_CONFIG_FILE), true);
    if (!is_array($data)) {
        return $defaults;
    }

    return array_merge($defaults, array_intersect_key($data, $defaults));
}

function saveKpiThresholds($thresholds) {
    $json = json_encode($thresholds, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents(KPI_CONFIG_FILE, $json . PHP_EOL, LOCK_EX) === false) {
        throw new Exception('ไม่สามารถบันทึกไฟล์ config KPI ได้');
    }
}

function postFloat($name) {
    if (!isset($_POST[$name]) || !is_numeric($_POST[$name])) {
        throw new Exception('ค่า KPI threshold ไม่ถูกต้อง');
    }
    return (float)$_POST[$name];
}

if (isset($_GET['logout'])) {
    unset($_SESSION['config_authenticated']);
    header('Location: config.php');
    exit;
}

$is_authenticated = $_SESSION['config_authenticated'] ?? false;
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (hash_equals(CONFIG_PAGE_PASSWORD, $_POST['password'] ?? '')) {
        $_SESSION['config_authenticated'] = true;
        header('Location: config.php');
        exit;
    }
    $error_message = 'รหัสผ่านไม่ถูกต้อง';
}

if ($is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add_break':
                $stmt = $conn->prepare("INSERT INTO break_times (break_name, start_time, end_time, duration_minutes) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    trim($_POST['break_name']),
                    normalizeTimeValue($_POST['start_time']),
                    normalizeTimeValue($_POST['end_time']),
                    (int)$_POST['duration_minutes']
                ]);
                $success_message = 'เพิ่มเวลาพักเบรคเรียบร้อยแล้ว';
                break;

            case 'update_break':
                $stmt = $conn->prepare("UPDATE break_times SET break_name = ?, start_time = ?, end_time = ?, duration_minutes = ?, is_active = ? WHERE id = ?");
                $stmt->execute([
                    trim($_POST['break_name']),
                    normalizeTimeValue($_POST['start_time']),
                    normalizeTimeValue($_POST['end_time']),
                    (int)$_POST['duration_minutes'],
                    isset($_POST['is_active']) ? 1 : 0,
                    (int)$_POST['id']
                ]);
                $success_message = 'อัปเดตเวลาพักเบรคเรียบร้อยแล้ว';
                break;

            case 'delete_break':
                $stmt = $conn->prepare("DELETE FROM break_times WHERE id = ?");
                $stmt->execute([(int)$_POST['id']]);
                $success_message = 'ลบเวลาพักเบรคเรียบร้อยแล้ว';
                break;

            case 'update_shifts':
                $shift_times = loadShiftTimes();
                foreach (array_keys($shift_times) as $shift) {
                    $shift_times[$shift] = [
                        'start' => normalizeTimeValue($_POST['shift_start'][$shift] ?? ''),
                        'end' => normalizeTimeValue($_POST['shift_end'][$shift] ?? ''),
                    ];
                }
                saveShiftTimes($shift_times);
                $success_message = 'บันทึกเวลาเริ่ม/จบกะเรียบร้อยแล้ว';
                break;

            case 'update_kpi_thresholds':
                $thresholds = [
                    'dr_threshold' => postFloat('dr_threshold'),
                    'dr_warn_threshold' => postFloat('dr_warn_threshold'),
                    'qr_good_threshold' => postFloat('qr_good_threshold'),
                    'qr_warn_threshold' => postFloat('qr_warn_threshold'),
                    'perf_threshold_excellent' => postFloat('perf_threshold_excellent'),
                    'perf_threshold_good' => postFloat('perf_threshold_good'),
                    'perf_threshold_warning' => postFloat('perf_threshold_warning'),
                    'prod_gauge_max' => postFloat('prod_gauge_max'),
                    'prod_target' => postFloat('prod_target'),
                ];
                saveKpiThresholds($thresholds);
                $success_message = 'บันทึก KPI thresholds เรียบร้อยแล้ว';
                break;
        }
    } catch (Exception $e) {
        $error_message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

$shift_times = loadShiftTimes();
$kpi_thresholds = loadKpiThresholds();
$break_times = [];
if ($is_authenticated) {
    $stmt = $conn->prepare("SELECT * FROM break_times ORDER BY start_time");
    $stmt->execute();
    $break_times = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Config - CWT Production</title>
    <link rel="icon" href="../img/favicon_circular.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="config-page">
    <nav class="navbar navbar-expand-lg fixed-top px-3">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <button class="btn btn-sm d-md-none me-2" id="mobileSidebarToggle"
                        style="color:var(--text-muted);border:1px solid var(--border);padding:6px 10px;"
                        title="เมนู" aria-label="Toggle menu">
                    <i class="fas fa-bars"></i>
                </button>
                <img src="../img/cwt_old-3d.png" alt="CWT Logo" loading="lazy" class="me-3" style="height:70px;">
                <div class="navbar-brand d-none d-md-block">
                    <h5 class="mb-0 fw-semibold text-primary">Chaiwattana</h5>
                    <small class="text-muted">Tannery Group</small>
                </div>
            </div>

            <h1 class="navbar-brand mb-0 mx-auto d-flex align-items-center">
                <i class="fas fa-gear me-2" style="color:#5b8dee;"></i>
                <span class="fw-bold" style="background:linear-gradient(90deg,#5b8dee,#34c780);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">
                    PRODUCTION CONFIG
                </span>
            </h1>

            <div class="d-flex align-items-center gap-3">
                <?php if ($is_authenticated): ?>
                    <a href="config.php?logout=1" class="btn btn-outline-danger btn-sm">ออกจากระบบ</a>
                <?php endif; ?>
                <img src="../img/cwt-3d.png" alt="CWT Logo" loading="lazy" class="d-none d-md-block" style="height:80px;">
            </div>
        </div>
    </nav>

    <div class="d-flex">
        <nav class="sidebar" id="mainSidebar">
            <button class="sidebar-toggle-btn" id="sidebarToggle" title="ยุบ/ขยาย เมนู" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
                <span class="nav-text ms-2">ซ่อนเมนู</span>
            </button>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="../index.php#production" title="Production">📊 <span class="nav-text">Production</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../index.php#quality" title="Quality">✅ <span class="nav-text">Quality</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../index.php#aireport" title="AI Report">🤖 <span class="nav-text">AI Report <span class="badge bg-warning text-dark ms-1" style="font-size:0.6rem;">beta</span></span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="formulas.php" title="Formulas">🧮 <span class="nav-text">Formulas</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="config.php" title="Config">⚙️ <span class="nav-text">Config</span></a>
                </li>
            </ul>
            <div class="sidebar-logo-bottom nav-text">
                <img src="../img/cwt_old-3d.png" alt="CWT Logo" loading="lazy">
            </div>
        </nav>

        <main class="main-content config-main">
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= h($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= h($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!$is_authenticated): ?>
            <div class="row justify-content-center">
                <div class="col-md-5 col-lg-4">
                    <div class="card config-login-card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">เข้าสู่หน้า Config</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="login">
                                <label class="form-label">รหัสผ่าน</label>
                                <input type="password" class="form-control mb-3" name="password" autofocus required>
                                <button type="submit" class="btn btn-primary w-100">เข้าสู่ระบบ</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card config-card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">ตั้งเวลาเริ่ม/จบกะ</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_shifts">
                                <div class="table-responsive">
                                    <table class="table align-middle config-table">
                                        <thead>
                                            <tr>
                                                <th>ช่วงเวลา</th>
                                                <th>เวลาเริ่ม</th>
                                                <th>เวลาจบ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($shift_times as $shift => $times): ?>
                                                <tr>
                                                    <td class="fw-semibold"><?= h($shift) ?></td>
                                                    <td><input type="time" class="form-control" name="shift_start[<?= h($shift) ?>]" value="<?= h($times['start']) ?>" required></td>
                                                    <td><input type="time" class="form-control" name="shift_end[<?= h($shift) ?>]" value="<?= h($times['end']) ?>" required></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <button type="submit" class="btn btn-success">บันทึกเวลากะ</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card config-card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">ตั้งค่า KPI ประจำปี</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="kpiThresholdForm">
                                <input type="hidden" name="action" value="update_kpi_thresholds">
                                <div class="row g-4">
                                    <div class="col-xl-6">
                                        <div class="config-kpi-section">
                                            <div class="config-kpi-section-title">Production</div>
                                            <div class="config-gauge-block">
                                                <div class="config-gauge-form">
                                                    <h6>Performance Gauge</h6>
                                                    <div class="row g-3">
                                                        <div class="col-md-4">
                                                            <label class="form-label">Warning ≥ (%)</label>
                                                            <input type="number" step="0.01" class="form-control kpi-preview-input" name="perf_threshold_warning" value="<?= h($kpi_thresholds['perf_threshold_warning']) ?>" required>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label">Good ≥ (%)</label>
                                                            <input type="number" step="0.01" class="form-control kpi-preview-input" name="perf_threshold_good" value="<?= h($kpi_thresholds['perf_threshold_good']) ?>" required>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label">Excellent ≥ (%)</label>
                                                            <input type="number" step="0.01" class="form-control kpi-preview-input" name="perf_threshold_excellent" value="<?= h($kpi_thresholds['perf_threshold_excellent']) ?>" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="config-kpi-preview">
                                                    <div class="preview-title">ตัวอย่างช่วงสี</div>
                                                    <div><span class="preview-dot critical"></span>Critical &lt; <span data-preview="perf_warning"></span>%</div>
                                                    <div><span class="preview-dot warning"></span>Warning <span data-preview="perf_warning"></span> - <span data-preview="perf_good_prev"></span>%</div>
                                                    <div><span class="preview-dot good"></span>Good <span data-preview="perf_good"></span> - <span data-preview="perf_excellent_prev"></span>%</div>
                                                    <div><span class="preview-dot excellent"></span>Excellent ≥ <span data-preview="perf_excellent"></span>%</div>
                                                </div>
                                            </div>

                                            <div class="config-gauge-block">
                                                <div class="config-gauge-form">
                                                    <h6>Productivity Gauge</h6>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label">Target = 100%</label>
                                                            <input type="number" step="0.01" class="form-control kpi-preview-input" name="prod_target" value="<?= h($kpi_thresholds['prod_target']) ?>" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Gauge Max</label>
                                                            <input type="number" step="0.01" class="form-control kpi-preview-input" name="prod_gauge_max" value="<?= h($kpi_thresholds['prod_gauge_max']) ?>" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="config-kpi-preview">
                                                    <div class="preview-title">ตัวอย่างช่วงสี</div>
                                                    <div><span class="preview-dot critical"></span>Critical &lt; <span data-preview="prod_warning"></span></div>
                                                    <div><span class="preview-dot warning"></span>Warning <span data-preview="prod_warning"></span> - <span data-preview="prod_good_prev"></span></div>
                                                    <div><span class="preview-dot good"></span>Good <span data-preview="prod_good"></span> - <span data-preview="prod_excellent_prev"></span></div>
                                                    <div><span class="preview-dot excellent"></span>Excellent ≥ <span data-preview="prod_excellent"></span></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-xl-6">
                                        <div class="config-kpi-section">
                                            <div class="config-kpi-section-title">Quality</div>
                                            <div class="config-gauge-block">
                                                <div class="config-gauge-form">
                                                    <h6>Quality Rate Gauge</h6>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label">Warning ≥ (%)</label>
                                                            <input type="number" step="0.01" class="form-control kpi-preview-input" name="qr_warn_threshold" value="<?= h($kpi_thresholds['qr_warn_threshold']) ?>" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Good ≥ (%)</label>
                                                            <input type="number" step="0.01" class="form-control kpi-preview-input" name="qr_good_threshold" value="<?= h($kpi_thresholds['qr_good_threshold']) ?>" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="config-kpi-preview">
                                                    <div class="preview-title">ตัวอย่างช่วงสี</div>
                                                    <div><span class="preview-dot critical"></span>Critical &lt; <span data-preview="qr_warning"></span>%</div>
                                                    <div><span class="preview-dot warning"></span>Warning <span data-preview="qr_warning"></span> - <span data-preview="qr_good_prev"></span>%</div>
                                                    <div><span class="preview-dot good"></span>Good ≥ <span data-preview="qr_good"></span>%</div>
                                                </div>
                                            </div>

                                            <div class="config-gauge-block">
                                                <div class="config-gauge-form">
                                                    <h6>Defect Rate Gauge</h6>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label">Good ≤ (%)</label>
                                                            <input type="number" step="0.01" class="form-control kpi-preview-input" name="dr_threshold" value="<?= h($kpi_thresholds['dr_threshold']) ?>" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Warning ≤ (%)</label>
                                                            <input type="number" step="0.01" class="form-control kpi-preview-input" name="dr_warn_threshold" value="<?= h($kpi_thresholds['dr_warn_threshold']) ?>" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="config-kpi-preview">
                                                    <div class="preview-title">ตัวอย่างช่วงสี</div>
                                                    <div><span class="preview-dot good"></span>Good ≤ <span data-preview="dr_good"></span>%</div>
                                                    <div><span class="preview-dot warning"></span>Warning &gt; <span data-preview="dr_good"></span>% ถึง ≤ <span data-preview="dr_warning"></span>%</div>
                                                    <div><span class="preview-dot critical"></span>Critical &gt; <span data-preview="dr_warning"></span>%</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-success mt-3">บันทึก KPI thresholds</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card config-card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">เพิ่มเวลาพักเบรคใหม่</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="addBreakForm">
                                <input type="hidden" name="action" value="add_break">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">ชื่อช่วงพักเบรค</label>
                                        <input type="text" class="form-control" name="break_name" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">เวลาเริ่ม</label>
                                        <input type="time" class="form-control" name="start_time" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">เวลาสิ้นสุด</label>
                                        <input type="time" class="form-control" name="end_time" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">ระยะเวลา (นาที)</label>
                                        <input type="number" class="form-control" name="duration_minutes" min="0" required>
                                    </div>
                                    <div class="col-md-1 d-flex align-items-end">
                                        <button type="submit" class="btn btn-success w-100">เพิ่ม</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card config-card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">รายการเวลาพักเบรค</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle config-table">
                                    <thead>
                                        <tr>
                                            <th>ชื่อช่วงพักเบรค</th>
                                            <th>เวลาเริ่ม</th>
                                            <th>เวลาสิ้นสุด</th>
                                            <th>ระยะเวลา (นาที)</th>
                                            <th>สถานะ</th>
                                            <th>วันที่สร้าง</th>
                                            <th>การจัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($break_times as $break): ?>
                                            <tr>
                                                <td><?= h($break['break_name']) ?></td>
                                                <td><?= h(date('H:i', strtotime($break['start_time']))) ?></td>
                                                <td><?= h(date('H:i', strtotime($break['end_time']))) ?></td>
                                                <td><?= h($break['duration_minutes']) ?></td>
                                                <td>
                                                    <?php if ($break['is_active']): ?>
                                                        <span class="badge bg-success">ใช้งาน</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">ไม่ใช้งาน</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= h(date('d/m/Y H:i', strtotime($break['created_at']))) ?></td>
                                                <td>
                                                    <button class="btn btn-warning btn-sm" onclick='editBreak(<?= json_encode($break, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>แก้ไข</button>
                                                    <button class="btn btn-danger btn-sm" onclick="deleteBreak(<?= (int)$break['id'] ?>, '<?= h($break['break_name']) ?>')">ลบ</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

            <?php renderDashboardFooter('Production Config'); ?>
        </main>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">แก้ไขเวลาพักเบรค</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_break">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">ชื่อช่วงพักเบรค</label>
                            <input type="text" class="form-control" name="break_name" id="edit_break_name" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">เวลาเริ่ม</label>
                                <input type="time" class="form-control" name="start_time" id="edit_start_time" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">เวลาสิ้นสุด</label>
                                <input type="time" class="form-control" name="end_time" id="edit_end_time" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ระยะเวลา (นาที)</label>
                            <input type="number" class="form-control" name="duration_minutes" id="edit_duration_minutes" min="0" required>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                            <label class="form-check-label" for="edit_is_active">ใช้งาน</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebar = document.getElementById('mainSidebar');
        const mainContent = document.querySelector('.main-content');
        const dashboardFooter = document.querySelector('.dashboard-footer');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        function closeMobileSidebar() {
            sidebar?.classList.remove('mobile-open');
            sidebarBackdrop?.classList.remove('active');
        }

        sidebarToggle?.addEventListener('click', () => {
            if (window.innerWidth < 768) {
                sidebar?.classList.toggle('mobile-open');
                sidebarBackdrop?.classList.toggle('active');
                return;
            }
            sidebar?.classList.toggle('collapsed');
            mainContent?.classList.toggle('sidebar-collapsed');
            dashboardFooter?.classList.toggle('sidebar-collapsed');
        });

        mobileSidebarToggle?.addEventListener('click', () => {
            sidebar?.classList.add('mobile-open');
            sidebarBackdrop?.classList.add('active');
        });

        sidebarBackdrop?.addEventListener('click', closeMobileSidebar);
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) closeMobileSidebar();
        });

        function numberValue(name) {
            return parseFloat(document.querySelector(`[name="${name}"]`)?.value || '0');
        }

        function fmt(value, digits = 2) {
            if (!Number.isFinite(value)) return '-';
            return Number(value.toFixed(digits)).toString();
        }

        function setPreview(key, value) {
            document.querySelectorAll(`[data-preview="${key}"]`).forEach(el => {
                el.textContent = value;
            });
        }

        function updateKpiPreview() {
            const perfWarning = numberValue('perf_threshold_warning');
            const perfGood = numberValue('perf_threshold_good');
            const perfExcellent = numberValue('perf_threshold_excellent');
            const prodTarget = numberValue('prod_target');
            const qrWarning = numberValue('qr_warn_threshold');
            const qrGood = numberValue('qr_good_threshold');
            const drGood = numberValue('dr_threshold');
            const drWarning = numberValue('dr_warn_threshold');

            setPreview('perf_warning', fmt(perfWarning, 2));
            setPreview('perf_good', fmt(perfGood, 2));
            setPreview('perf_excellent', fmt(perfExcellent, 2));
            setPreview('perf_good_prev', fmt(perfGood - 1, 2));
            setPreview('perf_excellent_prev', fmt(perfExcellent - 1, 2));

            const prodWarning = prodTarget * (perfWarning / 100);
            const prodGood = prodTarget * (perfGood / 100);
            const prodExcellent = prodTarget * (perfExcellent / 100);
            setPreview('prod_warning', fmt(prodWarning, 2));
            setPreview('prod_good', fmt(prodGood, 2));
            setPreview('prod_excellent', fmt(prodExcellent, 2));
            setPreview('prod_good_prev', fmt(Math.max(0, prodGood - 0.01), 2));
            setPreview('prod_excellent_prev', fmt(Math.max(0, prodExcellent - 0.01), 2));

            setPreview('qr_warning', fmt(qrWarning, 2));
            setPreview('qr_good', fmt(qrGood, 2));
            setPreview('qr_good_prev', fmt(qrGood - 0.01, 2));

            setPreview('dr_good', fmt(drGood, 2));
            setPreview('dr_warning', fmt(drWarning, 2));
        }

        document.querySelectorAll('.kpi-preview-input').forEach(input => {
            input.addEventListener('input', updateKpiPreview);
        });
        updateKpiPreview();
        document.getElementById('copyright-year').textContent = new Date().getFullYear();

        function calculateMinutes(startTime, endTime) {
            if (!startTime || !endTime) return '';
            const start = new Date('2000-01-01T' + startTime);
            const end = new Date('2000-01-01T' + endTime);
            let diff = (end - start) / 60000;
            if (diff < 0) diff += 24 * 60;
            return diff;
        }

        function bindDurationCalculator(startSelector, endSelector, targetSelector) {
            const start = document.querySelector(startSelector);
            const end = document.querySelector(endSelector);
            const target = document.querySelector(targetSelector);
            if (!start || !end || !target) return;
            const update = () => target.value = calculateMinutes(start.value, end.value);
            start.addEventListener('change', update);
            end.addEventListener('change', update);
        }

        bindDurationCalculator('input[name="start_time"]', 'input[name="end_time"]', 'input[name="duration_minutes"]');

        function editBreak(breakData) {
            document.getElementById('edit_id').value = breakData.id;
            document.getElementById('edit_break_name').value = breakData.break_name || '';
            document.getElementById('edit_start_time').value = (breakData.start_time || '').slice(0, 5);
            document.getElementById('edit_end_time').value = (breakData.end_time || '').slice(0, 5);
            document.getElementById('edit_duration_minutes').value = breakData.duration_minutes || 0;
            document.getElementById('edit_is_active').checked = String(breakData.is_active) === '1';
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }

        function deleteBreak(id, name) {
            Swal.fire({
                title: 'ยืนยันการลบ',
                text: `คุณต้องการลบเวลาพักเบรค "${name}" หรือไม่?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (!result.isConfirmed) return;
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete_break"><input type="hidden" name="id" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            });
        }

        document.getElementById('edit_start_time')?.addEventListener('change', () => {
            document.getElementById('edit_duration_minutes').value = calculateMinutes(
                document.getElementById('edit_start_time').value,
                document.getElementById('edit_end_time').value
            );
        });
        document.getElementById('edit_end_time')?.addEventListener('change', () => {
            document.getElementById('edit_duration_minutes').value = calculateMinutes(
                document.getElementById('edit_start_time').value,
                document.getElementById('edit_end_time').value
            );
        });
    </script>
</body>
</html>
