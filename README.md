# CWT Production Dashboard

ระบบ Dashboard สำหรับติดตามข้อมูลการผลิต คุณภาพ และประสิทธิภาพของไลน์เย็บ

## ฟีเจอร์หลัก

- `Production` แสดงผลการผลิตรายไลน์ พร้อม KPI และกราฟ
- `Quality` สรุปของเสีย ปัญหาที่พบบ่อย และ cross-table
- `Performance` ดูประสิทธิภาพรายวันและรายเดือน
- `Export` ส่งออกข้อมูลจากแต่ละโมดูล
- `Real-time update` รีเฟรชข้อมูลอัตโนมัติสำหรับหน้า Production

## โครงสร้างหลัก

- `index.html` หน้าหลักของ Dashboard
- `css/style.css` สไตล์หลักของระบบ
- `script/config.js` ค่าตั้งต้นและตัวแปรกลาง
- `script/data.js` โหลดและอัปเดตข้อมูล
- `script/charts.js` สร้างกราฟ
- `script/gauges.js` วาด KPI gauge
- `script/ui.js` จัดการ interaction ฝั่งหน้าเว็บ
- `api/` ชุด endpoint สำหรับดึงข้อมูลและ export

## การติดตั้ง

1. ตั้งค่า database ใน `config/db.php`
2. import schema ที่ต้องใช้จาก `sql/`
3. เปิด `index.html` ผ่าน web server/PHP environment เดิมของโปรเจกต์

## หมายเหตุ

- แนะนำแยกฟีเจอร์เสริมออกจาก dashboard หลักเพื่อให้ดูแลง่ายขึ้น
