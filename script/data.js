// API Functions - Production
async function fetchReportData(type = 'hourly') {
    const startDate = document.getElementById('production_date_start').value;
    const endDateEl = document.getElementById('production_date_end');
    const endDate = (endDateEl && endDateEl.value) ? endDateEl.value : startDate;

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

// API Functions - Quality
async function fetchQualityData() {
    const start_date = document.getElementById('quality_date_start').value;
    const end_date = document.getElementById('quality_date_end').value;

    await fetch(`api/get_defects_data.php?start_date=${start_date}&end_date=${end_date}`)
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            if (data.line_data.length === 0 &&
                data.problem_data.length === 0 &&
                data.model_data.length === 0) {
                return;
            }

            createParetoChart('lineDefectsChart',
                data.line_data.map(d => d.count),
                data.line_data.map(d => d.process),
                'Defects by Production Line'
            );

            createParetoChart('problemDefectsChart',
                data.problem_data.map(d => d.count),
                data.problem_data.map(d => d.detail),
                'Defects by Problem Type'
            );

            createParetoChart('modelDefectsChart',
                data.model_data.map(d => d.count),
                data.model_data.map(d => d.model),
                'Defects by Model'
            );

            createTimelineChart(data.timeline_data);
        } else {
            console.error('API Error:', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// Product summary totals
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
                    element.textContent = data.percentage + '%';
                    labelElement.textContent = `${LINE_NAMES[line]}`;

                    if (percentageElement) {
                        percentageElement.textContent = `${data.total_qty} ชิ้น`;
                        percentageElement.className = `percentage-badge ${getPercentageClass(data.percentage)}`;
                        percentageElement.classList.remove('d-none');
                    }

                    element.className = '';
                    const colorClass = getPercentageClass(data.percentage).replace('percentage-', '');
                    if (colorClass === 'critical') element.className = 'text-danger';
                    else if (colorClass === 'warning') element.className = 'text-warning';
                    else if (colorClass === 'good') element.className = 'text-success';
                    else if (colorClass === 'excellent') element.className = 'text-primary';

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
                    element.textContent = data.total_qty + ' ชิ้น' || 0 + ' ชิ้น';
                    labelElement.textContent = `${LINE_NAMES[line]}`;

                    if (percentageElement) {
                        percentageElement.textContent = `${data.percentage} %`;
                        percentageElement.classList.remove('d-none');
                    }

                    element.className = '';
                    if (line === 'fc') element.className = 'text-success';
                    else if (line === 'fb') element.className = 'text-warning';
                    else if (line === 'rc') element.className = 'text-danger';
                    else element.style.color = CHART_COLORS[line];
                }

                element.style.transform = 'scale(1.1)';
                setTimeout(() => {
                    element.style.transform = 'scale(1)';
                }, 200);
            }
        });

        // Draw KPI Gauge
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

