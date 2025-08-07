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

function getStatusEmoji(percentage) {
    if (percentage >= 101) return '🔵';
    if (percentage >= 93) return '🟢';
    if (percentage >= 80) return '🟡';
    return '🔴';
}

// Get color based on percentage
function getColorByPercentage(percentage) {
    if (percentage >= 101) return PERCENTAGE_COLORS.excellent;
    if (percentage >= 93) return PERCENTAGE_COLORS.good;
    if (percentage >= 80) return PERCENTAGE_COLORS.warning;
    return PERCENTAGE_COLORS.critical;
}

// Get CSS class based on percentage
function getPercentageClass(percentage) {
    if (percentage >= 101) return 'percentage-excellent';
    if (percentage >= 93) return 'percentage-good';
    if (percentage >= 80) return 'percentage-warning';
    return 'percentage-critical';
}


// API Functions production
async function fetchReportData(type = 'hourly') {
    const startDate = document.getElementById('production_date_start').value;
    const endDate = document.getElementById('production_date_end').value;
    
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
// API Functions quality
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
// Chart configuration
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
                color: '#333',  // สีของ label
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

// Create charts
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

// Update product summary totals
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
        
    } catch (error) {
        console.error('Error updating summary:', error);
    }
}
// quality summary
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
        // ดึงข้อมูลการผลิตจาก summary
        const summaryData = await fetchReportData('summary');

        const lines = ['fc', 'fb', 'rc', 'rb', 'third', 'sub'];
        
        lines.forEach(line => {
            const elementId = line === 'third' ? 'quality3RD' : `quality${line.toUpperCase()}`;
            const labelId = line === 'third' ? 'labelquality3RD' : `labelquality${line.toUpperCase()}`;
            const qualityId = line === 'third' ? 'percentagequality3RD' : `percentagequality${line.toUpperCase()}`;
            
            const element = document.getElementById(elementId);
            const labelElement = document.getElementById(labelId);
            const qualityElement = document.getElementById(qualityId);
            
            if (element) {
                // ดึงข้อมูลจาก processData หรือใส่ค่า default เป็น 0
                const lineData = processData[line] || { total_qty: 0 };
                const productionData = summaryData[line] || { total_qty: 0 };
                
                // คำนวณเปอร์เซ็นต์ของเสีย
                const defectPercentage = productionData.total_qty > 0 
                    ? ((lineData.total_qty / productionData.total_qty) * 100).toFixed(2)
                    : 0;

                // อัพเดตจำนวนชิ้น
                element.textContent = lineData.total_qty;
                
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

// Quality functions
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
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    // คำนวณค่าสะสม
    const total = data.reduce((a, b) => a + b, 0);
    let cumulative = 0;
    const cumulativePercentage = data.map(value => {
        cumulative += value;
        return Number(((cumulative / total) * 100).toFixed(1));
    });

    new Chart(ctx, {
        type: 'bar',
        plugins: [ChartDataLabels], // ✅ เปิด plugin
        data: {
            labels: labels,            
            datasets: [
                {
                    label: 'จำนวนของเสีย',
                    data: data,
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
                    yAxisID: 'percentage'
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                datalabels: {
                    anchor: 'start',
                    align: 'top',                    
                    color: '#333',
                    formatter: function(value, context) {
                        // ตรวจสอบว่าเป็นข้อมูลจาก dataset ไหน
                        if (context.datasetIndex === 0) {
                            // กราฟแท่ง (จำนวนของเสีย)
                            return value + ' ชิ้น';
                        } else {
                            // กราฟเส้น (เปอร์เซ็นต์สะสม)
                            return value.toFixed(1) + '%';
                        }
                    },
                    font: {
                        size: 12,
                        weight: 'bold'
                    }
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
                    beginAtZero: true,
                    position: 'left',
                    max: Math.max(...data) + 3, // เพิ่ม margin ให้สูงสุด
                    title: {
                        display: true,
                        text: 'จำนวนของเสีย (ชิ้น)'
                    },
                    ticks: {
                        stepSize: 2,
                    }
                },
                percentage: {
                    beginAtZero: true,
                    position: 'right',
                    max: 120,
                    title: {
                        display: true,
                        text: 'Cumulative %'
                    }
                }
            }
        }
    });
}
// Create timeline chart for defects over time
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
                label: 'จำนวนของเสียรวม',
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
                    color: '#333',
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
                x: {
                    title: {
                        display: true,
                        text: 'วันที่'
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'จำนวนของเสีย (ชิ้น)'
                    }
                }
            }
        }   
    });
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
// Load quality data
// Load and display report data
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
document.getElementById('production_btnFilter').addEventListener('click', async function() {
    await loadReportData();
});

document.getElementById('quality_btnFilter').addEventListener('click', async function() {
    await loadQualityData();
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
    loadReportData();
    
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
                            else if (percentage >= 93) status = '🟢 ตามเป้าหมาย';
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
    document.getElementById('production_date_start').value = today;
    document.getElementById('production_date_end').value = today;
    document.getElementById('quality_date_start').value = today;
    document.getElementById('quality_date_end').value = today;
    document.getElementById('report_date_start').value = today;
    document.getElementById('report_date_end').value = today;

    // Initialize empty charts first
    initializeCharts();
    
    // Add chart tooltips
    addChartTooltips();
    
    // Load initial data
    await loadReportData();
    // await loadQualityData();

    // Start real-time updates if enabled
    startRealTimeUpdate();
    
    // ✅ เรียกให้ทำงานกับค่า default ที่เป็น percentage
    toggleDisplayType();

    console.log('Sewing report system initialized');
});