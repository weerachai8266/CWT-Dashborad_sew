// Chart configuration
const chartConfig = {
    type: 'bar',
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            datalabels: {
                anchor: 'start',
                align: 'top',
                color: '#c8d4e0',
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
    plugins: [ChartDataLabels]
};

// Create a single production line chart
function createChart(canvasId, data, color, label) {
    const ctx = document.getElementById(canvasId).getContext('2d');

    const config = {
        ...chartConfig,
        plugins: [ChartDataLabels],
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

    if (currentDisplayType === 'percentage') {
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

// Initialize all production line charts
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
            chart.data.labels = data.labels || [];
            chart.data.datasets[0].data = data[line] || [];

            if (currentDisplayType === 'percentage') {
                chart.data.datasets[0].backgroundColor = data[line].map(value =>
                    getColorByPercentage(value) + '80'
                );
                chart.data.datasets[0].borderColor = data[line].map(value =>
                    getColorByPercentage(value)
                );
                chart.options.scales.y.ticks.callback = function(value) {
                    return value + '%';
                };
                chart.data.datasets[0].label = LINE_NAMES[line];
            } else {
                const originalColor = CHART_COLORS[line];
                chart.data.datasets[0].backgroundColor = originalColor + '80';
                chart.data.datasets[0].borderColor = originalColor;
                delete chart.options.scales.y.max;
                chart.options.scales.y.ticks.callback = function(value) {
                    return value;
                };
                chart.data.datasets[0].label = LINE_NAMES[line];
            }

            chart.update('active');
        }
    });

    setTimeout(() => {
        addChartTooltips();
    }, 100);
}

// Pareto chart for quality defects
function createParetoChart(canvasId, data, labels, title) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) {
        console.error(`Canvas element ${canvasId} not found`);
        return;
    }
    const existingChart = Chart.getChart(canvasId);
    if (existingChart) {
        existingChart.destroy();
    }
    const ctx = canvas.getContext('2d');

    const filteredData = data.map((value, index) => ({
        value: parseFloat(value) || 0,
        label: labels[index] || 'Unknown'
    })).filter(item => item.value > 0);

    if (filteredData.length === 0) {
        console.warn('No data after filtering zeros');
        return;
    }

    const combinedData = filteredData.sort((a, b) => b.value - a.value);
    const sortedData = combinedData.map(item => item.value);
    const sortedLabels = combinedData.map(item => item.label);

    const total = sortedData.reduce((a, b) => a + b, 0);

    if (total === 0) {
        console.warn('Total is zero, cannot calculate percentages');
        return;
    }

    const individualPercentage = sortedData.map(value =>
        Number(((value / total) * 100).toFixed(1))
    );

    let cumulative = 0;
    const cumulativePercentage = sortedData.map(value => {
        cumulative += value;
        return Number(((cumulative / total) * 100).toFixed(1));
    });

    const maxDataValue = Math.max(...sortedData);

    new Chart(ctx, {
        type: 'bar',
        plugins: [ChartDataLabels],
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
                        if (context.datasetIndex === 0) {
                            const percentage = individualPercentage[context.dataIndex];
                            return [`${value} ชิ้น`, `(${percentage}%)`];
                        } else {
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
                            return label.length > 10 ? label.substr(0, 10) + '...' : label;
                        }
                    }
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    max: Math.ceil(maxDataValue * 1.2),
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
                    max: 110,
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
                    grid: {
                        drawOnChartArea: false,
                    }
                }
            }
        }
    });
}

// Timeline chart for defects over time
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
                x: {
                    title: {
                        display: true,
                    },
                    ticks: {
                        maxTicksLimit: 15,
                        callback: function(value, index) {
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

// Line Performance Comparison Chart
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
                    return getColorByPercentage(eff) + 80;
                }),
                borderColor: data.map(item => {
                    const eff = parseFloat(item.efficiency);
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

// Target vs Actual Chart
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

// Efficiency Trend Chart
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
                pointRadius: data.map(item => item.is_weekend ? 3 : 5),
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
                    },
                    ticks: {
                        maxTicksLimit: 15,
                        callback: function(value, index) {
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

// Quality Performance Chart (Donut)
function createQualityPerformanceChart(kpis) {
    const canvas = document.getElementById('qualityPerformanceChart');
    if (!canvas) return;

    const existingChart = Chart.getChart(canvas);
    if (existingChart) existingChart.destroy();

    const ctx = canvas.getContext('2d');

    const defectRate = parseFloat(kpis.defect_rate) || 0;
    const passRate = Math.max(0, 100 - defectRate);

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Pass', 'Defect'],
            datasets: [{
                data: [passRate, defectRate],
                backgroundColor: [
                    'rgba(40, 167, 69, 0.5)',
                    'rgba(220, 53, 69, 0.5)'
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
