// ===== Chart.js Dark Theme Defaults =====
document.addEventListener('DOMContentLoaded', function() {
    Chart.defaults.color = '#8896a8';           // axis tick / legend text
    Chart.defaults.borderColor = 'rgba(255,255,255,0.07)'; // grid lines
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

// Color coding for percentage
const PERCENTAGE_COLORS = {
    critical: '#dc3545',    // 0-84%
    warning: '#ffc107',     // 85-94%
    good: '#28a745',        // 95-100%
    excellent: '#007bff'    // 101%+
};
// Get emoji based on percentage
function getStatusEmoji(percentage) {
    if (percentage >= 101) return '🔵';
    if (percentage >= 95) return '🟢';
    if (percentage >= 85) return '🟡';
    return '🔴';
}
// แก้บรรทัดที่ 1608 ด้วย
// Get color based on percentage
function getColorByPercentage(percentage) {
    if (percentage >= 101) return PERCENTAGE_COLORS.excellent;
    if (percentage >= 95) return PERCENTAGE_COLORS.good;
    if (percentage >= 85) return PERCENTAGE_COLORS.warning;
    return PERCENTAGE_COLORS.critical;
}

// Get CSS class based on percentage
function getPercentageClass(percentage) {
    if (percentage >= 101) return 'percentage-excellent';
    if (percentage >= 95) return 'percentage-good';
    if (percentage >= 85) return 'percentage-warning';
    return 'percentage-critical';
}

// ==================== KPI Speedometer Gauge ====================
function drawKPIGauge(overallPct, lineData) {
    const canvas = document.getElementById('kpiGaugeCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;

    // Responsive canvas sizing — cap height so gauge stays compact
    const cssW = canvas.parentElement.clientWidth || 480;
    const isMobile = cssW < 500;
    const rawH  = isMobile ? Math.round(cssW * 0.72) : Math.round(cssW * 0.34);
    const cssH  = Math.min(rawH, isMobile ? 320 : 280);   // max height cap
    canvas.style.height = cssH + 'px';
    canvas.width  = Math.round(cssW * dpr);
    canvas.height = Math.round(cssH * dpr);
    ctx.scale(dpr, dpr);

    const W = cssW, H = cssH;
    const cx = isMobile ? W / 2 : W * 0.36;
    const cy = isMobile ? H * 0.80 : H * 0.88;
    // R must leave room above arc: top label sits at cy - (R + arcW/2 + 20) >= 8px
    // Solve: R <= (cy - 28) / 1.10  →  use cy * 0.72 as safe upper bound
    const R  = isMobile ? Math.min(cx - 18, cy * 0.72) : Math.min(cx - 20, cy * 0.72);
    const arcW = Math.round(R * 0.40);  // arc thickness proportional to radius  แถบสีหนาขึ้น
    const MAX = 120;

    // angle: 0%→left(π), 100%→right(2π)
    const toAngle = v => Math.PI * (1 + v / MAX);

    // ── Background track ──────────────────────────────────────
    ctx.beginPath();
    ctx.arc(cx, cy, R, Math.PI, 0, false);
    ctx.strokeStyle = 'rgba(255,255,255,0.07)';
    ctx.lineWidth = arcW + 8;
    ctx.stroke();

    // ── Colored arc segments (สีตาม PERCENTAGE_COLORS) ────────
    const segments = [
        { from: 0,   to: 84,  color: PERCENTAGE_COLORS.critical  },  // 0–84%
        { from: 84,  to: 95,  color: PERCENTAGE_COLORS.warning   },  // 85–94%
        { from: 95,  to: 100, color: PERCENTAGE_COLORS.good      },  // 95–100%
        { from: 100, to: 120, color: PERCENTAGE_COLORS.excellent },  // 101%+
    ];
    segments.forEach(s => {
        ctx.beginPath();
        ctx.arc(cx, cy, R, toAngle(s.from), toAngle(s.to), false);
        ctx.strokeStyle = s.color;
        ctx.lineWidth = arcW;
        ctx.lineCap = 'butt';
        ctx.stroke();
    });

    // ── Tick marks & value labels (outside arc only) ───────────
    const ticks = [0, 84, 95, 100, 120];   // zone boundaries
    const tickFontSize = Math.max(9, Math.round(R * 0.08));
    ticks.forEach(v => {
        const a = toAngle(v);
        const cos = Math.cos(a), sin = Math.sin(a);
        // short tick line spanning only the outer edge
        const iR = R + arcW / 2 + 2, oR = R + arcW / 2 + 9;
        ctx.beginPath();
        ctx.moveTo(cx + iR * cos, cy + iR * sin);
        ctx.lineTo(cx + oR * cos, cy + oR * sin);
        ctx.strokeStyle = 'rgba(255,255,255,0.25)';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        const lR = R + arcW / 2 + 20;
        ctx.fillStyle = '#6b7a8d';
        ctx.font = `${tickFontSize}px Poppins, sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(v + '%', cx + lR * cos, cy + lR * sin);
    });

    // ── Animated needle ───────────────────────────────────────
    const clamped = Math.min(Math.max(overallPct, 0), MAX);
    const needleA = toAngle(clamped);
    const nLen  = R - arcW / 2 - 6;
    const nTail = R * 0.14;

    ctx.save();
    ctx.shadowColor = 'rgba(0,0,0,0.55)';
    ctx.shadowBlur = 8;
    ctx.beginPath();
    ctx.moveTo(cx - nTail * Math.cos(needleA), cy - nTail * Math.sin(needleA));
    ctx.lineTo(cx + nLen  * Math.cos(needleA), cy + nLen  * Math.sin(needleA));
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    ctx.stroke();
    ctx.shadowBlur = 0;

    // needle hub
    ctx.beginPath();
    ctx.arc(cx, cy, 10, 0, Math.PI * 2);
    ctx.fillStyle = '#2a2f3a';
    ctx.fill();
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 2.5;
    ctx.stroke();
    ctx.restore();

    // ── Center value & status (สีตาม PERCENTAGE_COLORS) ────────
    const statusColor = clamped >= 100 ? PERCENTAGE_COLORS.excellent
                      : clamped >= 95  ? PERCENTAGE_COLORS.good
                      : clamped >= 85  ? PERCENTAGE_COLORS.warning
                      :                  PERCENTAGE_COLORS.critical;
    const statusText  = clamped >= 100 ? 'Excellent' : clamped >= 95 ? 'Good' : clamped >= 85 ? 'Warning' : 'Critical';
    const valueFontSize = Math.max(20, Math.round(R * 0.20));
    const labelFontSize = Math.max(10, Math.round(R * 0.085));

    // big % sits well above the hub
    ctx.textAlign = 'center';
    ctx.fillStyle = statusColor;
    ctx.font = `bold ${valueFontSize}px Poppins, sans-serif`;
    ctx.textBaseline = 'bottom';
    ctx.fillText(overallPct.toFixed(1) + '%', cx, cy - R * 0.28);

    // small label below the hub
    ctx.fillStyle = '#8896a8';
    ctx.font = `${labelFontSize}px Poppins, sans-serif`;
    ctx.textBaseline = 'top';
    ctx.fillText('Overall KPI', cx, cy + 16);

    // ── Update badge ──────────────────────────────────────────
    const badge = document.getElementById('kpiGaugeLabel');
    if (badge) {
        badge.textContent = overallPct.toFixed(1) + '% — ' + statusText;
        badge.className = 'badge fs-6 px-3 py-1';
        badge.className += clamped >= 100 ? ' bg-primary' : clamped >= 95 ? ' bg-success' : clamped >= 85 ? ' bg-warning text-dark' : ' bg-danger';
    }

    // ── Per-line mini progress bars (right panel on desktop, below on mobile) ──
    if (!lineData) return;
    const lines = ['fc', 'fb', 'rc', 'rb', 'third', 'sub'];
    const lColors = { fc: '#28a745', fb: '#ffc107', rc: '#dc3545', rb: '#8b5cf6', third: '#fd7e14', sub: '#20c997' };
    const lNames  = { fc: 'F/C', fb: 'F/B', rc: 'R/C', rb: 'R/B', third: '3RD', sub: 'Sub' };

    if (isMobile) {
        // Row of mini bars below the gauge arc
        const barH   = Math.max(7, Math.round(R * 0.07));
        const startX = W * 0.04;
        const rowW   = W * 0.92;
        const itemW  = rowW / lines.length;
        const barY   = cy + 16;

        lines.forEach((key, i) => {
            const d = lineData[key];
            if (!d) return;
            const pct = Math.min(d.percentage || 0, MAX);
            const x = startX + i * itemW + itemW * 0.08;
            const bw = itemW * 0.84;

            ctx.fillStyle = 'rgba(255,255,255,0.06)';
            ctx.beginPath(); ctx.roundRect(x, barY, bw, barH, 3); ctx.fill();
            ctx.fillStyle = lColors[key];
            ctx.beginPath(); ctx.roundRect(x, barY, bw * (pct / MAX), barH, 3); ctx.fill();

            ctx.fillStyle = lColors[key];
            ctx.font = `bold ${Math.max(9, Math.round(R * 0.08))}px Poppins, sans-serif`;
            ctx.textAlign = 'center'; ctx.textBaseline = 'top';
            ctx.fillText(lNames[key], x + bw / 2, barY + barH + 3);

            ctx.fillStyle = '#8896a8';
            ctx.font = `${Math.max(8, Math.round(R * 0.07))}px Poppins, sans-serif`;
            ctx.fillText((d.percentage || 0) + '%', x + bw / 2, barY + barH + 15);
        });
    } else {
        // Vertical list of bars on the right side
        const panelX  = W * 0.64;
        const panelW  = W * 0.33;
        const barH    = Math.max(8, Math.round(H * 0.048));
        const fontSize = Math.max(10, Math.round(H * 0.044));
        const titleH  = fontSize + 6;
        const usableH = H - titleH - 16;                 // space below title
        const spacing = Math.floor(usableH / lines.length);
        const startY  = titleH + 8;                      // just below title

        lines.forEach((key, i) => {
            const d = lineData[key];
            if (!d) return;
            const pct = Math.min(d.percentage || 0, MAX);
            const y = startY + i * spacing + (spacing - barH) / 2;

            // Label
            ctx.fillStyle = lColors[key];
            ctx.font = `bold ${fontSize}px Poppins, sans-serif`;
            ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
            ctx.fillText(lNames[key], panelX, y + barH / 2);

            // Bar background
            const barX = panelX + 36;
            const bw   = panelW - 58;
            ctx.fillStyle = 'rgba(255,255,255,0.06)';
            ctx.beginPath(); ctx.roundRect(barX, y, bw, barH, 4); ctx.fill();

            // Bar fill
            ctx.fillStyle = lColors[key];
            ctx.globalAlpha = 0.85;
            ctx.beginPath(); ctx.roundRect(barX, y, bw * (pct / MAX), barH, 4); ctx.fill();
            ctx.globalAlpha = 1;

            // % text
            ctx.fillStyle = '#c8d4e0';
            ctx.font = `${fontSize}px Poppins, sans-serif`;
            ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
            ctx.fillText((d.percentage || 0) + '%', barX + bw + 5, y + barH / 2);
        });

        // Panel title
        ctx.fillStyle = '#8896a8';
        ctx.font = `${fontSize}px Poppins, sans-serif`;
        ctx.textAlign = 'left'; ctx.textBaseline = 'top';
        ctx.fillText('Per-Line Achievement', panelX, 6);
    }
}

// Redraw gauge on window resize
window.addEventListener('resize', () => {
    if (window._gaugeLastData) {
        drawKPIGauge(window._gaugeLastData.overall, window._gaugeLastData.lines);
    }
});

// ==================== Sidebar Collapse Toggle ====================
(function () {
    const sidebar     = document.getElementById('mainSidebar');
    const mainContent = document.querySelector('.main-content');
    const toggleBtn   = document.getElementById('sidebarToggle');
    const footer      = document.querySelector('.dashboard-footer');
    if (!sidebar || !mainContent || !toggleBtn) return;

    function apply(collapsed) {
        sidebar.classList.toggle('collapsed', collapsed);
        mainContent.classList.toggle('sidebar-collapsed', collapsed);
        if (footer) footer.style.left = collapsed ? '60px' : '220px';
    }

    // Restore saved preference
    apply(localStorage.getItem('sidebarCollapsed') === '1');

    toggleBtn.addEventListener('click', () => {
        const collapsed = !sidebar.classList.contains('collapsed');
        apply(collapsed);
        localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
        // Redraw gauge after layout shift
        setTimeout(() => {
            if (window._gaugeLastData) {
                drawKPIGauge(window._gaugeLastData.overall, window._gaugeLastData.lines);
            }
        }, 260);
    });
})();

// 1. API Functions production
async function fetchReportData(type = 'hourly') {
    const startDate = document.getElementById('production_date_start').value;
    // const endDate = document.getElementById('production_date_end').value;
    
    try {
        showLoading(true);
        hideError();
        
        const response = await fetch(`${API_BASE}?type=${type}&start_date=${startDate}&end_date=${startDate}&display_type=${currentDisplayType}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Unknown error occurred');
        }
        
        return result.data;
        
    } catch (error) {
        console.error('API Error:', error);
        showError(error.message);
        throw error;
    } finally {
        showLoading(false);
    }
}
// 2. API Functions quality
async function fetchQualityData() {

    const start_date = document.getElementById('quality_date_start').value;
    const end_date = document.getElementById('quality_date_end').value;

    fetch(`api/get_defects_data.php?start_date=${start_date}&end_date=${end_date}`)
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // ตรวจสอบว่ามีข้อมูลหรือไม่
            if (data.line_data.length === 0 && 
                data.problem_data.length === 0 && 
                data.model_data.length === 0) {
                console.log('No data available for selected date range');
                return;
            }

            // สร้างกราฟแยกตามไลน์
            createParetoChart('lineDefectsChart', 
                data.line_data.map(d => d.count),
                data.line_data.map(d => d.process),
                'Defects by Production Line'
            );

            // สร้างกราฟแยกตามปัญหา
            createParetoChart('problemDefectsChart',
                data.problem_data.map(d => d.count),
                data.problem_data.map(d => d.detail),
                'Defects by Problem Type'
            );

            // สร้างกราฟแยกตามโมเดล
            createParetoChart('modelDefectsChart',
                data.model_data.map(d => d.count),
                data.model_data.map(d => d.model),
                'Defects by Model'
            );
            // สร้างกราฟแนวโน้มของเสียตามช่วงเวลา
            createTimelineChart(data.timeline_data);
        } else {
            console.error('API Error:', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        // alert('เกิดข้อผิดพลาดในการโหลดข้อมูล: ' + error.message);
    });
}
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
// 1. Chart configuration
const chartConfig = {
    type: 'bar',
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            // datalabels plugin configuration
            datalabels: {
                anchor: 'start',  // ตำแหน่งของ label จะอยู่ที่ปลายแท่ง
                align: 'top',   // ตำแหน่งของ label จะอยู่ด้านบนของแท่ง
                color: '#c8d4e0',  // สีของ label
                font: {
                    size: 12,
                    weight: 'bold'
                },
                formatter: function(value) {
                    return currentDisplayType === 'percentage' ? value + '%' : value + ' ชิ้น';
                }
            },
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                suggestedMax: 120,
                ticks: {
                    stepSize: 10
                }
            }
        }
    },
    plugins: [ChartDataLabels] // ✅ ตรงนี้คือการเปิดใช้ plugin
};

