// ── Sidebar Collapse Toggle ────────────────────────────────────────────────
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
            apply(sidebar.classList.contains('mobile-open'));
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

// ── Generic Loading/Error State Helpers ───────────────────────────────────
function setLoadingVisible(stateId, spinnerId, show) {
    const stateEl = document.getElementById(stateId);
    if (!stateEl) return;
    stateEl.classList.toggle('d-none', !show);
    stateEl.classList.toggle('d-flex', show);
    const spinnerEl = document.getElementById(spinnerId);
    if (spinnerEl) spinnerEl.classList.toggle('d-none', !show);
}

function setErrorVisible(stateId, msgId, show, message = '') {
    const stateEl = document.getElementById(stateId);
    if (!stateEl) return;
    stateEl.classList.toggle('d-none', !show);
    stateEl.classList.toggle('d-flex', show);
    if (show && msgId) {
        const msgEl = document.getElementById(msgId);
        if (msgEl) msgEl.textContent = message;
    }
}

// Production tab loading/error
const showLoading      = (show = true)          => setLoadingVisible('loadingState', 'loadingSpinner', show);
const hideError        = ()                      => setErrorVisible('errorState', null, false);
const showError        = (msg)                   => setErrorVisible('errorState', 'errorMessage', true, msg);

// Quality tab loading/error
const showQualityLoading = (show = true)         => setLoadingVisible('qualityLoadingState', 'qualityLoadingSpinner', show);
const hideQualityError   = ()                    => setErrorVisible('qualityErrorState', null, false);
const showQualityError   = (msg)                 => setErrorVisible('qualityErrorState', 'qualityErrorMessage', true, msg);

// ── Export Date Dialog ────────────────────────────────────────────────────
function openExportDateDialog(options = {}) {
    const {
        title       = 'Export',
        singleDay   = false,
        subtitle    = '',
        onConfirm   = () => {},
        defaultStart = new Date().toISOString().split('T')[0],
        defaultEnd   = defaultStart,
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
            const end   = singleDay ? start : document.getElementById('exp_end').value;
            if (!start || !end) {
                Swal.showValidationMessage('กรุณาเลือกวันที่');
                return false;
            }
            if (end < start) {
                Swal.showValidationMessage('วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่ม');
                return false;
            }
            return { start, end };
        },
    }).then(res => {
        if (!res.isConfirmed) return;
        onConfirm(res.value);
    });
}

function initExportButtons() {
    document.querySelectorAll('.btn-export-date').forEach(btn => {
        btn.addEventListener('click', () => {
            const module = btn.dataset.module || 'export';
            const single = btn.dataset.single === '1';
            openExportDateDialog({
                title: `Export ${module}`,
                singleDay: single,
                onConfirm: ({ start, end }) => {
                    Swal.fire({
                        title: 'กำลังสร้างไฟล์...',
                        allowOutsideClick: false,
                        allowEscapeKey:    false,
                        didOpen: () => {
                            Swal.showLoading();
                            const urlMap = {
                                production: `api/export_product.php?start_date=${start}&end_date=${end}`,
                                quality:    `api/export_quality.php?start_date=${start}&end_date=${end}`,
                            };
                            window.location = urlMap[module] ?? `api/export_${module}.php?start_date=${start}&end_date=${end}`;
                            setTimeout(() => Swal.close(), 1500);
                        },
                    });
                },
            });
        });
    });
}

// ── Event Listeners ───────────────────────────────────────────────────────
document.getElementById('production_btnFilter').addEventListener('click', () => loadProductData());
document.getElementById('quality_btnFilter').addEventListener('click',    () => loadQualityData());
document.getElementById('realTimeUpdate').addEventListener('change',      () => startRealTimeUpdate());

// Display type toggle
function toggleDisplayType() {
    const radio = document.querySelector('input[name="displayType"]:checked');
    currentDisplayType = radio ? radio.value : 'percentage';

    Object.keys(CHART_COLORS).forEach(line => {
        const canvasId = line === 'third' ? 'chart3RD' : `chart${line.toUpperCase()}`;
        const chart = charts[canvasId];
        if (!chart) return;
        if (currentDisplayType === 'percentage') {
            chart.options.scales.y.ticks.callback = v => v + '%';
        } else {
            delete chart.options.scales.y.max;
            chart.options.scales.y.ticks.callback = v => v;
        }
    });

    addChartTooltips();
    loadProductData();
}

document.querySelectorAll('input[name="displayType"]').forEach(radio => {
    radio.addEventListener('change', function () {
        currentDisplayType = this.value;
        toggleDisplayType();
    });
});

// Chart tooltips
function addChartTooltips() {
    Object.keys(charts).forEach(canvasId => {
        const chart = charts[canvasId];
        if (!chart) return;
        chart.options.plugins.tooltip = {
            callbacks: {
                label(context) {
                    const label = context.dataset.label || '';
                    const value = context.parsed.y;
                    return currentDisplayType === 'percentage'
                        ? `${label}: ${value}%`
                        : `${label}: ${value} ชิ้น`;
                },
                afterLabel(context) {
                    if (currentDisplayType !== 'percentage') return '';
                    const pct = context.parsed.y;
                    if (pct >= PERF_THRESHOLD_EXCELLENT) return '🔵 เกินเป้าหมาย';
                    if (pct >= PERF_THRESHOLD_GOOD)      return '🟢 ตามเป้าหมาย';
                    if (pct >= PERF_THRESHOLD_WARNING)   return '🟡 ใกล้เป้าหมาย';
                    return '🔴 ต่ำกว่าเป้าหมาย';
                },
            },
        };
    });
}

// ── Dashboard Initialisation ──────────────────────────────────────────────
function initDashboard() {
    if (window._dashboardInitialized) return;
    window._dashboardInitialized = true;

    const today        = new Date().toISOString().split('T')[0];
    const now          = new Date();
    const firstOfMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;

    document.getElementById('production_date_start').value = today;
    document.getElementById('production_date_end').value   = today;
    document.getElementById('quality_date_start').value    = firstOfMonth;
    document.getElementById('quality_date_end').value      = today;

    const radio = document.querySelector('input[name="displayType"]:checked');
    currentDisplayType = radio ? radio.value : 'percentage';

    initExportButtons();
    initializeCharts();
    addChartTooltips();
    window._productivityLastRate = 0;

    document.addEventListener('shown.bs.tab', function (e) {
        if (e.target.id === 'quality-tab') {
            showLoading(false);
            hideError();
            loadQualityData();
        }
        if (e.target.id === 'production-tab') {
            showQualityLoading(false);
            hideQualityError();
            loadProductData();
        }
    });

    const activePane = document.querySelector('.tab-pane.active');
    if (activePane?.id === 'quality') {
        setTimeout(() => loadQualityData(), 0);
    } else {
        setTimeout(() => loadProductData(), 0);
    }

    startRealTimeUpdate();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboard);
} else {
    initDashboard();
}