// Product Summary Table
async function loadModelSummary() {
    try {
        const date = document.getElementById('production_date_start').value;
        const endDateEl = document.getElementById('production_date_end');
        const endDate = (endDateEl && endDateEl.value) ? endDateEl.value : date;
        const response = await fetch(`${API_BASE}?type=model_summary&start_date=${date}&end_date=${endDate}`);

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

// Update model summary table
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

// Quality summary cards
async function updateQualityCards(data) {
    try {
        const processData = {};
        data.line_data.forEach(item => {
            let key = item.process.toLowerCase().replace('/', '');
            processData[key] = {
                total_qty: item.count
            };
        });

        const today = new Date().toISOString().slice(0, 10);
        const start_date = document.getElementById('quality_date_start').value || today;
        const end_date = document.getElementById('quality_date_end').value || today;

        const productionResponse = await fetch(`${API_BASE}?type=summary&start_date=${encodeURIComponent(start_date)}&end_date=${encodeURIComponent(end_date)}&display_type=pieces`);
        const productionResult = await productionResponse.json();
        const summaryData = productionResult.success ? productionResult.data : {};

        const lines = ['fc', 'fb', 'rc', 'rb', '3rd', 'sub'];
        lines.forEach(line => {
            const elementId = line === '3rd' ? 'quality3RD' : `quality${line.toUpperCase()}`;
            const qualityId = line === '3rd' ? 'percentagequality3RD' : `percentagequality${line.toUpperCase()}`;

            const element = document.getElementById(elementId);
            const qualityElement = document.getElementById(qualityId);

            if (element) {
                const lineData = processData[line] || { total_qty: 0 };
                const productionData = summaryData[line === '3rd' ? 'third' : line] || { total_qty: 0 };
                const defectPercentage = productionData.total_qty > 0
                    ? ((lineData.total_qty / productionData.total_qty) * 100).toFixed(2)
                    : 0;

                element.textContent = lineData.total_qty;

                if (qualityElement) {
                    qualityElement.textContent = `${defectPercentage}%`;
                    qualityElement.classList.remove('d-none');

                    if (defectPercentage > 5) {
                        qualityElement.className = 'percentage-badge percentage-critical';
                    } else if (defectPercentage > 3) {
                        qualityElement.className = 'percentage-badge percentage-warning';
                    } else {
                        qualityElement.className = 'percentage-badge percentage-good';
                    }
                }

                if (qualityElement) {
                    qualityElement.textContent = `${defectPercentage} %`;
                }

                element.classList.add('pulse-animation');
                setTimeout(() => {
                    element.classList.remove('pulse-animation');
                }, 500);
            }
        });

        // Draw Quality KPI Gauge
        const lineKeys = ['fc', 'fb', 'rc', 'rb', 'third', 'sub'];
        const defectKeyMap = { fc: 'fc', fb: 'fb', rc: 'rc', rb: 'rb', third: '3rd', sub: 'sub' };
        let totalProd = 0, totalDefect = 0;
        const qualityLineData = {};
        lineKeys.forEach(key => {
            const prod   = Math.max(0, (summaryData[key]  || {}).total_qty || 0);
            const defect = Math.max(0, Math.min(prod, (processData[defectKeyMap[key]] || {}).total_qty || 0));
            totalProd   += prod;
            totalDefect += defect;
            qualityLineData[key] = {
                qualityRate: prod > 0 ? Math.min(100, Math.max(0, parseFloat(((prod - defect) / prod * 100).toFixed(2)))) : 100,
                defectRate:  prod > 0 ? Math.min(100, Math.max(0, parseFloat((defect / prod * 100).toFixed(2)))) : 0,
            };
        });
        const overallQuality = totalProd > 0
            ? Math.min(100, Math.max(0, parseFloat(((totalProd - totalDefect) / totalProd * 100).toFixed(2))))
            : 100;
        const overallDefect = totalProd > 0
            ? Math.min(10, Math.max(0, parseFloat((totalDefect / totalProd * 100).toFixed(2))))
            : 0;
        window._qualityGaugeLastData = { overall: overallQuality, defect: overallDefect, lines: qualityLineData };
        drawQualityKPIGauge(overallQuality, overallDefect, qualityLineData);

    } catch (error) {
        console.error('Error updating quality cards:', error);
    }
}

// Fetch Cross-tab data
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
            updateCrossProcessTable(data.process_data);
            updateCrossModelTable(data.model_data);
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

// Update Cross-Process-Detail table
function updateCrossProcessTable(data) {
    if (!data || !data.details || !data.processes) {
        document.getElementById('crossProcessBody').innerHTML =
            '<tr><td colspan="9" class="text-center py-3">ไม่พบข้อมูล Cross-Process-Detail</td></tr>';
        document.getElementById('processDetailCount').textContent = 'Total 0 Details';
        return;
    }

    const headerRow = document.querySelector('#crossProcessTable thead tr');
    headerRow.innerHTML = '<th scope="col" class="px-6 py-2 text-left text-sm font-bold">Detail\\Process</th>';

    data.processes.forEach(process => {
        headerRow.innerHTML += `<th scope="col" class="px-6 py-2 text-center text-sm font-bold">${process}</th>`;
    });

    headerRow.innerHTML += `
        <th scope="col" class="px-6 py-2 text-center text-sm font-bold">รวมเสีย</th>
        <th scope="col" class="px-6 py-2 text-center text-sm font-bold">%</th>
    `;

    let tbody = '';
    let totalAll = 0;

    data.details.forEach(detail => {
        let detailTotal = 0;
        data.processes.forEach(process => {
            detailTotal += data.data[detail][process] || 0;
        });
        totalAll += detailTotal;
    });

    data.details.forEach((detail, index) => {
        const rowClass = index % 2 === 0 ? 'qt-row-even' : 'qt-row-odd';
        let rowTotal = 0;

        tbody += `<tr class="${rowClass}">
            <td class="px-6 py-2 text-sm text-left font-medium qt-label">${detail}</td>`;

        data.processes.forEach(process => {
            const value = data.data[detail][process] || 0;
            rowTotal += value;
            tbody += `<td class="px-6 py-2 text-sm text-center qt-value">${value > 0 ? `<span class="qt-num">${value}</span>` : '<span class="qt-dash">-</span>'}</td>`;
        });

        const percentage = totalAll > 0 ? ((rowTotal / totalAll) * 100).toFixed(1) : '0.0';
        tbody += `
            <td class="px-6 py-2 text-sm text-center qt-total">${rowTotal}</td>
            <td class="px-6 py-2 text-sm text-center qt-pct">${percentage}%</td>
        </tr>`;
    });

    tbody += '<tr class="qt-summary"><td class="px-6 py-2 text-sm font-bold">รวมเสีย(ชิ้น)</td>';

    const columnTotals = {};
    data.processes.forEach(process => {
        columnTotals[process] = 0;
        data.details.forEach(detail => {
            columnTotals[process] += data.data[detail][process] || 0;
        });
        tbody += `<td class="px-6 py-2 text-sm text-center font-bold">${columnTotals[process]}</td>`;
    });

    tbody += `
        <td class="px-6 py-2 text-sm text-center font-bold">${totalAll}</td>
        <td class="px-6 py-2 text-sm text-center font-bold">100%</td>
    </tr>`;

    document.getElementById('crossProcessBody').innerHTML = tbody;
    document.getElementById('processDetailCount').textContent = `Total ${data.details.length} Details`;
}

// Update Cross-Model-Detail table
function updateCrossModelTable(data) {
    if (!data || !data.details || !data.models) {
        document.getElementById('crossModelBody').innerHTML =
            '<tr><td colspan="9" class="text-center py-3">ไม่พบข้อมูล Cross-Model-Detail</td></tr>';
        document.getElementById('modelDetailCount').textContent = 'Total 0 Details';
        return;
    }

    const headerRow = document.querySelector('#crossModelTable thead tr');
    headerRow.innerHTML = '<th scope="col" class="px-6 py-2 text-left text-sm font-bold">Detail\\Model</th>';

    data.models.forEach(model => {
        headerRow.innerHTML += `<th scope="col" class="px-6 py-2 text-center text-sm font-bold">${model}</th>`;
    });

    headerRow.innerHTML += `
        <th scope="col" class="px-6 py-2 text-center text-sm font-bold">รวมเสีย</th>
        <th scope="col" class="px-6 py-2 text-center text-sm font-bold">%</th>
    `;

    let tbody = '';
    let totalAll = 0;

    data.details.forEach(detail => {
        let detailTotal = 0;
        data.models.forEach(model => {
            detailTotal += data.data[detail][model] || 0;
        });
        totalAll += detailTotal;
    });

    data.details.forEach((detail, index) => {
        const rowClass = index % 2 === 0 ? 'qt-row-even' : 'qt-row-odd';
        let rowTotal = 0;

        tbody += `<tr class="${rowClass}">
            <td class="px-6 py-2 text-sm text-left font-medium qt-label">${detail}</td>`;

        data.models.forEach(model => {
            const value = data.data[detail][model] || 0;
            rowTotal += value;
            tbody += `<td class="px-6 py-2 text-sm text-center qt-value">${value > 0 ? `<span class="qt-num">${value}</span>` : '<span class="qt-dash">-</span>'}</td>`;
        });

        const percentage = totalAll > 0 ? ((rowTotal / totalAll) * 100).toFixed(1) : '0.0';
        tbody += `
            <td class="px-6 py-2 text-sm text-center qt-total">${rowTotal}</td>
            <td class="px-6 py-2 text-sm text-center qt-pct">${percentage}%</td>
        </tr>`;
    });

    tbody += '<tr class="qt-summary"><td class="px-6 py-2 text-sm font-bold">รวมเสีย(ชิ้น)</td>';

    const columnTotals = {};
    data.models.forEach(model => {
        columnTotals[model] = 0;
        data.details.forEach(detail => {
            columnTotals[model] += data.data[detail][model] || 0;
        });
        tbody += `<td class="px-6 py-2 text-sm text-center font-bold">${columnTotals[model]}</td>`;
    });

    tbody += `
        <td class="px-6 py-2 text-sm text-center font-bold">${totalAll}</td>
        <td class="px-6 py-2 text-sm text-center font-bold">100%</td>
    </tr>`;

    document.getElementById('crossModelBody').innerHTML = tbody;
    document.getElementById('modelDetailCount').textContent = `Total ${data.details.length} Details`;
}

// Load performance data
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

// Update Performance KPIs
function updatePerformanceKPIs(kpis) {
    document.getElementById('overallEfficiency').textContent = `${kpis.overall_efficiency}%`;
    document.getElementById('qualityRate').textContent = `${kpis.quality_rate}%`;
    document.getElementById('productivityRate').textContent = `${kpis.productivity_rate}`;
    document.getElementById('defectRate').textContent = `${kpis.defect_rate}%`;

    updateKPIColors('overallEfficiency', kpis.overall_efficiency);
    updateKPIColors('qualityRate', kpis.quality_rate);
    updateKPIColors('productivityRate', kpis.productivity_rate);
    updateKPIColors('defectRate', kpis.defect_rate, true);

    const rate = parseFloat(kpis.productivity_rate) || 0;
    window._productivityLastRate = rate;
    if (window._gaugeLastData) {
        drawKPIGauge(window._gaugeLastData.overall, window._gaugeLastData.lines);
    }
}

// Update KPI element color
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

// Load and display production data
// KPI Trend Charts
let kpiTrendChart = null;
let kpiAvgTrendChart = null;

async function loadKpiTrend() {
    const startEl = document.getElementById('production_date_start');
    const endEl   = document.getElementById('production_date_end');
    const startDate = startEl ? startEl.value : '';
    const endDate   = endEl   ? endEl.value   : '';

    // ถ้าเลือกวันเดียว หรือไม่มีช่วง → ใช้ย้อนหลัง 30 วันจากวันที่เลือก
    let trendStart, trendEnd;
    if (!startDate || startDate === endDate) {
        const base = startDate ? new Date(startDate) : new Date();
        const from = new Date(base);
        from.setDate(from.getDate() - 29);
        trendStart = from.toISOString().split('T')[0];
        trendEnd   = base.toISOString().split('T')[0];
    } else {
        trendStart = startDate;
        trendEnd   = endDate;
    }

    // อัปเดต period label
    const label = document.getElementById('kpiTrendPeriodLabel');
    if (label) label.textContent = `${trendStart} → ${trendEnd}`;

    try {
        const resp = await fetch(`${API_BASE}?type=daily&start_date=${trendStart}&end_date=${trendEnd}&display_type=percentage`);
        const result = await resp.json();
        if (!result.success) return;

        const data = result.data;
        const labels = data.labels || [];

        const datasets = [
            { key: 'fc',    label: 'F/C',     color: '#28a745' },
            { key: 'fb',    label: 'F/B',     color: '#ffc107' },
            { key: 'rc',    label: 'R/C',     color: '#dc3545' },
            { key: 'rb',    label: 'R/B',     color: '#9b72cf' },
            { key: 'third', label: '3RD',     color: '#fd7e14' },
            { key: 'sub',   label: 'Sub',     color: '#20c997' },
        ].map(s => ({
            label: s.label,
            data: data[s.key] || [],
            borderColor: s.color,
            backgroundColor: s.color + '22',
            borderWidth: 2,
            pointRadius: labels.length > 60 ? 0 : 3,
            pointHoverRadius: 5,
            tension: 0.3,
            fill: false,
        }));

        const canvas = document.getElementById('kpiTrendChart');
        if (!canvas) return;

        if (kpiTrendChart) {
            // อัปเดต in-place ไม่ reset animation จาก 0
            kpiTrendChart.data.labels = labels;
            datasets.forEach((ds, i) => {
                if (kpiTrendChart.data.datasets[i]) {
                    kpiTrendChart.data.datasets[i].data = ds.data;
                } else {
                    kpiTrendChart.data.datasets.push(ds);
                }
            });
            // ตัดชุดข้อมูลเกินออก (ถ้า line หายไป)
            kpiTrendChart.data.datasets.splice(datasets.length);
            kpiTrendChart.update('none');
        } else {
            kpiTrendChart = new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { color: '#8896a8', boxWidth: 12, padding: 12 } },
                        datalabels: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y}%`
                            }
                        }
                    },
                    scales: {
                        x: { ticks: { color: '#8896a8', maxTicksLimit: 15 }, grid: { color: 'rgba(255,255,255,0.05)' } },
                        y: {
                            beginAtZero: false,
                            ticks: { color: '#8896a8', callback: v => v + '%' },
                            grid: { color: 'rgba(255,255,255,0.05)' }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });
        }
    } catch (e) {
        console.error('KPI Trend error:', e);
    }
}

async function loadKpiAvgTrend() {
    const startEl = document.getElementById('production_date_start');
    const endEl   = document.getElementById('production_date_end');
    const startDate = startEl ? startEl.value : '';
    const endDate   = endEl   ? endEl.value   : '';

    let trendStart, trendEnd;
    if (!startDate || startDate === endDate) {
        const base = startDate ? new Date(startDate) : new Date();
        const from = new Date(base);
        from.setDate(from.getDate() - 29);
        trendStart = from.toISOString().split('T')[0];
        trendEnd   = base.toISOString().split('T')[0];
    } else {
        trendStart = startDate;
        trendEnd   = endDate;
    }

    const label = document.getElementById('kpiAvgTrendPeriodLabel');
    if (label) label.textContent = `${trendStart} → ${trendEnd}`;

    try {
        const resp = await fetch(`${API_BASE}?type=daily&start_date=${trendStart}&end_date=${trendEnd}&display_type=percentage`);
        const result = await resp.json();
        if (!result.success) return;

        const data = result.data;
        const labels = data.labels || [];
        const keys = ['fc', 'fb', 'rc', 'rb', 'third', 'sub'];

        // คำนวณค่าเฉลี่ยทุกวัน
        const avgData = labels.map((_, i) => {
            const vals = keys.map(k => data[k]?.[i] ?? 0).filter(v => v > 0);
            return vals.length ? Math.round((vals.reduce((a, b) => a + b, 0) / vals.length) * 10) / 10 : 0;
        });

        const canvas = document.getElementById('kpiAvgTrendChart');
        if (!canvas) return;

        // สีตาม thresholds
        const pointColors = avgData.map(v =>
            v >= 101 ? '#5b8dee' : v >= 95 ? '#28a745' : v >= 85 ? '#ffc107' : '#dc3545'
        );

        const dataset = {
            label: 'Overall KPI %',
            data: avgData,
            borderColor: '#5b8dee',
            backgroundColor: avgData.map(v =>
                (v >= 101 ? '#5b8dee' : v >= 95 ? '#28a745' : v >= 85 ? '#ffc107' : '#dc3545') + '33'
            ),
            pointBackgroundColor: pointColors,
            pointRadius: labels.length > 60 ? 0 : 4,
            pointHoverRadius: 6,
            borderWidth: 2,
            tension: 0.3,
            fill: true,
        };

        if (kpiAvgTrendChart) {
            // อัปเดต in-place ไม่ reset animation จาก 0
            kpiAvgTrendChart.data.labels = labels;
            if (kpiAvgTrendChart.data.datasets[0]) {
                kpiAvgTrendChart.data.datasets[0].data = dataset.data;
                kpiAvgTrendChart.data.datasets[0].backgroundColor = dataset.backgroundColor;
                kpiAvgTrendChart.data.datasets[0].pointBackgroundColor = dataset.pointBackgroundColor;
            } else {
                kpiAvgTrendChart.data.datasets = [dataset];
            }
            kpiAvgTrendChart.update('none');
        } else {
            kpiAvgTrendChart = new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: { labels, datasets: [dataset] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            display: labels.length <= 31,
                            anchor: 'end',
                            align: 'top',
                            color: ctx => pointColors[ctx.dataIndex],
                            font: { size: 10, weight: 'bold' },
                            formatter: v => v + '%'
                        },
                        tooltip: {
                            callbacks: {
                                label: ctx => ` Overall: ${ctx.parsed.y}%`
                            }
                        },
                        annotation: {
                            annotations: {
                                line85: { type: 'line', yMin: 85, yMax: 85, borderColor: '#ffc10766', borderWidth: 1, borderDash: [4, 4] },
                                line95: { type: 'line', yMin: 95, yMax: 95, borderColor: '#28a74566', borderWidth: 1, borderDash: [4, 4] },
                                line100: { type: 'line', yMin: 100, yMax: 100, borderColor: '#5b8dee66', borderWidth: 1, borderDash: [4, 4] },
                            }
                        }
                    },
                    scales: {
                        x: { ticks: { color: '#8896a8', maxTicksLimit: 15 }, grid: { color: 'rgba(255,255,255,0.05)' } },
                        y: {
                            beginAtZero: false,
                            ticks: { color: '#8896a8', callback: v => v + '%' },
                            grid: { color: 'rgba(255,255,255,0.05)' }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });
        }
    } catch (e) {
        console.error('KPI Avg Trend error:', e);
    }
}

async function loadProductData() {
    try {
        const startDate = document.getElementById('production_date_start').value;
        const endDate = document.getElementById('production_date_end').value;
        const chartType = (startDate === endDate) ? 'hourly' : 'daily';

        const chartData = await fetchReportData(chartType);
        updateCharts(chartData);

        await updateSummary();
        await loadModelSummary();
        await Promise.all([loadKpiTrend(), loadKpiAvgTrend()]);

        // Fetch productivity rate (Output/Man-Hr) in background
        fetch(`api/get_performance.php?action=kpis&start_date=${startDate}&end_date=${endDate}`)
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    const rate = parseFloat(d.data.productivity_rate) || 0;
                    window._productivityLastRate = rate;
                    if (window._gaugeLastData) {
                        drawKPIGauge(window._gaugeLastData.overall, window._gaugeLastData.lines);
                    }
                }
            })
            .catch(() => {});

    } catch (error) {
        console.error('Error loading report data:', error);
    }
}

// Load and display quality data
async function loadQualityData() {
    try {
        await fetchQualityData();

        const start_date = document.getElementById('quality_date_start').value;
        const end_date = document.getElementById('quality_date_end').value;

        const response = await fetch(`api/get_defects_data.php?start_date=${start_date}&end_date=${end_date}`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        const data = await response.json();

        if (data.success) {
            await updateQualityCards(data);
            await fetchCrossTabData();
        } else {
            console.error('API Error:', data.message);
        }

    } catch (error) {
        console.error('Error loading report data:', error);
    }
}

// Real-time update
function startRealTimeUpdate() {
    const checkbox = document.getElementById('realTimeUpdate');

    if (checkbox.checked) {
        updateInterval = setInterval(async () => {
            await loadProductData();
        }, 30000);
    } else {
        if (updateInterval) {
            clearInterval(updateInterval);
        }
    }
}
