// ==================== KPI Speedometer Gauge ====================
function drawKPIGauge(overallPct, lineData) {
    const canvas = document.getElementById('kpiGaugeCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;

    // Responsive canvas sizing
    const cssW = canvas.parentElement.clientWidth || 480;
    const isMobile = cssW < 560;
    const rawH  = isMobile ? Math.round(cssW * 1.05) : Math.round(cssW * 0.34);
    const cssH  = Math.min(rawH, isMobile ? 420 : 280);
    canvas.style.height = cssH + 'px';
    canvas.width  = Math.round(cssW * dpr);
    canvas.height = Math.round(cssH * dpr);
    ctx.scale(dpr, dpr);

    const W = cssW, H = cssH;
    const prodRate = window._productivityLastRate ?? 0;
    const hasProductivity = true;

    // ── helper: draw one half-circle speedometer ──────────────
    function drawSpeedometer(cx, cy, R, arcW, value, MAX, segments, ticks, centerLines, badge) {
        const toA = v => Math.PI * (1 + v / MAX);

        // Background track
        ctx.beginPath();
        ctx.arc(cx, cy, R, Math.PI, 0, false);
        ctx.strokeStyle = 'rgba(255,255,255,0.07)';
        ctx.lineWidth = arcW + 8;
        ctx.stroke();

        // Colored arc segments
        segments.forEach(s => {
            ctx.beginPath();
            ctx.arc(cx, cy, R, toA(s.from), toA(s.to), false);
            ctx.strokeStyle = s.color;
            ctx.lineWidth = arcW;
            ctx.lineCap = 'butt';
            ctx.stroke();
        });

        // Tick marks & labels
        const tickFontSize = Math.max(9, Math.round(R * 0.08));
        ticks.forEach(t => {
            const a = toA(t.val);
            const cos = Math.cos(a), sin = Math.sin(a);
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
            ctx.fillText(t.label, cx + lR * cos, cy + lR * sin);
        });

        // Needle
        const clamped = Math.min(Math.max(value, 0), MAX);
        const needleA = toA(clamped);
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
        ctx.beginPath();
        ctx.arc(cx, cy, 10, 0, Math.PI * 2);
        ctx.fillStyle = '#2a2f3a';
        ctx.fill();
        ctx.strokeStyle = '#e2e8f0';
        ctx.lineWidth = 2.5;
        ctx.stroke();
        ctx.restore();

        // Center text lines
        centerLines.forEach(line => {
            ctx.textAlign = 'center';
            ctx.fillStyle = line.color;
            ctx.font = `${line.bold ? 'bold ' : ''}${line.size}px Poppins, sans-serif`;
            ctx.textBaseline = line.baseline || 'middle';
            ctx.fillText(line.text, cx, line.y);
        });

        // Badge update
        if (badge) {
            const el = document.getElementById(badge.id);
            if (el) {
                el.textContent = badge.text;
                el.className = 'badge fs-6 px-3 py-1 ' + badge.cls;
            }
        }
    }

    // ── KPI gauge parameters ───────────────────────────────────
    // ค่า threshold ต่างๆ ถูกกำหนดใน config.js และนำมาใช้ที่นี่เพื่อความสอดคล้องกัน
    const kpiMAX = 120;
    const kpiSegs = [
        { from: 0,                       to: PERF_THRESHOLD_WARNING,   color: PERCENTAGE_COLORS.critical  },
        { from: PERF_THRESHOLD_WARNING,  to: PERF_THRESHOLD_GOOD,      color: PERCENTAGE_COLORS.warning   },
        { from: PERF_THRESHOLD_GOOD,     to: PERF_THRESHOLD_EXCELLENT, color: PERCENTAGE_COLORS.good      },
        { from: PERF_THRESHOLD_EXCELLENT, to: kpiMAX,                  color: PERCENTAGE_COLORS.excellent },
    ];
    const kpiTicks = [0, PERF_THRESHOLD_WARNING, PERF_THRESHOLD_GOOD, PERF_THRESHOLD_EXCELLENT, kpiMAX];

    const kpiClamped = Math.min(Math.max(overallPct, 0), kpiMAX);
    const kpiColor   = getColorByPercentage(kpiClamped);
    const kpiStatus  = kpiClamped >= PERF_THRESHOLD_EXCELLENT ? 'Excellent'
                     : kpiClamped >= PERF_THRESHOLD_GOOD      ? 'Good'
                     : kpiClamped >= PERF_THRESHOLD_WARNING   ? 'Warning'
                     :                                          'Critical';
    const kpiBadgeClass = kpiClamped >= PERF_THRESHOLD_EXCELLENT ? 'bg-primary'
                        : kpiClamped >= PERF_THRESHOLD_GOOD      ? 'bg-success'
                        : kpiClamped >= PERF_THRESHOLD_WARNING   ? 'bg-warning text-dark'
                        :                                          'bg-danger';

    // ── Productivity gauge parameters ─────────────────────────
    // ค่า threshold ต่างๆ ถูกกำหนดใน config.js และนำมาใช้ที่นี่เพื่อความสอดคล้องกัน
    const prodMAX  = PROD_GAUGE_MAX;
    const prodSegs = [
        { from: 0,                      to: PROD_THRESHOLD_WARNING,   color: PERCENTAGE_COLORS.critical  },
        { from: PROD_THRESHOLD_WARNING, to: PROD_THRESHOLD_GOOD,      color: PERCENTAGE_COLORS.warning   },
        { from: PROD_THRESHOLD_GOOD,    to: PROD_THRESHOLD_EXCELLENT, color: PERCENTAGE_COLORS.good      },
        { from: PROD_THRESHOLD_EXCELLENT, to: prodMAX,                color: PERCENTAGE_COLORS.excellent },
    ];
    const prodTicks = [0, PROD_THRESHOLD_WARNING, PROD_THRESHOLD_GOOD, PROD_THRESHOLD_EXCELLENT, prodMAX];
    const prodVal   = Math.min(prodRate || 0, prodMAX);
    const prodClamped = Math.min(Math.max(prodVal, 0), prodMAX);
    const prodColor   = prodClamped >= PROD_THRESHOLD_EXCELLENT ? PERCENTAGE_COLORS.excellent
                      : prodClamped >= PROD_THRESHOLD_GOOD      ? PERCENTAGE_COLORS.good
                      : prodClamped >= PROD_THRESHOLD_WARNING   ? PERCENTAGE_COLORS.warning
                      :                                          PERCENTAGE_COLORS.critical;
    const prodStatus  = prodClamped >= PROD_THRESHOLD_EXCELLENT ? 'Excellent'
                      : prodClamped >= PROD_THRESHOLD_GOOD      ? 'Good'
                      : prodClamped >= PROD_THRESHOLD_WARNING   ? 'Warning'
                      :                                          'Critical';
    const prodBadgeClass = prodClamped >= PROD_THRESHOLD_EXCELLENT ? 'bg-primary'
                         : prodClamped >= PROD_THRESHOLD_GOOD      ? 'bg-success'
                         : prodClamped >= PROD_THRESHOLD_WARNING   ? 'bg-warning text-dark'
                         :                                          'bg-danger';

    if (isMobile) {
        // ── Mobile: KPI gauge top half, productivity below, bars at bottom ──
        const halfH  = Math.round(H * (hasProductivity ? 0.38 : 0.55));
        const kpiCX  = W / 2;
        const kpiCY  = halfH * 0.84;
        const p2Y  = halfH + Math.round((H - halfH) * 0.5);
        const gaugeR = hasProductivity
            ? Math.min((kpiCX - 18) / 1.22, kpiCY * 0.72, (H - halfH) * 0.38)
            : Math.min((kpiCX - 18) / 1.22, kpiCY * 0.72);
        const kpiR   = gaugeR;
        const kpiAW  = Math.round(kpiR * 0.40);
        const valFS  = Math.max(18, Math.round(kpiR * 0.20));
        const lblFS  = Math.max(9,  Math.round(kpiR * 0.085));

        drawSpeedometer(kpiCX, kpiCY, kpiR, kpiAW, overallPct, kpiMAX, kpiSegs,
            kpiTicks.map(v => ({ val: v, label: v + '%' })),
            [
                { text: overallPct.toFixed(1) + '%', color: kpiColor, size: valFS, bold: true, baseline: 'bottom', y: kpiCY - kpiR * 0.28 },
                { text: 'Overall KPI',               color: '#8896a8', size: lblFS, baseline: 'top', y: kpiCY + 14 },
            ],
            { id: 'kpiGaugeLabel', text: overallPct.toFixed(1) + '% — ' + kpiStatus,
              cls: kpiBadgeClass }
        );

        if (hasProductivity) {
            const p2R  = gaugeR;
            const p2AW = Math.round(p2R * 0.40);
            const p2VFS = Math.max(14, Math.round(p2R * 0.20));
            const p2LFS = Math.max(8,  Math.round(p2R * 0.082));
            drawSpeedometer(kpiCX, p2Y, p2R, p2AW, prodVal, prodMAX, prodSegs,
                prodTicks.map(v => ({ val: v, label: String(v) })),
                [
                    { text: prodVal.toFixed(2),   color: prodColor, size: p2VFS, bold: true, baseline: 'bottom', y: p2Y - p2R * 0.28 },
                    { text: 'Productivity',         color: '#8896a8', size: p2LFS, baseline: 'top', y: p2Y + 12 },
                ],
                { id: 'productivityGaugeLabel', text: '⚡ ' + prodVal.toFixed(2) + ' — ' + prodStatus,
                  cls: prodBadgeClass }
            );
        }

        // Per-line bars (bottom strip)
        if (!lineData) return;
        const lines   = ['fc', 'fb', 'rc', 'rb', 'third', 'sub'];
        const lColors = { fc: '#28a745', fb: '#ffc107', rc: '#dc3545', rb: '#8b5cf6', third: '#fd7e14', sub: '#20c997' };
        const lNames  = { fc: 'F/C', fb: 'F/B', rc: 'R/C', rb: 'R/B', third: '3RD', sub: 'Sub' };
        const barH    = Math.max(7, Math.round(kpiR * 0.07));
        const startX  = W * 0.04, rowW = W * 0.92;
        const itemW   = rowW / lines.length;
        const barY    = H - barH - 22;

        lines.forEach((key, i) => {
            const d = lineData[key]; if (!d) return;
            const pct = Math.min(d.percentage || 0, kpiMAX);
            const x = startX + i * itemW + itemW * 0.08;
            const bw = itemW * 0.84;
            ctx.fillStyle = 'rgba(255,255,255,0.06)';
            ctx.beginPath(); ctx.roundRect(x, barY, bw, barH, 3); ctx.fill();
            ctx.fillStyle = lColors[key];
            ctx.beginPath(); ctx.roundRect(x, barY, bw * (pct / kpiMAX), barH, 3); ctx.fill();
            ctx.fillStyle = lColors[key];
            ctx.font = `bold ${Math.max(9, Math.round(kpiR * 0.08))}px Poppins, sans-serif`;
            ctx.textAlign = 'center'; ctx.textBaseline = 'top';
            ctx.fillText(lNames[key], x + bw / 2, barY + barH + 3);
            ctx.fillStyle = '#8896a8';
            ctx.font = `${Math.max(8, Math.round(kpiR * 0.07))}px Poppins, sans-serif`;
            ctx.fillText((d.percentage || 0) + '%', x + bw / 2, barY + barH + 15);
        });

    } else {
        // ── Desktop: KPI gauge left, Productivity center, per-line bars right ──
        const gaugeZoneW = hasProductivity ? W * 0.32 : W * 0.64;
        const kpiZoneW  = gaugeZoneW;
        const prodZoneW = hasProductivity ? gaugeZoneW : 0;
        const barZoneX  = kpiZoneW + prodZoneW;
        const barZoneW  = W - barZoneX;
        const sharedGaugeR = Math.min((gaugeZoneW / 2 - 20) / 1.22, H * 0.88 * 0.72);

        // KPI gauge
        const kpiCX = kpiZoneW / 2;
        const kpiCY = H * 0.88;
        const kpiR  = sharedGaugeR;
        const kpiAW = Math.round(kpiR * 0.40);
        const valFS = Math.max(20, Math.round(kpiR * 0.20));
        const lblFS = Math.max(10, Math.round(kpiR * 0.085));

        drawSpeedometer(kpiCX, kpiCY, kpiR, kpiAW, overallPct, kpiMAX, kpiSegs,
            kpiTicks.map(v => ({ val: v, label: v + '%' })),
            [
                { text: overallPct.toFixed(1) + '%', color: kpiColor, size: valFS, bold: true, baseline: 'bottom', y: kpiCY - kpiR * 0.28 },
                { text: 'Overall KPI',               color: '#8896a8', size: lblFS, baseline: 'top', y: kpiCY + 16 },
            ],
            { id: 'kpiGaugeLabel', text: overallPct.toFixed(1) + '% — ' + kpiStatus,
              cls: kpiBadgeClass }
        );

        // Productivity gauge (center zone)
        if (hasProductivity) {
            const pCX  = kpiZoneW + prodZoneW / 2;
            const pCY  = H * 0.88;
            const pR   = sharedGaugeR;
            const pAW  = Math.round(pR * 0.40);
            const pVFS = Math.max(16, Math.round(pR * 0.20));
            const pUFS = Math.max(9,  Math.round(pR * 0.09));
            const pLFS = Math.max(9,  Math.round(pR * 0.082));

            // vertical divider
            ctx.beginPath();
            ctx.moveTo(kpiZoneW, H * 0.06);
            ctx.lineTo(kpiZoneW, H * 0.96);
            ctx.strokeStyle = 'rgba(255,255,255,0.05)';
            ctx.lineWidth = 1;
            ctx.stroke();

            drawSpeedometer(pCX, pCY, pR, pAW, prodVal, prodMAX, prodSegs,
                prodTicks.map(v => ({ val: v, label: String(v) })),
                [
                    { text: prodVal.toFixed(2),   color: prodColor, size: pVFS, bold: true, baseline: 'bottom', y: pCY - pR * 0.28 },
                    { text: 'Productivity',         color: '#8896a8', size: pLFS, baseline: 'top',   y: pCY + 14 },
                ],
                { id: 'productivityGaugeLabel', text: '⚡ ' + prodVal.toFixed(2) + ' — ' + prodStatus,
                  cls: prodBadgeClass }
            );
        }

        // Per-line bars (right zone)
        if (!lineData) return;
        const lines    = ['fc', 'fb', 'rc', 'rb', 'third', 'sub'];
        const lColors  = { fc: '#28a745', fb: '#ffc107', rc: '#dc3545', rb: '#8b5cf6', third: '#fd7e14', sub: '#20c997' };
        const lNames   = { fc: 'F/C', fb: 'F/B', rc: 'R/C', rb: 'R/B', third: '3RD', sub: 'Sub' };
        const barH     = Math.max(8, Math.round(H * 0.048));
        const fontSize = Math.max(10, Math.round(H * 0.044));
        const titleH   = fontSize + 6;
        const usableH  = H - titleH - 16;
        const spacing  = Math.floor(usableH / lines.length);
        const startY   = titleH + 8;
        const panelX   = barZoneX + 8;
        const labelW   = 36;
        const pctColW  = Math.max(28, Math.round(fontSize * 2.6));
        const barX0    = panelX + labelW;
        const bw       = W - barX0 - pctColW - 6;

        // divider before bars
        ctx.beginPath();
        ctx.moveTo(barZoneX, H * 0.06);
        ctx.lineTo(barZoneX, H * 0.96);
        ctx.strokeStyle = 'rgba(255,255,255,0.05)';
        ctx.lineWidth = 1;
        ctx.stroke();

        ctx.fillStyle = '#8896a8';
        ctx.font = `${fontSize}px Poppins, sans-serif`;
        ctx.textAlign = 'left'; ctx.textBaseline = 'top';
        ctx.fillText('Per-Line Achievement', panelX, 6);

        lines.forEach((key, i) => {
            const d = lineData[key]; if (!d) return;
            const pct = Math.min(d.percentage || 0, kpiMAX);
            const y   = startY + i * spacing + (spacing - barH) / 2;

            ctx.fillStyle = lColors[key];
            ctx.font = `bold ${fontSize}px Poppins, sans-serif`;
            ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
            ctx.fillText(lNames[key], panelX, y + barH / 2);

            const barX = barX0;
            ctx.fillStyle = 'rgba(255,255,255,0.06)';
            ctx.beginPath(); ctx.roundRect(barX, y, bw, barH, 4); ctx.fill();
            ctx.fillStyle = lColors[key];
            ctx.globalAlpha = 0.85;
            ctx.beginPath(); ctx.roundRect(barX, y, bw * (pct / kpiMAX), barH, 4); ctx.fill();
            ctx.globalAlpha = 1;

            ctx.fillStyle = '#c8d4e0';
            ctx.font = `${fontSize}px Poppins, sans-serif`;
            ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
            ctx.fillText((d.percentage || 0) + '%', W - 4, y + barH / 2);
        });
    }
}

// Redraw gauges on window resize
window.addEventListener('resize', () => {
    if (window._gaugeLastData) {
        drawKPIGauge(window._gaugeLastData.overall, window._gaugeLastData.lines);
    }
    if (window._qualityGaugeLastData) {
        drawQualityKPIGauge(window._qualityGaugeLastData.overall, window._qualityGaugeLastData.defect, window._qualityGaugeLastData.lines);
    }
});

// ==================== Quality KPI Gauge ====================
function drawQualityKPIGauge(overallQuality, overallDefect, lineData) {
    const canvas = document.getElementById('qualityKpiGaugeCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;

    const cssW = canvas.parentElement.clientWidth || 480;
    const isMobile = cssW < 560;
    const rawH  = isMobile ? Math.round(cssW * 1.05) : Math.round(cssW * 0.34);
    const cssH  = Math.min(rawH, isMobile ? 420 : 280);
    canvas.style.height = cssH + 'px';
    canvas.width  = Math.round(cssW * dpr);
    canvas.height = Math.round(cssH * dpr);
    ctx.scale(dpr, dpr);

    const W = cssW, H = cssH;

    // ── helper: draw one half-circle speedometer ──────────────
    function drawSpeedometer(cx, cy, R, arcW, value, MAX, MIN, segments, ticks, centerLines, badge) {
        const toA = v => Math.PI * (1 + (v - MIN) / (MAX - MIN));
        ctx.beginPath();
        ctx.arc(cx, cy, R, Math.PI, 0, false);
        ctx.strokeStyle = 'rgba(255,255,255,0.07)';
        ctx.lineWidth = arcW + 8;
        ctx.stroke();
        segments.forEach(s => {
            ctx.beginPath();
            ctx.arc(cx, cy, R, toA(s.from), toA(s.to), false);
            ctx.strokeStyle = s.color;
            ctx.lineWidth = arcW;
            ctx.lineCap = 'butt';
            ctx.stroke();
        });
        const tickFontSize = Math.max(9, Math.round(R * 0.08));
        ticks.forEach(t => {
            const a = toA(t.val);
            const cos = Math.cos(a), sin = Math.sin(a);
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
            ctx.fillText(t.label, cx + lR * cos, cy + lR * sin);
        });
        const clamped = Math.min(Math.max(value, MIN), MAX);
        const needleA = toA(clamped);
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
        ctx.beginPath();
        ctx.arc(cx, cy, 10, 0, Math.PI * 2);
        ctx.fillStyle = '#2a2f3a';
        ctx.fill();
        ctx.strokeStyle = '#e2e8f0';
        ctx.lineWidth = 2.5;
        ctx.stroke();
        ctx.restore();
        centerLines.forEach(line => {
            ctx.textAlign = 'center';
            ctx.fillStyle = line.color;
            ctx.font = `${line.bold ? 'bold ' : ''}${line.size}px Poppins, sans-serif`;
            ctx.textBaseline = line.baseline || 'middle';
            ctx.fillText(line.text, cx, line.y);
        });
        if (badge) {
            const el = document.getElementById(badge.id);
            if (el) {
                el.textContent = badge.text;
                el.className = 'badge fs-6 px-3 py-1 ' + badge.cls;
            }
        }
    }

    // ── Quality Rate gauge parameters ─────────────────────────
    const qMIN   = 85;
    const qMAX   = 100;
    const qSegs  = [
        { from: qMIN,              to: QR_WARN_THRESHOLD, color: PERCENTAGE_COLORS.critical },
        { from: QR_WARN_THRESHOLD, to: QR_GOOD_THRESHOLD, color: PERCENTAGE_COLORS.warning  },
        { from: QR_GOOD_THRESHOLD, to: 100,               color: PERCENTAGE_COLORS.good     },
    ];
    const qTicks = [
        { val: qMIN,               label: qMIN + '%' },
        { val: QR_WARN_THRESHOLD,  label: QR_WARN_THRESHOLD + '%' },
        { val: QR_GOOD_THRESHOLD,  label: QR_GOOD_THRESHOLD + '%' },
        { val: 100,                label: '100%' },
    ];
    const qClamped = Math.min(Math.max(overallQuality, 0), qMAX);
    const qColor   = qClamped >= QR_GOOD_THRESHOLD ? PERCENTAGE_COLORS.good
                   : qClamped >= QR_WARN_THRESHOLD  ? PERCENTAGE_COLORS.warning
                   :                                  PERCENTAGE_COLORS.critical;
    const qStatus  = qClamped >= QR_GOOD_THRESHOLD ? 'Good'
                   : qClamped >= QR_WARN_THRESHOLD  ? 'Warning' : 'Critical';

    // ── Defect Rate gauge parameters ──────────────────────────
    const dMAX   = 2.5;
    const dSegs  = [
        { from: 0,                to: DR_THRESHOLD,      color: PERCENTAGE_COLORS.good     },
        { from: DR_THRESHOLD,      to: DR_WARN_THRESHOLD, color: PERCENTAGE_COLORS.warning  },
        { from: DR_WARN_THRESHOLD, to: dMAX,              color: PERCENTAGE_COLORS.critical },
    ];
    const dTicks   = [0, DR_THRESHOLD, DR_WARN_THRESHOLD, dMAX];
    const dVal     = Math.min(overallDefect || 0, dMAX);
    const dClamped = dVal;
    const defColor = dClamped <= DR_THRESHOLD      ? PERCENTAGE_COLORS.good
                   : dClamped <= DR_WARN_THRESHOLD  ? PERCENTAGE_COLORS.warning
                   :                                  PERCENTAGE_COLORS.critical;
    const dStatus  = dClamped <= DR_THRESHOLD      ? 'Good'
                   : dClamped <= DR_WARN_THRESHOLD  ? 'Warning' : 'Critical';
    const defBadgeClass = dClamped <= DR_THRESHOLD      ? 'bg-success'
                        : dClamped <= DR_WARN_THRESHOLD ? 'bg-warning text-dark'
                        :                                 'bg-danger';

    if (isMobile) {
        // ── Mobile: Quality top, Defect center, bars bottom ──
        const halfH  = Math.round(H * 0.38);
        const qCX = W / 2, qCY = halfH * 0.84;
        const p2Y  = halfH + Math.round((H - halfH) * 0.5);
        const sharedGaugeR = Math.min((qCX - 18) / 1.22, qCY * 0.72, (H - halfH) * 0.38);
        const qR  = sharedGaugeR;
        const qAW = Math.round(qR * 0.40);
        drawSpeedometer(qCX, qCY, qR, qAW, overallQuality, qMAX, qMIN, qSegs,
            qTicks,
            [
                { text: overallQuality.toFixed(1) + '%', color: qColor, size: Math.max(18, Math.round(qR * 0.20)), bold: true, baseline: 'bottom', y: qCY - qR * 0.28 },
                { text: 'Quality Rate', color: '#8896a8', size: Math.max(9, Math.round(qR * 0.085)), baseline: 'top', y: qCY + 14 },
            ],
            { id: 'qualityKpiGaugeLabel', text: overallQuality.toFixed(1) + '% — ' + qStatus,
              cls: qClamped >= QR_GOOD_THRESHOLD ? 'bg-success' : qClamped >= QR_WARN_THRESHOLD ? 'bg-warning text-dark' : 'bg-danger' }
        );

        const p2R  = sharedGaugeR;
        const p2AW = Math.round(p2R * 0.40);
        drawSpeedometer(qCX, p2Y, p2R, p2AW, dVal, dMAX, 0, dSegs,
            dTicks.map(v => ({ val: v, label: v + '%' })),
            [
                { text: dVal.toFixed(2) + '%', color: defColor, size: Math.max(14, Math.round(p2R * 0.20)), bold: true, baseline: 'bottom', y: p2Y - p2R * 0.28 },
                { text: 'Defect Rate', color: '#8896a8', size: Math.max(8, Math.round(p2R * 0.082)), baseline: 'top', y: p2Y + 12 },
            ],
            { id: 'qualityDefectGaugeLabel', text: dVal.toFixed(2) + '% — ' + dStatus,
              cls: defBadgeClass }
        );

    } else {
        // ── Desktop: Quality Rate left | Defect Rate center | per-line bars right ──
        const gaugeZoneW = W * 0.32;
        const qZoneW = gaugeZoneW;
        const dZoneW = gaugeZoneW;
        const barZoneX = qZoneW + dZoneW;
        const sharedGaugeR = Math.min((gaugeZoneW / 2 - 20) / 1.22, H * 0.88 * 0.72);

        // Quality Rate gauge (left)
        const qCX = qZoneW / 2;
        const qCY = H * 0.88;
        const qR  = sharedGaugeR;
        const qAW = Math.round(qR * 0.40);

        drawSpeedometer(qCX, qCY, qR, qAW, overallQuality, qMAX, qMIN, qSegs,
            qTicks,
            [
                { text: overallQuality.toFixed(1) + '%', color: qColor, size: Math.max(20, Math.round(qR * 0.20)), bold: true, baseline: 'bottom', y: qCY - qR * 0.28 },
                { text: 'Quality Rate', color: '#8896a8', size: Math.max(10, Math.round(qR * 0.085)), baseline: 'top', y: qCY + 16 },
            ],
            { id: 'qualityKpiGaugeLabel', text: overallQuality.toFixed(1) + '% — ' + qStatus,
              cls: qClamped >= QR_GOOD_THRESHOLD ? 'bg-success' : qClamped >= QR_WARN_THRESHOLD ? 'bg-warning text-dark' : 'bg-danger' }
        );

        // Divider left|center
        ctx.beginPath();
        ctx.moveTo(qZoneW, H * 0.06); ctx.lineTo(qZoneW, H * 0.96);
        ctx.strokeStyle = 'rgba(255,255,255,0.05)'; ctx.lineWidth = 1; ctx.stroke();

        // Defect Rate gauge (center)
        const dCX  = qZoneW + dZoneW / 2;
        const dCY  = H * 0.88;
        const dR   = sharedGaugeR;
        const dAW  = Math.round(dR * 0.40);
        const dVFS = Math.max(16, Math.round(dR * 0.20));
        const dLFS = Math.max(9,  Math.round(dR * 0.082));

        drawSpeedometer(dCX, dCY, dR, dAW, dVal, dMAX, 0, dSegs,
            dTicks.map(v => ({ val: v, label: v + '%' })),
            [
                { text: dVal.toFixed(2) + '%', color: defColor, size: dVFS, bold: true, baseline: 'bottom', y: dCY - dR * 0.28 },
                { text: 'Defect Rate',          color: '#8896a8', size: dLFS, baseline: 'top', y: dCY + 14 },
            ],
            { id: 'qualityDefectGaugeLabel', text: dVal.toFixed(2) + '% — ' + dStatus,
              cls: defBadgeClass }
        );

        // Divider center|bars
        ctx.beginPath();
        ctx.moveTo(barZoneX, H * 0.06); ctx.lineTo(barZoneX, H * 0.96);
        ctx.strokeStyle = 'rgba(255,255,255,0.05)'; ctx.lineWidth = 1; ctx.stroke();

        // Per-line bars (right zone)
        if (!lineData) return;
        const lines    = ['fc', 'fb', 'rc', 'rb', 'third', 'sub'];
        const lColors  = { fc: '#28a745', fb: '#ffc107', rc: '#dc3545', rb: '#8b5cf6', third: '#fd7e14', sub: '#20c997' };
        const lNames   = { fc: 'F/C', fb: 'F/B', rc: 'R/C', rb: 'R/B', third: '3RD', sub: 'Sub' };
        const fontSize = Math.max(10, Math.round(H * 0.042));
        const smFS     = Math.max(8,  Math.round(H * 0.034));
        const barH     = Math.max(18, Math.round(H * 0.072));
        const usableH  = H - fontSize - 20;
        const spacing  = Math.floor(usableH / lines.length);
        const startY   = fontSize + 14;
        const panelX   = barZoneX + 8;
        const labelW   = 36;
        const pctColW  = Math.max(76, Math.round(fontSize * 6.2));
        const barX0    = panelX + labelW;
        const bw       = W - barX0 - pctColW - 6;

        ctx.fillStyle = '#8896a8';
        ctx.font = `${fontSize}px Poppins, sans-serif`;
        ctx.textAlign = 'left'; ctx.textBaseline = 'top';
        ctx.fillText('Per-Line Quality / Defect', panelX, 6);

        lines.forEach((key, i) => {
            const d = lineData[key]; if (!d) return;
            const qr = Math.min(d.qualityRate || 0, 100);
            const dr = Math.min(d.defectRate  || 0, 100);
            const y  = startY + i * spacing + (spacing - barH) / 2;
            const midY = y + barH / 2;

            // Line label
            ctx.fillStyle = lColors[key];
            ctx.font = `bold ${fontSize}px Poppins, sans-serif`;
            ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
            ctx.fillText(lNames[key], panelX, midY);

            const halfBar = Math.round((barH - 2) / 2);
            const qrY = y;
            const drY = y + halfBar + 2;

            // QR bar background
            ctx.fillStyle = 'rgba(255,255,255,0.06)';
            ctx.beginPath(); ctx.roundRect(barX0, qrY, bw, halfBar, 3); ctx.fill();

            // QR bar fill
            const qrColor = qr >= 95 ? PERCENTAGE_COLORS.good : qr >= 85 ? PERCENTAGE_COLORS.warning : PERCENTAGE_COLORS.critical;
            ctx.fillStyle = qrColor;
            ctx.globalAlpha = 0.85;
            ctx.beginPath(); ctx.roundRect(barX0, qrY, bw * (qr / 100), halfBar, 3); ctx.fill();
            ctx.globalAlpha = 1;

            // DR bar background
            ctx.fillStyle = 'rgba(255,255,255,0.06)';
            ctx.beginPath(); ctx.roundRect(barX0, drY, bw, halfBar, 3); ctx.fill();

            // DR bar fill
            const drBarColor = dr <= 1 ? PERCENTAGE_COLORS.good : dr <= 3 ? PERCENTAGE_COLORS.warning : PERCENTAGE_COLORS.critical;
            ctx.fillStyle = drBarColor;
            ctx.globalAlpha = 0.85;
            ctx.beginPath(); ctx.roundRect(barX0, drY, bw * Math.min(dr / dMAX, 1), halfBar, 3); ctx.fill();
            ctx.globalAlpha = 1;

            // Right-side labels
            ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
            ctx.fillStyle = '#c8d4e0';
            ctx.font = `${smFS}px Poppins, sans-serif`;
            ctx.fillText('QR ' + qr.toFixed(1) + '%', W - 4, qrY + halfBar / 2);
            ctx.fillStyle = drBarColor;
            ctx.fillText('DR ' + dr.toFixed(2) + '%', W - 4, drY + halfBar / 2);
        });
    }
}
