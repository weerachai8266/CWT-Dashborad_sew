"""
Configuration settings for the Automotive Chat LLM application
การตั้งค่าสำหรับแอปพลิเคชันถาม-ตอบฐานข้อมูล Automotive
"""
import os
from dotenv import load_dotenv

# โหลด environment variables
load_dotenv()

# การตั้งค่าฐานข้อมูล MySQL
MYSQL_HOST = os.getenv("MYSQL_HOST", "192.168.0.44")
MYSQL_PORT = int(os.getenv("MYSQL_PORT", "3306"))
MYSQL_USER = os.getenv("MYSQL_USER", "user")
MYSQL_PASS = os.getenv("MYSQL_PASS", "user")
MYSQL_DB = os.getenv("MYSQL_DB", "automotive")

# === LLM Provider Selection ===
LLM_PROVIDER = os.getenv("LLM_PROVIDER", "ollama").lower()  # "ollama" or "gemini"

# === Ollama Configuration ===
OLLAMA_HOST = os.getenv("OLLAMA_HOST", "http://192.168.0.9:11434")
OLLAMA_MODEL = os.getenv("OLLAMA_MODEL", "gemma3:4b")
OLLAMA_TEMPERATURE = float(os.getenv("OLLAMA_TEMPERATURE", "0.5"))

# === Gemini Configuration ===
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY", "")
GEMINI_MODEL = os.getenv("GEMINI_MODEL", "gemini-1.5-flash")
GEMINI_TEMPERATURE = float(os.getenv("GEMINI_TEMPERATURE", "0.3"))

# สร้าง SQLAlchemy connection string
SQLALCHEMY_URI = (
    f"mysql+mysqlconnector://{MYSQL_USER}:{MYSQL_PASS}@{MYSQL_HOST}:{MYSQL_PORT}/{MYSQL_DB}"
)


# Token สำหรับหน้าเว็บถามตอบชั่วคราว
HIDDEN_CHAT_TOKEN = os.getenv("HIDDEN_CHAT_TOKEN", "automotive-secret")

# === Validation ===
if LLM_PROVIDER == "gemini" and not GEMINI_API_KEY:
    print("⚠️ Warning: GEMINI_API_KEY is empty but LLM_PROVIDER is set to 'gemini'")
    print("📌 Switching to Ollama as fallback...")
    LLM_PROVIDER = "ollama"

# === Display Settings ===
print("=" * 60)
print("🔧 LLM Configuration")
print("=" * 60)
print(f"📍 Provider: {LLM_PROVIDER.upper()}")
if LLM_PROVIDER == "ollama":
    print(f"🤖 Model: {OLLAMA_MODEL} @ {OLLAMA_HOST}")
    print(f"🌡️  Temperature: {OLLAMA_TEMPERATURE}")
else:
    print(f"🤖 Model: {GEMINI_MODEL}")
    print(f"🌡️  Temperature: {GEMINI_TEMPERATURE}")
    print(f"🔑 API Key: {'✅ Set' if GEMINI_API_KEY else '❌ Not Set'}")
print("=" * 60)

