/**
 * AI Report [beta]
 * ──────────────────────────────────────────────────────────────
 * ไฟล์นี้ทำงานแยกจาก dashboard เดิมทั้งหมด
 * ปิด/เปิด AI ได้ที่ api/ai_report.php → define('AI_ENABLED', true/false)
 * ──────────────────────────────────────────────────────────────
 */

(function () {
    'use strict';

    // ── Label maps ──────────────────────────────────────────────
    const LINE_TH = { fc: 'F/C', fb: 'F/B', rc: 'R/C', rb: 'R/B', '3rd': '3RD', sub: 'Sub' };
    const REPORT_LABELS = {
        combined:   'รายงานรวม (ผลิต + คุณภาพ)',
        production: 'รายงานการผลิต',
        quality:    'รายงานด้านคุณภาพ',
    };

    // ── DOM refs ─────────────────────────────────────────────────
    const $$ = (id) => document.getElementById(id);

    let aiEnabled = false;
    let lastReportText = '';

    // ── Initialise when tab activated ────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        setDefaultDates();
        checkAIStatus();
        bindEvents();
    });

    // Also re-check status when the tab is shown (in case of lazy load)
    document.addEventListener('shown.bs.tab', (e) => {
        if (e.target && e.target.id === 'aireport-tab') {
            checkAIStatus();
        }
    });

    // ── Set default date range (current month) ───────────────────
    // ใช้วิธีเดียวกับ ui.js: สร้าง string จาก local date โดยตรง
    // ห้ามใช้ .toISOString() เพราะแปลงเป็น UTC ทำให้วันผิดใน timezone +7
    function setDefaultDates() {
        const n = new Date();
        const today      = `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-${String(n.getDate()).padStart(2, '0')}`;
        const firstOfMonth = `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-01`;

        const startEl = $$('ai_date_start');
        const endEl   = $$('ai_date_end');
        if (startEl && !startEl.value) startEl.value = firstOfMonth;
        if (endEl   && !endEl.value)   endEl.value   = today;
    }

    // ── Check AI status from API ─────────────────────────────────
    function checkAIStatus() {
        const badge = $$('aiStatusBadge');
        const btn   = $$('ai_btnGenerate');
        if (!badge) return;

        badge.className = 'badge bg-secondary ms-1';
        badge.textContent = '⏳ ตรวจสอบ AI...';

        fetch('api/ai_report.php?action=status')
            .then((r) => r.json())
            .then((res) => {
                if (res.success && res.data.ai_enabled) {
                    aiEnabled = true;
                    badge.className  = 'badge bg-success ms-1';
                    badge.textContent = `🤖 AI พร้อม (${res.data.model})`;
                    if (btn) btn.disabled = false;
                } else {
                    aiEnabled = false;
                    badge.className  = 'badge bg-warning text-dark ms-1';
                    badge.textContent = '⚠ AI ปิดอยู่';
                    if (btn) btn.disabled = true;
                }
            })
            .catch(() => {
                aiEnabled = false;
                badge.className  = 'badge bg-danger ms-1';
                badge.textContent = '❌ เชื่อมต่อ API ไม่ได้';
                if (btn) btn.disabled = true;
            });
    }

    // ── Bind UI events ───────────────────────────────────────────
    function bindEvents() {
        const btn     = $$('ai_btnGenerate');
        const btnCopy = $$('ai_btnCopy');

        if (btn) btn.addEventListener('click', generateReport);
        if (btnCopy) btnCopy.addEventListener('click', copyReport);
    }

    // ── Generate report ──────────────────────────────────────────
    function generateReport() {
        if (!aiEnabled) {
            showError('AI ถูกปิดใช้งาน (AI_ENABLED = false ใน api/ai_report.php)');
            return;
        }

        const start = $$('ai_date_start')?.value;
        const end   = $$('ai_date_end')?.value;
        const type  = document.querySelector('input[name="aiReportType"]:checked')?.value || 'combined';

        if (!start || !end) {
            alert('กรุณาเลือกช่วงวันที่');
            return;
        }
        if (start > end) {
            alert('วันเริ่มต้นต้องไม่มากกว่าวันสิ้นสุด');
            return;
        }

        showLoading();

        const url = `api/ai_report.php?action=report&type=${encodeURIComponent(type)}&date_start=${start}&date_end=${end}`;

        fetch(url)
            .then((r) => r.json())
            .then((res) => {
                if (!res.success) throw new Error(res.message || 'เกิดข้อผิดพลาด');
                renderReport(res.data);
            })
            .catch((err) => {
                showError(err.message || 'ไม่สามารถสร้างรายงานได้');
            });
    }

    // ── Render report to DOM ─────────────────────────────────────
    function renderReport(data) {
        lastReportText = data.report_text || '';

        // header labels
        const typeLabel  = $$('aiReportTypeLabel');
        const periodLabel = $$('aiPeriodLabel');
        const modelLabel = $$('aiModelLabel');
        const timingLabel = $$('aiTimingLabel');

        if (typeLabel)  typeLabel.textContent  = REPORT_LABELS[data.report_type] || data.report_type;
        if (periodLabel) periodLabel.textContent = `${data.period.start} → ${data.period.end}`;
        if (modelLabel) modelLabel.textContent  = `model: ${data.model}`;
        if (timingLabel && data.total_ms) {
            const secs = (data.total_ms / 1000).toFixed(1);
            timingLabel.textContent = `⏱ ${secs}s  (${data.eval_count} tokens)`;
        }

        // report text (Markdown → HTML basic render)
        const content = $$('aiReportContent');
        if (content) {
            content.innerHTML = markdownToHtml(lastReportText);
        }

        // data summary cards
        if (data.production && data.production.totals) {
            renderProdCards(data.production);
        }
        if (data.quality && data.quality.defect_by_line) {
            renderQualCards(data.quality);
        }

        // show/hide states
        $$('aiReportPlaceholder')?.classList.add('d-none');
        $$('aiReportLoading')?.classList.add('d-none');
        $$('aiReportContent')?.classList.remove('d-none');
        $$('ai_btnCopy')?.classList.remove('d-none');
    }

    // ── Render production summary cards ──────────────────────────
    function renderProdCards(prod) {
        const container = $$('aiProdCards');
        if (!container) return;

        const grand = prod.grand_total || 0;
        let html = '';

        for (const [line, qty] of Object.entries(prod.totals || {})) {
            const label = LINE_TH[line] || line.toUpperCase();
            const pct   = grand > 0 ? ((qty / grand) * 100).toFixed(1) : '0';
            html += `
                <div class="col-4 col-md-2 mb-2">
                    <div class="border rounded p-2 text-center">
                        <div class="fw-bold fs-6">${numberFmt(qty)}</div>
                        <small class="text-muted">${label}</small>
                        <div style="font-size:0.7rem;color:#888;">${pct}%</div>
                    </div>
                </div>`;
        }

        html += `
            <div class="col-12 mt-1 text-center">
                <span class="badge bg-info">รวม ${numberFmt(grand)} ชิ้น</span>
            </div>`;

        $$('aiProdPlaceholder')?.remove();
        container.innerHTML = html;
    }

    // ── Render quality summary cards ─────────────────────────────
    function renderQualCards(qual) {
        const container = $$('aiQualCards');
        if (!container) return;

        const total = qual.total_defects || 0;
        let html = '';

        for (const [line, qty] of Object.entries(qual.defect_by_line || {})) {
            const label = LINE_TH[line] || line.toUpperCase();
            html += `
                <div class="col-4 col-md-2 mb-2">
                    <div class="border rounded p-2 text-center">
                        <div class="fw-bold fs-6 text-danger">${numberFmt(qty)}</div>
                        <small class="text-muted">${label}</small>
                    </div>
                </div>`;
        }

        // Top defects list
        if (qual.top_defects && qual.top_defects.length > 0) {
            html += `<div class="col-12 mt-2"><small class="fw-bold">Top ปัญหา:</small><ol class="mb-0 ps-3" style="font-size:0.78rem;">`;
            qual.top_defects.slice(0, 5).forEach((d) => {
                html += `<li>[${d.process}] ${d.detail} — <strong>${d.total}</strong> ชิ้น</li>`;
            });
            html += `</ol></div>`;
        }

        html += `
            <div class="col-12 mt-1 text-center">
                <span class="badge bg-danger">ของเสียรวม ${numberFmt(total)} ชิ้น</span>
            </div>`;

        $$('aiQualPlaceholder')?.remove();
        container.innerHTML = html;
    }

    // ── Show loading state ────────────────────────────────────────
    function showLoading() {
        $$('aiReportPlaceholder')?.classList.add('d-none');
        $$('aiReportContent')?.classList.add('d-none');
        $$('ai_btnCopy')?.classList.add('d-none');
        $$('aiReportLoading')?.classList.remove('d-none');

        // reset data cards
        const prodCards = $$('aiProdCards');
        const qualCards = $$('aiQualCards');
        if (prodCards) prodCards.innerHTML = '<div class="col-12 text-muted py-2">กำลังโหลด...</div>';
        if (qualCards) qualCards.innerHTML = '<div class="col-12 text-muted py-2">กำลังโหลด...</div>';
    }

    // ── Show error state ──────────────────────────────────────────
    function showError(msg) {
        $$('aiReportLoading')?.classList.add('d-none');
        $$('aiReportPlaceholder')?.classList.add('d-none');

        const content = $$('aiReportContent');
        if (content) {
            const lines = escapeHtml(msg).split('\n').filter(l => l.trim());
            const title = lines[0];
            const bullets = lines.slice(1)
                .filter(l => l.startsWith('•') || l.startsWith('-') || l.match(/^[•\-*]/))
                .map(l => `<li>${l.replace(/^[•\-*]\s*/, '')}</li>`)
                .join('');
            const extra = lines.slice(1)
                .filter(l => !l.startsWith('•') && !l.startsWith('-') && !l.match(/^[•\-*]/))
                .map(l => `<div>${l}</div>`)
                .join('');
            content.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>${title}</strong>
                    ${extra}
                    ${bullets ? `<ul class="mb-0 mt-2">${bullets}</ul>` : ''}
                </div>`;
            content.classList.remove('d-none');
        }
    }

    // ── Copy report to clipboard ─────────────────────────────────
    function copyReport() {
        if (!lastReportText) return;
        navigator.clipboard.writeText(lastReportText).then(() => {
            const btn = $$('ai_btnCopy');
            if (btn) {
                const orig = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => { btn.innerHTML = orig; }, 1500);
            }
        }).catch(() => {
            // fallback
            const ta = document.createElement('textarea');
            ta.value = lastReportText;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        });
    }

    // ── Minimal Markdown → HTML (line-by-line) ───────────────────
    function markdownToHtml(md) {
        if (!md) return '';

        function inlineFmt(text) {
            text = text
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            text = text.replace(/\*(.+?)\*/g, '<em>$1</em>');
            return text;
        }

        const lines = md.split('\n');
        let out = '';
        let inUl = false, inOl = false;
        let paraBuf = [];

        function flushPara() {
            if (!paraBuf.length) return;
            const t = paraBuf.join('<br>').trim();
            if (t) out += `<p class="ai-p">${t}</p>`;
            paraBuf = [];
        }
        function closeLists() {
            if (inUl) { out += '</ul>'; inUl = false; }
            if (inOl) { out += '</ol>'; inOl = false; }
        }

        for (const raw of lines) {
            const line = raw.trimEnd();

            // Headings
            let m;
            if ((m = line.match(/^### (.+)$/))) {
                flushPara(); closeLists();
                out += `<h5 class="ai-h3">${inlineFmt(m[1])}</h5>`; continue;
            }
            if ((m = line.match(/^## (.+)$/))) {
                flushPara(); closeLists();
                out += `<h4 class="ai-h2">${inlineFmt(m[1])}</h4>`; continue;
            }
            if ((m = line.match(/^# (.+)$/))) {
                flushPara(); closeLists();
                out += `<h3 class="ai-h1">${inlineFmt(m[1])}</h3>`; continue;
            }

            // HR
            if (/^---+$/.test(line)) {
                flushPara(); closeLists();
                out += '<hr>'; continue;
            }

            // Unordered list
            if ((m = line.match(/^[-*] (.+)$/))) {
                flushPara();
                if (inOl) { out += '</ol>'; inOl = false; }
                if (!inUl) { out += '<ul class="ai-ul">'; inUl = true; }
                out += `<li>${inlineFmt(m[1])}</li>`; continue;
            }

            // Ordered list
            if ((m = line.match(/^(\d+)[\.\)] (.+)$/))) {
                flushPara();
                if (inUl) { out += '</ul>'; inUl = false; }
                if (!inOl) { out += '<ol class="ai-ol">'; inOl = true; }
                out += `<li>${inlineFmt(m[2])}</li>`; continue;
            }

            // Empty line
            if (line.trim() === '') {
                flushPara(); closeLists(); continue;
            }

            // Normal text
            closeLists();
            paraBuf.push(inlineFmt(line));
        }

        flushPara();
        closeLists();
        return out;
    }

    // ── Utils ─────────────────────────────────────────────────────
    function numberFmt(n) {
        return Number(n).toLocaleString('th-TH');
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})();