// 1. Create charts
function createChart(canvasId, data, color, label) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    // Update chart config for percentage display
    const config = {
        ...chartConfig,
        plugins: [ChartDataLabels], // ✅ เปิด plugin
        data: {
            labels: currentData.labels || [],
            datasets: [{
                label: label,
                data: data || [],
                backgroundColor: function(context) {
                    if (currentDisplayType === 'percentage') {
                        const value = context.parsed.y;
                        return getColorByPercentage(value) + '80'; // + '80' คือการเติม ค่าความโปร่งใส
                    }
                    return color + '80';
                },
                // ✅ กำหนดสีเส้นขอบของแท่งกราฟ
                borderColor: function(context) {
                    if (currentDisplayType === 'percentage') {
                        const value = context.parsed.y;
                        return getColorByPercentage(value);
                    }
                    return color;
                },
                borderWidth: 2,
                borderRadius: 4
            }]
        }
    };
    
    // Add percentage-specific options
    if (currentDisplayType === 'percentage') {
        // config.options.scales.y.max = 130;
        config.options.scales.y.ticks.callback = function(value) {
            return value + '%';
        };
    } else {
        delete config.options.scales.y.max;
        config.options.scales.y.ticks.callback = function(value) {
            return value;
        };
    }
    
    charts[canvasId] = new Chart(ctx, config);
}

// 1. Initialize all charts
function initializeCharts() {
    Object.keys(CHART_COLORS).forEach(line => {
        const canvasId = line === 'third' ? 'chart3RD' : `chart${line.toUpperCase()}`;
        createChart(canvasId, [], CHART_COLORS[line], LINE_NAMES[line]);
    });
}

