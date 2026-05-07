// ===== Chart.js Dark Theme Defaults =====
document.addEventListener('DOMContentLoaded', function() {
    Chart.defaults.color = '#8896a8';
    Chart.defaults.borderColor = 'rgba(255,255,255,0.07)';
    Chart.defaults.plugins.legend.labels.color = '#8896a8';
});

// Global variables
let charts = {};
let currentData = {};
let updateInterval;
let currentDisplayType = 'pieces'; // 'pieces' or 'percentage'

// API Configuration
const API_BASE = 'api/get_production_data.php';

// Chart colors configuration
const CHART_COLORS = {
    fc: '#28a745',
    fb: '#ffc107',
    rc: '#dc3545',
    rb: '#6f42c1',
    third: '#fd7e14',
    sub: '#20c997'
};

// Line names in Thai
const LINE_NAMES = {
    fc: 'F/C',
    fb: 'F/B',
    rc: 'R/C',
    rb: 'R/B',
    third: '3RD',
    sub: 'Sub'
};

const KPI_CONFIG = window.KPI_CONFIG || {};

// Defect Rate threshold — ปรับได้ทุกปี (หน่วย: %)
const DR_THRESHOLD      = Number(KPI_CONFIG.dr_threshold ?? 1.7);   // DR ≤ ค่านี้ = Good
const DR_WARN_THRESHOLD = Number(KPI_CONFIG.dr_warn_threshold ?? 2.0);   // DR ≤ ค่านี้ = Warning (> ค่านี้ = Critical)

// Quality Rate threshold — สัมพันธ์กับ DR (zone ratio ≈ DR red:yellow = 62.5%:37.5%)
const QR_GOOD_THRESHOLD = Number(KPI_CONFIG.qr_good_threshold ?? 98);   // QR ≥ ค่านี้ = Good
const QR_WARN_THRESHOLD = Number(KPI_CONFIG.qr_warn_threshold ?? 96);   // QR ≥ ค่านี้ = Warning

// Performance percentage thresholds
const PERF_THRESHOLD_EXCELLENT = Number(KPI_CONFIG.perf_threshold_excellent ?? 101);  // % ขึ้นไป = เกินเป้า (น้ำเงิน)
const PERF_THRESHOLD_GOOD      = Number(KPI_CONFIG.perf_threshold_good ?? 95);   // % ขึ้นไป = ดี (เขียว)
const PERF_THRESHOLD_WARNING   = Number(KPI_CONFIG.perf_threshold_warning ?? 85);   // % ขึ้นไป = เฝ้าระวัง (เหลือง)
                                        // ต่ำกว่านี้   = วิกฤต (แดง)

// Productivity thresholds (หน่วย: ชิ้นต่อ Man-Hour)
const PROD_GAUGE_MAX           = Number(KPI_CONFIG.prod_gauge_max ?? 5.0);  // ค่าสูงสุดบนเกจ
const PROD_TARGET              = Number(KPI_CONFIG.prod_target ?? 4.0);  // เป้าหมาย Productivity = 100%
const PROD_THRESHOLD_EXCELLENT = PROD_TARGET * (PERF_THRESHOLD_EXCELLENT / 100);
const PROD_THRESHOLD_GOOD      = PROD_TARGET * (PERF_THRESHOLD_GOOD / 100);
const PROD_THRESHOLD_WARNING   = PROD_TARGET * (PERF_THRESHOLD_WARNING / 100);
                                        // ต่ำกว่า warning = Critical

// Color coding for percentage
const PERCENTAGE_COLORS = {
    critical: '#dc3545',    // 0-84%
    warning: '#ffc107',     // 85-94%
    good: '#28a745',        // 95-100%
    excellent: '#007bff'    // 101%+
};

function getColorByPercentage(percentage) {
    if (percentage >= PERF_THRESHOLD_EXCELLENT) return PERCENTAGE_COLORS.excellent;
    if (percentage >= PERF_THRESHOLD_GOOD)      return PERCENTAGE_COLORS.good;
    if (percentage >= PERF_THRESHOLD_WARNING)   return PERCENTAGE_COLORS.warning;
    return PERCENTAGE_COLORS.critical;
}

function getPercentageClass(percentage) {
    if (percentage >= PERF_THRESHOLD_EXCELLENT) return 'percentage-excellent';
    if (percentage >= PERF_THRESHOLD_GOOD)      return 'percentage-good';
    if (percentage >= PERF_THRESHOLD_WARNING)   return 'percentage-warning';
    return 'percentage-critical';
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.perf-warning-label').forEach(el => el.textContent = PERF_THRESHOLD_WARNING);
    document.querySelectorAll('.perf-good-label').forEach(el => el.textContent = PERF_THRESHOLD_GOOD);
    document.querySelectorAll('.perf-excellent-label').forEach(el => el.textContent = PERF_THRESHOLD_EXCELLENT);
    document.querySelectorAll('.perf-good-prev-label').forEach(el => el.textContent = PERF_THRESHOLD_GOOD - 1);
    document.querySelectorAll('.perf-excellent-prev-label').forEach(el => el.textContent = PERF_THRESHOLD_EXCELLENT - 1);
    document.querySelectorAll('.dr-threshold-label').forEach(el => el.textContent = DR_THRESHOLD);
    document.querySelectorAll('.dr-warn-threshold-label').forEach(el => el.textContent = DR_WARN_THRESHOLD);
});