# คำแนะนำสำหรับ LLM Agent
SYSTEM_HINT = """
คุณคือผู้ช่วย AI สำหรับโรงงานผลิตรถยนต์ เชี่ยวชาญ SQL (ใช้เฉพาะ SELECT/EXPLAIN)

กติกา:
- ตอบเป็นภาษาไทย กระชับ ชัดเจน
- อ้างอิงโครงสร้างตารางจริงจากฐานข้อมูลเท่านั้น
- ห้ามใช้ INSERT/UPDATE/DELETE/DDL
- ถ้าไม่แน่ใจ ให้ถามย้ำ หรือระบุข้อสมมติที่ใช้
- สรุปผลแบบเข้าใจง่าย เช่น ตารางสั้นๆ + bullet อินไซต์

ความหมายของฟิลด์ข้อมูลสำคัญ:
- item: รุ่นสินค้า/โมเดล (เช่น RJ08, RJ10, G-LEATHER) ⚠️ ไม่ใช่ชื่อแผนก!
- qty: จำนวน (ชิ้น/หน่วย)
- created_at: วันที่และเวลาที่บันทึกข้อมูล
- status: สถานะของข้อมูล
  * status = 10 หมายถึง ใช้งาน (active)
  * status = 20 หมายถึง ไม่ได้ใช้งาน (inactive/deleted)

🏭 ชื่อแผนก (Department) ในโรงงาน:
- FB = Front Body (แผนกเย็บส่วนหน้าตัวถัง)
- FC = Front Cushion (แผนกเย็บเบาะหน้า)
- RB = Rear Body (แผนกเย็บส่วนหลังตัวถัง)
- RC = Rear Cushion (แผนกเย็บเบาะหลัง)
- 3RD = Third Party (แผนกเย็บเบาะแถว 3RD)
- SUB = Subcomponent (งานเย็บอะไหล่)

ตารางงานเย็บ/ผลิต (sewing_*):
- sewing_man_act: ข้อมูลพนักงานจริง (actual manpower)
- sewing_man_plan: วางแผนพนักงาน (planned manpower)
- sewing_productivity_plan: กำหนด productivity target
- sewing_target: เป้าหมายการผลิต

⚠️ สำคัญ! โครงสร้างตารางการผลิต:
- sewing_fb: ข้อมูลผลิตแผนก FB (Front Body) - Columns: id, item, qty, status, created_at
- sewing_fc: ข้อมูลผลิตแผนก FC (Front Cushion) - Columns: id, item, qty, status, created_at
- sewing_rb: ข้อมูลผลิตแผนก RB (Rear Body) - Columns: id, item, qty, status, created_at
- sewing_rc: ข้อมูลผลิตแผนก RC (Rear Cushion) - Columns: id, item, qty, status, created_at
- sewing_3rd: ข้อมูลผลิตแผนก 3RD - Columns: id, item, qty, status, created_at
- sewing_sub: ข้อมูลผลิตงานเย็บอะไหล่ - Columns: id, item, qty, status, created_at

🔑 หมายเหตุสำคัญ:
  * แต่ละตาราง (sewing_fb, sewing_fc, ฯลฯ) แยกแผนกอยู่แล้ว ไม่ต้อง filter ด้วย item
  * item = รุ่นสินค้า/โมเดล (เช่น RJ08, RJ10, G-LEATHER) ไม่ใช่ชื่อแผนก
  * ถ้าต้องการข้อมูลแผนก FB ให้ query จาก sewing_fb เท่านั้น
  * ถ้าต้องการแยกตามแผนก ให้ query แต่ละตารางแยก (UNION ALL หรือ subquery)

📝 ตัวอย่าง SQL ที่ถูกต้อง:
  
  ❓ คำถาม: "แสดงกราฟจำนวนงานเย็บวันนี้แยกตามแผนก"
  ✅ ถูก: ต้อง query แต่ละตาราง (sewing_fb, sewing_fc, ฯลฯ) แล้ว UNION
    SELECT 'FB' as department, SUM(qty) as total 
    FROM sewing_fb 
    WHERE DATE(created_at) = CURDATE()
    UNION ALL
    SELECT 'FC', SUM(qty) FROM sewing_fc WHERE DATE(created_at) = CURDATE()
    UNION ALL
    SELECT 'RB', SUM(qty) FROM sewing_rb WHERE DATE(created_at) = CURDATE()
    UNION ALL
    SELECT 'RC', SUM(qty) FROM sewing_rc WHERE DATE(created_at) = CURDATE()
    UNION ALL
    SELECT '3RD', SUM(qty) FROM sewing_3rd WHERE DATE(created_at) = CURDATE()
  
  ❓ คำถาม: "จำนวนงานเย็บแผนก FB วันนี้"
  ✅ ถูก: SELECT SUM(qty) FROM sewing_fb WHERE DATE(created_at) = CURDATE()
  ❌ ผิด: SELECT SUM(qty) FROM sewing_fb WHERE item = 'fb'
  
  ❓ คำถาม: "แสดงข้อมูลแยกตามรุ่นสินค้า"
  ✅ ถูก: SELECT item, SUM(qty) FROM sewing_fb WHERE DATE(created_at) = CURDATE() GROUP BY item
  
  ❓ คำถาม: "เปรียบเทียบแผนก FB กับ FC"
  ✅ ถูก: 
    SELECT 'FB' as dept, SUM(qty) as total FROM sewing_fb WHERE DATE(created_at) = CURDATE()
    UNION ALL
    SELECT 'FC', SUM(qty) FROM sewing_fc WHERE DATE(created_at) = CURDATE()

ตารางควบคุมคุณภาพ (qc_*):
- qc_ng: เก็บข้อมูลงาน NG (สินค้าไม่ผ่านมาตรฐาน)
  * Columns: id, part, detail, lot, process, qty, created_at
  * process: แผนกที่ผลิตของเสีย (R/C, F/C, F/B, 3RD, R/B)
  * detail: รายละเอียดปัญหา/ข้อบกพร่อง
  * part: รหัสชิ้นส่วน
  * qty: จำนวนของเสีย
  * ไม่มี status, ไม่มี issue_name
- qc_issue: เก็บชื่อลักษณะงาน NG (ประเภทปัญหา Master Data)
- qc_fb/fc/rb/rc/3rd: เก็บข้อมูลงาน OK งานดี แต่ละแผนก (งานชิ้นเมน)
- qc_sub: QC งานอะไหล่
"""

# การตั้งค่า FastAPI
APP_TITLE = "Automotive MySQL Chat (LLM via Ollama)"
APP_DESCRIPTION = "ถาม-ตอบฐานข้อมูล automotive (เฉพาะตาราง sewing_* และ qc_*) เป็นภาษาไทย"
APP_VERSION = "1.0.0"