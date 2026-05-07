<?php require_once __DIR__ . '/../config/app.php'; ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>สูตรการคำนวณ - CWT Dashboard</title>
    <link rel="icon" href="../img/favicon_circular.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
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
                <i class="fas fa-square-root-variable me-2" style="color:#5b8dee;"></i>
                <span class="fw-bold" style="background:linear-gradient(90deg,#5b8dee,#34c780);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">
                    KPI FORMULAS
                </span>
            </h1>

            <div class="d-flex align-items-center gap-3">
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
                    <a class="nav-link active" href="formulas.php" title="Formulas">🧮 <span class="nav-text">Formulas</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="config.php" title="Config">⚙️ <span class="nav-text">Config</span></a>
                </li>
            </ul>
            <div class="sidebar-logo-bottom nav-text">
                <img src="../img/cwt_old-3d.png" alt="CWT Logo" loading="lazy">
            </div>
        </nav>

        <main class="main-content formula-main">
            <div class="row mb-3">
                <div class="col-12">
                    <div class="card formula-overview-card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">ภาพรวมสูตรที่ใช้บน Dashboard</h5>
                        </div>
                        <div class="card-body">
                            <p class="formula-overview-text">
                                หน้านี้ใช้เป็นเอกสารอ้างอิงว่าตัวเลขบน Dashboard มาจากตารางใด คำนวณอย่างไร และใช้ threshold ใดในการกำหนดสีของ Gauge โดยค่าที่ปรับประจำปี เช่น KPI target และช่วงสี จะอ้างอิงจากหน้า Config
                            </p>
                            <div class="row g-3">
                                <div class="col-lg-3 col-md-6">
                                    <div class="formula-topic-card">
                                        <div class="formula-topic-title">Production</div>
                                        <small>สูตรยอดผลิต, เป้าหมายรายชั่วโมง, Overall KPI และ Productivity</small>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <div class="formula-topic-card">
                                        <div class="formula-topic-title">Quality</div>
                                        <small>สูตร Quality Rate, Defect Rate, Gauge และ Monthly DR</small>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <div class="formula-topic-card">
                                        <div class="formula-topic-title">Gauge Color</div>
                                        <small>เกณฑ์ Critical, Warning, Good, Excellent จาก KPI Config</small>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <div class="formula-topic-card">
                                        <div class="formula-topic-title">Data Source</div>
                                        <small>ชื่อตาราง, status ที่นับ, ช่วงวันที่ และเงื่อนไขพิเศษ</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-xl-6">
                    <div class="card formula-section-card h-100">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Production Formulas</h5>
                        </div>
                        <div class="card-body">
                            <div class="formula-block">
                                <h6>Performance KPI</h6>
                                <p>ใช้วัดความสำเร็จการผลิตเทียบกับเป้าหมาย โดยคำนวณเป็นเปอร์เซ็นต์รายชั่วโมงก่อน แล้วนำมาเฉลี่ย</p>
                                <div class="formula-code">Hourly Achievement (%) = Actual Qty ของชั่วโมง / Adjusted Hourly Target × 100</div>
                                <div class="formula-code">Line KPI (%) = ค่าเฉลี่ยของ Hourly Achievement ในไลน์นั้น</div>
                                <div class="formula-code">Overall KPI (%) = ค่าเฉลี่ยของ Line KPI ทุกไลน์ที่มีข้อมูล</div>
                                <ul class="formula-notes">
                                    <li>Actual Qty มาจากตาราง sewing_fc, sewing_fb, sewing_rc, sewing_rb, sewing_3rd, sewing_sub เฉพาะ status = 10</li>
                                    <li>Adjusted Hourly Target คือ target รายชั่วโมงที่ปรับตามนาทีทำงานจริงของชั่วโมงนั้น</li>
                                    <li>ชั่วโมงที่มีพักเบรคจะลด target ตามจำนวน working minutes ที่เหลือ</li>
                                    <li>Overall KPI บน Gauge ใช้ค่าเฉลี่ยของเปอร์เซ็นต์รายไลน์ ไม่ใช่ยอดผลิตรวม / เป้ารวมโดยตรง</li>
                                </ul>
                                <div class="formula-threshold">
                                    สีของ Performance Gauge ใช้ <code>PERF_THRESHOLD_WARNING</code>, <code>PERF_THRESHOLD_GOOD</code>, <code>PERF_THRESHOLD_EXCELLENT</code> จากหน้า Config
                                </div>
                            </div>

                            <div class="formula-block">
                                <h6>Productivity</h6>
                                <p>ใช้วัดจำนวนผลิตต่อ Man-Hour โดยตัวหารเปลี่ยนตามชนิดของฟิลเตอร์วันที่</p>
                                <div class="formula-code">Productivity = Total Output / Total Man-Hours</div>
                                <ul class="formula-notes">
                                    <li>Total Output คือยอดผลิตรวมทุกไลน์จาก Production Summary เฉพาะ status = 10</li>
                                    <li>ถ้าฟิลเตอร์เป็นวันปัจจุบัน ระบบไม่ใช้ thour แต่คำนวณเวลาทำงานจริงจากเวลาเริ่ม/จบกะใน Config และหัก break_times ที่ active</li>
                                    <li>ถ้าฟิลเตอร์หลายวันหรือไม่ใช่วันนี้ ระบบใช้สูตรเดิมจาก thour ใน sewing_man_act เพราะ thour เป็นชั่วโมงสุทธิหลังหักเบรคแล้ว</li>
                                </ul>
                                <div class="formula-code">Today Man-Hours = Σ(จำนวนพนักงานของกะ × ชั่วโมงสุทธิที่ผ่านไปจริงของกะ)</div>
                                <div class="formula-code">Multi-Day Man-Hours = Σ(จำนวนพนักงานของแถว × thour)</div>
                                <div class="formula-example">
                                    ตัวอย่าง: ผลิต 1,000 ชิ้น ใช้ 240 Man-Hours → Productivity = 1,000 / 240 = 4.17 ชิ้นต่อ Man-Hour
                                </div>
                                <div class="formula-threshold">
                                    เป้าหมาย Productivity ใช้ <code>PROD_TARGET</code> จากหน้า Config และแบ่งสีสัมพันธ์กับ Performance percentage
                                </div>
                            </div>

                            <div class="formula-block">
                                <h6>Production Summary</h6>
                                <p>ใช้สรุปยอดผลิตและข้อมูลประกอบรายไลน์ในช่วงวันที่เลือก</p>
                                <div class="formula-code">Total Qty = SUM(qty) ของแต่ละไลน์ เฉพาะ status = 10</div>
                                <div class="formula-code">Total Items = COUNT(record) ของงานผลิตที่ถูกบันทึก</div>
                                <div class="formula-code">Unique Items = COUNT(DISTINCT item)</div>
                                <ul class="formula-notes">
                                    <li>Target รายไลน์มาจาก sewing_target ตามวันที่ใช้งาน</li>
                                    <li>Daily Target รวมคิดจาก target รายชั่วโมง × นาทีทำงานจริงของวันที่มี production</li>
                                    <li>ข้อมูลที่แสดงบน card รายไลน์ใช้ key เดียวกับ line ได้แก่ F/C, F/B, R/C, R/B, 3RD, Sub</li>
                                </ul>
                            </div>

                            <div class="formula-block">
                                <h6>Data Source หลักของ Production</h6>
                                <ul class="formula-notes">
                                    <li><code>sewing_fc</code>, <code>sewing_fb</code>, <code>sewing_rc</code>, <code>sewing_rb</code>, <code>sewing_3rd</code>, <code>sewing_sub</code>: ยอดผลิตจริง</li>
                                    <li><code>sewing_target</code>: เป้าหมายรายไลน์</li>
                                    <li><code>sewing_man_act</code>: จำนวนพนักงานจริงและ thour สำหรับสูตร Productivity แบบหลายวัน</li>
                                    <li><code>break_times</code>: เวลาพักที่ใช้หักเฉพาะ Productivity กรณีฟิลเตอร์วันปัจจุบัน</li>
                                    <li><code>config/shift_times.json</code>: เวลาเริ่ม/จบกะที่ใช้คำนวณเวลาทำงานจริงของวันนี้</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card formula-section-card h-100">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Quality Formulas</h5>
                        </div>
                        <div class="card-body">
                            <div class="formula-block">
                                <h6>Quality Rate</h6>
                                <p>ใช้วัดสัดส่วนงานดีเทียบกับจำนวนผลิตทั้งหมดในช่วงวันที่เลือก</p>
                                <div class="formula-code">Quality Rate (%) = ((Production Qty - Defect Qty) / Production Qty) × 100</div>
                                <ul class="formula-notes">
                                    <li>Production Qty คือยอดผลิตรวมจากแต่ละไลน์ในหน้า Quality</li>
                                    <li>Defect Qty คือยอดของเสียจากข้อมูล defect ของไลน์เดียวกัน</li>
                                    <li>ถ้า Production Qty เท่ากับ 0 ระบบจะแสดง Quality Rate เป็น 100%</li>
                                    <li>ค่าถูกจำกัดให้อยู่ระหว่าง 0-100% เพื่อป้องกันค่าผิดปกติจากข้อมูลนำเข้า</li>
                                </ul>
                                <div class="formula-example">
                                    ตัวอย่าง: ผลิต 10,000 ชิ้น พบของเสีย 120 ชิ้น → ((10,000 - 120) / 10,000) × 100 = 98.80%
                                </div>
                                <div class="formula-threshold">
                                    สีบน Gauge ใช้ค่า <code>QR_WARN_THRESHOLD</code> และ <code>QR_GOOD_THRESHOLD</code> จากหน้า Config
                                </div>
                            </div>

                            <div class="formula-block">
                                <h6>Defect Rate</h6>
                                <p>ใช้วัดสัดส่วนของเสียเทียบกับจำนวนผลิตทั้งหมด ยิ่งต่ำยิ่งดี</p>
                                <div class="formula-code">Defect Rate (%) = (Defect Qty / Production Qty) × 100</div>
                                <ul class="formula-notes">
                                    <li>คำนวณแยกได้ทั้งรายไลน์และภาพรวมทุกไลน์</li>
                                    <li>ถ้า Production Qty เท่ากับ 0 ระบบจะแสดง Defect Rate เป็น 0%</li>
                                    <li>Gauge ภาพรวมจำกัดค่าสูงสุดที่ 2.5% เพื่อให้ช่วงสีอ่านง่าย</li>
                                    <li>สถานะ Good/Warning/Critical ใช้ threshold จากหน้า Config</li>
                                </ul>
                                <div class="formula-example">
                                    ตัวอย่าง: ผลิต 10,000 ชิ้น พบของเสีย 120 ชิ้น → (120 / 10,000) × 100 = 1.20%
                                </div>
                                <div class="formula-threshold">
                                    Good ≤ <code>DR_THRESHOLD</code>, Warning ≤ <code>DR_WARN_THRESHOLD</code>, มากกว่านั้นเป็น Critical
                                </div>
                            </div>

                            <div class="formula-block">
                                <h6>Quality KPI Gauge</h6>
                                <p>Gauge ชุดนี้แสดง 2 ค่าในพื้นที่เดียวกัน คือ Quality Rate และ Defect Rate</p>
                                <ul class="formula-notes">
                                    <li>Quality Rate ใช้ช่วง 85-100% บนหน้าปัด</li>
                                    <li>Defect Rate ใช้ช่วง 0-2.5% บนหน้าปัด</li>
                                    <li>บน desktop ทั้งสอง gauge ใช้ขนาดรัศมีเดียวกันเพื่อเทียบภาพรวมได้ง่าย</li>
                                    <li>แถบด้านข้างแสดง Quality Rate และ Defect Rate รายไลน์</li>
                                </ul>
                            </div>

                            <div class="formula-block">
                                <h6>Monthly DR</h6>
                                <p>ใช้สรุป Defect Rate รายเดือนของปีปัจจุบัน</p>
                                <div class="formula-code">Monthly DR (%) = (Defect Qty ของเดือน / Production Qty ของเดือน) × 100</div>
                                <ul class="formula-notes">
                                    <li>Production Qty รวมจากตาราง sewing_fc, sewing_fb, sewing_rc, sewing_rb, sewing_3rd, sewing_sub เฉพาะ status = 10</li>
                                    <li>Defect Qty รวมจากตาราง qc_ng ตามปีและเดือนเดียวกัน</li>
                                    <li>ถ้าเดือนใดยังไม่มี Production Qty ระบบจะแสดงค่า DR เป็นค่าว่าง</li>
                                    <li>เส้น Target ใช้ <code>DR_THRESHOLD</code> จากหน้า Config</li>
                                    <li>เส้นค่าเฉลี่ยคำนวณจากเดือนที่มีข้อมูล DR เท่านั้น</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php renderDashboardFooter('KPI Formulas'); ?>
        </main>
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

        document.getElementById('copyright-year').textContent = new Date().getFullYear();
    </script>
</body>
</html>
