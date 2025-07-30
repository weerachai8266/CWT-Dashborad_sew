# CWT Production Dashboard - Toggle Switch Feature

## 🎯 ฟีเจอร์ที่เพิ่มเข้ามา

### 1. Toggle Switch ชิ้น/เปอร์เซ็น
- **ตำแหน่ง**: ใน Report Filter Section (บรรทัด 105-117 ของ index.php)
- **การใช้งาน**: คลิกที่ Toggle Switch เพื่อสลับระหว่าง "ชิ้น" และ "เปอร์เซ็น"
- **แสดงผล**: 
  - เมื่อเลือก "ชิ้น" จะแสดงจำนวนชิ้นจริง
  - เมื่อเลือก "เปอร์เซ็น" จะแสดงเปอร์เซ็นต์เทียบกับเป้าหมาย

### 2. ระบบคำนวณเปอร์เซ็นต์
- **เป้าหมาย**: ดึงจากตาราง `sewing_target`
- **เวลาพักเบรค**: หักออกจากเวลาทำงานจริง
- **สูตรคำนวณ**: `(ผลผลิตจริง / เป้าหมายปรับตามเวลาทำงานจริง) × 100`

### 3. Color Coding System
- 🔴 **แดง (0-79%)**: ต่ำกว่าเป้าหมายมาก
- 🟡 **เหลือง (80-94%)**: ใกล้เป้าหมาย  
- 🟢 **เขียว (95-104%)**: ตามเป้าหมาย
- 🔵 **น้ำเงิน (105%+)**: เกินเป้าหมาย

## 📋 วิธีการติดตั้งและใช้งาน

### 1. สร้างตารางฐานข้อมูล
```sql
-- รันคำสั่ง SQL ใน sql/create_break_times_table.sql
SOURCE sql/create_break_times_table.sql;
```

### 2. ทดสอบระบบ Toggle Switch
1. เปิดไฟล์ `test_toggle.html` เพื่อทดสอบ Toggle Switch
2. คลิกที่ Toggle Switch เพื่อดูการเปลี่ยนแปลงของกราฟ
3. สังเกตการเปลี่ยนสีและข้อมูลของกราฟ

### 3. เข้าใช้งานระบบจริง
1. เปิดเว็บไซต์ที่ `index.php`
2. ใน Report Filter Section จะเห็น Toggle Switch "แสดงผล"
3. คลิกเพื่อสลับระหว่าง "ชิ้น" และ "เปอร์เซ็น"
4. รอให้ระบบโหลดข้อมูลและอัปเดตกราฟ

### 4. จัดการเวลาพักเบรค
1. คลิกปุ่ม "⚙️ จัดการเบรค" ใน Report Filter
2. เพิ่ม/แก้ไข/ลบเวลาพักเบรคได้ตามต้องการ

## 🔧 ไฟล์ที่เกี่ยวข้อง

### Frontend
- `index.php` - หน้าหลักพร้อม Toggle Switch
- `css/style.css` - CSS สำหรับ Toggle Switch และ Color Coding
- `script/script.js` - JavaScript จัดการ Toggle Switch

### Backend
- `api/data.class.php` - ฟังก์ชันคำนวณเปอร์เซ็นต์และเวลาพักเบรค
- `api/get_report_data.php` - API endpoint รองรับ display_type

### Management
- `break_management.php` - หน้าจัดการเวลาพักเบรค
- `sql/create_break_times_table.sql` - SQL สำหรับสร้างตาราง

### Testing
- `test_toggle.html` - ไฟล์ทดสอบ Toggle Switch (ทำงานได้ดี ✅)

## 🎨 UI Components

### Toggle Switch
```html
<div class="display-toggle-container">
    <label class="form-label small mb-1">แสดงผล</label>
    <div class="toggle-switch">
        <input type="checkbox" id="displayToggle" class="toggle-input">
        <label for="displayToggle" class="toggle-label">
            <span class="toggle-text" data-on="เปอร์เซ็น" data-off="ชิ้น"></span>
            <span class="toggle-slider"></span>
        </label>
    </div>
</div>
```

### Summary Cards พร้อม Percentage Badge
```html
<div class="border rounded p-3 position-relative summary-card" data-line="fc">
    <h5 class="text-success" id="totalFC">0</h5>
    <small id="labelFC">F/C ชิ้น</small>
    <span class="percentage-badge d-none" id="percentageFC"></span>
</div>
```

## 📊 การทำงานของระบบ

### เมื่อสลับเป็นโหมด "เปอร์เซ็น"
1. กราฟจะเปลี่ยนสีตามเปอร์เซ็นต์
2. Summary Statistics จะแสดงเปอร์เซ็นต์แทนจำนวนชิ้น
3. Percentage Badge จะแสดงจำนวนชิ้นจริง
4. Tooltip จะแสดงสถานะ (เกินเป้าหมาย/ตามเป้าหมาย/ฯลฯ)

### เมื่อสลับเป็นโหมด "ชิ้น"
1. กราฟจะใช้สีเดิมตาม Line
2. Summary Statistics จะแสดงจำนวนชิ้นจริง
3. Percentage Badge จะซ่อน

## 🔄 Real-time Updates
- ระบบรองรับการอัปเดตแบบ Real-time ทุก 30 วินาที
- เมื่อสลับ Toggle Switch จะอัปเดตข้อมูลทันที
- Animation Effects สำหรับค่าที่ต่ำหรือสูงเกินไป

## 🧪 การทดสอบ
### ✅ ผลการทดสอบ
- **test_toggle.html**: ทำงานได้ดี - Toggle Switch สลับโหมดได้ถูกต้อง
- **Color Coding**: เปลี่ยนสีตามเปอร์เซ็นต์ได้ถูกต้อง
- **Chart Updates**: กราฟอัปเดตทันทีเมื่อสลับโหมด
- **Y-axis Labels**: แสดง % สำหรับโหมดเปอร์เซ็นต์ได้ถูกต้อง

### 🔧 การแก้ไขปัญหา
หากกราฟไม่เปลี่ยนเป็นเปอร์เซ็น:
1. ตรวจสอบ Console ว่ามี JavaScript Error หรือไม่
2. ตรวจสอบว่าตาราง `break_times` และ `sewing_target` มีข้อมูลหรือไม่
3. ลองทดสอบด้วย `test_toggle.html` ก่อน

## 🎯 เป้าหมายเริ่มต้น (ถ้าไม่มีในฐานข้อมูล)
- F/C: 160 ชิ้น/ชั่วโมง
- F/B: 135 ชิ้น/ชั่วโมง  
- R/C: 195 ชิ้น/ชั่วโมง
- R/B: 106 ชิ้น/ชั่วโมง
- 3RD: 93 ชิ้น/ชั่วโมง
- Sub: 101 ชิ้น/ชั่วโมง

## ⏰ เวลาพักเบรคเริ่มต้น
- เบรคเช้า: 08:00-08:10 (10 นาที)
- เบรคสาย: 10:00-10:10 (10 นาที)
- พักกลางวัน: 12:10-13:10 (60 นาที)
- เบรคบ่าย: 15:00-15:10 (10 นาที)
- เบรคเย็น: 16:50-17:00 (10 นาที)
- ล้างมือ: 17:00-17:30 (30 นาที)

**รวมเวลาพักเบรค: 130 นาที (2 ชั่วโมง 10 นาที)**