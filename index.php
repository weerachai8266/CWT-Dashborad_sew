<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CWT Production</title>
    <!-- <link rel="shortcut icon" href="https://cdn.dinoq.com/datafilerepo/greenpower/greenpowerlogo.ico" type="image/x-icon"> -->
    <link rel="icon" href="img/favicon_circular.ico" type="image/x-icon">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Chart.js CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

</head>
<body>
    <!-- Header Navbar -->
    <nav class="navbar navbar-light bg-light border-bottom fixed-top px-3">
        <div class="container-fluid">
            <div class="d-flex align-items-center gap-3">
                <img src="img/logo-chaiwattana.png" alt="CWT Logo" style="height: 40px;">
            </div>
            <!-- <div class="d-flex align-items-center gap-3">
                <img src="img/seat.png" alt="CWT Logo" style="height: 40px;">
            </div> -->
            <span class="navbar-brand mb-0 h1 fw-semibold mx-auto">CWT Production</span>
            <div class="d-flex align-items-center gap-3">
                <img src="img/cwt.png" alt="CWT Logo" style="height: 40px;">
            </div>
        </div>
    </nav>
    <!-- Main Container -->
    <div class="d-flex">  
        <!-- Sidebar -->
        <nav class="sidebar">
            <ul class="nav flex-column pt-3">
                <li class="nav-item">
                    <a class="nav-link active" id="board-tab" data-bs-toggle="tab" href="#board" role="tab">📊 Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="target-tab" data-bs-toggle="tab" href="#target" role="tab">🎯 Target</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="report-tab" data-bs-toggle="tab" href="#report_data" role="tab">📑 Report</a>
                </li>
            </ul>
        </nav>


        <!-- Main Content -->
        <div class="main-content">
            <div class="tab-content">
                <!-- Dashboard Tab -->
                <div class="tab-pane fade show active" id="board" role="tabpanel">
                    <!-- Report Filter -->
                    <div class="row " id="report-filter-form">
                        <div class="col-md-3">
                            <div class="input-group">
                                <label class="input-group-text">วันที่</label>
                                <input class="form-control" type="date" id="report_date_start" value="<?= date('Y-m-d') ?>">
                                <input class="form-control" type="date" id="report_date_end" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">                            
                            <div class="col-auto d-flex align-items-center gap-3">

                                <!-- auto update -->
                                <div class="form-check mt-1">
                                    <input type="checkbox" class="form-check-input" id="realTimeUpdate">
                                    <label class="form-check-label" for="realTimeUpdate">อัปเดตอัตโนมัติทุก 30 วิ</label>
                                </div>

                                <!-- Display Type -->
                                <div class="col-auto">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="displayType" id="radioPercentage" value="percentage" checked>
                                        <label class="form-check-label" for="radioPercentage">
                                            แสดงผลแบบเปอร์เซ็น
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="col-auto">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="displayType" id="radioPieces" value="pieces">
                                        <label class="form-check-label" for="radioPieces">
                                            แสดงผลแบบชิ้น
                                        </label>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- button -->
                        <div class="col-md-3 d-flex justify-content-end gap-3 align-items-start">
                            <!-- <a href="break_management.php" class="btn btn-secondary btn-sm">⚙️ จัดการเบรค</a> -->
                            <button id="btnFilter" class="btn btn-primary">ตกลง</button>
                            <a id="btnExport" href="#" class="btn btn-success">Excel</a>
                        </div>
                        
                    </div>  <!-- End of Report Filter -->

                    <hr class="my-4">

                    <!-- Loading State -->
                    <div id="loadingState" class="d-none text-center mb-3">
                        <div id="loadingSpinner" class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div class="text-muted mt-2">กำลังโหลดข้อมูล...</div>
                    </div>

                    <!-- Error Message -->
                    <div id="errorState" class="alert alert-danger d-none" role="alert">
                        <span id="errorMessage">เกิดข้อผิดพลาด</span>
                    </div>

                    <!-- Loading Spinner -->
                    <div id="loadingState" class="d-none text-center my-3">
                        <div id="loadingSpinner" class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div class="text-muted mt-2">กำลังโหลดข้อมูล...</div>
                    </div> 
                    <!-- End fo Loading Spinner -->

                    <!-- Summary Statistics -->
                    <!-- เดี๋ยวกลับมาแก้ฟังก์ชั่นนี้ -->
                    <div class="row mt-4">
                        <div class="col-12 mb-4">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0">📋 สรุปผลรวม</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="fc">
                                                <h5 class="text-success" id="totalFC">0</h5>
                                                <small id="labelFC">F/C ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentageFC"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="fb">
                                                <h5 class="text-warning" id="totalFB">0</h5>
                                                <small id="labelFB">F/B ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentageFB"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="rc">
                                                <h5 class="text-danger" id="totalRC">0</h5>
                                                <small id="labelRC">R/C ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentageRC"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="rb">
                                                <h5 style="color: #6f42c1;" id="totalRB">0</h5>
                                                <small id="labelRB">R/B ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentageRB"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="third">
                                                <h5 style="color: #fd7e14;" id="total3RD">0</h5>
                                                <small id="label3RD">3RD ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentage3RD"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="border rounded p-3 position-relative summary-card" data-line="sub">
                                                <h5 style="color: #20c997;" id="totalSUB">0</h5>
                                                <small id="labelSUB">Sub ชิ้น</small>
                                                <span class="percentage-badge d-none" id="percentageSUB"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>  <!-- End of Summary Statistics -->
                            
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
                </div>  <!-- End of Dashboard Tab -->

                <!-- Report Data Tab -->
                <div class="tab-pane fade" id="target" role="tabpanel">

                    <div class="row">
                        <h3>Report</h3>
                        <p>ข้อมูลรายงาน...</p>
                    </div>

                    <hr class="my-4">      

                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0">📊 รายงานการผลิต</h6>
                                </div>
                            </div>
                        </div>                        
                    </div>
                </div> <!-- End of Report Data Tab -->

                <!-- Report Data Tab -->
                <div class="tab-pane fade" id="report_data" role="tabpanel">
                    <h3>Report</h3>
                    <p>ข้อมูลรายงาน...</p>
                </div>  <!-- End of Report Data Tab -->

            </div>  <!-- End of Tab Content -->
        </div> <!-- End of Main Content -->

    </div>  <!-- End of Container Fluid -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script/script.js"></script>
    
</body>
</html>