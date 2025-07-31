// Global variables
let charts = {};
let currentData = {};
let updateInterval;
let currentDisplayType = 'pieces'; // 'pieces' or 'percentage'

// API Configuration
const API_BASE = 'api/get_report_data.php';

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
    critical: '#dc3545',    // 0-79%
    warning: '#ffc107',     // 80-94%
    good: '#28a745',        // 95-104%
    excellent: '#007bff'    // 105%+
};

// Get color based on percentage
function getColorByPercentage(percentage) {
    if (percentage >= 105) return PERCENTAGE_COLORS.excellent;
    if (percentage >= 95) return PERCENTAGE_COLORS.good;
    if (percentage >= 80) return PERCENTAGE_COLORS.warning;
    return PERCENTAGE_COLORS.critical;
}

// Get CSS class based on percentage
function getPercentageClass(percentage) {
    if (percentage >= 105) return 'percentage-excellent';
    if (percentage >= 95) return 'percentage-good';
    if (percentage >= 80) return 'percentage-warning';
    return 'percentage-critical';
}


// API Functions
async function fetchReportData(type = 'hourly') {
    const startDate = document.getElementById('report_date_start').value;
    const endDate = document.getElementById('report_date_end').value;
    
    try {
        showLoading(true);
        hideError();
        
        const response = await fetch(`${API_BASE}?type=${type}&start_date=${startDate}&end_date=${endDate}&display_type=${currentDisplayType}`);
        
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
// Chart configuration
const chartConfig = {
    type: 'bar',
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 10
                }
            }
        }
    }
};

// Create charts
function createChart(canvasId, data, color, label) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    // Update chart config for percentage display
    const config = {
        ...chartConfig,
        data: {
            labels: currentData.labels || [],
            datasets: [{
                label: label,
                data: data || [],
                backgroundColor: function(context) {
                    if (currentDisplayType === 'percentage') {
                        const value = context.parsed.y;
                        return getColorByPercentage(value) + '80';
                    }
                    return color + '80';
                },
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
        config.options.scales.y.max = 120;
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

// Initialize all charts
function initializeCharts() {
    Object.keys(CHART_COLORS).forEach(line => {
        const canvasId = line === 'third' ? 'chart3RD' : `chart${line.toUpperCase()}`;
        createChart(canvasId, [], CHART_COLORS[line], LINE_NAMES[line]);
    });
}

// Update charts with new data
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
                chart.data.datasets[0].backgroundColor = data[line].map(value =>
                    getColorByPercentage(value) + '80'
                );
                chart.data.datasets[0].borderColor = data[line].map(value =>
                    getColorByPercentage(value)
                );
                
                // Update Y-axis for percentage
                chart.options.scales.y.max = 120;
                chart.options.scales.y.ticks.callback = function(value) {
                    return value + '%';
                };
                
                // Update dataset label
                chart.data.datasets[0].label = LINE_NAMES[line] + ' %';
                
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
                chart.data.datasets[0].label = LINE_NAMES[line] + ' ชิ้น';
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

// Update summary totals
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
                    labelElement.textContent = `${LINE_NAMES[line]} %`;
                    
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
                    } else if (data.percentage >= 105) {
                        element.parentElement.classList.add('excellent-animation');
                        setTimeout(() => {
                            element.parentElement.classList.remove('excellent-animation');
                        }, 2000);
                    }
                    
                } else {
                    // Show pieces mode
                    element.textContent = data.total_qty || 0;
                    labelElement.textContent = `${LINE_NAMES[line]} ชิ้น`;
                    
                    // Hide percentage badge
                    if (percentageElement) {
                        percentageElement.classList.add('d-none');
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
        
    } catch (error) {
        console.error('Error updating summary:', error);
    }
}

// Load and display report data
async function loadReportData() {
    try {
        // Load hourly data for charts
        const hourlyData = await fetchReportData('hourly');
        updateCharts(hourlyData);
        
        // Load summary data
        await updateSummary();
        
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
            await loadReportData();
            console.log('Real-time update completed');
        }, 30000); // Update every 30 seconds
    } else {
        if (updateInterval) {
            clearInterval(updateInterval);
        }
    }
}

// Event Listeners
document.getElementById('btnFilter').addEventListener('click', async function() {
    await loadReportData();
});

document.getElementById('btnExport').addEventListener('click', function(e) {
    e.preventDefault();
    
    const startDate = document.getElementById('report_date_start').value;
    const endDate = document.getElementById('report_date_end').value;
    const displayType = currentDisplayType;
    
    // Open export URL in new window with display type
    const exportUrl = `api/export_excel.php?start_date=${startDate}&end_date=${endDate}&display_type=${displayType}`;
    window.open(exportUrl, '_blank');
});

document.getElementById('realTimeUpdate').addEventListener('change', function() {
    startRealTimeUpdate();
});

// Toggle display type
function toggleDisplayType() {
    const toggle = document.getElementById('displayToggle');
    currentDisplayType = toggle.checked ? 'percentage' : 'pieces';
    
    // Update existing charts instead of destroying them
    Object.keys(CHART_COLORS).forEach(line => {
        const canvasId = line === 'third' ? 'chart3RD' : `chart${line.toUpperCase()}`;
        const chart = charts[canvasId];
        
        if (chart) {
            // Update chart options for display type
            if (currentDisplayType === 'percentage') {
                chart.options.scales.y.max = 120;
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
    loadReportData();
    
    console.log('Display type changed to:', currentDisplayType);
}

// Toggle Switch Event Listener
document.getElementById('displayToggle').addEventListener('change', function() {
    toggleDisplayType();
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
                            if (percentage >= 105) status = '🔵 เกินเป้าหมาย';
                            else if (percentage >= 95) status = '🟢 ตามเป้าหมาย';
                            else if (percentage >= 80) status = '🟡 ใกล้เป้าหมาย';
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

// Initialize on page load
document.addEventListener('DOMContentLoaded', async function() {
    // Set default dates to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('report_date_start').value = today;
    document.getElementById('report_date_end').value = today;

    // document.getElementById('displayToggle').checked = true; // ให้ toggle อยู่ในตำแหน่ง "เปอร์เซ็น"
    // currentDisplayType = 'percentage';                       // ตั้งค่าตัวแปรให้ตรง
    
    // Initialize empty charts first
    initializeCharts();
    
    // Add chart tooltips
    addChartTooltips();
    
    // Load initial data
    await loadReportData();
    
    // Start real-time updates if enabled
    startRealTimeUpdate();
    
    console.log('Sewing report system initialized');
});