// 1. Update charts with new data
function updateCharts(data) {
    currentData = data;
    
    Object.keys(CHART_COLORS).forEach(line => {
        const canvasId = line === 'third' ? 'chart3RD' : `chart${line.toUpperCase()}`;
        const chart = charts[canvasId];
        
        if (chart && data[line]) {
            // Update data
            chart.data.labels = data.labels || [];
            chart.data.datasets[0].data = data[line] || [];
            
            // Update colors and styling based on display type
            if (currentDisplayType === 'percentage') {
                // Use percentage-based colors
                // สีของแท่งกราฟจะถูกกำหนดตามเปอร์เซ็นต์
                // แท่งกราฟจะมีความโปร่งใส 80% สีพื้นหลัง
                chart.data.datasets[0].backgroundColor = data[line].map(value =>
                    getColorByPercentage(value) + '80'
                );
                // สีเส้นขอบของแท่งกราฟจะเป็นสีที่ไม่โปร่งใส
                chart.data.datasets[0].borderColor = data[line].map(value =>
                    getColorByPercentage(value)
                );
                
                // Update Y-axis for percentage
                // chart.options.scales.y.max = 130;
                chart.options.scales.y.ticks.callback = function(value) {
                    return value + '%';
                };
                
                // Update dataset label charts ชื่อตรงแท่งกราฟ
                chart.data.datasets[0].label = LINE_NAMES[line];
                
            } else {
                // Use original line colors
                const originalColor = CHART_COLORS[line];
                chart.data.datasets[0].backgroundColor = originalColor + '80'; 
                chart.data.datasets[0].borderColor = originalColor;
                
                // Reset Y-axis for pieces
                delete chart.options.scales.y.max;
                chart.options.scales.y.ticks.callback = function(value) {
                    return value;
                };
                
                // Update dataset label
                chart.data.datasets[0].label = LINE_NAMES[line];
            }
            
            // Force chart update with animation
            chart.update('active');
        }
    });
    
    // Update tooltips after chart update
    setTimeout(() => {
        addChartTooltips();
    }, 100);
}

// 1. Product summary totals
async function updateSummary() {
    try {
        const summaryData = await fetchReportData('summary');
        
        Object.keys(summaryData).forEach(line => {
            const elementId = line === 'third' ? 'total3RD' : `total${line.toUpperCase()}`;
            const labelId = line === 'third' ? 'label3RD' : `label${line.toUpperCase()}`;
            const percentageId = line === 'third' ? 'percentage3RD' : `percentage${line.toUpperCase()}`;
            
            const element = document.getElementById(elementId);
            const labelElement = document.getElementById(labelId);
            const percentageElement = document.getElementById(percentageId);
            
            if (element && summaryData[line]) {
                const data = summaryData[line];
                
                if (currentDisplayType === 'percentage') {
                    // Show percentage mode
                    element.textContent = data.percentage + '%';
                    labelElement.textContent = `${LINE_NAMES[line]}`;
                    
                    // Show percentage badge
                    if (percentageElement) {
                        percentageElement.textContent = `${data.total_qty} ชิ้น`;
                        percentageElement.className = `percentage-badge ${getPercentageClass(data.percentage)}`;
                        percentageElement.classList.remove('d-none');
                    }
                    
                    // Update main number color based on percentage
                    element.className = '';
                    const colorClass = getPercentageClass(data.percentage).replace('percentage-', '');
                    if (colorClass === 'critical') element.className = 'text-danger';
                    else if (colorClass === 'warning') element.className = 'text-warning';
                    else if (colorClass === 'good') element.className = 'text-success';
                    else if (colorClass === 'excellent') element.className = 'text-primary';
                    
                    // Add animation for critical values
                    if (data.percentage < 80) {
                        element.parentElement.classList.add('critical-animation');
                        setTimeout(() => {
                            element.parentElement.classList.remove('critical-animation');
                        }, 500);
                    } else if (data.percentage >= 101) {
                        element.parentElement.classList.add('excellent-animation');
                        setTimeout(() => {
                            element.parentElement.classList.remove('excellent-animation');
                        }, 2000);
                    }
                    
                } else {
                    // Show pieces mode
                    element.textContent = data.total_qty + ' ชิ้น'|| 0 + ' ชิ้น';
                    labelElement.textContent = `${LINE_NAMES[line]}`;
                    
                    // Hide percentage badge
                    if (percentageElement) {
                        percentageElement.textContent = `${data.percentage} %`; // เพิ่มเปอร์เซ็นต์
                        percentageElement.classList.remove('d-none');   // แสดงเปอร์เซ็นต์ เดิม .add('d-none');
                    }
                    
                    // Reset to original colors
                    element.className = '';
                    if (line === 'fc') element.className = 'text-success';
                    else if (line === 'fb') element.className = 'text-warning';
                    else if (line === 'rc') element.className = 'text-danger';
                    else element.style.color = CHART_COLORS[line];
                }
                
                // Add animation effect
                element.style.transform = 'scale(1.1)';
                setTimeout(() => {
                    element.style.transform = 'scale(1)';
                }, 200);
            }
        });

        // ── Draw KPI Gauge ────────────────────────────────────────
        const lineKeys = ['fc', 'fb', 'rc', 'rb', 'third', 'sub'];
        const validPcts = lineKeys
            .filter(k => summaryData[k] && summaryData[k].percentage != null)
            .map(k => summaryData[k].percentage);
        const overallPct = validPcts.length
            ? Math.round((validPcts.reduce((a, b) => a + b, 0) / validPcts.length) * 10) / 10
            : 0;
        window._gaugeLastData = { overall: overallPct, lines: summaryData };
        drawKPIGauge(overallPct, summaryData);
        
    } catch (error) {
        console.error('Error updating summary:', error);
    }
}
// 1. Product Summary Table
async function loadModelSummary() {
  try {
    const date = document.getElementById('production_date_start').value;
    const response = await fetch(`${API_BASE}?type=model_summary&start_date=${date}&end_date=${date}`);
    
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    const result = await response.json();
    if (!result.success) {
      throw new Error(result.message || 'Unknown error occurred');
    }
    
    updateModelSummaryTable(result.data);
  } catch (error) {
    console.error('Error loading model summary:', error);
    document.getElementById('modelSummaryBody').innerHTML = 
      `<tr><td colspan="8" class="text-center text-danger">ไม่สามารถโหลดข้อมูลได้: ${error.message}</td></tr>`;
  }
  
}

// 1. ฟังก์ชันอัพเดตตารางสรุปโมเดล
function updateModelSummaryTable(data) {
  const tbody = document.getElementById('modelSummaryBody');
  const badge = document.getElementById('modelCountBadge');
  
  if (!data || !data.models || data.models.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" class="text-center py-3">ไม่พบข้อมูลโมเดลการผลิต</td></tr>';
    badge.textContent = 'Total 0 Models';
    return;
  }

  badge.textContent = `Total ${data.models.length} Models`;

  let html = '';
  
  // เรียงลำดับข้อมูลตามจำนวนรวม (มากไปน้อย)
//   data.models.sort((a, b) => b.total - a.total);
  
  // สร้างแถวข้อมูลสำหรับแต่ละโมเดล
  data.models.forEach((model, index) => {
    const rowClass = index % 2 === 0 ? 'qt-row-even' : 'qt-row-odd';
    const fmt = (v) => v > 0 ? `<span class="qt-num">${v.toLocaleString()}</span>` : '<span class="qt-dash">-</span>';
    
    html += `<tr class="${rowClass}">
      <td class="px-6 py-2 whitespace-nowrap text-sm text-left qt-label">${model.name}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model.fc)}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model.fb)}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model.rc)}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model.rb)}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model['3rd'])}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model.sub)}</td>
    </tr>`;
  });
  
  // เพิ่มแถวสรุป
  const fmtB = (v) => v > 0 ? v.toLocaleString() : '-';
  html += `<tr class="qt-summary">
    <td class="px-6 py-2 whitespace-nowrap text-sm font-bold text-left">รวมทั้งหมด</td>
    <td class="px-6 py-2 whitespace-nowrap text-sm font-bold text-center">${fmtB(data.totals.fc)}</td>
    <td class="px-6 py-2 whitespace-nowrap text-sm font-bold text-center">${fmtB(data.totals.fb)}</td>
    <td class="px-6 py-2 whitespace-nowrap text-sm font-bold text-center">${fmtB(data.totals.rc)}</td>
    <td class="px-6 py-2 whitespace-nowrap text-sm font-bold text-center">${fmtB(data.totals.rb)}</td>
    <td class="px-6 py-2 whitespace-nowrap text-sm font-bold text-center">${fmtB(data.totals['3rd'])}</td>
    <td class="px-6 py-2 whitespace-nowrap text-sm font-bold text-center">${fmtB(data.totals.sub)}</td>
  </tr>`;
  
  tbody.innerHTML = html;
}
// 2. Quality summary
async function updateQualityCards(data) {
    try {
        // แปลงข้อมูลจาก array เป็น object ที่จัดกลุ่มตาม process
        const processData = {};
        data.line_data.forEach(item => {
            // แปลง process name ให้เป็น key ที่ใช้ในระบบ
            let key = item.process.toLowerCase().replace('/', '');
            processData[key] = {
                total_qty: item.count
            };
        });        
        // ดึงข้อมูลการผลิตตามวันที่ใน quality filter แทน
        const start_date = document.getElementById('quality_date_start').value;
        const end_date = document.getElementById('quality_date_end').value;

        // สร้าง URL สำหรับดึงข้อมูลการผลิตตามวันที่ quality
        const productionResponse = await fetch(`${API_BASE}?type=summary&start_date=${start_date}&end_date=${end_date}&display_type=pieces`);
        const productionResult = await productionResponse.json();
        const summaryData = productionResult.success ? productionResult.data : {};

        const lines = ['fc', 'fb', 'rc', 'rb', '3rd', 'sub'];
        lines.forEach(line => {
            const elementId = line === '3rd' ? 'quality3RD' : `quality${line.toUpperCase()}`;
            const labelId = line === '3rd' ? 'labelquality3RD' : `labelquality${line.toUpperCase()}`;
            const qualityId = line === '3rd' ? 'percentagequality3RD' : `percentagequality${line.toUpperCase()}`;
            
            const element = document.getElementById(elementId);
            // const labelElement = document.getElementById(labelId);
            const qualityElement = document.getElementById(qualityId);
            
            if (element) {
                // ดึงข้อมูลจาก processData หรือใส่ค่า default เป็น 0
                const lineData = processData[line] || { total_qty: 0 };
                const productionData = summaryData[line === '3rd' ? 'third' : line] || { total_qty: 0 };
                // console.log('check data:', summaryData);
                
                // คำนวณเปอร์เซ็นต์ของเสีย
                const defectPercentage = productionData.total_qty > 0 
                    ? ((lineData.total_qty / productionData.total_qty) * 100).toFixed(2)
                    : 0;

                // อัพเดตจำนวนชิ้น
                element.textContent = lineData.total_qty;

                // แสดงข้อมูลเพิ่มเติมใน console สำหรับ debug
                console.log(`Line ${line}: Defects=${lineData.total_qty}, Production=${productionData.total_qty}, Percentage=${defectPercentage}%`);
                                
                // อัพเดต label
                if (qualityElement) {
                    qualityElement.textContent = `${defectPercentage}%`;
                    qualityElement.classList.remove('d-none');
                    
                    // เพิ่มสีตามเปอร์เซ็นต์ของเสีย
                    if (defectPercentage > 5) {
                        qualityElement.className = 'percentage-badge percentage-critical';
                    } else if (defectPercentage > 3) {
                        qualityElement.className = 'percentage-badge percentage-warning';
                    } else {
                        qualityElement.className = 'percentage-badge percentage-good';
                    }
                }

                // อัพเดตเปอร์เซ็นต์ของเสีย
                if (qualityElement) {
                    qualityElement.textContent = `${defectPercentage} %`;
                }
                
                // อัพเดต animation
                element.classList.add('pulse-animation');
                setTimeout(() => {
                    element.classList.remove('pulse-animation');
                }, 500);
            }
        });
        
        console.log('Quality cards updated successfully');
        
    } catch (error) {
        console.error('Error updating quality cards:', error);
    }
}

