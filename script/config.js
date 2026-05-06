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

// Defect Rate threshold — ปรับได้ทุกปี (หน่วย: %)
const DR_THRESHOLD      = 1.7;   // DR ≤ ค่านี้ = Good
const DR_WARN_THRESHOLD = 2.0;   // DR ≤ ค่านี้ = Warning (> ค่านี้ = Critical)

// Quality Rate threshold — สัมพันธ์กับ DR (zone ratio ≈ DR red:yellow = 62.5%:37.5%)
const QR_GOOD_THRESHOLD = 98;   // QR ≥ ค่านี้ = Good   (green zone ~18° wide)
const QR_WARN_THRESHOLD = 96;   // QR ≥ ค่านี้ = Warning (yellow zone ~12° wide, 95 อยู่ที่ 30° จากขวา)

// Performance percentage thresholds
const PERF_THRESHOLD_EXCELLENT = 101;  // % ขึ้นไป = เกินเป้า (น้ำเงิน)
const PERF_THRESHOLD_GOOD      = 95;   // % ขึ้นไป = ดี (เขียว)
const PERF_THRESHOLD_WARNING   = 85;   // % ขึ้นไป = เฝ้าระวัง (เหลือง)
                                        // ต่ำกว่านี้   = วิกฤต (แดง)

// Color coding for percentage
const PERCENTAGE_COLORS = {
    critical: '#dc3545',    // 0-84%
    warning: '#ffc107',     // 85-94%
    good: '#28a745',        // 95-100%
    excellent: '#007bff'    // 101%+
};

function getStatusEmoji(percentage) {
    if (percentage >= PERF_THRESHOLD_EXCELLENT) return '🔵';
    if (percentage >= PERF_THRESHOLD_GOOD)      return '🟢';
    if (percentage >= PERF_THRESHOLD_WARNING)   return '🟡';
    return '🔴';
}

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
