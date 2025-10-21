"""
LLM Agent layer for Automotive Chat application
การจัดการ LLM Agent สำหรับแอปพลิเคชันถาม-ตอบฐานข้อมูล Automotive
รองรับ Ollama และ Gemini พร้อมฟีเจอร์สร้างกราฟอัตโนมัติ
"""
import signal
from datetime import datetime, timedelta
import locale
import json
import re

from langchain_google_genai import ChatGoogleGenerativeAI # ใช้ ChatGoogleGenerativeAI
from langchain.chains import create_sql_query_chain
from langchain.prompts import PromptTemplate

from .config import (
    LLM_PROVIDER,
    OLLAMA_HOST, OLLAMA_MODEL, OLLAMA_TEMPERATURE,
    GEMINI_API_KEY, GEMINI_MODEL, GEMINI_TEMPERATURE,
    SYSTEM_HINT, SQLALCHEMY_URI
)
from .database import discover_allowed_tables, get_database_connection

import logging
logger = logging.getLogger(__name__)

# ตั้งค่า locale เป็นภาษาไทย (สำหรับแสดงวันที่)
try:
    locale.setlocale(locale.LC_TIME, 'th_TH.UTF-8')
except:
    try:
        locale.setlocale(locale.LC_TIME, 'Thai_Thailand.874')
    except:
        pass  # ถ้าไม่มี Thai locale ให้ใช้ default


def get_current_date_info() -> dict:
    """ดึงข้อมูลวันที่ปัจจุบัน"""
    now = datetime.now()
    
    # วันที่สำคัญ
    yesterday = now - timedelta(days=1)
    tomorrow = now + timedelta(days=1)
    
    # หาวันแรกและวันสุดท้ายของสัปดาห์ (จันทร์-อาทิตย์)
    start_of_week = now - timedelta(days=now.weekday())
    end_of_week = start_of_week + timedelta(days=6)
    
    # หาวันแรกและวันสุดท้ายของเดือน
    start_of_month = now.replace(day=1)
    if now.month == 12:
        end_of_month = now.replace(year=now.year + 1, month=1, day=1) - timedelta(days=1)
    else:
        end_of_month = now.replace(month=now.month + 1, day=1) - timedelta(days=1)
    
    # ชื่อวันภาษาไทย
    thai_days = ['จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์', 'อาทิตย์']
    thai_months = [
        'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
    ]
    
    return {
        # วันที่เป็นตัวเลข (MySQL format)
        'today': now.strftime('%Y-%m-%d'),
        'yesterday': yesterday.strftime('%Y-%m-%d'),
        'tomorrow': tomorrow.strftime('%Y-%m-%d'),
        'week_start': start_of_week.strftime('%Y-%m-%d'),
        'week_end': end_of_week.strftime('%Y-%m-%d'),
        'month_start': start_of_month.strftime('%Y-%m-%d'),
        'month_end': end_of_month.strftime('%Y-%m-%d'),
        
        # วันที่เป็นภาษาไทย (เพื่อความเข้าใจ)
        'today_th': f"วัน{thai_days[now.weekday()]}ที่ {now.day} {thai_months[now.month-1]} {now.year + 543}",
        'yesterday_th': f"วัน{thai_days[yesterday.weekday()]}ที่ {yesterday.day} {thai_months[yesterday.month-1]} {yesterday.year + 543}",
        
        # ข้อมูลเพิ่มเติม
        'day_of_week': thai_days[now.weekday()],
        'day_of_month': now.day,
        'month': thai_months[now.month - 1],
        'year_th': now.year + 543,
        'year_ad': now.year,
        'week_number': now.isocalendar()[1],
        'time': now.strftime('%H:%M:%S'),
    }

