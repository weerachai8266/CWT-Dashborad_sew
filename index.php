<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CWT Dashboard</title>
    <!-- <link rel="shortcut icon" href="https://cdn.dinoq.com/datafilerepo/greenpower/greenpowerlogo.ico" type="image/x-icon"> -->
    <link rel="icon" href="img/favicon_circular.ico" type="image/x-icon">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime('css/style.css') ?>">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Tracker Configuration -->
    <script>
        window.TRACKER_SITE='sewing'; // เปลี่ยนชื่อเว็บตามจริง
        window.TRACKER_URL = location.hostname === '192.168.100.10'
        ? 'http://192.168.100.10/dashboard/tracker.php'
        : 'http://192.168.0.44/dashboard/tracker.php';
    </script>
    <script src="http://192.168.0.44/dashboard/track.js" async></script>

    <!-- ปรับปรุงความเร็วของการโหลดหน้า -->
    <link rel="preload" href="script/config.js" as="script">
    <link rel="preload" href="script/gauges.js" as="script">
    <link rel="preload" href="script/charts.js" as="script">
    <link rel="preload" href="script/data.js" as="script">
    <link rel="preload" href="script/ui.js" as="script">
    <link rel="preload" href="css/style.css" as="style">

</head>
<body>
    <!-- Header Navbar -->
    <nav class="navbar navbar-expand-lg fixed-top px-3">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <!-- Hamburger สำหรับ mobile เท่านั้น -->
                <button class="btn btn-sm d-md-none me-2" id="mobileSidebarToggle"
                        style="color:var(--text-muted);border:1px solid var(--border);padding:6px 10px;"
                        title="เมนู" aria-label="Toggle menu">
                    <i class="fas fa-bars"></i>
                </button>
                <!-- end Hamburger -->
                <img src="img/cwt_old-3d.png" alt="CWT Logo" loading="lazy" class="me-3" style="height: 70px;">
                <div class="navbar-brand d-none d-md-block">
                    <h5 class="mb-0 fw-semibold text-primary">Chaiwattana</h5>
                    <small class="text-muted">Tannery Group</small>
                </div>
            </div>
            
            <h1 class="navbar-brand mb-0 mx-auto d-flex align-items-center">
                <i class="fas fa-chart-line me-2" style="color:#5b8dee;"></i>
                <span class="fw-bold" style="background:linear-gradient(90deg,#5b8dee,#34c780);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">
                    PRODUCTION DASHBOARD
                </span>
            </h1>
            
            <div class="d-flex align-items-center gap-3">
                <img src="img/cwt-3d.png" alt="CWT Logo" loading="lazy" style="height: 80px;">
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <div class="d-flex">  
        <!-- Sidebar -->
        <nav class="sidebar" id="mainSidebar">
            <!-- Sidebar toggle at top -->
            <button class="sidebar-toggle-btn" id="sidebarToggle" title="ยุบ/ขยาย เมนู" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
                <span class="nav-text ms-2">ซ่อนเมนู</span>
            </button>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link active" id="production-tab" data-bs-toggle="tab" href="#production" role="tab" title="Production">📊 <span class="nav-text">Production</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="quality-tab" data-bs-toggle="tab" href="#quality" role="tab" title="Quality">✅ <span class="nav-text">Quality</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="aireport-tab" data-bs-toggle="tab" href="#aireport" role="tab" title="AI Report">🤖 <span class="nav-text">AI Report <span class="badge bg-warning text-dark ms-1" style="font-size:0.6rem;">beta</span></span></a>
                </li>
            </ul>
            <!-- Sidebar bottom logo -->
            <div class="sidebar-logo-bottom nav-text">
                <img src="img/cwt_old-3d.png" alt="CWT Logo" loading="lazy">
            </div>
        </nav>

        <!-- Main Content -->
        <div class="main-content">
            <div class="tab-content">
                <!-- Production Tab -->
                <div class="tab-pane fade show active" id="production" role="tabpanel">
                    <!-- production Filter -->
                    <div class="d-flex flex-wrap align-items-center gap-2 py-2 px-1 position-relative" id="production-filter-form">
                        <!-- Date Range -->
                        <div class="input-group input-group-sm" style="width:auto;">
                            <label class="input-group-text">📅</label>
                            <input type="date" class="form-control form-control-sm" id="production_date_start">
                            <input type="date" class="form-control form-control-sm" id="production_date_end">
                        </div>

                        <!-- Divider -->
                        <div class="vr opacity-25 d-none d-md-block"></div>

                        <!-- Display Type -->
                        <div class="d-flex align-items-center gap-2" style="font-size:0.82rem;">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="radio" name="displayType" id="radioPercentage" value="percentage" checked>
                                <label class="form-check-label" for="radioPercentage">%</label>
                            </div>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="radio" name="displayType" id="radioPieces" value="pieces">
                                <label class="form-check-label" for="radioPieces">ชิ้น</label>
                            </div>
                        </div>

                        <!-- Divider -->
                        <div class="vr opacity-25 d-none d-md-block"></div>

                        <!-- Auto Update -->
                        <div class="form-check mb-0" style="font-size:0.82rem;">
                            <input type="checkbox" class="form-check-input" id="realTimeUpdate">
                            <label class="form-check-label" for="realTimeUpdate">Auto 30s</label>
                        </div>

                        <!-- Loading / Error — centered absolutely in the toolbar -->
                        <div id="loadingState" class="d-none align-items-center gap-1" style="font-size:0.8rem;position:absolute;left:50%;transform:translateX(-50%);">
                            <div id="loadingSpinner" class="spinner-border spinner-border-sm text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <span class="text-muted">กำลังโหลด...</span>
                        </div>
                        <div id="errorState" class="d-none align-items-center gap-1" style="font-size:0.8rem;position:absolute;left:50%;transform:translateX(-50%);">
                            <span class="badge bg-danger">⚠ <span id="errorMessage">เกิดข้อผิดพลาด</span></span>
                        </div>

                        <!-- Buttons pushed to right -->
                        <div class="ms-auto d-flex gap-2">
                            <button id="production_btnFilter" class="btn btn-primary btn-sm">ตกลง</button>
                            <button class="btn btn-success btn-sm btn-export-date" data-module="production">Export</button>
                        </div>
                    </div>  <!-- End of production Filter -->
                     
                    <!-- Summary Statistics -->
                    <div class="row mt-0">
                        <div class="col-12 mb-3">
                            <div class="card">
                                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">📋 สรุปผลรวม</h6>
                                    <span id="summaryDateLabel" class="badge bg-secondary" style="font-size:0.72rem;"></span>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="fc">
                                                <h4 class="text-success" id="totalFC">0</h4>
                                                <small id="labelFC">F/C ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentageFC"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="fb">
                                                <h4 class="text-warning" id="totalFB">0</h4>
                                                <small id="labelFB">F/B ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentageFB"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="rc">
                                                <h4 class="text-danger" id="totalRC">0</h4>
                                                <small id="labelRC">R/C ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentageRC"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="rb">
                                                <h4 style="color: #6f42c1;" id="totalRB">0</h4>
                                                <small id="labelRB">R/B ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentageRB"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="third">
                                                <h4 style="color: #fd7e14;" id="total3RD">0</h4>
                                                <small id="label3RD">3RD ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentage3RD"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="sub">
                                                <h4 style="color: #20c997;" id="totalSUB">0</h4>
                                                <small id="labelSUB">Sub ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentageSUB"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>  <!-- End of Summary Statistics -->

                    <!-- KPI Speedometer Gauge + Productivity Gauge -->
                    <div class="row mt-0 mb-3">
                        <div class="col-12">
                            <div class="card kpi-gauge-card">
                                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <h6 class="mb-0">🎯 Performance KPI Gauge — ภาพรวมประสิทธิภาพการผลิต</h6>
                                    <div class="d-flex gap-3 flex-wrap align-items-center" style="font-size:0.75rem;">
                                        <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#dc3545;margin-right:4px;"></span>Critical &lt;85%</span>
                                        <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#ffc107;margin-right:4px;"></span>Warning 85–94%</span>
                                        <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#28a745;margin-right:4px;"></span>Good 95–100%</span>
                                        <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#007bff;margin-right:4px;"></span>Excellent &gt;100%</span>
                                    </div>
                                    <!-- <div class="d-flex gap-2 align-items-center flex-wrap">
                                        <span id="kpiGaugeLabel" class="badge bg-secondary fs-6 px-3 py-1">--</span>
                                        <span id="productivityGaugeLabel" class="badge bg-secondary fs-6 px-3 py-1">⚡ --</span>
                                    </div> -->
                                    <span id="gaugeDateLabel" class="badge bg-secondary" style="font-size:0.72rem;"></span>
                                </div>
                                <div class="card-body p-0" style="background:transparent;">
                                    <canvas id="kpiGaugeCanvas" style="width:100%;display:block;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>  <!-- End of KPI Gauge -->

                    <!-- KPI Trend Charts -->
                    <div class="row mt-0 mb-3">
                        <!-- แต่ละ Line -->
                        <div class="col-lg-8 col-12 mb-3 mb-lg-0">
                            <div class="card h-100">
                                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">📉 KPI Trend — แยกไลน์</h6>
                                    <span id="kpiTrendPeriodLabel" class="badge bg-secondary" style="font-size:0.72rem;"></span>
                                </div>
                                <div class="card-body" style="padding:12px 8px; position:relative; height:260px;">
                                    <canvas id="kpiTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <!-- ค่าเฉลี่ยรวม -->
                        <div class="col-lg-4 col-12">
                            <div class="card h-100">
                                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">📊 Overall KPI Trend — ค่าเฉลี่ยรวม</h6>
                                    <span id="kpiAvgTrendPeriodLabel" class="badge bg-secondary" style="font-size:0.72rem;"></span>
                                </div>
                                <div class="card-body" style="padding:12px 8px; position:relative; height:260px;">
                                    <canvas id="kpiAvgTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>  <!-- End of KPI Trend Charts -->

                    <!-- Charts Section -->
                    <div class="row">
                        <!-- Line F/C -->
                        <div class="col-lg-6 col-md-12 mb-4">
                            <div class="card line-card fc">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0">📈 Line F/C</h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="chartFC"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Line F/B -->
                        <div class="col-lg-6 col-md-12 mb-4">
                            <div class="card line-card fb">
                                <div class="card-header bg-warning text-dark">
                                    <h6 class="mb-0">📈 Line F/B</h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="chartFB"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Line R/C -->
                        <div class="col-lg-6 col-md-12 mb-4">
                            <div class="card line-card rc">
                                <div class="card-header bg-danger text-white">
                                    <h6 class="mb-0">📈 Line R/C</h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="chartRC"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Line R/B -->
                        <div class="col-lg-6 col-md-12 mb-4">
                            <div class="card line-card rb">
                                <div class="card-header" style="background-color: #6f42c1; color: white;">
                                    <h6 class="mb-0">📈 Line R/B</h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="chartRB"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Line 3RD -->
                        <div class="col-lg-6 col-md-12 mb-4">
                            <div class="card line-card third">
                                <div class="card-header" style="background-color: #fd7e14; color: white;">
                                    <h6 class="mb-0">📈 Line 3RD</h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="chart3RD"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Line Sub -->
                        <div class="col-lg-6 col-md-12 mb-4">
                            <div class="card line-card sub">
                                <div class="card-header" style="background-color: #20c997; color: white;">
                                    <h6 class="mb-0">📈 Line Sub Assy</h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="chartSUB"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div> <!-- End of Charts Row -->

                    <!-- Model Summary Card -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow-sm" id="modelSummaryCard">
                                <div class="card-header bg-info d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 text-white">Model Summary</h6>
                                    <span class="badge bg-primary" id="modelCountBadge">Total 0 Models</span>
                                </div>
                                <div class="card-body p-3">
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full" id="modelSummaryTable">
                                            <thead class="quality-thead">
                                                <tr>
                                                    <th scope="col" class="px-6 py-2 text-left text-sm font-bold uppercase tracking-wider rounded-tl-lg">Model</th>
                                                    <th scope="col" class="px-6 py-2 text-center text-sm font-bold uppercase tracking-wider">F/C</th>
                                                    <th scope="col" class="px-6 py-2 text-center text-sm font-bold uppercase tracking-wider">F/B</th>
                                                    <th scope="col" class="px-6 py-2 text-center text-sm font-bold uppercase tracking-wider">R/C</th>
                                                    <th scope="col" class="px-6 py-2 text-center text-sm font-bold uppercase tracking-wider">R/B</th>
                                                    <th scope="col" class="px-6 py-2 text-center text-sm font-bold uppercase tracking-wider">3RD</th>
                                                    <th scope="col" class="px-6 py-2 text-center text-sm font-bold uppercase tracking-wider">Sub</th>
                                                </tr>
                                            </thead>
                                            <tbody id="modelSummaryBody">
                                            <tr class="qt-row-even">
                                                <td colspan="9" class="text-center py-3">กำลังโหลดข้อมูล...</td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>  
                    <!-- end of Model Summary Card -->
                </div>  <!-- End of Production Tab -->

                <!-- Quality Data Tab -->
                <div class="tab-pane fade" id="quality" role="tabpanel">
                    <!-- Quality Filter -->
                    <div class="d-flex flex-wrap align-items-center gap-2 py-2 px-1" id="quality-filter-form">
                        <!-- Date Range -->
                        <div class="input-group input-group-sm" style="width:auto;">
                            <label class="input-group-text">📅</label>
                            <input type="date" class="form-control form-control-sm" id="quality_date_start">
                            <input type="date" class="form-control form-control-sm" id="quality_date_end">
                        </div>

                        <!-- Buttons pushed to right -->
                        <div class="ms-auto d-flex gap-2">
                            <button id="quality_btnFilter" class="btn btn-primary btn-sm">ตกลง</button>
                            <button class="btn btn-success btn-sm btn-export-date" data-module="quality">Export</button>
                        </div>
                    </div>  <!-- End of Quality Filter -->                    

                    <!-- <hr class="my-2"> -->
                    <div class="row mt-0">
                        <div class="col-12 mb-3">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0">🔴 สรุปของเสีย</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="fc">
                                                <h4 class="text-success" id="qualityFC">0</h4>
                                                <small id="labelqualityFC">F/C ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentagequalityFC"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="fb">
                                                <h4 class="text-warning" id="qualityFB">0</h4>
                                                <small id="labelqualityFB">F/B ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentagequalityFB"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="rc">
                                                <h4 class="text-danger" id="qualityRC">0</h4>
                                                <small id="labelqualityRC">R/C ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentagequalityRC"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="rb">
                                                <h4 style="color: #6f42c1;" id="qualityRB">0</h4>
                                                <small id="labelqualityRB">R/B ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentagequalityRB"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="third">
                                                <h4 style="color: #fd7e14;" id="quality3RD">0</h4>
                                                <small id="labelquality3RD">3RD ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentagequality3RD"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="sub">
                                                <h4 style="color: #20c997;" id="qualitySUB">0</h4>
                                                <small id="labelqualitySUB">Sub ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentagequalitySUB"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>  <!-- End of Summary Statistics -->

                    <!-- Quality KPI Gauge -->
                    <div class="row mt-0 mb-3">
                        <div class="col-12">
                            <div class="card kpi-gauge-card">
                                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <h6 class="mb-0">🎯 Quality KPI Gauge — ภาพรวมคุณภาพการผลิต</h6>
                                    <div class="d-flex gap-3 flex-wrap align-items-center" style="font-size:0.75rem;">
                                        <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#28a745;margin-right:4px;"></span>&lt; 1.7% (Good)</span>
                                        <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#ffc107;margin-right:4px;"></span>1.7–2.0% (Warning)</span>
                                        <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#dc3545;margin-right:4px;"></span>&gt; 2.0% (Critical)</span>
                                        <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#fd7e14;margin-right:4px;"></span>Defect Rate</span>
                                    </div>
                                    <div class="d-flex gap-2 align-items-center flex-wrap">
                                        <span id="qualityKpiGaugeLabel" class="badge bg-secondary fs-6 px-3 py-1">--</span>
                                        <span id="qualityDefectGaugeLabel" class="badge bg-secondary fs-6 px-3 py-1">🔴 --</span>
                                    </div>
                                </div>
                                <div class="card-body p-0" style="background:transparent;">
                                    <canvas id="qualityKpiGaugeCanvas" style="width:100%;display:block;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>  <!-- End of Quality KPI Gauge -->

                    <!-- Monthly DR Trend Chart -->
                    <div class="row mt-0 mb-3">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <h6 class="mb-0">📊 Defect Rate รายเดือน — ปี <span id="monthlyDrYearLabel"></span></h6>
                                    <div class="d-flex gap-3 flex-wrap align-items-center" style="font-size:0.75rem;">
                                        <span><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#28a745;margin-right:4px;"></span>≤ <span class="dr-threshold-label">1.7</span>% (Good)</span>
                                        <span><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#ffc107;margin-right:4px;"></span>≤ 2.0% (Warning)</span>
                                        <span><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#dc3545;margin-right:4px;"></span>&gt; 2.0% (Critical)</span>
                                        <span><span style="display:inline-block;width:9px;height:2px;background:#ffc107;margin-right:4px;vertical-align:middle;"></span>Threshold</span>
                                    </div>
                                </div>
                                <div class="card-body" style="padding:12px 8px; position:relative; height:370px;">
                                    <canvas id="monthlyDrChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>  <!-- End of Monthly DR Trend Chart -->

                    <!-- Charts Container -->
                    <!-- กราฟสรุปของเสียแยกตามไลน์ -->
                    <div class="row mb-3">
                        <div class="col-6 mb-3">
                            <div class="card line-card">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0">📊 Production Line</h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="lineDefectsChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- กราฟสรุปของเสียแยกตามปัญหา -->
                        <div class="col-6 mb-3">
                            <div class="card line-card">
                                <div class="card-header bg-warning text-dark">
                                    <h6 class="mb-0">📊 Problem Type</h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="problemDefectsChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- กราฟสรุปของเสียแยกตามโมเดล -->
                        <div class="col-6 mb-3">
                            <div class="card line-card">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0">📊 Model</h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="modelDefectsChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="card line-card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0">📈 Timeline of Defects</h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="timelineDefectsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>  <!-- End of Charts Container -->

                    <!-- Quality Data Tab -->
                    <div class="row mt-3">
                        <!-- Cross Process-Detail Table -->
                        <div class="col-12">
                            <div class="card shadow-sm mb-3" id="crossProcessCard">
                                <div class="card-header bg-info d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 text-white">Cross-Process-Detail</h6>
                                    <span class="badge bg-primary" id="processDetailCount">Total 0 Details</span>
                                </div>
                                <div class="card-body p-3">
                                    <div class="overflow-x-auto">
                                        <table class="table table-hover" id="crossProcessTable">
                                            <thead class="quality-thead">
                                                <tr>
                                                    <th scope="col" class="px-6 py-2 text-left text-sm font-bold">Detail\Nickname</th>
                                                    <!-- Headers จะถูกเติมจาก JavaScript -->
                                                </tr>
                                            </thead>
                                            <tbody id="crossProcessBody">
                                                <tr>
                                                    <td colspan="9" class="text-center py-3">กำลังโหลดข้อมูล...</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Cross Model-Detail Table -->
                        <div class="col-12">
                            <div class="card shadow-sm" id="crossModelCard">
                                <div class="card-header bg-info d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 text-white">Cross-Model-Detail</h6>
                                    <span class="badge bg-primary" id="modelDetailCount">Total 0 Details</span>
                                </div>
                                <div class="card-body p-3">
                                    <div class="overflow-x-auto">
                                        <table class="table table-hover" id="crossModelTable">
                                            <thead class="quality-thead">
                                                <tr>
                                                    <th scope="col" class="px-6 py-2 text-left text-sm font-bold">Detail\Model</th>
                                                    <!-- Headers จะถูกเติมจาก JavaScript -->
                                                </tr>
                                            </thead>
                                            <tbody id="crossModelBody">
                                                <tr>
                                                    <td colspan="9" class="text-center py-3">กำลังโหลดข้อมูล...</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- End of Cross Model-Detail Table -->

                </div>  <!-- End of quality Data Tab --> 

                <!-- AI Report [beta] Tab -->
                <div class="tab-pane fade" id="aireport" role="tabpanel">

                    <!-- Filter Bar -->
                    <div class="d-flex flex-wrap align-items-center gap-2 py-2 px-1" id="aireport-filter-form">
                        <div class="input-group input-group-sm" style="width:auto;">
                            <label class="input-group-text">📅</label>
                            <input type="date" class="form-control form-control-sm" id="ai_date_start">
                            <input type="date" class="form-control form-control-sm" id="ai_date_end">
                        </div>

                        <div class="vr opacity-25 d-none d-md-block"></div>

                        <div class="d-flex align-items-center gap-2" style="font-size:0.82rem;">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="radio" name="aiReportType" id="aiTypeCombined" value="combined" checked>
                                <label class="form-check-label" for="aiTypeCombined">รายงานรวม</label>
                            </div>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="radio" name="aiReportType" id="aiTypeProduction" value="production">
                                <label class="form-check-label" for="aiTypeProduction">การผลิต</label>
                            </div>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="radio" name="aiReportType" id="aiTypeQuality" value="quality">
                                <label class="form-check-label" for="aiTypeQuality">คุณภาพ</label>
                            </div>
                        </div>

                        <span id="aiStatusBadge" class="badge bg-secondary ms-1" style="font-size:0.72rem;">⏳ ตรวจสอบ AI...</span>

                        <div class="ms-auto d-flex gap-2">
                            <button id="ai_btnGenerate" class="btn btn-primary btn-sm" disabled>
                                <i class="fas fa-robot me-1"></i>สร้างรายงาน
                            </button>
                        </div>
                    </div><!-- End Filter Bar -->

                    <!-- Report Output + Data Summary (2-column layout) -->
                    <div class="row mt-3 mb-3 align-items-start">

                        <!-- LEFT: AI Report -->
                        <div class="col mb-3">
                            <div class="card h-100" id="aiReportCard">
                                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"
                                     style="background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);color:#e0e0e0;">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-robot" style="color:#7c83fd;"></i>
                                        <h6 class="mb-0">AI Report — <span id="aiReportTypeLabel">รายงานรวม</span></h6>
                                        <span id="aiPeriodLabel" class="badge bg-secondary" style="font-size:0.7rem;"></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <!-- <span id="aiModelLabel" class="text-muted" style="font-size:0.72rem;"></span> -->
                                        <span id="aiTimingLabel" class="text-muted" style="font-size:0.72rem;"></span>
                                        <button id="ai_btnCopy" class="btn btn-outline-secondary btn-sm d-none" title="คัดลอกรายงาน">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body" id="aiReportBody" style="overflow-y:auto;">
                                    <div id="aiReportPlaceholder" class="text-center py-5 text-muted">
                                        <i class="fas fa-robot fa-3x mb-3" style="opacity:0.3;"></i>
                                        <p>เลือกช่วงเวลาและประเภทรายงาน แล้วกด <strong>สร้างรายงาน</strong></p>
                                    </div>
                                    <div id="aiReportLoading" class="d-none text-center py-5">
                                        <div class="spinner-border text-primary mb-3" role="status"></div>
                                        <p class="text-muted">AI กำลังวิเคราะห์ข้อมูล... (ใช้เวลาประมาณ 60-120 วินาที)</p>
                                        <div class="progress" style="height:4px;max-width:400px;margin:0 auto;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:100%"></div>
                                        </div>
                                    </div>
                                    <div id="aiReportContent" class="d-none ai-report-content"></div>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT: Production + Quality stacked -->
                        <div class="col" id="aiDataSummaryRow">
                            <div class="card mb-3">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0">📊 ข้อมูลการผลิต (ช่วงที่เลือก)</h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="row text-center g-2" id="aiProdCards">
                                        <div class="col-12 text-muted py-2" id="aiProdPlaceholder">ยังไม่มีข้อมูล</div>
                                    </div>
                                </div>
                            </div>
                            <div class="card mb-3">
                                <div class="card-header bg-danger text-white">
                                    <h6 class="mb-0">🔴 ข้อมูลคุณภาพ (ช่วงที่เลือก)</h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="row text-center g-2" id="aiQualCards">
                                        <div class="col-12 text-muted py-2" id="aiQualPlaceholder">ยังไม่มีข้อมูล</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div><!-- End Report + Data Summary -->

                </div><!-- End of AI Report Tab -->

            </div>  <!-- End of Tab Content -->

            <!-- Footer -->
            <footer class="dashboard-footer">
                <div class="footer-inner">
                    <div class="footer-left">
                        <!-- <img src="img/logo.png" alt="CWT Logo" style="height:28px;opacity:0.7;"> -->
                        <span class="footer-brand">Chaiwattana Tannery Group</span>
                        <span>Leather Seats and Auto Parts</span>
                    </div>
                    <div class="footer-center">
                        <span>Production Dashboard &copy; 2025&ndash;<span id="copyright-year"></span></span>
                    </div>
                    <div class="footer-right">
                        <span class="footer-version">v2.2 [beta]</span>
                        <span class="footer-divider">|</span>
                        <span>Powered by weerachai</span>
                    </div>
                </div>
            </footer>
        </div> <!-- End of Main Content -->

    </div>  <!-- End of Container Fluid -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script/config.js?v=<?= filemtime('script/config.js') ?>"></script>
    <script src="script/gauges.js?v=<?= filemtime('script/gauges.js') ?>"></script>
    <script src="script/charts.js?v=<?= filemtime('script/charts.js') ?>"></script>
    <script src="script/data.js?v=<?= filemtime('script/data.js') ?>"></script>
    <script src="script/ui.js?v=<?= filemtime('script/ui.js') ?>"></script>
    <script src="script/ai_report.js?v=<?= filemtime('script/ai_report.js') ?>"></script>
    <script>document.getElementById('copyright-year').textContent = new Date().getFullYear();</script>
    <script>
        // Mobile hamburger button เชื่อมกับ sidebarToggle
        const mobileBtn = document.getElementById('mobileSidebarToggle');
        if (mobileBtn) {
            mobileBtn.addEventListener('click', () => {
                document.getElementById('sidebarToggle').click();
            });
        }
    </script>
</body>
</html>
