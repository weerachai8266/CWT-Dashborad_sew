<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include 'connect.php'; // เชื่อมต่อฐานข้อมูล

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add':
                $stmt = $conn->prepare("INSERT INTO break_times (break_name, start_time, end_time, duration_minutes) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['break_name'],
                    $_POST['start_time'],
                    $_POST['end_time'],
                    $_POST['duration_minutes']
                ]);
                $success_message = "เพิ่มเวลาพักเบรคเรียบร้อยแล้ว";
                break;
                
            case 'update':
                $stmt = $conn->prepare("UPDATE break_times SET break_name = ?, start_time = ?, end_time = ?, duration_minutes = ?, is_active = ? WHERE id = ?");
                $stmt->execute([
                    $_POST['break_name'],
                    $_POST['start_time'],
                    $_POST['end_time'],
                    $_POST['duration_minutes'],
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['id']
                ]);
                $success_message = "อัปเดตเวลาพักเบรคเรียบร้อยแล้ว";
                break;
                
            case 'delete':
                $stmt = $conn->prepare("DELETE FROM break_times WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $success_message = "ลบเวลาพักเบรคเรียบร้อยแล้ว";
                break;
        }
    } catch (Exception $e) {
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// Fetch all break times
$stmt = $conn->prepare("SELECT * FROM break_times ORDER BY start_time");
$stmt->execute();
$break_times = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>จัดการเวลาพักเบรค - CWT Production</title>
    <link rel="icon" href="img/favicon_circular.ico" type="image/x-icon">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <!-- Header Navbar -->
    <nav class="navbar navbar-light bg-light border-bottom fixed-top px-3">
        <div class="container-fluid">
            <div class="d-flex align-items-center gap-3">
                <img src="img/logo-chaiwattana.png" alt="CWT Logo" style="height: 40px;">
                <a href="index.php" class="btn btn-outline-primary btn-sm">← กลับหน้าหลัก</a>
            </div>
            <span class="navbar-brand mb-0 h1 fw-semibold mx-auto">จัดการเวลาพักเบรค</span>
        </div>
    </nav>

    <div class="container-fluid" style="padding-top: 80px;">
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $success_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= $error_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Add New Break Time Form -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">➕ เพิ่มเวลาพักเบรคใหม่</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="addBreakForm">
                            <input type="hidden" name="action" value="add">
                            <div class="row">
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
                                    <input type="number" class="form-control" name="duration_minutes" required>
                                </div>
                                <div class="col-md-1 d-flex align-items-end">
                                    <button type="submit" class="btn btn-success">เพิ่ม</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Break Times List -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">📋 รายการเวลาพักเบรค</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
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
                                            <td><?= htmlspecialchars($break['break_name']) ?></td>
                                            <td><?= date('H:i', strtotime($break['start_time'])) ?></td>
                                            <td><?= date('H:i', strtotime($break['end_time'])) ?></td>
                                            <td><?= $break['duration_minutes'] ?></td>
                                            <td>
                                                <?php if ($break['is_active']): ?>
                                                    <span class="badge bg-success">ใช้งาน</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">ไม่ใช้งาน</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('d/m/Y H:i', strtotime($break['created_at'])) ?></td>
                                            <td>
                                                <button class="btn btn-warning btn-sm" onclick="editBreak(<?= htmlspecialchars(json_encode($break)) ?>)">
                                                    แก้ไข
                                                </button>
                                                <button class="btn btn-danger btn-sm" onclick="deleteBreak(<?= $break['id'] ?>, '<?= htmlspecialchars($break['break_name']) ?>')">
                                                    ลบ
                                                </button>
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
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">แก้ไขเวลาพักเบรค</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
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
                            <input type="number" class="form-control" name="duration_minutes" id="edit_duration_minutes" required>
                        </div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                            <label class="form-check-label" for="edit_is_active">
                                ใช้งาน
                            </label>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-calculate duration when times change
        function calculateDuration() {
            const startTime = document.querySelector('input[name="start_time"]').value;
            const endTime = document.querySelector('input[name="end_time"]').value;
            
            if (startTime && endTime) {
                const start = new Date('2000-01-01 ' + startTime);
                const end = new Date('2000-01-01 ' + endTime);
                let diff = (end - start) / (1000 * 60); // minutes
                
                if (diff < 0) diff += 24 * 60; // Handle overnight breaks
                
                document.querySelector('input[name="duration_minutes"]').value = diff;
            }
        }

        // Add event listeners for auto-calculation
        document.querySelector('input[name="start_time"]').addEventListener('change', calculateDuration);
        document.querySelector('input[name="end_time"]').addEventListener('change', calculateDuration);

        // Edit break function
        function editBreak(breakData) {
            document.getElementById('edit_id').value = breakData.id;
            document.getElementById('edit_break_name').value = breakData.break_name;
            document.getElementById('edit_start_time').value = breakData.start_time;
            document.getElementById('edit_end_time').value = breakData.end_time;
            document.getElementById('edit_duration_minutes').value = breakData.duration_minutes;
            document.getElementById('edit_is_active').checked = breakData.is_active == 1;
            
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }

        // Delete break function
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
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Auto-calculate duration in edit modal
        function calculateEditDuration() {
            const startTime = document.getElementById('edit_start_time').value;
            const endTime = document.getElementById('edit_end_time').value;
            
            if (startTime && endTime) {
                const start = new Date('2000-01-01 ' + startTime);
                const end = new Date('2000-01-01 ' + endTime);
                let diff = (end - start) / (1000 * 60); // minutes
                
                if (diff < 0) diff += 24 * 60; // Handle overnight breaks
                
                document.getElementById('edit_duration_minutes').value = diff;
            }
        }

        document.getElementById('edit_start_time').addEventListener('change', calculateEditDuration);
        document.getElementById('edit_end_time').addEventListener('change', calculateEditDuration);
    </script>
</body>
</html>