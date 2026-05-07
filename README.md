# CWT Production Dashboard

ระบบ Dashboard สำหรับติดตามข้อมูลการผลิตและคุณภาพของไลน์เย็บ บริษัท Chaiwattana Tannery Group

---

## โมดูล

| โมดูล | รายละเอียด |
|---|---|
| **Production** | ผลผลิตรายไลน์ (F/C, F/B, R/C, R/B, 3RD, Sub) พร้อม KPI Gauge, Trend Chart, Model Summary |
| **Quality** | สรุปของเสียรายไลน์, Defect Rate รายเดือน, Pareto Chart, Cross-Process/Model Table |
| **AI Report** | วิเคราะห์ข้อมูลผ่าน AI โดยอัตโนมัติ (beta) |
| **Export** | ส่งออก Excel แยกตามโมดูล พร้อมเลือกช่วงวันที่ |
| **Auto Update** | รีเฟรชข้อมูล Production อัตโนมัติทุก 30 วินาที |

---

## โครงสร้างโปรเจกต์

```
index.php               # หน้าหลัก (PHP สำหรับ cache busting)
config/
  db.php                # การเชื่อมต่อ database
  db.sample.php         # ตัวอย่าง config (ไม่มี credential)
css/
  style.css             # Dark theme + responsive layout
script/
  config.js             # ค่าคงที่กลาง (threshold, สี, ชื่อไลน์)
  data.js               # โหลด/อัปเดตข้อมูลจาก API
  charts.js             # สร้างกราฟทั้งหมด (Chart.js)
  gauges.js             # KPI Speedometer Gauge (Canvas)
  ui.js                 # Sidebar, export dialog, event listeners
  ai_report.js          # AI Report tab
api/
  get_production_data.php   # ข้อมูลการผลิต (hourly/daily/summary)
  get_defects_data.php      # ข้อมูลของเสีย
  get_monthly_dr.php        # Defect Rate รายเดือน
  get_cross_tabs.php        # Cross-Process/Model table
  get_performance.php       # KPI ประสิทธิภาพ
  export_product.php        # Export Excel การผลิต
  export_quality.php        # Export Excel คุณภาพ
  ai_report.php             # AI วิเคราะห์รายงาน
sql/
  sql.sql               # Schema และข้อมูลตัวอย่าง
```

---

## การติดตั้ง

1. Copy `config/db.sample.php` → `config/db.php` แล้วกรอกข้อมูล database
2. Import schema จาก `sql/sql.sql`
3. วางโฟลเดอร์ใน web server ที่รัน PHP (Apache/Nginx)
4. เปิดผ่าน browser ที่ `http://localhost/sewing/`

---

## ค่าคงที่ที่ปรับได้ (`script/config.js`)

```js
// Performance threshold
const PERF_THRESHOLD_EXCELLENT = 101;  // % ขึ้นไป = เกินเป้า (น้ำเงิน)
const PERF_THRESHOLD_GOOD      = 95;   // % ขึ้นไป = ดี (เขียว)
const PERF_THRESHOLD_WARNING   = 85;   // % ขึ้นไป = เฝ้าระวัง (เหลือง)

// Defect Rate threshold
const DR_THRESHOLD      = 1.7;   // DR ≤ ค่านี้ = Good
const DR_WARN_THRESHOLD = 2.0;   // DR ≤ ค่านี้ = Warning
```

แก้ค่าที่นี่ที่เดียว — มีผลต่อสี badge, gauge, กราฟ และ tooltip ทั้งหมด

---

## Cache Busting

ไฟล์ `index.php` ใช้ `filemtime()` ต่อท้าย URL ของไฟล์ JS/CSS ทุกไฟล์
เมื่อแก้ไขไฟล์แล้ว browser ทุกเครื่องจะโหลดเวอร์ชันใหม่อัตโนมัติ ไม่ต้อง clear cache

---

## Stack

- **Frontend**: HTML5, Bootstrap 5, Tailwind CSS, Chart.js, SweetAlert2
- **Backend**: PHP 8+, MySQL
- **Library**: PHPSpreadsheet (Export Excel)
- **Font**: Kanit, Poppins (Google Fonts)
