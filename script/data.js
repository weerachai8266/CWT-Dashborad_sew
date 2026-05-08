// ── HTML escape helper — prevents XSS from DB data rendered via innerHTML ──
function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ── API: Production (hourly / daily / summary / model_summary) ─────────────
async function fetchReportData(type = 'hourly') {
    const startDate = document.getElementById('production_date_start').value;
    const endDateEl = document.getElementById('production_date_end');
    const endDate   = endDateEl?.value || startDate;

    const params = new URLSearchParams({
        type,
        start_date:   startDate,
        end_date:     endDate,
        display_type: currentDisplayType,
    });

    try {
        showLoading(true);
        hideError();
        const response = await fetch(`${API_BASE}?${params}`);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const result = await response.json();
        if (!result.success) throw new Error(result.message || 'Unknown error occurred');
        return result.data;
    } catch (error) {
        console.error('API Error:', error);
        showError(error.message);
        throw error;
    } finally {
        showLoading(false);
    }
}

// ── API: Quality defects — fetch once, share with charts and cards ─────────
async function fetchDefectsData() {
    const params = new URLSearchParams({
        start_date: document.getElementById('quality_date_start').value,
        end_date:   document.getElementById('quality_date_end').value,
    });
    const response = await fetch(`api/get_defects_data.php?${params}`);
    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
    const data = await response.json();
    if (!data.success) throw new Error(data.message || 'Unknown error occurred');
    return data;
}

function drawQualityCharts(data) {
    if (!data.line_data.length && !data.problem_data.length && !data.model_data.length) return;
    createParetoChart('lineDefectsChart',    data.line_data.map(d => d.count),    data.line_data.map(d => d.process), 'Defects by Production Line');
    createParetoChart('problemDefectsChart', data.problem_data.map(d => d.count), data.problem_data.map(d => d.detail),  'Defects by Problem Type');
    createParetoChart('modelDefectsChart',   data.model_data.map(d => d.count),   data.model_data.map(d => d.model),   'Defects by Model');
    createTimelineChart(data.timeline_data);
}

