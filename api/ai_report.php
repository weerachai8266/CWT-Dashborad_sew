<?php
// AI Report API — factory-analyst via Ollama
// ปรับตั้งค่า: config/ai.php  |  ปิด AI: set AI_ENABLED=false ที่นั่น
require_once __DIR__ . '/../config/ai.php';
require_once __DIR__ . '/../config/app.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
set_time_limit(360);

header('Content-Type: application/json; charset=utf-8');
setCorsHeaders();

// ── helper ──────────────────────────────────────────────────
function jsonOut(bool $ok, $data = null, string $msg = ''): void {
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── status endpoint ─────────────────────────────────────────
$action = $_GET['action'] ?? 'report';

if ($action === 'status') {
    jsonOut(true, ['ai_enabled' => AI_ENABLED, 'model' => OLLAMA_MODEL, 'host' => OLLAMA_HOST . ':' . OLLAMA_PORT]);
}

// ── data endpoint: คืนข้อมูล DB เฉพาะ ไม่เรียก Ollama ─────
if ($action === 'data') {
    try {
        include(__DIR__ . '/../config/db.php');
    } catch (Throwable $e) {
        jsonOut(false, null, 'Database error: ' . $e->getMessage());
    }
    $date_start = $_GET['date_start'] ?? date('Y-m-01');
    $date_end   = $_GET['date_end']   ?? date('Y-m-d');
    $prod = getProductionSummary($conn, $date_start, $date_end);
    $qual = getQualitySummary($conn, $date_start, $date_end);
    jsonOut(true, ['production' => $prod, 'quality' => $qual]);
}

// ── ถ้า AI ปิด ───────────────────────────────────────────────
if (!AI_ENABLED) {
    jsonOut(false, null, 'AI ถูกปิดใช้งานอยู่ (AI_ENABLED = false)');
}

// ── database ─────────────────────────────────────────────────
try {
    include(__DIR__ . '/../config/db.php');
} catch (Throwable $e) {
    jsonOut(false, null, 'Database error: ' . $e->getMessage());
}

// ── request params ───────────────────────────────────────────
$report_type = $_GET['type'] ?? 'combined';   // combined | production | quality
$date_start  = $_GET['date_start'] ?? date('Y-m-01');
$date_end    = $_GET['date_end']   ?? date('Y-m-d');

// validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end)) {
    jsonOut(false, null, 'รูปแบบวันที่ไม่ถูกต้อง');
}

