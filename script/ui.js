// ==================== Sidebar Collapse Toggle ====================
(function () {
    const sidebar     = document.getElementById('mainSidebar');
    const mainContent = document.querySelector('.main-content');
    const toggleBtn   = document.getElementById('sidebarToggle');
    const footer      = document.querySelector('.dashboard-footer');
    if (!sidebar || !mainContent || !toggleBtn) return;

    const backdrop = document.createElement('div');
    backdrop.className = 'sidebar-backdrop';
    document.body.appendChild(backdrop);

    const isMobile = () => window.innerWidth < 768;

    function redrawGauge() {
        setTimeout(() => {
            if (window._gaugeLastData) {
                drawKPIGauge(window._gaugeLastData.overall, window._gaugeLastData.lines);
            }
        }, 260);
    }

    function apply(collapsed) {
        if (isMobile()) {
            const open = !collapsed;
            sidebar.classList.toggle('mobile-open', open);
            backdrop.classList.toggle('active', open);
            mainContent.style.marginLeft = '';
            if (footer) footer.style.left = '';
        } else {
            sidebar.classList.remove('mobile-open');
            backdrop.classList.remove('active');
            sidebar.classList.toggle('collapsed', collapsed);
            mainContent.classList.toggle('sidebar-collapsed', collapsed);
            if (footer) footer.style.left = collapsed ? '60px' : '220px';
        }
    }

    apply(localStorage.getItem('sidebarCollapsed') === '1');

    toggleBtn.addEventListener('click', () => {
        if (isMobile()) {
            const isOpen = sidebar.classList.contains('mobile-open');
            apply(isOpen);
        } else {
            const collapsed = !sidebar.classList.contains('collapsed');
            apply(collapsed);
            localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
        }
        redrawGauge();
    });

    backdrop.addEventListener('click', () => apply(true));

    window.addEventListener('resize', () => {
        apply(isMobile() ? true : localStorage.getItem('sidebarCollapsed') === '1');
    });
})();

// UI Helper Functions
function showLoading(show = true) {
    const loadingState = document.getElementById('loadingState');
    const spinner = document.getElementById('loadingSpinner');

    if (show) {
        loadingState.classList.remove('d-none');
        spinner.classList.remove('d-none');
    } else {
        loadingState.classList.add('d-none');
        spinner.classList.add('d-none');
    }
}

function showError(message) {
    const errorState = document.getElementById('errorState');
    const errorMessage = document.getElementById('errorMessage');

    errorMessage.textContent = message;
    errorState.classList.remove('d-none');
}

function hideError() {
    const errorState = document.getElementById('errorState');
    errorState.classList.add('d-none');
}