// ── UI helpers ────────────────────────────────────────────────────────────
function updateProductionLastUpdate() {
    const el = document.getElementById('productionLastUpdateLabel');
    if (!el) return;
    el.textContent = 'Last update: ' + new Date().toLocaleTimeString('th-TH', {
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
}

// ── Summary cards (Production tab) ────────────────────────────────────────
async function updateSummary() {
    try {
        const summaryData = await fetchReportData('summary');

        Object.keys(summaryData).forEach(line => {
            const elementId    = line === 'third' ? 'total3RD'      : `total${line.toUpperCase()}`;
            const labelId      = line === 'third' ? 'label3RD'      : `label${line.toUpperCase()}`;
            const percentageId = line === 'third' ? 'percentage3RD' : `percentage${line.toUpperCase()}`;

            const element           = document.getElementById(elementId);
            const labelElement      = document.getElementById(labelId);
            const percentageElement = document.getElementById(percentageId);

            if (!element || !summaryData[line]) return;
            const data = summaryData[line];

            if (currentDisplayType === 'percentage') {
                element.textContent = data.percentage + '%';
                if (labelElement) labelElement.textContent = LINE_NAMES[line];

                if (percentageElement) {
                    percentageElement.textContent = `${data.total_qty} ชิ้น`;
                    percentageElement.className = `percentage-badge ${getPercentageClass(data.percentage)}`;
                    percentageElement.classList.remove('d-none');
                }

                element.className = '';
                const colorClass = getPercentageClass(data.percentage).replace('percentage-', '');
                if (colorClass === 'critical')  element.className = 'text-danger';
                else if (colorClass === 'warning')   element.className = 'text-warning';
                else if (colorClass === 'good')      element.className = 'text-success';
                else if (colorClass === 'excellent') element.className = 'text-primary';

                if (data.percentage < PERF_THRESHOLD_WARNING) {
                    element.parentElement.classList.add('critical-animation');
                    setTimeout(() => element.parentElement.classList.remove('critical-animation'), 500);
                } else if (data.percentage >= PERF_THRESHOLD_EXCELLENT) {
                    element.parentElement.classList.add('excellent-animation');
                    setTimeout(() => element.parentElement.classList.remove('excellent-animation'), 2000);
                }
            } else {
                element.textContent = (data.total_qty || 0) + ' ชิ้น';
                if (labelElement) labelElement.textContent = LINE_NAMES[line];

                if (percentageElement) {
                    percentageElement.textContent = `${data.percentage} %`;
                    percentageElement.classList.remove('d-none');
                }

                element.className = '';
                if (line === 'fc')      element.className = 'text-success';
                else if (line === 'fb') element.className = 'text-warning';
                else if (line === 'rc') element.className = 'text-danger';
                else                    element.style.color = CHART_COLORS[line];
            }

            element.style.transform = 'scale(1.1)';
            setTimeout(() => { element.style.transform = 'scale(1)'; }, 200);
        });

        // KPI Gauge
        const lineKeys  = ['fc', 'fb', 'rc', 'rb', 'third', 'sub'];
        const validPcts = lineKeys
            .filter(k => summaryData[k]?.percentage != null)
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

// ── Model Summary Table ────────────────────────────────────────────────────
async function loadModelSummary() {
    try {
        const date    = document.getElementById('production_date_start').value;
        const endDate = document.getElementById('production_date_end')?.value || date;
        const params  = new URLSearchParams({ type: 'model_summary', start_date: date, end_date: endDate });
        const response = await fetch(`${API_BASE}?${params}`);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const result = await response.json();
        if (!result.success) throw new Error(result.message || 'Unknown error occurred');
        updateModelSummaryTable(result.data);
    } catch (error) {
        console.error('Error loading model summary:', error);
        document.getElementById('modelSummaryBody').innerHTML =
            `<tr><td colspan="8" class="text-center text-danger">ไม่สามารถโหลดข้อมูลได้: ${escHtml(error.message)}</td></tr>`;
    }
}

function updateModelSummaryTable(data) {
    const tbody = document.getElementById('modelSummaryBody');
    const badge = document.getElementById('modelCountBadge');

    if (!data?.models?.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-3">ไม่พบข้อมูลโมเดลการผลิต</td></tr>';
        badge.textContent = 'Total 0 Models';
        return;
    }

    badge.textContent = `Total ${data.models.length} Models`;
    const fmt  = v => v > 0 ? `<span class="qt-num">${v.toLocaleString()}</span>` : '<span class="qt-dash">-</span>';
    const fmtB = v => v > 0 ? v.toLocaleString() : '-';

    let html = data.models.map((model, index) => {
        const rowClass = index % 2 === 0 ? 'qt-row-even' : 'qt-row-odd';
        return `<tr class="${rowClass}">
      <td class="px-6 py-2 whitespace-nowrap text-sm text-left qt-label">${escHtml(model.name)}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model.fc)}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model.fb)}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model.rc)}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model.rb)}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model['3rd'])}</td>
      <td class="px-6 py-2 whitespace-nowrap text-sm text-center qt-value">${fmt(model.sub)}</td>
    </tr>`;
    }).join('');

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

// ── Quality Summary Cards ──────────────────────────────────────────────────
async function updateQualityCards(defectsData) {
    try {
        const processData = {};
        defectsData.line_data.forEach(item => {
            const key = item.process.toLowerCase().replace('/', '');
            processData[key] = { total_qty: item.count };
        });

        const today      = new Date().toISOString().slice(0, 10);
        const start_date = document.getElementById('quality_date_start').value || today;
        const end_date   = document.getElementById('quality_date_end').value   || today;

        const params          = new URLSearchParams({ type: 'summary', start_date, end_date, display_type: 'pieces' });
        const productionResult = await fetch(`${API_BASE}?${params}`).then(r => r.json());
        const summaryData      = productionResult.success ? productionResult.data : {};

        const lines = ['fc', 'fb', 'rc', 'rb', '3rd', 'sub'];
        lines.forEach(line => {
            const elementId  = line === '3rd' ? 'quality3RD'         : `quality${line.toUpperCase()}`;
            const qualityId  = line === '3rd' ? 'percentagequality3RD' : `percentagequality${line.toUpperCase()}`;
            const element    = document.getElementById(elementId);
            const qualityEl  = document.getElementById(qualityId);
            if (!element) return;

            const lineKey        = line === '3rd' ? 'third' : line;
            const lineData       = processData[line]  || { total_qty: 0 };
            const productionData = summaryData[lineKey] || { total_qty: 0 };
            const defectPct      = productionData.total_qty > 0
                ? Number(((lineData.total_qty / productionData.total_qty) * 100).toFixed(2))
                : 0;

            element.textContent = lineData.total_qty;

            if (qualityEl) {
                qualityEl.textContent = `${defectPct.toFixed(2)} %`;
                qualityEl.classList.remove('d-none');
                if (defectPct <= DR_THRESHOLD)          qualityEl.className = 'percentage-badge percentage-good';
                else if (defectPct <= DR_WARN_THRESHOLD) qualityEl.className = 'percentage-badge percentage-warning';
                else                                     qualityEl.className = 'percentage-badge percentage-critical';
            }

            element.classList.add('pulse-animation');
            setTimeout(() => element.classList.remove('pulse-animation'), 500);
        });

        // Quality KPI Gauge
        const lineKeys      = ['fc', 'fb', 'rc', 'rb', 'third', 'sub'];
        const defectKeyMap  = { fc: 'fc', fb: 'fb', rc: 'rc', rb: 'rb', third: '3rd', sub: 'sub' };
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

// ── Cross-tab tables (generic — replaces two identical functions) ──────────
async function fetchCrossTabData() {
    const params = new URLSearchParams({
        start_date: document.getElementById('quality_date_start').value,
        end_date:   document.getElementById('quality_date_end').value,
    });
    try {
        const response = await fetch(`api/get_cross_tabs.php?${params}`);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Unknown error occurred');
        updateCrossTable(data.process_data, 'crossProcessTable', 'processDetailCount', 'processes', 'Process');
        updateCrossTable(data.model_data,   'crossModelTable',   'modelDetailCount',   'models',    'Model');
    } catch (error) {
        console.error('Error loading cross-tab data:', error);
        const msg = `<tr><td colspan="9" class="text-center text-danger">ไม่สามารถโหลดข้อมูลได้: ${escHtml(error.message)}</td></tr>`;
        document.getElementById('crossProcessBody').innerHTML = msg;
        document.getElementById('crossModelBody').innerHTML   = msg;
    }
}

function updateCrossTable(data, tableId, countId, colsKey, colLabel) {
    const tbody   = document.getElementById(tableId)?.querySelector('tbody') || document.getElementById(tableId.replace('Table', 'Body'));
    const countEl = document.getElementById(countId);

    if (!data?.details?.length || !data[colsKey]?.length) {
        if (tbody)   tbody.innerHTML = `<tr><td colspan="9" class="text-center py-3">ไม่พบข้อมูล</td></tr>`;
        if (countEl) countEl.textContent = 'Total 0 Details';
        return;
    }

    const cols    = data[colsKey];
    const details = data.details;

    // Header
    const headerRow = document.querySelector(`#${tableId} thead tr`);
    headerRow.innerHTML =
        `<th scope="col" class="px-6 py-2 text-left text-sm font-bold">Detail\\${colLabel}</th>` +
        cols.map(c => `<th scope="col" class="px-6 py-2 text-center text-sm font-bold">${escHtml(c)}</th>`).join('') +
        `<th scope="col" class="px-6 py-2 text-center text-sm font-bold">รวมเสีย</th>
         <th scope="col" class="px-6 py-2 text-center text-sm font-bold">%</th>`;

    // Grand total (for % column)
    let totalAll = 0;
    details.forEach(detail => {
        cols.forEach(col => { totalAll += data.data[detail]?.[col] || 0; });
    });

    // Body rows
    let html = details.map((detail, index) => {
        const rowClass = index % 2 === 0 ? 'qt-row-even' : 'qt-row-odd';
        let rowTotal = 0;
        const cells = cols.map(col => {
            const v = data.data[detail]?.[col] || 0;
            rowTotal += v;
            return `<td class="px-6 py-2 text-sm text-center qt-value">${v > 0 ? `<span class="qt-num">${v}</span>` : '<span class="qt-dash">-</span>'}</td>`;
        }).join('');
        const pct = totalAll > 0 ? ((rowTotal / totalAll) * 100).toFixed(1) : '0.0';
        return `<tr class="${rowClass}">
            <td class="px-6 py-2 text-sm text-left font-medium qt-label">${escHtml(detail)}</td>
            ${cells}
            <td class="px-6 py-2 text-sm text-center qt-total">${rowTotal}</td>
            <td class="px-6 py-2 text-sm text-center qt-pct">${pct}%</td>
        </tr>`;
    }).join('');

    // Summary row
    const colSums = cols.map(col => {
        const s = details.reduce((acc, d) => acc + (data.data[d]?.[col] || 0), 0);
        return `<td class="px-6 py-2 text-sm text-center font-bold">${s}</td>`;
    }).join('');
    html += `<tr class="qt-summary">
        <td class="px-6 py-2 text-sm font-bold">รวมเสีย(ชิ้น)</td>
        ${colSums}
        <td class="px-6 py-2 text-sm text-center font-bold">${totalAll}</td>
        <td class="px-6 py-2 text-sm text-center font-bold">100%</td>
    </tr>`;

    if (tbody) tbody.innerHTML = html;
    if (countEl) countEl.textContent = `Total ${details.length} Details`;
}

// ── KPI Trend Charts — fetch ONCE, draw both charts ───────────────────────
let kpiTrendChart    = null;
let kpiAvgTrendChart = null;

async function loadKpiTrends() {
    const startEl   = document.getElementById('production_date_start');
    const endEl     = document.getElementById('production_date_end');
    const startDate = startEl?.value || '';
    const endDate   = endEl?.value   || '';

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

    document.getElementById('kpiTrendPeriodLabel')?.setAttribute('data-range', `${trendStart} → ${trendEnd}`);
    const lblTrend  = document.getElementById('kpiTrendPeriodLabel');
    const lblAvg    = document.getElementById('kpiAvgTrendPeriodLabel');
    if (lblTrend) lblTrend.textContent = `${trendStart} → ${trendEnd}`;
    if (lblAvg)   lblAvg.textContent   = `${trendStart} → ${trendEnd}`;

    try {
        const params = new URLSearchParams({ type: 'daily', start_date: trendStart, end_date: trendEnd, display_type: 'percentage' });
        const resp   = await fetch(`${API_BASE}?${params}`);
        const result = await resp.json();
        if (!result.success) return;

        const data   = result.data;
        const labels = data.labels || [];

        _drawKpiTrendChart(labels, data);
        _drawKpiAvgTrendChart(labels, data);
    } catch (e) {
        console.error('KPI Trend error:', e);
    }
}

function _drawKpiTrendChart(labels, data) {
    const canvas = document.getElementById('kpiTrendChart');
    if (!canvas) return;

    const datasets = [
        { key: 'fc',    label: 'F/C', color: '#28a745' },
        { key: 'fb',    label: 'F/B', color: '#ffc107' },
        { key: 'rc',    label: 'R/C', color: '#dc3545' },
        { key: 'rb',    label: 'R/B', color: '#9b72cf' },
        { key: 'third', label: '3RD', color: '#fd7e14' },
        { key: 'sub',   label: 'Sub', color: '#20c997' },
    ].map(s => ({
        label: s.label, data: data[s.key] || [],
        borderColor: s.color, backgroundColor: s.color + '22',
        borderWidth: 2, tension: 0.3, fill: false,
        pointRadius: labels.length > 60 ? 0 : 3, pointHoverRadius: 5,
    }));

    if (kpiTrendChart) {
        kpiTrendChart.data.labels = labels;
        datasets.forEach((ds, i) => {
            if (kpiTrendChart.data.datasets[i]) kpiTrendChart.data.datasets[i].data = ds.data;
            else kpiTrendChart.data.datasets.push(ds);
        });
        kpiTrendChart.data.datasets.splice(datasets.length);
        kpiTrendChart.update('none');
    } else {
        kpiTrendChart = new Chart(canvas.getContext('2d'), {
            type: 'line', data: { labels, datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { color: '#8896a8', boxWidth: 12, padding: 12 } },
                    datalabels: { display: false },
                    tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y}%` } },
                },
                scales: {
                    x: { ticks: { color: '#8896a8', maxTicksLimit: 15 }, grid: { color: 'rgba(255,255,255,0.05)' } },
                    y: { beginAtZero: false, ticks: { color: '#8896a8', callback: v => v + '%' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                },
            },
            plugins: [ChartDataLabels],
        });
    }
}

function _drawKpiAvgTrendChart(labels, data) {
    const canvas = document.getElementById('kpiAvgTrendChart');
    if (!canvas) return;

    const keys    = ['fc', 'fb', 'rc', 'rb', 'third', 'sub'];
    const avgData = labels.map((_, i) => {
        const vals = keys.map(k => data[k]?.[i] ?? 0).filter(v => v > 0);
        return vals.length ? Math.round((vals.reduce((a, b) => a + b, 0) / vals.length) * 10) / 10 : 0;
    });

    const pointColors = avgData.map(v =>
        v >= PERF_THRESHOLD_EXCELLENT ? '#5b8dee'
        : v >= PERF_THRESHOLD_GOOD    ? '#28a745'
        : v >= PERF_THRESHOLD_WARNING ? '#ffc107'
        : '#dc3545'
    );

    const dataset = {
        label: 'Overall KPI %', data: avgData,
        borderColor: '#5b8dee',
        backgroundColor: avgData.map(v =>
            (v >= PERF_THRESHOLD_EXCELLENT ? '#5b8dee' : v >= PERF_THRESHOLD_GOOD ? '#28a745' : v >= PERF_THRESHOLD_WARNING ? '#ffc107' : '#dc3545') + '33'
        ),
        pointBackgroundColor: pointColors,
        pointRadius: labels.length > 60 ? 0 : 4, pointHoverRadius: 6,
        borderWidth: 2, tension: 0.3, fill: true,
    };

    if (kpiAvgTrendChart) {
        kpiAvgTrendChart.data.labels = labels;
        if (kpiAvgTrendChart.data.datasets[0]) {
            Object.assign(kpiAvgTrendChart.data.datasets[0], {
                data: dataset.data, backgroundColor: dataset.backgroundColor,
                pointBackgroundColor: dataset.pointBackgroundColor,
            });
        } else {
            kpiAvgTrendChart.data.datasets = [dataset];
        }
        kpiAvgTrendChart.update('none');
    } else {
        kpiAvgTrendChart = new Chart(canvas.getContext('2d'), {
            type: 'line', data: { labels, datasets: [dataset] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    datalabels: {
                        display: labels.length <= 31,
                        anchor: 'end', align: 'top',
                        color: ctx => pointColors[ctx.dataIndex],
                        font: { size: 10, weight: 'bold' },
                        formatter: v => v + '%',
                    },
                    tooltip: { callbacks: { label: ctx => ` Overall: ${ctx.parsed.y}%` } },
                    annotation: {
                        annotations: {
                            line85:  { type: 'line', yMin: PERF_THRESHOLD_WARNING,   yMax: PERF_THRESHOLD_WARNING,   borderColor: '#ffc10766', borderWidth: 1, borderDash: [4, 4] },
                            line95:  { type: 'line', yMin: PERF_THRESHOLD_GOOD,      yMax: PERF_THRESHOLD_GOOD,      borderColor: '#28a74566', borderWidth: 1, borderDash: [4, 4] },
                            line100: { type: 'line', yMin: PERF_THRESHOLD_EXCELLENT, yMax: PERF_THRESHOLD_EXCELLENT, borderColor: '#5b8dee66', borderWidth: 1, borderDash: [4, 4] },
                        },
                    },
                },
                scales: {
                    x: { ticks: { color: '#8896a8', maxTicksLimit: 15 }, grid: { color: 'rgba(255,255,255,0.05)' } },
                    y: { beginAtZero: false, ticks: { color: '#8896a8', callback: v => v + '%' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                },
            },
            plugins: [ChartDataLabels],
        });
    }
}

// ── Load Production Data ───────────────────────────────────────────────────
async function loadProductData() {
    try {
        const startDate = document.getElementById('production_date_start').value;
        const endDate   = document.getElementById('production_date_end').value;
        const chartType = startDate === endDate ? 'hourly' : 'daily';

        const dateLabel    = startDate === endDate ? startDate : `${startDate} → ${endDate}`;
        const summaryDateEl = document.getElementById('summaryDateLabel');
        const gaugeDateEl   = document.getElementById('gaugeDateLabel');
        if (summaryDateEl) summaryDateEl.textContent = dateLabel;
        if (gaugeDateEl)   gaugeDateEl.textContent   = dateLabel;

        const chartData = await fetchReportData(chartType);
        updateCharts(chartData);

        window._productivityLastRate = null;
        await updateSummary();
        await loadModelSummary();
        await loadKpiTrends(); // single fetch, draws both trend charts

        // Productivity rate in background
        const params = new URLSearchParams({ action: 'kpis', start_date: startDate, end_date: endDate });
        fetch(`api/get_performance.php?${params}`)
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    window._productivityLastRate = parseFloat(d.data.productivity_rate) || 0;
                    if (window._gaugeLastData) {
                        drawKPIGauge(window._gaugeLastData.overall, window._gaugeLastData.lines);
                        updateProductionLastUpdate();
                    }
                }
            })
            .catch(() => {});
    } catch (error) {
        console.error('Error loading report data:', error);
    }
}

// ── Monthly DR Chart ───────────────────────────────────────────────────────
let monthlyDrChart = null;
window._monthlyDrMonths = null;

const drTablePlugin = {
    id: 'drTablePlugin',
    afterDraw(chart) {
        if (chart.canvas.id !== 'monthlyDrChart') return;
        const months = window._monthlyDrMonths;
        if (!months || !chart.scales.x) return;
        const { ctx, scales } = chart;
        const axisBottom = scales.x.bottom;
        const rowH = 16;
        const y0 = axisBottom + rowH;
        const y1 = axisBottom + rowH * 2 + 2;
        const y2 = axisBottom + rowH * 3 + 4;

        const lx = scales.x.left - 4;
        ctx.textAlign = 'right';
        ctx.font = '9px sans-serif';
        ctx.fillStyle = '#6c757d';
        ctx.fillText('DR%',  lx, y0);
        ctx.fillText('เสีย', lx, y1);
        ctx.fillText('ผลิต', lx, y2);

        months.forEach((m, i) => {
            const x = scales.x.getPixelForTick(i);
            ctx.textAlign = 'center';
            const drColor = m.dr === null ? '#6c757d'
                : m.dr <= DR_THRESHOLD      ? '#28a745'
                : m.dr <= DR_WARN_THRESHOLD ? '#ffc107'
                : '#dc3545';
            ctx.fillStyle = drColor;
            ctx.font = 'bold 10px sans-serif';
            ctx.fillText(m.dr !== null ? m.dr.toFixed(2) + '%' : '-', x, y0);
            ctx.fillStyle = 'rgba(220,53,69,0.75)';
            ctx.font = '10px sans-serif';
            ctx.fillText(m.defect_qty > 0 ? m.defect_qty.toLocaleString() : '-', x, y1);
            ctx.fillStyle = '#8896a8';
            ctx.fillText(m.prod_qty   > 0 ? m.prod_qty.toLocaleString()   : '-', x, y2);
        });
    },
};

async function loadMonthlyDrChart() {
    const startDate = document.getElementById('quality_date_start')?.value;
    const year      = startDate ? new Date(startDate + 'T00:00:00').getFullYear() : new Date().getFullYear();
    const yearLabel = document.getElementById('monthlyDrYearLabel');
    if (yearLabel) yearLabel.textContent = year;

    document.querySelectorAll('.dr-threshold-label').forEach(el => { el.textContent = DR_THRESHOLD; });

    try {
        const params = new URLSearchParams({ year });
        const resp   = await fetch(`api/get_monthly_dr.php?${params}`);
        const result = await resp.json();
        if (!result.success) return;

        const months   = result.months;
        const labels   = months.map(m => m.label);
        const drValues = months.map(m => m.dr);

        const barBg = drValues.map(v =>
            v === null               ? 'rgba(100,100,100,0.18)'
            : v <= DR_THRESHOLD      ? 'rgba(40,167,69,0.38)'
            : v <= DR_WARN_THRESHOLD ? 'rgba(255,193,7,0.38)'
            :                          'rgba(220,53,69,0.38)'
        );
        const barBorder     = barBg.map(c => c.replace(/[\d.]+\)$/, '1)'));
        const thresholdLine = labels.map(() => DR_THRESHOLD);
        const validDr       = drValues.filter(v => v !== null);
        const avgDr         = validDr.length
            ? Math.round(validDr.reduce((a, b) => a + b, 0) / validDr.length * 100) / 100
            : null;
        const avgLine  = labels.map(() => avgDr);
        const avgColor = avgDr === null ? '#8896a8'
            : avgDr <= DR_THRESHOLD      ? '#28a745'
            : avgDr <= DR_WARN_THRESHOLD ? '#ff6207'
            : '#dc3545';

        const datasets = [
            { type: 'bar',  label: 'Defect Rate (%)', data: drValues, backgroundColor: barBg, borderColor: barBorder, borderWidth: 2, borderRadius: 4, order: 2 },
            { type: 'line', label: `Target ${DR_THRESHOLD}%`,  data: thresholdLine, borderColor: '#ff6207', borderWidth: 2, borderDash: [6, 4], pointRadius: 0, pointHoverRadius: 0, fill: false, order: 1 },
            { type: 'line', label: avgDr !== null ? `เฉลี่ย ${avgDr.toFixed(2)}%` : 'เฉลี่ย', data: avgLine, borderColor: avgColor, borderWidth: 2, borderDash: [3, 3], pointRadius: 0, pointHoverRadius: 0, fill: false, order: 1 },
        ];

        window._monthlyDrMonths = months;
        const canvas = document.getElementById('monthlyDrChart');
        if (!canvas) return;

        if (monthlyDrChart) {
            monthlyDrChart.data.labels = labels;
            Object.assign(monthlyDrChart.data.datasets[0], { data: drValues, backgroundColor: barBg, borderColor: barBorder });
            monthlyDrChart.data.datasets[1].data  = thresholdLine;
            monthlyDrChart.data.datasets[1].label = `Target ${DR_THRESHOLD}%`;
            Object.assign(monthlyDrChart.data.datasets[2], { data: avgLine, label: avgDr !== null ? `เฉลี่ย ${avgDr.toFixed(2)}%` : 'เฉลี่ย', borderColor: avgColor });
            monthlyDrChart.update('none');
        } else {
            monthlyDrChart = new Chart(canvas.getContext('2d'), {
                type: 'bar', data: { labels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', align: 'center', labels: { color: '#8896a8', boxWidth: 14, padding: 14 } },
                        datalabels: {
                            display: ctx => ctx.datasetIndex === 0 && ctx.dataset.data[ctx.dataIndex] !== null,
                            anchor: 'start', align: 'end', offset: 4,
                            color: '#ffffff', font: { size: 11, weight: 'bold' },
                            formatter: v => v === null ? '' : v.toFixed(2) + '%',
                        },
                        tooltip: {
                            callbacks: {
                                label: ctx => {
                                    if (ctx.datasetIndex === 1) return ` Target: ${DR_THRESHOLD}%`;
                                    if (ctx.datasetIndex === 2) return avgDr !== null ? ` เฉลี่ย: ${avgDr.toFixed(2)}%` : ' เฉลี่ย: -';
                                    const m = months[ctx.dataIndex];
                                    return [
                                        ` DR: ${m.dr !== null ? m.dr.toFixed(2) + '%' : 'ไม่มีข้อมูล'}`,
                                        ` ของเสีย: ${m.defect_qty.toLocaleString()} ชิ้น`,
                                        ` ผลผลิต: ${m.prod_qty.toLocaleString()} ชิ้น`,
                                    ];
                                },
                            },
                        },
                    },
                    layout: { padding: { bottom: 58 } },
                    scales: {
                        x: { ticks: { color: '#8896a8' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                        y: { beginAtZero: true, max: 2.5, ticks: { color: '#8896a8', callback: v => v + '%' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                    },
                },
                plugins: [ChartDataLabels, drTablePlugin],
            });
        }
    } catch (e) {
        console.error('Monthly DR chart error:', e);
    }
}

// ── Load Quality Data — single defects fetch ───────────────────────────────
async function loadQualityData() {
    try {
        showQualityLoading(true);
        hideQualityError();

        const data = await fetchDefectsData();

        drawQualityCharts(data);
        await updateQualityCards(data);
        await fetchCrossTabData();
        await loadMonthlyDrChart();
    } catch (error) {
        console.error('Error loading quality data:', error);
        showQualityError(error.message);
    } finally {
        showQualityLoading(false);
    }
}

// ── Real-time auto-update ──────────────────────────────────────────────────
function startRealTimeUpdate() {
    const checkbox = document.getElementById('realTimeUpdate');
    if (checkbox.checked) {
        updateInterval = setInterval(() => loadProductData(), 30000);
    } else {
        if (updateInterval) clearInterval(updateInterval);
    }
}