// 2. Quality functions
function createParetoChart(canvasId, data, labels, title) {
    // Check if canvas exists
    const canvas = document.getElementById(canvasId);
    if (!canvas) {
        console.error(`Canvas element ${canvasId} not found`);
        return;
    }
    // Clear existing chart if it exists
    const existingChart = Chart.getChart(canvasId);
    if (existingChart) {    
        existingChart.destroy();
        
    }
    // const ctx = document.getElementById(canvasId).getContext('2d');
    const ctx = canvas.getContext('2d');
    
    // ✅ Debug ข้อมูลก่อนเรียง
    console.log('Original data:', data);
    console.log('Original labels:', labels);

    // ✅ แปลง data เป็น number และกรองข้อมูลที่ = 0 ออก
    const filteredData = data.map((value, index) => ({
        value: parseFloat(value) || 0,  // ✅ แปลงเป็น number
        label: labels[index] || 'Unknown'
    })).filter(item => item.value > 0); // กรองเฉพาะค่าที่ > 0
    
    console.log('Filtered data:', filteredData);
    
    if (filteredData.length === 0) {
        console.warn('No data after filtering zeros');
        return;
    }

    // ✅ เรียงข้อมูลจากมากไปน้อยก่อน (สำคัญมาก!)
    const combinedData = filteredData.sort((a, b) => b.value - a.value);
    
    const sortedData = combinedData.map(item => item.value);
    const sortedLabels = combinedData.map(item => item.label);

    // ✅ Debug ข้อมูลหลังเรียง
    console.log('Sorted data:', sortedData);
    console.log('Sorted labels:', sortedLabels);

    // คำนวณค่าต่างๆ
    const total = sortedData.reduce((a, b) => a + b, 0);
    console.log('Total:', total);

     // ✅ ตรวจสอบ total
    if (total === 0) {
        console.warn('Total is zero, cannot calculate percentages');
        return;
    }

    // คำนวณเปอร์เซ็นต์ของแต่ละรายการ
    const individualPercentage = sortedData.map(value => 
        Number(((value / total) * 100).toFixed(1))
    );
    console.log('Individual percentages:', individualPercentage);

    // ✅ ตรวจสอบผลรวม percentage ต้องเป็น 100
    const percentageSum = individualPercentage.reduce((a, b) => a + b, 0);
    console.log('Sum of individual percentages:', percentageSum);

    // คำนวณเปอร์เซ็นต์สะสม
    let cumulative = 0;
    const cumulativePercentage = sortedData.map(value => {
        cumulative += value;
        return Number(((cumulative / total) * 100).toFixed(1));
    });

    // ✅ กำหนด max ให้สมดุลกัน
    const maxDataValue = Math.max(...sortedData);

    new Chart(ctx, {
        type: 'bar',
        plugins: [ChartDataLabels], // ✅ เปิด plugin
        data: {
            labels: sortedLabels,            
            datasets: [
                {
                    label: 'Defect Quantity',
                    data: sortedData,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 2,
                    borderRadius: 4,
                    fill: true,
                    order: 2
                },
                {
                    label: 'Cumulative %',
                    data: cumulativePercentage,
                    type: 'line',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 2,
                    fill: false,
                    order: 1,
                    yAxisID: 'percentage',
                    pointHoverRadius: 7,
                    tension: 0.1
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                datalabels: {
                    anchor: 'start',
                    align: 'top',                    
                    color: '#c8d4e0',
                    formatter: function(value, context) {
                        // ตรวจสอบว่าเป็นข้อมูลจาก dataset ไหน
                        if (context.datasetIndex === 0) {
                            // กราฟแท่ง - แสดงทั้งจำนวนและเปอร์เซ็นต์
                            const percentage = individualPercentage[context.dataIndex];
                            return [`${value} ชิ้น`, `(${percentage}%)`];
                        } else {
                            // กราฟเส้น (เปอร์เซ็นต์สะสม)
                            return value.toFixed(1) + '%';
                        }
                    },
                    font: {
                        size: 11,
                        weight: 'bold'
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (context.datasetIndex === 0) {
                                const value = context.parsed.y;
                                const percentage = individualPercentage[context.dataIndex];
                                return [
                                    `จำนวนของเสีย: ${value} ชิ้น`,
                                    `เปอร์เซ็นต์: ${percentage}%`,
                                    `จากทั้งหมด: ${total} ชิ้น`
                                ];
                            } else {
                                return `เปอร์เซ็นต์สะสม: ${context.parsed.y}%`;
                            }
                        }
                    }
                },
                legend: {
                    display: true,
                    position: 'top'
                },
                title: {
                    display: false,
                    text: title
                }
            },
            scales: {
                x: {
                    ticks: {
                        callback: function(value, index) {
                            const label = labels[index];
                            // ตัดข้อความให้แสดงแค่ 15 ตัวอักษร
                            return label.length > 10 ? label.substr(0, 10) + '...' : label;
                        }
                    }
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    max: Math.ceil(maxDataValue * 1.2), // เพิ่ม margin ให้สูงสุด
                    title: {
                        display: true,
                        text: 'Defect Quantity (Pieces)'
                    },
                    ticks: {
                        stepSize: Math.ceil(maxDataValue / 10),
                    }
                },
                percentage: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    max: 110, // ✅ เผื่อ margin 10%
                    title: {
                        display: true,
                        text: 'เปอร์เซ็นต์สะสม (%)'
                    },
                    ticks: {
                        stepSize: 10,
                        callback: function(value) {
                            return value + '%';
                        }
                    },
                    // ✅ ป้องกัน grid lines ทับกัน
                    grid: {
                        drawOnChartArea: false,
                    }
                }
            }
        }
    });
}
// 2. Create timeline chart for defects over time
// ประโยชน์ของกราฟนี้:
// 1.แสดงแนวโน้มของเสียว่าเพิ่มขึ้นหรือลดลงตามเวลา
// 2.เห็น Pattern การเกิดของเสียในแต่ละช่วงเวลา
// 3.สามารถระบุวันที่มีของเสียสูงผิดปกติ
// 4.ช่วยในการวางแผนปรับปรุงกระบวนการผลิต
// 5.เมื่อ Hover ที่จุดข้อมูล จะเห็นรายละเอียดว่าเกิดของเสียที่ไลน์ไหนบ้าง
// คุณสามารถใช้ข้อมูลนี้วิเคราะห์ Pattern การเกิดของเสีย และวางแผนป้องกันได้อย่างมีประสิทธิภาพมากขึ้นครับ
function createTimelineChart(data) {
    const ctx = document.getElementById('timelineDefectsChart').getContext('2d');
    
    const dates = data.map(item => item.defect_date);
    const counts = data.map(item => item.total_defects);

    new Chart(ctx, {
        type: 'line',
        plugins: [ChartDataLabels],
        data: {
            labels: dates,
            datasets: [{
                label: 'Total Defect Quantity',
                data: counts,
                borderColor: 'rgba(75, 192, 192, 1)',
                tension: 0.3,
                fill: true,                
                backgroundColor: 'rgba(75, 192, 192, 0.2)'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                datalabels: {
                    anchor: 'start',
                    align: 'top',                    
                    color: '#c8d4e0',
                    formatter: function(value) {
                        return value + ' ชิ้น';
                    },
                    font: {
                        size: 12,
                        weight: 'bold'
                    }
                },
                title: {
                    display: false,
                    text: 'แนวโน้มของเสียตามช่วงเวลา'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const dataPoint = data[context.dataIndex];
                            return [
                                `จำนวนของเสีย: ${context.parsed.y} ชิ้น`,
                                `ไลน์ที่พบ: ${dataPoint.processes}`
                            ];
                        }
                    }
                }
            },
            scales: {
                // x: {
                //     title: {
                //         display: true,
                //         text: 'วันที่'
                //     }
                // },
                x: {
                    title: {
                        display: true,
                        // text: 'วันที่'
                    },
                    ticks: {
                        maxTicksLimit: 15, // จำกัดจำนวน label ที่แสดงไม่ให้เยอะเกินไป
                        callback: function(value, index) {
                            // แสดงทุก 3-5 วัน ขึ้นอยู่กับจำนวนข้อมูล
                            const step = Math.ceil(data.length / 10);
                            return (index % step === 0) ? this.getLabelForValue(value) : '';
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Defect Quantity (Pieces)'
                    }
                }
            }
        }   
    });
}
// 2. ดึงข้อมูล Cross-tab จาก API
async function fetchCrossTabData() {
    const start_date = document.getElementById('quality_date_start').value;
    const end_date = document.getElementById('quality_date_end').value;
    
    try {
        const response = await fetch(`api/get_cross_tabs.php?start_date=${start_date}&end_date=${end_date}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            // แสดงตาราง Cross-Process-Detail
            updateCrossProcessTable(data.process_data);
            
            // แสดงตาราง Cross-Model-Detail
            updateCrossModelTable(data.model_data);
            
            console.log('Cross-tab data loaded successfully');
        } else {
            throw new Error(data.message || 'Unknown error occurred');
        }
    } catch (error) {
        console.error('Error loading cross-tab data:', error);
        document.getElementById('crossProcessBody').innerHTML = 
            `<tr><td colspan="9" class="text-center text-danger">ไม่สามารถโหลดข้อมูลได้: ${error.message}</td></tr>`;
        document.getElementById('crossModelBody').innerHTML = 
            `<tr><td colspan="9" class="text-center text-danger">ไม่สามารถโหลดข้อมูลได้: ${error.message}</td></tr>`;
    }
}

// 2. อัพเดตตาราง Cross-Process-Detail
function updateCrossProcessTable(data) {
    if (!data || !data.details || !data.processes) {
        document.getElementById('crossProcessBody').innerHTML = 
            '<tr><td colspan="9" class="text-center py-3">ไม่พบข้อมูล Cross-Process-Detail</td></tr>';
        document.getElementById('processDetailCount').textContent = 'Total 0 Details';
        return;
    }
    
    // อัพเดต header
    const headerRow = document.querySelector('#crossProcessTable thead tr');
    headerRow.innerHTML = '<th scope="col" class="px-6 py-2 text-left text-sm font-bold">Detail\\Process</th>';

    data.processes.forEach(process => {
        headerRow.innerHTML += `<th scope="col" class="px-6 py-2 text-center text-sm font-bold">${process}</th>`;
    });
    
    // เพิ่มคอลัมน์รวมและเปอร์เซ็นต์
    headerRow.innerHTML += `
        <th scope="col" class="px-6 py-2 text-center text-sm font-bold">รวมเสีย</th>
        <th scope="col" class="px-6 py-2 text-center text-sm font-bold">%</th>
    `;
    
    // อัพเดตเนื้อหาตาราง
    let tbody = '';
    let totalAll = 0;
    
    // คำนวณผลรวมทั้งหมด
    data.details.forEach(detail => {
        let detailTotal = 0;
        data.processes.forEach(process => {
            detailTotal += data.data[detail][process] || 0;
        });
        totalAll += detailTotal;
    });
    
    // สร้างแถวข้อมูล
    data.details.forEach((detail, index) => {
        const rowClass = index % 2 === 0 ? 'qt-row-even' : 'qt-row-odd';
        let rowTotal = 0;
        
        tbody += `<tr class="${rowClass}">
            <td class="px-6 py-2 text-sm text-left font-medium qt-label">${detail}</td>`;
            
        // แสดงข้อมูลแต่ละ process
        data.processes.forEach(process => {
            const value = data.data[detail][process] || 0;
            rowTotal += value;
            tbody += `<td class="px-6 py-2 text-sm text-center qt-value">${value > 0 ? `<span class="qt-num">${value}</span>` : '<span class="qt-dash">-</span>'}</td>`;
        });
        
        // แสดงผลรวมและเปอร์เซ็นต์
        const percentage = totalAll > 0 ? ((rowTotal / totalAll) * 100).toFixed(1) : '0.0';
        tbody += `
            <td class="px-6 py-2 text-sm text-center qt-total">${rowTotal}</td>
            <td class="px-6 py-2 text-sm text-center qt-pct">${percentage}%</td>
        </tr>`;
    });
    
    // เพิ่มแถวสรุป
    tbody += '<tr class="qt-summary"><td class="px-6 py-2 text-sm font-bold">รวมเสีย(ชิ้น)</td>';
    
    // คำนวณผลรวมแต่ละคอลัมน์
    const columnTotals = {};
    data.processes.forEach(process => {
        columnTotals[process] = 0;
        data.details.forEach(detail => {
            columnTotals[process] += data.data[detail][process] || 0;
        });
        tbody += `<td class="px-6 py-2 text-sm text-center font-bold">${columnTotals[process]}</td>`;
    });
    
    // ปิดแถวสรุป
    tbody += `
        <td class="px-6 py-2 text-sm text-center font-bold">${totalAll}</td>
        <td class="px-6 py-2 text-sm text-center font-bold">100%</td>
    </tr>`;

    document.getElementById('crossProcessBody').innerHTML = tbody;
    document.getElementById('processDetailCount').textContent = `Total ${data.details.length} Details`;
}

// 2. อัพเดตตาราง Cross-Model-Detail (คล้ายกับ process แต่ใช้ Model แทน)
function updateCrossModelTable(data) {
    // ทำเหมือนกับ updateCrossProcessTable แต่เปลี่ยนจาก process เป็น model
    if (!data || !data.details || !data.models) {
        document.getElementById('crossModelBody').innerHTML = 
            '<tr><td colspan="9" class="text-center py-3">ไม่พบข้อมูล Cross-Model-Detail</td></tr>';
        document.getElementById('modelDetailCount').textContent = 'Total 0 Details';
        return;
    }
    
    // อัพเดต header
    const headerRow = document.querySelector('#crossModelTable thead tr');
    headerRow.innerHTML = '<th scope="col" class="px-6 py-2 text-left text-sm font-bold">Detail\\Model</th>';
    
    data.models.forEach(model => {
        headerRow.innerHTML += `<th scope="col" class="px-6 py-2 text-center text-sm font-bold">${model}</th>`;
    });
    
    // เพิ่มคอลัมน์รวมและเปอร์เซ็นต์
    headerRow.innerHTML += `
        <th scope="col" class="px-6 py-2 text-center text-sm font-bold">รวมเสีย</th>
        <th scope="col" class="px-6 py-2 text-center text-sm font-bold">%</th>
    `;
    
    // อัพเดตเนื้อหาตาราง (คล้ายกับ updateCrossProcessTable)
    let tbody = '';
    let totalAll = 0;
    
    // คำนวณผลรวมทั้งหมด
    data.details.forEach(detail => {
        let detailTotal = 0;
        data.models.forEach(model => {
            detailTotal += data.data[detail][model] || 0;
        });
        totalAll += detailTotal;
    });
    
    // สร้างแถวข้อมูล
    data.details.forEach((detail, index) => {
        const rowClass = index % 2 === 0 ? 'qt-row-even' : 'qt-row-odd';
        let rowTotal = 0;
        
        tbody += `<tr class="${rowClass}">
            <td class="px-6 py-2 text-sm text-left font-medium qt-label">${detail}</td>`;
            
        // แสดงข้อมูลแต่ละ model
        data.models.forEach(model => {
            const value = data.data[detail][model] || 0;
            rowTotal += value;
            tbody += `<td class="px-6 py-2 text-sm text-center qt-value">${value > 0 ? `<span class="qt-num">${value}</span>` : '<span class="qt-dash">-</span>'}</td>`;
        });
        
        // แสดงผลรวมและเปอร์เซ็นต์
        const percentage = totalAll > 0 ? ((rowTotal / totalAll) * 100).toFixed(1) : '0.0';
        tbody += `
            <td class="px-6 py-2 text-sm text-center qt-total">${rowTotal}</td>
            <td class="px-6 py-2 text-sm text-center qt-pct">${percentage}%</td>
        </tr>`;
    });
    
    // เพิ่มแถวสรุป
    tbody += '<tr class="qt-summary"><td class="px-6 py-2 text-sm font-bold">รวมเสีย(ชิ้น)</td>';
    
    // คำนวณผลรวมแต่ละคอลัมน์
    const columnTotals = {};
    data.models.forEach(model => {
        columnTotals[model] = 0;
        data.details.forEach(detail => {
            columnTotals[model] += data.data[detail][model] || 0;
        });
        tbody += `<td class="px-6 py-2 text-sm text-center font-bold">${columnTotals[model]}</td>`;
    });
    
    // ปิดแถวสรุป
    tbody += `
        <td class="px-6 py-2 text-sm text-center font-bold">${totalAll}</td>
        <td class="px-6 py-2 text-sm text-center font-bold">100%</td>
    </tr>`;
    
    document.getElementById('crossModelBody').innerHTML = tbody;
    document.getElementById('modelDetailCount').textContent = `Total ${data.details.length} Details`;
}
// 3. Performance functions
async function loadPerformanceData() {
    try {
        const start_date = document.getElementById('performance_date_start').value;
        const end_date = document.getElementById('performance_date_end').value;
        const type = document.querySelector('input[name="performanceType"]:checked').value;
        
        const response = await fetch(`api/get_performance.php?action=all&start_date=${start_date}&end_date=${end_date}&type=${type}`);
        const data = await response.json();
        
        if (data.success) {
            updatePerformanceKPIs(data.data.kpis);
            createEfficiencyTrendChart(data.data.efficiency_trend);
            createLinePerformanceChart(data.data.line_performance);
            createTargetVsActualChart(data.data.line_performance);
            createQualityPerformanceChart(data.data.kpis);
        }
        
    } catch (error) {
        console.error('Error loading performance data:', error);
    }
}

// 3. อัพเดต Performance KPIs
function updatePerformanceKPIs(kpis) {
    document.getElementById('overallEfficiency').textContent = `${kpis.overall_efficiency}%`;
    document.getElementById('qualityRate').textContent = `${kpis.quality_rate}%`;
    document.getElementById('productivityRate').textContent = `${kpis.productivity_rate}`;
    document.getElementById('defectRate').textContent = `${kpis.defect_rate}%`;
    
    // เพิ่มสีตามค่า KPI
    updateKPIColors('overallEfficiency', kpis.overall_efficiency);
    updateKPIColors('qualityRate', kpis.quality_rate);
    updateKPIColors('productivityRate', kpis.productivity_rate);
    updateKPIColors('defectRate', kpis.defect_rate, true); // reverse for defect rate
}

// 3. สร้างกราฟแสดงประสิทธิภาพของไลน์
function updateKPIColors(elementId, value, reverse = false) {
    const element = document.getElementById(elementId);
    element.classList.remove('text-success', 'text-warning', 'text-danger');
    
    if (reverse) {
        if (value < 3) element.classList.add('text-success');
        else if (value < 5) element.classList.add('text-warning');
        else element.classList.add('text-danger');
    } else {
        if (value >= 90) element.classList.add('text-success');
        else if (value >= 80) element.classList.add('text-warning');
        else element.classList.add('text-danger');
    }
}

// 3. สร้าง Line Performance Comparison Chart
function createLinePerformanceChart(data) {
    const canvas = document.getElementById('performanceComparisonChart');
    if (!canvas) return;
    
    const existingChart = Chart.getChart(canvas);
    if (existingChart) existingChart.destroy();
    
    const ctx = canvas.getContext('2d');
    
    new Chart(ctx, {
        type: 'bar',
        plugins: [ChartDataLabels],
        data: {
            labels: data.map(item => item.process),
            datasets: [{
                label: 'Efficiency %',
                data: data.map(item => parseFloat(item.efficiency)),
                backgroundColor: data.map(item => {
                    const eff = parseFloat(item.efficiency);
                    // if (eff >= 101) return 'rgba(40, 167, 69, 0.8)';  // green
                    // if (eff >= 93) return 'rgba(40, 167, 69, 0.8)';  // green
                    // if (eff >= 80) return 'rgba(255, 193, 7, 0.8)';   // yellow
                    // return 'rgba(220, 53, 69, 0.8)';                  // red
                    return getColorByPercentage(eff) + 80;
                }),
                borderColor: data.map(item => {
                    const eff = parseFloat(item.efficiency);
                    // if (eff >= 101) return 'rgba(40, 167, 69, 1)';
                    // if (eff >= 93) return 'rgba(40, 167, 69, 1)';
                    // if (eff >= 80) return 'rgba(255, 193, 7, 1)';
                    // return 'rgba(220, 53, 69, 1)';
                    return getColorByPercentage(eff);
                }),
                borderWidth: 2,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                datalabels: {
                    anchor: 'start',
                    align: 'top',
                    formatter: (value) => `${value.toFixed(2)}%`,
                    font: { size: 11, weight: 'bold' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Efficiency (%)', color: '#8896a8' }
                }
            }
        }
    });
}

// 3. สร้าง Target vs Actual Chart
function createTargetVsActualChart(data) {
    const canvas = document.getElementById('targetVsActualChart');
    if (!canvas) return;
    
    const existingChart = Chart.getChart(canvas);
    if (existingChart) existingChart.destroy();
    
    const ctx = canvas.getContext('2d');
    
    new Chart(ctx, {
        type: 'bar',
        plugins: [ChartDataLabels],
        data: {
            labels: data.map(item => item.process),
            datasets: [
                {
                    label: 'Target',
                    data: data.map(item => item.target_qty),
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 2,
                    borderRadius: 4
                },
                {
                    label: 'Actual',
                    data: data.map(item => item.actual_qty),
                    backgroundColor: 'rgba(255, 99, 132, 0.5)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 2,
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: true },
                datalabels: {
                    anchor: 'start',
                    align: 'top',
                    formatter: (value) => `${value}`,
                    font: { size: 10, weight: 'bold' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Quantity (pieces)', color: '#8896a8' }
                }
            }
        }
    });
}
// 3. สร้าง Efficiency Trend Chart
function createEfficiencyTrendChart(data) {
    const canvas = document.getElementById('efficiencyTrendChart');
    if (!canvas) return;
    
    const existingChart = Chart.getChart(canvas);
    if (existingChart) existingChart.destroy();
    
    const ctx = canvas.getContext('2d');
    
    new Chart(ctx, {
        type: 'line',
        plugins: [ChartDataLabels],
        data: {
            labels: data.map(item => item.period),
            datasets: [{
                label: 'Efficiency %',
                data: data.map(item => parseFloat(item.efficiency)),
                borderColor: 'rgba(75, 192, 192, 1)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.3,
                fill: true,
                pointRadius: data.map(item => item.is_weekend ? 3 : 5), // จุดเล็กสำหรับวันหยุด
                pointBackgroundColor: data.map(item => 
                    item.is_weekend ? 'rgba(255, 99, 132, 0.5)' : 'rgba(75, 192, 192, 1)'
                )
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                datalabels: {
                    anchor: 'end',
                    align: 'top',
                    formatter: (value, context) => {
                        const dataPoint = data[context.dataIndex];
                        // ไม่แสดง label สำหรับวันหยุด
                        return dataPoint.is_weekend ? '' : `${value.toFixed(1)}%`;
                    },
                    font: { size: 10, weight: 'bold' }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const dataPoint = data[context.dataIndex];
                            const weekend_text = dataPoint.is_weekend ? ' (วันหยุด)' : '';
                            return [
                                `Efficiency: ${context.parsed.y.toFixed(1)}%${weekend_text}`,
                                `Actual: ${dataPoint.actual.toLocaleString()} ชิ้น`,
                                `Target: ${dataPoint.target.toLocaleString()} ชิ้น`
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    title: {
                        display: true,
                        // text: 'วันที่'
                    },
                    ticks: {
                        maxTicksLimit: 15, // จำกัดจำนวน label ที่แสดงไม่ให้เยอะเกินไป
                        callback: function(value, index) {
                            // แสดงทุก 3-5 วัน ขึ้นอยู่กับจำนวนข้อมูล
                            const step = Math.ceil(data.length / 10);
                            return (index % step === 0) ? this.getLabelForValue(value) : '';
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    max: 120,
                    title: { display: true, text: 'Efficiency (%)', color: '#8896a8' }
                }
            }
        }
    });
}

// 3. สร้าง Quality Performance Chart (Donut Chart)
function createQualityPerformanceChart(kpis) {
    const canvas = document.getElementById('qualityPerformanceChart');
    if (!canvas) return;
    
    const existingChart = Chart.getChart(canvas);
    if (existingChart) existingChart.destroy();
    
    const ctx = canvas.getContext('2d');
    
    // const qualityRate = parseFloat(kpis.quality_rate) || 0;
    const defectRate = parseFloat(kpis.defect_rate) || 0;
    const passRate = Math.max(0, 100 - defectRate);
    
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Pass', 'Defect'],
            datasets: [{
                data: [passRate, defectRate],
                backgroundColor: [
                    'rgba(40, 167, 69, 0.5)',   // green for pass
                    'rgba(220, 53, 69, 0.5)'    // red for defect
                ],
                borderColor: [
                    'rgba(40, 167, 69, 1)',
                    'rgba(220, 53, 69, 1)'
                ],
                borderWidth: 2,
                cutout: '40%',
                radius: '100%'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                datalabels: {
                    formatter: (value, ctx) => {
                        const total = passRate + defectRate;
                        return total ? (value / total * 100).toFixed(1) + '%' : '0%';
                    },
                    font: { size: 12, weight: 'bold' },
                    color: '#fff'
                },
                tooltip: {
                    callbacks: {
                        label: (c) => c.label + ': ' + c.parsed + '%'
                    }
                }
            },
            layout: { padding: 4 }
        }
    });
}
// 1 Load and display report data
async function loadProductData() {
    try {
        // Load hourly data for charts
        const hourlyData = await fetchReportData('hourly');
        updateCharts(hourlyData);
        
        // Load summary data
        await updateSummary();
        await loadModelSummary();

        console.log('Report data loaded successfully');
        
    } catch (error) {
        console.error('Error loading report data:', error);
    }
}
// 2 Load and display Quality data
async function loadQualityData() {
    try {
        // Load quality data for charts
        await fetchQualityData();

        const start_date = document.getElementById('quality_date_start').value;
        const end_date = document.getElementById('quality_date_end').value;

        // เปลี่ยนจาก fetch เป็น await fetch
        const response = await fetch(`api/get_defects_data.php?start_date=${start_date}&end_date=${end_date}`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        const data = await response.json();

        if (data.success) {
            // อัปเดต Quality Cards ก่อน
            await updateQualityCards(data);
            await fetchCrossTabData();

            console.log('Quality data loaded successfully',data);
        } else {
            console.error('API Error:', data.message);
        }
        console.log('Report data loaded successfully');
        
    } catch (error) {
        console.error('Error loading report data:', error);
    }
}

// Real-time update function
function startRealTimeUpdate() {
    const checkbox = document.getElementById('realTimeUpdate');
    
    if (checkbox.checked) {
        updateInterval = setInterval(async () => {
            await loadProductData();
            console.log('Real-time update completed');
        }, 30000); // Update every 30 seconds
    } else {
        if (updateInterval) {
            clearInterval(updateInterval);
        }
    }
}

// --- Export Date Dialog (Reusable) ---
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

// Generic launcher: ปุ่มทุกหน้าที่มี class .btn-export-date
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
                            // สร้าง URL ตาม module
                            let url;
                            switch (module) {
                                case 'production':
                                    url = `api/export_product.php?start_date=${start}&end_date=${end}`;
                                    break;
                                case 'quality':
                                    url = `api/export_quality.php?start_date=${start}&end_date=${end}`;
                                    break;
                                case 'performance':
                                    url = `api/export_performance.php?start_date=${start}&end_date=${end}`;
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

// Event Listeners สำหรับ Production Filter
document.getElementById('production_btnFilter').addEventListener('click', async function() {
    await loadProductData();
});
// Event Listener สำหรับ Quality Filter
document.getElementById('quality_btnFilter').addEventListener('click', async function() {
    await loadQualityData();
});
// Event Listener สำหรับ Performance Filter
document.getElementById('performance_btnFilter').addEventListener('click', function() {
    loadPerformanceData();
});
// Event Listener สำหรับ Export Button
document.getElementById('btnExport').addEventListener('click', function(e) {
    e.preventDefault();
    
    const startDate = document.getElementById('report_date_start').value;
    const endDate = document.getElementById('report_date_end').value;
    const displayType = currentDisplayType;
    
    // Open export URL in new window with display type
    const exportUrl = `api/export_excel.php?start_date=${startDate}&end_date=${endDate}&display_type=${displayType}`;
    window.open(exportUrl, '_blank');
});

// Event Listener สำหรับ Real-time Update Checkbox
document.getElementById('realTimeUpdate').addEventListener('change', function() {
    startRealTimeUpdate();
});

// Toggle display type
function toggleDisplayType() {
    // const toggle = document.getElementById('displayToggle');
    // currentDisplayType = toggle.checked ? 'percentage' : 'pieces';
    const selectedRadio = document.querySelector('input[name="displayType"]:checked');
    currentDisplayType = selectedRadio ? selectedRadio.value : 'percentage';

    
    // Update existing charts instead of destroying them
    Object.keys(CHART_COLORS).forEach(line => {
        const canvasId = line === 'third' ? 'chart3RD' : `chart${line.toUpperCase()}`;
        const chart = charts[canvasId];
        
        if (chart) {
            // Update chart options for display type
            if (currentDisplayType === 'percentage') {
                // chart.options.scales.y.max = 130;
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
    
    // Update tooltips
    addChartTooltips();
    
    // Load data with new display type
    loadProductData();
    
    console.log('Display type changed to:', currentDisplayType);
}

document.querySelectorAll('input[name="displayType"]').forEach(radio => {
    radio.addEventListener('change', function() {
        currentDisplayType = this.value;
        toggleDisplayType(); // ฟังก์ชันเดิมที่ใช้ update กราฟ ฯลฯ
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
                            if (percentage >= 101) status = '🔵 เกินเป้าหมาย';
                            else if (percentage >= 95) status = '🟢 ตามเป้าหมาย';
                            else if (percentage >= 85) status = '🟡 ใกล้เป้าหมาย';
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

// Update chart tooltips when display type changes
function updateChartTooltips() {
    addChartTooltips();
    Object.values(charts).forEach(chart => {
        if (chart) {
            chart.update('none');
        }
    });
}
document.addEventListener('DOMContentLoaded', function() {
    window.addEventListener('resize', () => setTimeout(equalizeChartCards, 100));
});
// Initialize on page load
document.addEventListener('DOMContentLoaded', async function() {
    // Set default dates to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('production_date_start').value = today;
    // document.getElementById('production_date_end').value = today;

    document.getElementById('quality_date_start').value = today;
    document.getElementById('quality_date_end').value = today;

    document.getElementById('performance_date_start').value = today;
    document.getElementById('performance_date_end').value = today;

    document.getElementById('report_date_start').value = today;
    document.getElementById('report_date_end').value = today;

    // Initialize export buttons
    initExportButtons();

    // Initialize empty charts first
    initializeCharts();
    
    // Add chart tooltips
    addChartTooltips();
    
    // Load initial data
    await loadProductData();
    // await loadQualityData();

    // Start real-time updates if enabled
    startRealTimeUpdate();
    
    // ✅ เรียกให้ทำงานกับค่า default ที่เป็น percentage
    toggleDisplayType();

    document.getElementById('production-tab').addEventListener('shown.bs.tab', function() {
        loadProductData();
    });

    document.getElementById('quality-tab').addEventListener('shown.bs.tab', function() {
        loadQualityData();
    });

    document.getElementById('performance-tab').addEventListener('shown.bs.tab', function() {
        loadPerformanceData();
    });

    console.log('Sewing report system initialized');
});

// ==================== AI Chat System ====================

// Chat state
let isChatOpen = false;
let isLLMOnline = false;
let chatHistory = [];

// Initialize chat system
function initAIChatSystem() {
    const chatToggleBtn = document.getElementById('chat-toggle-btn');
    const chatCloseBtn = document.getElementById('chat-close-btn');
    const chatContainer = document.getElementById('chat-container');
    const chatInput = document.getElementById('chat-input');
    const chatSendBtn = document.getElementById('chat-send-btn');

    // Toggle chat window
    chatToggleBtn?.addEventListener('click', toggleChat);
    chatCloseBtn?.addEventListener('click', toggleChat);

    // Send message on button click
    chatSendBtn?.addEventListener('click', sendChatMessage);

    // Send message on Enter (Shift+Enter for new line)
    chatInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendChatMessage();
        }
    });

    // Auto-resize textarea
    chatInput?.addEventListener('input', () => {
        chatInput.style.height = 'auto';
        chatInput.style.height = chatInput.scrollHeight + 'px';
    });

    // Check LLM server status on load
    checkLLMStatus();
}

// Toggle chat window
function toggleChat() {
    isChatOpen = !isChatOpen;
    const chatContainer = document.getElementById('chat-container');
    const chatToggleBtn = document.getElementById('chat-toggle-btn');
    
    if (isChatOpen) {
        chatContainer.style.display = 'flex';
        chatToggleBtn.style.display = 'none';
        document.getElementById('chat-input')?.focus();
    } else {
        chatContainer.style.display = 'none';
        chatToggleBtn.style.display = 'flex';
    }
}

// Check LLM server status
async function checkLLMStatus() {
    const statusElement = document.getElementById('llm-status');
    
    try {
        const response = await fetch('api/chat_llm.php');
        const data = await response.json();
        
        isLLMOnline = data.success && data.status === 'online';
        
        if (statusElement) {
            if (isLLMOnline) {
                statusElement.innerHTML = '<i class="fas fa-circle status-online"></i> พร้อมใช้งาน';
                statusElement.className = 'text-white-50';
            } else {
                statusElement.innerHTML = '<i class="fas fa-circle status-offline"></i> ไม่พร้อมใช้งาน';
                statusElement.className = 'text-white-50';
            }
        }
    } catch (error) {
        console.error('Error checking LLM status:', error);
        isLLMOnline = false;
        if (statusElement) {
            statusElement.innerHTML = '<i class="fas fa-circle status-offline"></i> เกิดข้อผิดพลาด';
            statusElement.className = 'text-white-50';
        }
    }
}

// Send chat message
async function sendChatMessage() {
    const chatInput = document.getElementById('chat-input');
    const chatSendBtn = document.getElementById('chat-send-btn');
    const question = chatInput.value.trim();
    
    if (!question) return;
    
    // Check if LLM is online
    if (!isLLMOnline) {
        addChatMessage('กรุณารอสักครู่ ระบบ AI กำลังเริ่มต้น...', 'bot');
        await checkLLMStatus();
        if (!isLLMOnline) {
            addChatMessage('ขออภัยครับ ระบบ AI ยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ', 'bot');
            return;
        }
    }
    
    // Add user message to chat
    addChatMessage(question, 'user');
    
    // Clear input
    chatInput.value = '';
    chatInput.style.height = 'auto';
    
    // Disable send button
    chatSendBtn.disabled = true;
    
    // Show typing indicator
    showTypingIndicator();
    
    try {
        // Send to API with longer timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 180000); // 3 minutes timeout
        
        const response = await fetch('api/chat_llm.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ question }),
            signal: controller.signal
        });
        
        clearTimeout(timeoutId);
        const data = await response.json();
        
        // Remove typing indicator
        removeTypingIndicator();
        
        if (data.success) {
            // Add bot response
            addChatMessage(data.answer, 'bot', data.chart);
            
            // Save to history
            chatHistory.push({
                question,
                answer: data.answer,
                chart: data.chart,
                timestamp: data.timestamp
            });
        } else {
            addChatMessage('ขออภัยครับ เกิดข้อผิดพลาด: ' + data.error, 'bot');
        }
    } catch (error) {
        console.error('Error sending message:', error);
        removeTypingIndicator();
        
        if (error.name === 'AbortError') {
            addChatMessage('⏱️ ขออภัยครับ คำถามนี้ใช้เวลานานเกินไป (มากกว่า 3 นาที) กรุณาลองถามคำถามที่สั้นกว่า หรือเฉพาะเจาะจงมากขึ้น', 'bot');
        } else {
            addChatMessage('❌ ขออภัยครับ ไม่สามารถเชื่อมต่อกับระบบ AI ได้ กรุณาลองใหม่อีกครั้ง\n\n' + 
                          'เคล็ดลับ: ลองถามคำถามที่ชัดเจนและเฉพาะเจาะจงมากขึ้น', 'bot');
        }
    } finally {
        // Re-enable send button
        chatSendBtn.disabled = false;
        chatInput.focus();
    }
}

// Add message to chat
function addChatMessage(message, type = 'bot', chartData = null) {
    const chatMessages = document.getElementById('chat-messages');
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${type}-message`;
    
    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';
    
    if (type === 'bot') {
        const icon = '<i class="fas fa-robot me-2"></i>';
        contentDiv.innerHTML = icon + message;
    } else {
        contentDiv.textContent = message;
    }
    
    messageDiv.appendChild(contentDiv);
    
    // Add chart if available
    if (chartData && type === 'bot') {
        const chartContainer = createChatChart(chartData);
        if (chartContainer) {
            contentDiv.appendChild(chartContainer);
        }
    }
    
    chatMessages.appendChild(messageDiv);
    
    // Scroll to bottom
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// Create chart in chat message
function createChatChart(chartData) {
    if (!chartData || !chartData.data || chartData.data.length === 0) {
        return null;
    }
    
    const container = document.createElement('div');
    container.className = 'chat-chart-container';
    
    const canvas = document.createElement('canvas');
    canvas.id = 'chat-chart-' + Date.now();
    container.appendChild(canvas);
    
    // Wait for DOM to be ready
    setTimeout(() => {
        const ctx = canvas.getContext('2d');
        const labels = chartData.data.map(item => item.label);
        const values = chartData.data.map(item => item.value);
        
        // Generate colors
        const colors = generateChartColors(values.length);
        
        new Chart(ctx, {
            type: chartData.type || 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: chartData.title || 'ข้อมูล',
                    data: values,
                    backgroundColor: colors.background,
                    borderColor: colors.border,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: chartData.title || 'กราฟแสดงผล'
                    }
                },
                scales: chartData.type !== 'pie' && chartData.type !== 'doughnut' ? {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: !!chartData.y_label,
                            text: chartData.y_label || ''
                        }
                    },
                    x: {
                        title: {
                            display: !!chartData.x_label,
                            text: chartData.x_label || ''
                        }
                    }
                } : {}
            }
        });
    }, 100);
    
    return container;
}