// Export Date Dialog
function openExportDateDialog(options = {}) {
    const {
        title = 'Export',
        singleDay = false,
        subtitle = '',
        onConfirm = () => {},
        defaultStart = new Date().toISOString().split('T')[0],
        defaultEnd = defaultStart
    } = options;

    Swal.fire({
        title,
        html: `
          ${subtitle ? `<div class="mb-2 small text-muted">${subtitle}</div>` : ''}
          <div class="text-start">
            <label class="form-label mb-1">วันที่เริ่ม</label>
            <input type="date" id="exp_start" class="form-control mb-2" value="${defaultStart}">
            ${singleDay ? '' : `
            <label class="form-label mb-1 mt-1">วันที่สิ้นสุด</label>
            <input type="date" id="exp_end" class="form-control" value="${defaultEnd}">
            <div class="form-text mt-1">ถ้าเลือกวันเดียว ให้ใส่เหมือนกันทั้งสองช่อง</div>`}
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Export',
        cancelButtonText: 'ยกเลิก',
        focusConfirm: false,
        preConfirm: () => {
            const start = document.getElementById('exp_start').value;
            const end = singleDay ? start : document.getElementById('exp_end').value;
            if (!start || !end) {
                Swal.showValidationMessage('กรุณาเลือกวันที่');
                return false;
            }
            if (end < start) {
                Swal.showValidationMessage('วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่ม');
                return false;
            }
            return { start, end };
        }
    }).then(res => {
        if (!res.isConfirmed) return;
        onConfirm(res.value);
    });
}

// Initialize export buttons
function initExportButtons() {
    document.querySelectorAll('.btn-export-date').forEach(btn => {
        btn.addEventListener('click', () => {
            const module = btn.dataset.module || 'export';
            const single = (btn.dataset.single === '1');
            openExportDateDialog({
                title: `Export ${module}`,
                singleDay: single,
                onConfirm: ({ start, end }) => {
                    Swal.fire({
                        title: 'กำลังสร้างไฟล์...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                            let url;
                            switch (module) {
                                case 'production':
                                    url = `api/export_product.php?start_date=${start}&end_date=${end}`;
                                    break;
                                case 'quality':
                                    url = `api/export_quality.php?start_date=${start}&end_date=${end}`;
                                    break;
                                default:
                                    url = `api/export_${module}.php?start_date=${start}&end_date=${end}`;
                            }
                            window.location = url;
                            setTimeout(() => Swal.close(), 1500);
                        }
                    });
                }
            });
        });
    });
}

// Event Listeners
document.getElementById('production_btnFilter').addEventListener('click', async function() {
    await loadProductData();
});

document.getElementById('quality_btnFilter').addEventListener('click', async function() {
    await loadQualityData();
});

document.getElementById('realTimeUpdate').addEventListener('change', function() {
    startRealTimeUpdate();
});

// Toggle display type
function toggleDisplayType() {
    const selectedRadio = document.querySelector('input[name="displayType"]:checked');
    currentDisplayType = selectedRadio ? selectedRadio.value : 'percentage';

    Object.keys(CHART_COLORS).forEach(line => {
        const canvasId = line === 'third' ? 'chart3RD' : `chart${line.toUpperCase()}`;
        const chart = charts[canvasId];

        if (chart) {
            if (currentDisplayType === 'percentage') {
                chart.options.scales.y.ticks.callback = function(value) {
                    return value + '%';
                };
            } else {
                delete chart.options.scales.y.max;
                chart.options.scales.y.ticks.callback = function(value) {
                    return value;
                };
            }
        }
    });

    addChartTooltips();
    loadProductData();
}

document.querySelectorAll('input[name="displayType"]').forEach(radio => {
    radio.addEventListener('change', function() {
        currentDisplayType = this.value;
        toggleDisplayType();
    });
});

// Add tooltip functionality for charts
function addChartTooltips() {
    Object.keys(charts).forEach(canvasId => {
        const chart = charts[canvasId];
        if (chart) {
            chart.options.plugins.tooltip = {
                callbacks: {
                    label: function(context) {
                        const label = context.dataset.label || '';
                        const value = context.parsed.y;

                        if (currentDisplayType === 'percentage') {
                            return `${label}: ${value}%`;
                        } else {
                            return `${label}: ${value} ชิ้น`;
                        }
                    },
                    afterLabel: function(context) {
                        if (currentDisplayType === 'percentage') {
                            const percentage = context.parsed.y;
                            let status = '';
                            if (percentage >= PERF_THRESHOLD_EXCELLENT) status = '🔵 เกินเป้าหมาย';
                            else if (percentage >= PERF_THRESHOLD_GOOD)      status = '🟢 ตามเป้าหมาย';
                            else if (percentage >= PERF_THRESHOLD_WARNING)   status = '🟡 ใกล้เป้าหมาย';
                            else status = '🔴 ต่ำกว่าเป้าหมาย';

                            return status;
                        }
                        return '';
                    }
                }
            };
        }
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', async function() {
    const today = new Date().toISOString().split('T')[0];
    const _n = new Date();
    const firstOfMonth = `${_n.getFullYear()}-${String(_n.getMonth() + 1).padStart(2, '0')}-01`;

    document.getElementById('production_date_start').value = today;
    document.getElementById('production_date_end').value = today;

    document.getElementById('quality_date_start').value = firstOfMonth;
    document.getElementById('quality_date_end').value = today;

    initExportButtons();
    initializeCharts();
    addChartTooltips();

    window._productivityLastRate = 0;
    await loadProductData();

    // โหลด Quality ใน background หลัง Production เสร็จ
    loadQualityData();

    startRealTimeUpdate();
    toggleDisplayType();

    // ใช้ event delegation บน document เพื่อรับ tab events ได้เสมอ
    // quality-tab: reload เมื่อ user คลิก tab (หรือกด ตกลง)
    document.addEventListener('shown.bs.tab', function(e) {
        if (e.target.id === 'quality-tab')    loadQualityData();
        if (e.target.id === 'production-tab') loadProductData();
    });
});