// ============================================================
//  รวบรวมข้อมูล PRODUCTION
// ============================================================
function getProductionSummary(PDO $conn, string $start, string $end): array {
    $lines = ['fc', 'fb', 'rc', 'rb', '3rd', 'sub'];
    $totals = [];
    $daily  = [];

    foreach ($lines as $line) {
        $table = "sewing_{$line}";
        try {
            $stmt = $conn->prepare(
                "SELECT SUM(qty) AS total FROM `{$table}`
                 WHERE DATE(created_at) BETWEEN ? AND ? AND status = 10"
            );
            $stmt->execute([$start, $end]);
            $totals[$line] = (int)($stmt->fetchColumn() ?: 0);

            // รายวัน (14 วันล่าสุด)
            $stmt2 = $conn->prepare(
                "SELECT DATE(created_at) AS day, SUM(qty) AS total
                 FROM `{$table}`
                 WHERE DATE(created_at) BETWEEN ? AND ? AND status = 10
                 GROUP BY day ORDER BY day DESC LIMIT 14"
            );
            $stmt2->execute([$start, $end]);
            $daily[$line] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $totals[$line] = 0;
            $daily[$line]  = [];
        }
    }

    // Hourly target rate (ชิ้น/ชั่วโมง)
    $hourly_rate = [];
    try {
        $stmt = $conn->prepare("SELECT fc,fb,rc,rb,`3rd`,sub FROM sewing_target ORDER BY created_at DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $hourly_rate = $row;
    } catch (Throwable $e) {}

    // ชั่วโมงทำงานจริงในช่วงเวลา (รวมทุกกะ)
    $total_working_hours = 0.0;
    $manpower_by_shift   = [];
    try {
        $stmt = $conn->prepare(
            "SELECT shift,
                    SUM(thour) AS total_hours,
                    COUNT(DISTINCT DATE(created_at)) AS days,
                    AVG(fc_act)      AS fc,
                    AVG(fb_act)      AS fb,
                    AVG(rc_act)      AS rc,
                    AVG(rb_act)      AS rb,
                    AVG(`3rd_act`)   AS `3rd`,
                    AVG(sub_act)     AS sub
             FROM sewing_man_act
             WHERE DATE(created_at) BETWEEN ? AND ?
             GROUP BY shift"
        );
        $stmt->execute([$start, $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $total_working_hours += (float)$r['total_hours'];
            $manpower_by_shift[] = $r;
        }
    } catch (Throwable $e) {}

    // คำนวณ period target = hourly_rate × total_working_hours
    $period_target = [];
    foreach ($lines as $line) {
        $rate = (float)($hourly_rate[$line] ?? 0);
        $period_target[$line] = $rate > 0 ? (int)round($rate * $total_working_hours) : 0;
    }

    // คำนวณ efficiency % ที่ถูกต้อง
    $efficiency = [];
    foreach ($lines as $line) {
        $tgt = $period_target[$line];
        $efficiency[$line] = ($tgt > 0) ? round(($totals[$line] / $tgt) * 100, 1) : null;
    }

    return [
        'period'              => ['start' => $start, 'end' => $end],
        'totals'              => $totals,
        'grand_total'         => array_sum($totals),
        'hourly_rate'         => $hourly_rate,
        'period_target'       => $period_target,
        'period_target_grand' => array_sum($period_target),
        'efficiency'          => $efficiency,
        'total_working_hours' => round($total_working_hours, 1),
        'manpower_by_shift'   => $manpower_by_shift,
        'daily_samples'       => $daily,
    ];
}

// ============================================================
//  รวบรวมข้อมูล QUALITY
// ============================================================
function getQualitySummary(PDO $conn, string $start, string $end): array {
    $lines = ['fc', 'fb', 'rc', 'rb', '3rd', 'sub'];
    // process label ใน qc_ng ตรงกับชื่อไลน์ในรูปแบบ F/C, R/B ฯลฯ
    $process_map = ['fc'=>'F/C','fb'=>'F/B','rc'=>'R/C','rb'=>'R/B','3rd'=>'3RD','sub'=>'SUB'];

    // ---- งานดี (ผ่าน QC) แยกตามไลน์ จาก qc_* (status=10) ----
    // ยกเว้น sub: ไม่ผ่าน qc_sub → ใช้ยอดผลิต sewing_sub (status=10) แทน
    $passed_by_line = [];
    foreach ($lines as $line) {
        if ($line === 'sub') {
            try {
                $stmt = $conn->prepare(
                    "SELECT SUM(qty) AS total FROM `sewing_sub`
                     WHERE status = 10 AND DATE(created_at) BETWEEN ? AND ?"
                );
                $stmt->execute([$start, $end]);
                $passed_by_line['sub'] = (int)($stmt->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $passed_by_line['sub'] = 0;
            }
            continue;
        }
        $table = "qc_{$line}";
        try {
            $stmt = $conn->prepare(
                "SELECT SUM(qty) AS total FROM `{$table}`
                 WHERE status = 10 AND DATE(created_at) BETWEEN ? AND ?"
            );
            $stmt->execute([$start, $end]);
            $passed_by_line[$line] = (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $passed_by_line[$line] = 0;
        }
    }

    // ---- งานเสีย (NG) จาก qc_ng ----
    $ng_by_line  = [];
    $top_defects = [];
    $total_ng    = 0;
    try {
        // รวมทั้งหมด
        $stmt = $conn->prepare(
            "SELECT SUM(qty) FROM qc_ng WHERE DATE(created_at) BETWEEN ? AND ?"
        );
        $stmt->execute([$start, $end]);
        $total_ng = (int)($stmt->fetchColumn() ?: 0);

        // แยกตาม process (= ชื่อไลน์)
        $stmt = $conn->prepare(
            "SELECT process, SUM(qty) AS total FROM qc_ng
             WHERE DATE(created_at) BETWEEN ? AND ?
             GROUP BY process ORDER BY total DESC"
        );
        $stmt->execute([$start, $end]);
        $process_to_line = array_flip($process_map);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $proc = strtoupper($row['process']);
            $key  = $process_to_line[$proc] ?? strtolower($proc);
            $ng_by_line[$key] = (int)$row['total'];
        }

        // top defects by detail + process
        $stmt = $conn->prepare(
            "SELECT detail, process, SUM(qty) AS total
             FROM qc_ng
             WHERE DATE(created_at) BETWEEN ? AND ?
             GROUP BY detail, process
             ORDER BY total DESC
             LIMIT 10"
        );
        $stmt->execute([$start, $end]);
        $top_defects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* no qc_ng */ }

    $total_passed    = array_sum($passed_by_line);
    $total_inspected = $total_passed + $total_ng;
    $defect_rate     = $total_inspected > 0
        ? round($total_ng / $total_inspected * 100, 2) : 0;

    return [
        'period'          => ['start' => $start, 'end' => $end],
        'passed_by_line'  => $passed_by_line,
        'total_passed'    => $total_passed,
        'ng_by_line'      => $ng_by_line,
        'total_ng'        => $total_ng,
        'total_inspected' => $total_inspected,
        'defect_rate'     => $defect_rate,
        'top_defects'     => $top_defects,
        // alias สำหรับ JS rendering (backward compat)
        'defect_by_line'  => $ng_by_line,
        'total_defects'   => $total_ng,
    ];
}

// ============================================================
//  สร้าง Prompt จากข้อมูล
// ============================================================
function buildPrompt(string $type, array $prod, array $qual): string {
    $lines_th = ['fc' => 'F/C', 'fb' => 'F/B', 'rc' => 'R/C', 'rb' => 'R/B', '3rd' => '3RD', 'sub' => 'Sub Assy'];

    // /no_think บังคับปิด thinking mode ของ Qwen3 ใน Ollama
    $prompt = "/no_think\n";
    $prompt .= "คุณคือนักวิเคราะห์โรงงานผลิต ตอบเป็นภาษาไทยเท่านั้น ห้ามใช้ภาษาอื่น ห้ามแสดงกระบวนคิด ตอบเพียงรายงานสรุปเท่านั้น\n";
    $prompt .= "ช่วงเวลา: {$prod['period']['start']} ถึง {$prod['period']['end']}\n\n";

    if ($type === 'combined' || $type === 'production') {
        $prompt .= "=== ข้อมูลการผลิต ===\n";
        $prompt .= "ชั่วโมงทำงานรวม: " . ($prod['total_working_hours'] ?? 0) . " ชั่วโมง\n";
        $prompt .= "ยอดผลิตรวม: " . number_format($prod['grand_total']) . " ชิ้น";
        if (!empty($prod['period_target_grand'])) {
            $grandEff = $prod['period_target_grand'] > 0
                ? round(($prod['grand_total'] / $prod['period_target_grand']) * 100, 1)
                : 0;
            $prompt .= " (เป้าหมายรวม: " . number_format($prod['period_target_grand']) . " ชิ้น, ประสิทธิภาพ: {$grandEff}%)";
        }
        $prompt .= "\n\nแยกตามไลน์:\n";

        foreach ($prod['totals'] as $line => $qty) {
            $label = $lines_th[$line] ?? strtoupper($line);
            $rate  = $prod['hourly_rate'][$line] ?? 0;
            $tgt   = $prod['period_target'][$line] ?? 0;
            $eff   = $prod['efficiency'][$line] ?? null;

            $prompt .= "  - {$label}: ผลิต " . number_format($qty) . " ชิ้น";
            if ($tgt > 0) {
                $prompt .= " / เป้า " . number_format($tgt) . " ชิ้น";
                if ($eff !== null) {
                    $prompt .= " / ประสิทธิภาพ {$eff}%";
                }
            }
            if ($rate > 0) {
                $prompt .= " (อัตรา {$rate} ชิ้น/ชม.)";
            }
            $prompt .= "\n";
        }

        if (!empty($prod['manpower_by_shift'])) {
            $prompt .= "\nจำนวนพนักงานเฉลี่ย:\n";
            foreach ($prod['manpower_by_shift'] as $mp) {
                $shift = $mp['shift'] ?? 'ไม่ระบุ';
                $hrs   = round((float)$mp['total_hours'], 1);
                $total = array_sum(array_map(
                    fn($k) => (float)($mp[$k] ?? 0),
                    ['fc','fb','rc','rb','3rd','sub']
                ));
                $prompt .= "  - กะ{$shift}: " . round($total) . " คน, {$hrs} ชม. ({$mp['days']} วัน)\n";
            }
        }
    }

    if ($type === 'combined' || $type === 'quality') {
        $total_passed    = $qual['total_passed']    ?? 0;
        $total_ng        = $qual['total_ng']        ?? 0;
        $total_inspected = $qual['total_inspected'] ?? ($total_passed + $total_ng);
        $defect_rate     = $qual['defect_rate']     ?? 0;

        $prompt .= "\n=== ข้อมูลคุณภาพ (หน่วย: QC ตรวจสอบ) ===\n";
        $prompt .= "ยอดตรวจสอบรวม: " . number_format($total_inspected) . " ชิ้น";
        $prompt .= " (งานดีผ่าน QC: " . number_format($total_passed) . ", งานเสีย NG: " . number_format($total_ng) . ")\n";
        $prompt .= "อัตราของเสีย (Defect Rate): {$defect_rate}%\n";

        if (!empty($qual['passed_by_line'])) {
            $prompt .= "\nงานดี (ผ่าน QC) แยกตามไลน์:\n";
            foreach ($qual['passed_by_line'] as $line => $qty) {
                $label = $lines_th[$line] ?? strtoupper($line);
                $ng    = $qual['ng_by_line'][$line] ?? 0;
                $tot   = $qty + $ng;
                $rate  = $tot > 0 ? round($ng / $tot * 100, 2) : 0;
                $prompt .= "  - {$label}: ผ่าน " . number_format($qty) . " ชิ้น";
                if ($ng > 0) {
                    $prompt .= " / NG {$ng} ชิ้น (defect rate {$rate}%)";
                }
                $prompt .= "\n";
            }
        }

        if (!empty($qual['top_defects'])) {
            $prompt .= "\nปัญหาของเสีย Top 10 (จาก qc_ng):\n";
            foreach ($qual['top_defects'] as $i => $d) {
                $no = $i + 1;
                $prompt .= "  {$no}. ไลน์ {$d['process']}: {$d['detail']} – {$d['total']} ชิ้น\n";
            }
        }
    }

    // คำสั่งรายงาน — ใส่ heading แรกไว้ท้าย prompt เพื่อ force model เขียนต่อทันที
    $prompt .= "\nเขียนรายงานสรุปเป็นภาษาไทย รูปแบบ Markdown ห้ามใช้ภาษาอังกฤษ ห้ามแสดงกระบวนการคิด\n";
    $prompt .= "แบ่งรายงานออกเป็น 3 หัวข้อดังนี้:\n";
    $prompt .= "  1. KPI – ตัวชี้วัดหลัก (ประสิทธิภาพการผลิตรวม, อัตราของเสีย, จำนวนพนักงาน)\n";
    $prompt .= "  2. การผลิต – ยอดผลิต เป้าหมาย ประสิทธิภาพแยกตามไลน์ และประเด็นที่ควรปรับปรุง\n";
    $prompt .= "  3. คุณภาพ – ยอดงานดี/NG แยกตามไลน์ อัตราของเสีย และปัญหาที่พบบ่อย\n";
    $prompt .= "  4. คำแนะนำ – ข้อเสนอแนะเชิงปฏิบัติ 3-5 ข้อ เพื่อปรับปรุงประสิทธิภาพและลดของเสีย\n\n";
    $prompt .= "## 1. KPI\n";

    return $prompt;
}

// ============================================================
//  ตัด thinking preamble ออกจาก response
//  Model มักเขียน English "Okay, let's tackle..." ก่อนเนื้อหาจริง
//  วิธี: หาบรรทัดแรกที่เป็นภาษาไทย (สัดส่วน Thai char > 25%)
//        หรือ Markdown heading ที่มีไทย แล้วเอาตั้งแต่นั้น
// ============================================================
function stripThinkingPreamble(string $text, string $prependHeading = ''): string {
    // ลบ <think>...</think> block มาตรฐาน
    $text = preg_replace('/<think>.*?<\/think>/si', '', $text);

    // ลบ thinking content ที่ไม่มี opening tag แต่มี </think> ปิดท้าย
    // (model บางครั้ง stream thinking โดยตัด <think> ออก)
    if (stripos($text, '</think>') !== false) {
        $text = preg_replace('/^.*?<\/think>/su', '', $text);
    }

    // ลบ heading ที่ model อาจ echo คืนมา (เป็น duplicate ของที่เราเติมไว้)
    $text = preg_replace('/^\s*##\s*1\.\s*(สรุปภาพรวม|KPI)[^\n]*\n?/u', '', $text);

    $lines     = explode("\n", $text);
    $startIdx  = count($lines); // default = ไม่พบ

    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;

        // หาก Markdown heading มีอักษรไทย
        if (preg_match('/^#+.*[\x{0E00}-\x{0E7F}]/u', $trimmed)) {
            $startIdx = $i;
            break;
        }

        // นับสัดส่วน Thai char
        preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $trimmed, $m);
        $thaiCount = count($m[0]);
        $totalLen  = mb_strlen($trimmed);

        if ($totalLen >= 5 && ($thaiCount / $totalLen) > 0.25) {
            $startIdx = $i;
            break;
        }
    }

    // ถ้าไม่เจอเลย ให้เอาทั้งหมด
    if ($startIdx === count($lines)) $startIdx = 0;

    $body = trim(implode("\n", array_slice($lines, $startIdx)));

    // เติม heading ที่กำหนดไว้ (ถ้า body ยังไม่เริ่มด้วย heading นั้น)
    if ($prependHeading !== '' && !str_starts_with($body, $prependHeading)) {
        return $prependHeading . "\n" . $body;
    }
    return $body;
}

// ============================================================
//  เรียก Ollama API
// ============================================================
function callOllama(string $prompt): array {
    $url  = 'http://' . OLLAMA_HOST . ':' . OLLAMA_PORT . '/api/generate';
    $body = json_encode([
        'model'  => OLLAMA_MODEL,
        'prompt' => $prompt,
        'stream' => false,
        'think'  => false,   // Ollama native flag สำหรับ Qwen3
        'options' => [
            'temperature' => 0.3,
            'num_predict' => 4096,  // เพิ่มจาก 2048 เพื่อไม่ตัดรายงาน
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => OLLAMA_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err) {
        // แปลง cURL error ให้อ่านง่ายขึ้น
        if (str_contains($err, 'Connection refused')) {
            throw new RuntimeException(
                "❌ ไม่สามารถเชื่อมต่อ Ollama ได้ — เซิร์ฟเวอร์ AI (" . OLLAMA_HOST . ":" . OLLAMA_PORT . ") ไม่ตอบสนอง\n" .
                "สาเหตุที่เป็นไปได้:\n" .
                "• เครื่อง Windows เข้าสู่โหมดพักหน้าจอ (Sleep) — กรุณาตั้ง Power Plan เป็น \"Never sleep\"\n" .
                "• Ollama service หยุดทำงาน — รัน ollama serve ที่เครื่อง " . OLLAMA_HOST . " อีกครั้ง\n" .
                "• ไฟร์วอลล์บล็อก port " . OLLAMA_PORT
            );
        }
        if (str_contains($err, 'timed out') || str_contains($err, 'Operation timed out')) {
            throw new RuntimeException(
                "⏱ Ollama ไม่ตอบสนองภายในเวลาที่กำหนด (" . OLLAMA_TIMEOUT . "s) — Model อาจกำลังโหลดอยู่ กรุณาลองใหม่อีกครั้ง"
            );
        }
        throw new RuntimeException("cURL error: {$err}");
    }
    if ($code !== 200) {
        throw new RuntimeException("Ollama HTTP {$code}: " . substr($raw, 0, 200));
    }

    $json = json_decode($raw, true);
    if (!isset($json['response'])) {
        throw new RuntimeException("Unexpected Ollama response format");
    }

    // กรอง thinking text ออก แล้วเติม heading แรกกลับ
    $text = stripThinkingPreamble(
        $json['response'],
        '## 1. KPI'
    );
    $text = trim($text);

    return [
        'text'       => $text,
        'model'      => $json['model'] ?? OLLAMA_MODEL,
        'total_ms'   => round(($json['total_duration'] ?? 0) / 1e6),
        'eval_count' => $json['eval_count'] ?? 0,
    ];
}

// ============================================================
//  Main
// ============================================================
try {
    // production data always needed (prompt uses $prod['period'] for all report types)
    $prod = getProductionSummary($conn, $date_start, $date_end);
    $qual = ($report_type !== 'production') ? getQualitySummary($conn, $date_start, $date_end) : [];

    $prompt = buildPrompt($report_type, $prod, $qual);
    $ai     = callOllama($prompt);

    jsonOut(true, [
        'report_type' => $report_type,
        'period'      => ['start' => $date_start, 'end' => $date_end],
        'report_text' => $ai['text'],
        'model'       => $ai['model'],
        'total_ms'    => $ai['total_ms'],
        'eval_count'  => $ai['eval_count'],
        'production'  => $prod,
        'quality'     => $qual,
    ]);

} catch (Throwable $e) {
    error_log('[ai_report] ' . $e->getMessage());
    jsonOut(false, null, $e->getMessage());
}