// Generate chart colors
function generateChartColors(count) {
    const baseColors = [
        '#667eea', '#764ba2', '#f093fb', '#4facfe',
        '#43e97b', '#fa709a', '#fee140', '#30cfd0'
    ];
    
    const background = [];
    const border = [];
    
    for (let i = 0; i < count; i++) {
        const color = baseColors[i % baseColors.length];
        background.push(color + '80'); // Add transparency
        border.push(color);
    }
    
    return { background, border };
}

// Show typing indicator
function showTypingIndicator() {
    const chatMessages = document.getElementById('chat-messages');
    const typingDiv = document.createElement('div');
    typingDiv.className = 'message bot-message';
    typingDiv.id = 'typing-indicator';
    
    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';
    contentDiv.style.display = 'flex';
    contentDiv.style.flexDirection = 'column';
    contentDiv.style.gap = '8px';
    
    const indicatorDiv = document.createElement('div');
    indicatorDiv.className = 'typing-indicator';
    indicatorDiv.innerHTML = '<span></span><span></span><span></span>';
    
    const textDiv = document.createElement('div');
    textDiv.style.fontSize = '12px';
    textDiv.style.color = '#666';
    textDiv.innerHTML = '<i class="fas fa-robot me-1"></i> กำลังคิด... (อาจใช้เวลา 30-60 วินาที)';
    
    contentDiv.appendChild(indicatorDiv);
    contentDiv.appendChild(textDiv);
    typingDiv.appendChild(contentDiv);
    chatMessages.appendChild(typingDiv);
    
    // Scroll to bottom
    chatMessages.scrollTop = chatMessages.scrollHeight;
    
    // Update message every 15 seconds
    let elapsedSeconds = 0;
    const updateInterval = setInterval(() => {
        elapsedSeconds += 15;
        if (document.getElementById('typing-indicator')) {
            if (elapsedSeconds === 15) {
                textDiv.innerHTML = '<i class="fas fa-robot me-1"></i> กำลังวิเคราะห์ข้อมูล...';
            } else if (elapsedSeconds === 30) {
                textDiv.innerHTML = '<i class="fas fa-robot me-1"></i> กำลังสร้างคำตอบ...';
            } else if (elapsedSeconds === 45) {
                textDiv.innerHTML = '<i class="fas fa-robot me-1"></i> เกือบเสร็จแล้ว...';
            } else if (elapsedSeconds >= 60) {
                textDiv.innerHTML = '<i class="fas fa-robot me-1"></i> กำลังประมวลผลคำถามที่ซับซ้อน...';
            }
        } else {
            clearInterval(updateInterval);
        }
    }, 15000);
    
    // Store interval ID for cleanup
    typingDiv.dataset.intervalId = updateInterval;
}

// Remove typing indicator
function removeTypingIndicator() {
    const typingIndicator = document.getElementById('typing-indicator');
    if (typingIndicator) {
        // Clear interval if exists
        const intervalId = typingIndicator.dataset.intervalId;
        if (intervalId) {
            clearInterval(parseInt(intervalId));
        }
        typingIndicator.remove();
    }
}

// Initialize chat system when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initAIChatSystem();
});