class AutomotiveChatAgent:
    """
    คลาสสำหรับจัดการ LLM Agent ที่ใช้ในการถาม-ตอบฐานข้อมูล
    รองรับ Ollama และ Gemini พร้อมฟีเจอร์สร้างกราฟอัตโนมัติ
    """
    
    def __init__(self):
        """
        เริ่มต้น Agent พร้อมการเชื่อมต่อฐานข้อมูลและ LLM
        """
        self.llm = None
        self.database = None
        self.sql_query_chain = None
        self.llm_provider = LLM_PROVIDER
        self.date_info = get_current_date_info()

        self.answer_prompt = PromptTemplate(
            input_variables=["question", "sql_query", "sql_result"],
            template=(
                f"{SYSTEM_HINT}\n\n"
                "คำถาม: {question}\n"
                "SQL ที่ใช้: {sql_query}\n"
                "ผลลัพธ์จากฐานข้อมูล: {sql_result}\n\n"
                "กรุณาสรุปคำตอบภาษาไทยให้เข้าใจง่าย หากข้อมูลไม่เพียงพอให้ระบุอย่างชัดเจน"
            ),
        )
        self._initialize_agent()    
   
    def _initialize_agent(self):
        """สร้าง LLM และ Agent พร้อมข้อมูลวันที่"""
        try:
            print(f"\n{'='*60}")
            print(f"🚀 Initializing LLM Agent...")
            print(f"📍 Provider: {self.llm_provider.upper()}")
            print(f"📅 Today: {self.date_info['today_th']}")
            print(f"{'='*60}\n")

            # เลือก LLM ตาม Provider
            if self.llm_provider == "gemini":
                self._init_gemini()
            elif self.llm_provider == "ollama":
                self._init_ollama()
            else:
                raise ValueError(f"Invalid LLM_PROVIDER: {self.llm_provider}")

            self.database = get_database_connection()

            # เพิ่มข้อมูลวันที่ใน Prompt
            date_info_text = (
                f"**Current Date Information:**\n"
                f"- Today (วันนี้): {self.date_info['today']}\n"
                f"- Yesterday (เมื่อวาน): {self.date_info['yesterday']}\n"
                f"- This Week (สัปดาห์นี้): {self.date_info['week_start']} to {self.date_info['week_end']}\n"
                f"- This Month (เดือนนี้): {self.date_info['month_start']} to {self.date_info['month_end']}\n"
                f"- Current Time: {self.date_info['time']}\n"
            )

            sql_prompt = PromptTemplate(
                input_variables=["input", "table_info", "top_k", "dialect"],
                template=(
                    f"{date_info_text}\n"
                    "Tables:\n{table_info}\n"
                    "Dialect: {dialect}, Limit: {top_k}\n"
                    "\n**IMPORTANT RULES:**\n"
                    "1. qc_ng table: id, part, detail, lot, process, qty, created_at\n"
                    "   - qc_ng has NO 'status' column!\n"
                    "\n2. Other tables MUST use: WHERE status = 10\n"
                    "\n3. **Date Filtering (USE CURRENT DATE INFO ABOVE):**\n"
                    f"   - 'วันนี้' (today) → WHERE DATE(created_at) = '{self.date_info['today']}'\n"
                    f"   - 'เมื่อวาน' (yesterday) → WHERE DATE(created_at) = '{self.date_info['yesterday']}'\n"
                    f"   - 'สัปดาห์นี้' (this week) → WHERE DATE(created_at) BETWEEN '{self.date_info['week_start']}' AND '{self.date_info['week_end']}'\n"
                    f"   - 'เดือนนี้' (this month) → WHERE DATE(created_at) BETWEEN '{self.date_info['month_start']}' AND '{self.date_info['month_end']}'\n"
                    "\n4. **Combine Multiple Tables:**\n"
                    "   - For 'total production': Use UNION ALL for all sewing tables\n"
                    "\nQuestion: {input}\n"
                    "SQL:"
                ),
            )

            self.sql_query_chain = create_sql_query_chain(
                llm=self.llm,
                db=self.database,
                prompt=sql_prompt,
                k=5,
            )
            
            self.database._sample_rows_in_table_info = 0
            print("✅ LLM Agent เริ่มต้นสำเร็จพร้อมข้อมูลวันที่")
            
        except Exception as e:
            print(f"❌ ไม่สามารถเริ่มต้น LLM Agent ได้: {e}")
            raise e
    
    def _init_ollama(self):
        """เริ่มต้น Ollama LLM"""
        from langchain_ollama import OllamaLLM
        
        print(f"🤖 Connecting to Ollama...")
        print(f"   Host: {OLLAMA_HOST}")
        print(f"   Model: {OLLAMA_MODEL}")
        print(f"   Temperature: {OLLAMA_TEMPERATURE}")
        
        self.llm = OllamaLLM(
            base_url=OLLAMA_HOST,
            model=OLLAMA_MODEL,
            temperature=OLLAMA_TEMPERATURE
        )
        
        print(f"✅ Ollama LLM connected")
    
    def _init_gemini(self):
        """เริ่มต้น Gemini LLM"""
        from langchain_google_genai import ChatGoogleGenerativeAI
        
        if not GEMINI_API_KEY:
            raise Exception("GEMINI_API_KEY is not set")
        
        print(f"🤖 Connecting to Gemini...")
        print(f"   Model: {GEMINI_MODEL}")
        print(f"   Temperature: {GEMINI_TEMPERATURE}")
        
        self.llm = ChatGoogleGenerativeAI(
            model=GEMINI_MODEL,
            google_api_key=GEMINI_API_KEY,
            temperature=GEMINI_TEMPERATURE,
            convert_system_message_to_human=True
        )
        
        print(f"✅ Gemini LLM connected")
        
    def _classify_question(self, question: str) -> str:
        """จำแนกประเภทคำถาม"""
        try:
            if self.llm_provider == "ollama":
                from langchain_ollama import OllamaLLM
                classifier_llm = OllamaLLM(
                    model=OLLAMA_MODEL,
                    temperature=0.0,
                    base_url=OLLAMA_HOST,
                    timeout=30
                )
            else:
                from langchain_google_genai import ChatGoogleGenerativeAI
                classifier_llm = ChatGoogleGenerativeAI(
                    model=GEMINI_MODEL,
                    google_api_key=GEMINI_API_KEY,
                    temperature=0.0,
                    convert_system_message_to_human=True
                )

            date_context = (
                f"\n**ข้อมูลวันที่ปัจจุบัน:**\n"
                f"- วันนี้: {self.date_info['today_th']} ({self.date_info['today']})\n"
                f"- เมื่อวาน: {self.date_info['yesterday_th']} ({self.date_info['yesterday']})\n"
                f"- สัปดาห์นี้: {self.date_info['week_start']} ถึง {self.date_info['week_end']}\n"
                f"- เดือนนี้: {self.date_info['month']} {self.date_info['year_th']}\n"
                f"- เวลาปัจจุบัน: {self.date_info['time']}\n"
            )

            classify_prompt = f"""คุณเป็น AI ที่ช่วยจำแนกประเภทคำถาม
{date_context}
ฐานข้อมูลของเรามีข้อมูลเกี่ยวกับ: การผลิตรถยนต์, คุณภาพ QC/NG (ของเสีย), พนักงาน, เป้าหมายการผลิต, ปัญหาในโรงงาน, สายการผลิต

คำถาม: "{question}"

คำถามนี้ต้องการข้อมูลจากฐานข้อมูล หรือเป็นคำถามทั่วไป?

ตัวอย่าง "database":
- "วันนี้มีของเสียกี่ชิ้น"
- "เมื่อวานมีของเสียเท่าไหร่"
- "แผนกไหนมีปัญหามากที่สุด"

ตัวอย่าง "general":
- "สวัสดี"
- "วันนี้วันอะไร"
- "ขอบคุณ"

ตอบเพียง 1 คำ: "database" หรือ "general"

คำตอบ:"""

            response = classifier_llm.invoke(classify_prompt)
            
            if self.llm_provider == "ollama":
                response_text = response.strip().lower()
            else:
                response_text = response.content if hasattr(response, 'content') else str(response)
                response_text = response_text.strip().lower()

            if "database" in response_text:
                return "database"
            elif "general" in response_text:
                return "general"
            else:
                return "database"
                
        except Exception as e:
            print(f"⚠️ ไม่สามารถจำแนกคำถามได้: {e}")
            return "database"

    def _answer_general_question(self, question: str) -> str:
        """
        ตอบคำถามทั่วไปโดยใช้ LLM โดยตรง (ไม่ผ่าน SQL)
        """
        try:
            # เลือก LLM
            if self.llm_provider == "ollama":
                from langchain_ollama import OllamaLLM
                general_llm = OllamaLLM(
                    model=OLLAMA_MODEL,
                    temperature=0.7,
                    base_url=OLLAMA_HOST,
                    timeout=60
                )
            else:
                from langchain_google_genai import ChatGoogleGenerativeAI
                general_llm = ChatGoogleGenerativeAI(
                    model=GEMINI_MODEL,
                    google_api_key=GEMINI_API_KEY,
                    temperature=0.7,
                    convert_system_message_to_human=True
                )

            # เพิ่มข้อมูลวันที่
            date_context = (
                f"\n**ข้อมูลวันที่และเวลาปัจจุบัน:**\n"
                f"- วันนี้: {self.date_info['today_th']} ({self.date_info['today']})\n"
                f"- เมื่อวาน: {self.date_info['yesterday_th']}\n"
                f"- วันในสัปดาห์: {self.date_info['day_of_week']}\n"
                f"- เดือน: {self.date_info['month']} พ.ศ. {self.date_info['year_th']}\n"
                f"- เวลา: {self.date_info['time']} น.\n"
            )

            general_prompt = f"""คุณเป็น AI ผู้ช่วยที่เป็นมิตรและมีความรู้
{date_context}
คำถาม: {question}

กรุณาตอบคำถามเป็นภาษาไทยที่เป็นธรรมชาติและเข้าใจง่าย 
ถ้าคำถามถามเกี่ยวกับวันที่/เวลา ให้ใช้ข้อมูลที่ให้ไว้ข้างต้น:"""

            response = general_llm.invoke(general_prompt)
            response_text = response.content if hasattr(response, 'content') else str(response)
            return response_text.strip()

        except Exception as e:
            return f"❌ ขออภัยครับ ไม่สามารถตอบคำถามได้ในขณะนี้: {str(e)}"
    
    def _is_safe_query(self, query: str) -> bool:
        """ตรวจสอบว่าคำสั่ง SQL มีความปลอดภัยพอสำหรับการรัน"""
        if not query:
            return False
        
        # ลบ markdown code fence และ whitespace
        cleaned = query.strip()
        if cleaned.startswith('```'):
            # ลบ ```sql หรือ ``` ที่ต้นและท้าย
            lines = cleaned.split('\n')
            if lines[0].startswith('```'):
                lines = lines[1:]
            if lines and lines[-1].strip() == '```':
                lines = lines[:-1]
            cleaned = '\n'.join(lines).strip()
        
        cleaned_lower = cleaned.lower()
        return (cleaned_lower.startswith("select") or 
                cleaned_lower.startswith("with") or 
                cleaned_lower.startswith("explain"))
    
    def _select_relevant_tables(self, question: str) -> list:
        """
        เลือกตารางที่เกี่ยวข้องกับคำถาม (เพื่อลด context size)
        """
        question_lower = question.lower()
        all_tables = self.database.get_usable_table_names()

        # กลุ่มตารางตามหน้าที่
        table_groups = {
            # ข้อมูลของเสีย NG
            'ng': ['qc_ng'],
            
            # ข้อมูลผลิตจริง (output/production)
            'production': ['sewing_fb', 'sewing_fc', 'sewing_rb', 'sewing_rc', 'sewing_3rd', 'sewing_sub'],
            
            # ข้อมูล QC งานดี
            'qc_ok': ['qc_fb', 'qc_fc', 'qc_rb', 'qc_rc', 'qc_3rd', 'qc_sub'],
            
            # ข้อมูลพนักงาน
            'manpower': ['sewing_man_act', 'sewing_man_plan'],
            
            # ข้อมูลเป้าหมายและแผน
            'target': ['sewing_target', 'sewing_productivity_plan'],
            
            # ข้อมูล lot
            'lot': ['sewing_lot'],
            
            # ตาราง master data
            'master': ['qc_issue']
        }
        selected_tables = []
        
        # กรณีพิเศษ: ถามเรื่อง NG ให้เลือก qc_ng เป็นหลัก (ข้อมูลจริง)
        if any(word in question_lower for word in [" ng ", "ng ", " ng", "ของเสีย", "ไม่ผ่าน", "defect", "ปัญหา"]):
            # ถ้าถามเรื่องสถิติ/นับ/เกิดบ่อย/ประเภทปัญหา ให้ใช้ qc_ng อย่างเดียว (ข้อมูลจริง)
            if any(word in question_lower for word in ["บ่อย", "จำนวน", "นับ", "กี่", "count", "มาก", "น้อย", "สถิติ", "ประเภทปัญหา", "ประเภท ng"]):
                selected = ["qc_ng"]
                print(f"🎯 ตรวจพบคำเกี่ยวกับ NG + สถิติ → เลือก qc_ng อย่างเดียว")
                return selected
            # ถ้าถามเรื่อง "ประเภทมีอะไรบ้าง" ให้ใช้ qc_issue (master data)
            elif any(word in question_lower for word in ["มีอะไร", "รายชื่อ", "ชนิด", "types"]):
                selected = ["qc_issue"]
                print(f"🎯 ตรวจพบคำเกี่ยวกับ issue types → เลือก qc_issue")
                return selected
            else:
                # กรณีอื่นๆ ใช้ qc_ng อย่างเดียว
                selected = ["qc_ng"]
                print(f"🎯 ตรวจพบคำเกี่ยวกับ NG → เลือก qc_ng")
                return selected
        
        # กรณีถามเรื่อง issue/ลักษณะ (ไม่เกี่ยวกับ NG)
        if any(word in question_lower for word in ["issue", "ลักษณะ"]):
            if "qc_issue" in all_tables:
                return ["qc_issue"]
        
        # หากถามว่ามีตารางอะไรบ้าง หรือถามทั่วไป
        if any(word in question_lower for word in ["ตารางอะไร", "ตารางไหน", "table", "มีอะไรบ้าง"]):
            return all_tables[:3]
        
        # กรณีถามเรื่อง OK/งานดี ให้เลือก qc_fb/fc/rb/rc
        if any(word in question_lower for word in ["ok", "งานดี", "ผ่าน", "คุณภาพดี"]):
            selected = [t for t in all_tables if t.startswith("qc_") and t not in ["qc_ng", "qc_issue"]]
            if selected:
                return selected[:4]
        
        # เช็คคำสำคัญเพื่อเลือกกลุ่มตาราง
        keywords_map = {
            # คำถามเกี่ยวกับของเสีย NG
            'ng': ['ng', ' ng ', 'ของเสีย', 'ของ เสีย', 'ปัญหา', 'บกพร่อง', 'defect'],
            
            # คำถามเกี่ยวกับการผลิต (output)
            'production': ['ผลิต', 'เย็บ', 'ทำ', 'output', 'production', 'งาน', 'ชิ้น', 'qty', 'จำนวน'],
            
            # คำถามเกี่ยวกับ QC ผ่าน
            'qc_ok': ['qc ', ' qc', 'ผ่าน', 'ok', 'ดี', 'qualify'],
            
            # คำถามเกี่ยวกับพนักงาน
            'manpower': ['พนักงาน', 'คน', 'manpower', 'แรงงาน', 'จำนวนคน'],
            
            # คำถามเกี่ยวกับเป้าหมาย
            'target': ['เป้า', 'target', 'แผน', 'plan', 'productivity'],
            
            # คำถามเกี่ยวกับ lot
            'lot': ['lot', 'ล็อต', 'ล็อค', 'batch']
        }
        # หาว่าคำถามตรงกับกลุ่มไหน
        matched_groups = set()

        # คัดเลือกตารางตาม keyword
        for group, keywords in keywords_map.items():
            for keyword in keywords:
                if keyword in question_lower:
                    matched_groups.add(group)
                    break   # เจอ keyword ในกลุ่มนี้แล้ว เลิกเช็คคำอื่นในกลุ่มนี้
        
        # ถ้าไม่มีตารางที่ match ให้ส่งตารางทั้งหมด
        if not matched_groups:
            matched_groups.add('production')

        # รวมตารางจากกลุ่มที่ตรง
        for group in matched_groups:
            if group in table_groups:
                selected_tables.extend(table_groups[group])

        # ลบตารางซ้ำ
        selected_tables = list(set(selected_tables))

        # เรียงตาราง (NG ก่อน, production ก่อน)
        priority_order = ['qc_ng'] + table_groups['production'] + table_groups['qc_ok']
        selected_tables.sort(key=lambda t: priority_order.index(t) if t in priority_order else 999)

        return selected_tables
    
    def ask_question(self, question: str) -> str:
        """
        ส่งคำถามไปยัง Agent และรับคำตอบกลับมา
        """
        self.date_info = get_current_date_info()

        if not self.sql_query_chain or not self.database:
            return "❌ Agent ยังไม่พร้อมใช้งาน"
        
        try:
            prompt = question.strip()
            
            print(f"🤔 กำลังประมวลผลคำถาม: {prompt}")
            
            # จำแนกประเภทคำถาม
            question_type = self._classify_question(prompt)
            
            # ถ้าเป็นคำถามทั่วไป ให้ตอบโดยตรงโดยไม่ผ่าน SQL
            if question_type == "general":
                print(f"💬 ตรวจพบคำถามทั่วไป → ตอบโดยไม่ใช้ฐานข้อมูล")
                return self._answer_general_question(prompt)
            
            # ถ้าเป็นคำถามเกี่ยวกับฐานข้อมูล ให้ใช้ SQL Agent
            print(f"🗄️ ตรวจพบคำถามเกี่ยวกับฐานข้อมูล → ใช้ SQL Agent")
            
            # เลือกตารางที่เกี่ยวข้องเท่านั้น
            relevant_tables = self._select_relevant_tables(prompt)
            print(f"📊 ใช้ตาราง {len(relevant_tables)} ตาราง: {', '.join(relevant_tables)}")

            # รัน Agent พร้อม timeout handling
            def timeout_handler(signum, frame):
                raise TimeoutError("Agent ใช้เวลานานเกินไป")
            
            signal.signal(signal.SIGALRM, timeout_handler)
            signal.alarm(120)
            
            try:
                sql_query_raw = self.sql_query_chain.invoke({
                    "question": prompt,
                    "table_names_to_use": relevant_tables
                })
                # แปลง response object เป็น text
                if hasattr(sql_query_raw, 'content'):
                    sql_query = sql_query_raw.content
                else:
                    sql_query = str(sql_query_raw)
                
                # ตัดเอาเฉพาะส่วน SQL
                sql_query = sql_query.strip()
                
                if not sql_query.lower().startswith(('select', 'with', 'explain', '```')):
                    lines = sql_query.split('\n')
                    sql_lines = []
                    found_sql = False
                    for line in lines:
                        line_lower = line.strip().lower()
                        if line_lower.startswith(('select', 'with', 'explain')) or line.strip().startswith('```'):
                            found_sql = True
                        if found_sql:
                            sql_lines.append(line)
                    if sql_lines:
                        sql_query = '\n'.join(sql_lines).strip()
                
                # ตัดคำอธิบายท้าย SQL ออก
                if '\n\nคำอธิบาย' in sql_query:
                    sql_query = sql_query.split('\n\nคำอธิบาย')[0].strip()
                elif '\n\nคำถาม' in sql_query:
                    sql_query = sql_query.split('\n\nคำถาม')[0].strip()
                elif '\n\nหรือ' in sql_query:
                    sql_query = sql_query.split('\n\nหรือ')[0].strip()
                
                if '```' in sql_query and sql_query.count('```') >= 2:
                    parts = sql_query.split('```')
                    sql_query = '```'.join(parts[:3])
                
                print(f"🧠 SQL ที่สร้างขึ้น: {sql_query[:200]}")

                if not self._is_safe_query(sql_query):
                    return "⚠️ ระบบสร้างคำสั่ง SQL ที่ไม่ปลอดภัย กรุณาลองถามใหม่"

                # ทำความสะอาด SQL
                clean_sql = sql_query.strip()
                if clean_sql.startswith('```'):
                    lines = clean_sql.split('\n')
                    if lines[0].startswith('```'):
                        lines = lines[1:]
                    if lines and lines[-1].strip() == '```':
                        lines = lines[:-1]
                    clean_sql = '\n'.join(lines).strip()
                
                clean_sql = clean_sql.rstrip(';').strip()

                # รัน SQL
                sql_result = self.database.run(clean_sql)
                result_preview = (
                    sql_result if len(sql_result) <= 4000 else sql_result[:4000] + "..."
                )

                # สรุปผลแบบสั้น เน้นตัวเลข (สำหรับผู้บริหาร)
                summary_prompt = (
                    f"Question: {prompt}\n"
                    f"SQL Result: {result_preview}\n\n"
                    f"Summarize the result in Thai with brief explanation:\n"
                    f"1. Answer the question directly with numbers/data\n"
                    f"2. Add brief context or insight (1 sentence)\n"
                    f"3. NO SQL code, NO table names, NO technical details\n"
                    f"\n"
                    f"Example format:\n"
                    f"วันนี้ผลิตได้ 1,234 ชิ้น รวมทุกแผนกทั้งหมด\n"
                    f"\n"
                    f"OR for multiple data:\n"
                    f"แผนกที่มีของเสียมากที่สุด 3 อันดับ:\n"
                    f"• R/C: 914 ชิ้น (สูงสุด)\n"
                    f"• F/C: 589 ชิ้น\n"
                    f"• F/B: 476 ชิ้น\n"
                    f"\n"
                    f"Keep it professional and easy to understand.\n\n"
                    f"Answer:"
                )
                
                answer_response = self.llm.invoke(summary_prompt)
            
                # แปลง response object เป็น text ที่อ่านได้
                if hasattr(answer_response, 'content'):
                    answer_text = answer_response.content
                else:
                    answer_text = str(answer_response)
                
                answer_text = answer_text.strip()
                
                # ตัดคำอธิบายเกี่ยวกับ SQL/ตารางออก
                if 'โดยคำนวณจาก' in answer_text:
                    answer_text = answer_text.split('โดยคำนวณจาก')[0].strip()
                if 'จากข้อมูลในตาราง' in answer_text:
                    answer_text = answer_text.split('จากข้อมูลในตาราง')[0].strip()
                if 'ตาราง' in answer_text and 'status' in answer_text:
                    sentences = answer_text.split('.')
                    clean_sentences = [s for s in sentences if 'ตาราง' not in s and 'status' not in s]
                    answer_text = '. '.join(clean_sentences).strip()
                
                # ตัด SQL code block ออก
                if 'SQL:' in answer_text or '```sql' in answer_text:
                    answer_text = answer_text.split('SQL:')[0].strip()
                    answer_text = answer_text.split('```')[0].strip()

                # ตัดประโยคที่พูดถึง status, ตาราง
                if 'status' in answer_text.lower():
                    sentences = answer_text.split('.')
                    clean_sentences = [s for s in sentences if 'status' not in s.lower()]
                    if clean_sentences:
                        answer_text = '. '.join(clean_sentences).strip()

                # ตัดประโยคที่พูดถึงชื่อตาราง
                table_keywords = ['ตาราง sewing', 'ตาราง qc_', 'จากตาราง', 'ใช้ตาราง']
                for keyword in table_keywords:
                    if keyword in answer_text:
                        sentences = answer_text.split('.')
                        clean_sentences = [s for s in sentences if keyword not in s]
                        if clean_sentences:
                            answer_text = '. '.join(clean_sentences).strip()
                        break
                print(f"✅ ได้คำตอบแล้ว: {answer_text}")
                
                signal.alarm(0)
                return answer_text
                
            except TimeoutError:
                return "⏰ ระบบใช้เวลานานเกินไป กรุณาลองใหม่อีกครั้ง"
            finally:
                signal.alarm(0)
            
        except Exception as e:
            signal.alarm(0)
            error_msg = f"❌ เกิดข้อผิดพลาดในการประมวลผล: {str(e)}"
            print(error_msg)        
            
            if "Connection" in str(e):
                return "❌ ไม่สามารถเชื่อมต่อ Ollama ได้ กรุณาตรวจสอบว่า Ollama service ทำงานหรือไม่"
            elif "timeout" in str(e).lower():
                return "⏰ การประมวลผลใช้เวลานานเกินไป กรุณาลองคำถามที่ง่ายกว่า"
            else:
                return f"❌ เกิดข้อผิดพลาด: {str(e)[:200]}"    
       
    def ask_question_with_chart(self, question: str) -> dict:
        """ถามคำถามพร้อมสร้างกราฟ"""
        answer = self.ask_question(question)
        chart_config = self._suggest_chart(question, answer)
        
        return {
            "answer": answer,
            "chart": chart_config
        }
    
    def _suggest_chart(self, question: str, answer: str) -> dict:
        """วิเคราะห์และสร้าง config กราฟ"""
        try:
            chart_keywords = [
                'กราฟ', 'chart', 'แผนภูมิ', 'เปรียบเทียบ', 'แยกตาม',
                'สรุป', 'top', 'อันดับ', 'มากที่สุด', 'น้อยที่สุด',
                'แสดง', 'แนวโน้ม', 'ย้อนหลัง'
            ]
            
            should_chart = any(keyword in question.lower() for keyword in chart_keywords)
            
            if not should_chart:
                return None
            
            chart_prompt = f"""วิเคราะห์คำถามและคำตอบต่อไปนี้ แล้วสร้าง JSON config สำหรับกราฟ

คำถาม: {question}
คำตอบ: {answer}

ถ้าข้อมูลเหมาะกับการแสดงเป็นกราฟ ให้สร้าง JSON ดังนี้:

{{
  "type": "bar",
  "title": "ชื่อกราฟภาษาไทย",
  "x_label": "แกน X",
  "y_label": "แกน Y",
  "data": [
    {{"label": "ชื่อ 1", "value": 100}},
    {{"label": "ชื่อ 2", "value": 200}}
  ]
}}

**สำคัญ:** 
1. ให้เรียง data จากมากไปน้อย
2. ตอบเป็น JSON เท่านั้น
3. ถ้าไม่เหมาะสร้างกราฟ ตอบ: null

JSON Config:"""

            response = self.llm.invoke(chart_prompt)
            response_text = response.content if hasattr(response, 'content') else str(response)
            
            response_text = re.sub(r'```json\s*', '', response_text)
            response_text = re.sub(r'```\s*', '', response_text)
            response_text = response_text.strip()
            
            if response_text.lower() == 'null':
                return None
            
            json_match = re.search(r'\{[\s\S]*\}', response_text)
            if json_match:
                chart_config = json.loads(json_match.group())
                
                if chart_config and 'data' in chart_config and len(chart_config['data']) > 0:
                    print(f"📊 สร้างกราฟสำเร็จ: {chart_config['title']}")
                    return chart_config
            
            return None
            
        except Exception as e:
            print(f"⚠️ ไม่สามารถสร้าง chart config: {e}")
            return None

    def get_agent_status(self) -> dict:
        """ตรวจสอบสถานะของ Agent"""
        if self.llm_provider == "ollama":
            return {
                "llm_provider": "ollama",
                "llm_model": OLLAMA_MODEL,
                "llm_host": OLLAMA_HOST,
                "temperature": OLLAMA_TEMPERATURE,
                "agent_ready": self.sql_query_chain is not None and self.database is not None,
                "llm_ready": self.llm is not None
            }
        else:
            return {
                "llm_provider": "gemini",
                "llm_model": GEMINI_MODEL,
                "temperature": GEMINI_TEMPERATURE,
                "agent_ready": self.sql_query_chain is not None and self.database is not None,
                "llm_ready": self.llm is not None
            }
    
    def reload_agent(self):
        """
        รีโหลด Agent ใหม่ (สำหรับกรณีที่ต้องการรีเซ็ต)
        """
        print("🔄 กำลังรีโหลด Agent...")
        self.llm = None
        self.database = None
        self.sql_query_chain = None
        self._initialize_agent()   

# สร้าง global instance
_agent_instance = None

def get_chat_agent() -> AutomotiveChatAgent:
    """
    ได้รับ instance ของ Chat Agent (Singleton pattern)
    
    Returns:
        AutomotiveChatAgent: instance ของ Chat Agent
    """
    global _agent_instance
    
    if _agent_instance is None:
        _agent_instance = AutomotiveChatAgent()
    
    return _agent_instance


def ask_automotive_question(question: str) -> str:
    """ฟังก์ชันสำหรับถามคำถามโดยตรง"""
    agent = get_chat_agent()
    return agent.ask_question(question)


def ask_automotive_question_with_chart(question: str) -> dict:
    """ฟังก์ชันสำหรับถามคำถามพร้อมสร้างกราฟ"""
    agent = get_chat_agent()
    return agent.ask_question_with_chart(question)

