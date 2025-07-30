# Dashboard Toggle Switch Feature - สรุปการทำงาน

## 🎯 ภาพรวมของระบบ

ระบบ Toggle Switch ใน Dashboard Tab ของ Report Filter ที่ช่วยให้ผู้ใช้สามารถสลับการแสดงผลข้อมูลระหว่าง:
- **"ชิ้น"** (pieces) - แสดงจำนวนชิ้นงานที่ผลิตได้จริง
- **"เปอร์เซ็น"** (percentage) - แสดงเปอร์เซ็นต์ความสำเร็จเทียบกับเป้าหมาย

## 🔧 คุณสมบัติหลัก

### 1. Toggle Switch UI
- ตำแหน่ง: Report Filter Section ของหน้า Dashboard
- การทำงาน: คลิกเพื่อสลับระหว่าง "ชิ้น" และ "เปอร์เซ็น"
- การแสดงผล: อัปเดตทั้ง Charts และ Summary Statistics แบบ Real-time

### 2. การคำนวณเปอร์เซ็นต์
```
เปอร์เซ็นต์ = (ผลผลิตจริง / เป้าหมายที่ปรับตามเวลาทำงาน) × 100

เป้าหมายที่ปรับ = เป้าหมายรายชั่วโมง × เวลาทำงานจริง (นาที) / 60
```

### 3. ระบบ Color Coding
- 🔴 **แดง (0-79%)**: ต่ำกว่าเป้าหมาย
- 🟡 **เหลือง (80-94%)**: ใกล้เป้าหมาย
- 🟢 **เขียว (95-104%)**: ตามเป้าหมาย
- 🔵 **น้ำเงิน (105%+)**: เกินเป้าหมาย

### 4. การจัดการเวลาพักเบรค
- คำนวณเวลาทำงานจริงโดยหักเวลาพักเบรค
- รองรับการตั้งค่าเวลาพักเบรคที่แตกต่างกันในแต่ละวัน
- เป้าหมายจะถูกปรับตามเวลาทำงานจริง

## 📁 ไฟล์ที่เกี่ยวข้อง

### Frontend Files
- **`index.php`** (บรรทัด 105-117): Toggle Switch HTML
- **`script/script.js`**: JavaScript สำหรับ Toggle Switch และ Chart Updates
- **`css/style.css`**: CSS สำหรับ Toggle Switch และ Color Coding

### Backend Files
- **`api/data.class.php`**: 
  - `getTargets()`: ดึงเป้าหมายตามวันที่ Filter
  - `getActualWorkingMinutes()`: คำนวณเวลาทำงานจริง
  - `getSummaryReport()` & `getHourlyReport()`: รองรับทั้งสองโหมด
- **`api/get_report_data.php`**: API Endpoint สำหรับดึงข้อมูล

### Database Files
- **`sql/create_break_times_table.sql`**: ตารางเก็บเวลาพักเบรค
- **`break_management.php`**: หน้าจัดการเวลาพักเบรค

### Testing Files
- **`test_date_filter.php`**: ทดสอบการคำนวณตามวันที่ Filter
- **`test_chart_toggle.html`**: ทดสอบการทำงานของ Chart Toggle
- **`create_sample_data.php`**: สร้างข้อมูลทดสอบ
- **`debug_data.php`**: ตรวจสอบข้อมูลในฐานข้อมูล

## 🔄 การทำงานของระบบ

### 1. เมื่อผู้ใช้คลิก Toggle Switch:
```javascript
function toggleDisplayType() {
    const toggle = document.getElementById('displayToggle');
    const isPercentage = toggle.checked;
    
    // อัปเดต Global Variable
    window.currentDisplayType = isPercentage ? 'percentage' : 'pieces';
    
    // อัปเดต Charts และ Summary
    updateCharts();
    updateSummaryCards();
}
```

### 2. การดึงข้อมูลจาก API:
```javascript
// API Call พร้อม display_type parameter
const apiUrl = `api/get_report_data.php?type=${type}&start_date=${startDate}&end_date=${endDate}&display_type=${displayType}`;
```

### 3. การคำนวณใน Backend:
```php
// ดึงเป้าหมายตามวันที่ Filter
$targets = $this->getTargets($start_date);

// คำนวณเวลาทำงานจริง
$actualMinutes = $this->getActualWorkingMinutes($start_date);

// คำนวณเป้าหมายที่ปรับแล้ว
$adjustedTarget = ($targets[$line] * $actualMinutes) / 60;

// คำนวณเปอร์เซ็นต์
$percentage = ($actualMinutes > 0) ? ($total_qty / $adjustedTarget) * 100 : 0;
```

## 🎨 การแสดงผลแบบ Responsive

### Summary Cards
- แสดงค่าตัวเลขพร้อม Badge สีตาม Color Coding
- สลับระหว่างหน่วย "ชิ้น" และ "%" อัตโนมัติ

### Charts
- อัปเดตสีของ Bar Charts ตาม Color Coding
- แสดง Tooltip พร้อมข้อมูลเปอร์เซ็นต์
- Animation เมื่อสลับโหมด

## 🧪 การทดสอบ

### 1. ทดสอบการคำนวณตามวันที่:
```bash
php test_date_filter.php
```

### 2. ทดสอบ Chart Toggle:
เปิดไฟล์ `test_chart_toggle.html` ในเบราว์เซอร์

### 3. ทดสอบ Frontend:
1. เปิด `index.php`
2. เลือกวันที่ต่างๆ ใน Date Filter
3. สลับ Toggle Switch และสังเกตการเปลี่ยนแปลง

## 📊 ตัวอย่างผลลัพธ์

### โหมด "ชิ้น":
```json
{
    "sub": {
        "total_qty": 317,
        "percentage": 74.9,
        "target": 54
    }
}
```

### โหมด "เปอร์เซ็น":
```json
{
    "sub": {
        "total_qty": 317,
        "percentage": 74.9,
        "target": 54,
        "color": "red"
    }
}
```

## 🔧 การบำรุงรักษา

### เพิ่มเป้าหมายใหม่:
```sql
INSERT INTO sewing_target (fc, fb, rc, rb, `3rd`, sub, created_at) 
VALUES (200, 180, 250, 150, 120, 130, '2025-07-30 08:00:00');
```

### จัดการเวลาพักเบรค:
เข้าหน้า `break_management.php` เพื่อเพิ่ม/แก้ไขเวลาพักเบรค

## ✅ สถานะการพัฒนา

- [x] Toggle Switch UI Implementation
- [x] Color Coding System
- [x] Break Time Management
- [x] Percentage Calculation Logic
- [x] API Integration
- [x] Frontend Chart Updates
- [x] Date Filter Integration
- [x] Testing & Debugging
- [x] Documentation

## 🎯 ผลลัพธ์สุดท้าย

ระบบ Toggle Switch ทำงานได้อย่างสมบูรณ์ โดย:
1. ✅ คำนวณเปอร์เซ็นต์ตามเป้าหมายของวันที่ที่เลือกใน Filter
2. ✅ แสดงผล Color Coding ตามช่วงเปอร์เซ็นต์
3. ✅ อัปเดต Charts และ Summary Cards แบบ Real-time
4. ✅ รองรับการจัดการเวลาพักเบรค
5. ✅ ใช้งานง่ายและตอบสนองได้ดี

---
*สร้างเมื่อ: 30 กรกฎาคม 2025*
*ผู้พัฒนา: Kilo Code*