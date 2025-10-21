"""
Database layer for Automotive Chat LLM application
การจัดการฐานข้อมูลสำหรับแอปพลิเคชันถาม-ตอบฐานข้อมูล Automotive
"""
from typing import List
from sqlalchemy import create_engine, text
from langchain_community.utilities import SQLDatabase

from .config import SQLALCHEMY_URI, MYSQL_DB


def discover_allowed_tables() -> List[str]:
    """
    ค้นหารายชื่อตารางที่อนุญาตให้ใช้ (prefix: sewing_, qc_)
    """
    engine = create_engine(SQLALCHEMY_URI)
    
    try:
        with engine.connect() as conn:
            # ค้นหาตารางที่ขึ้นต้นด้วย sewing_ หรือ qc_
            query = text("""
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = :db
                  AND (table_name LIKE 'sewing_%'
                       OR table_name LIKE 'qc_%')
                ORDER BY table_name
            """)
            
            rows = conn.execute(query, {"db": MYSQL_DB}).fetchall()
            return [row[0] for row in rows]
            
    except Exception as e:
        print(f"❌ ไม่สามารถเชื่อมต่อฐานข้อมูลได้: {e}")
        # ถ้าไม่สามารถเชื่อมต่อได้ ให้ใช้รายชื่อตารางที่ทราบแน่นอน
        return get_default_allowed_tables()


def get_default_allowed_tables() -> List[str]:
    """
    รายชื่อตารางที่อนุญาตโดย default (สำหรับกรณีที่เชื่อมต่อฐานข้อมูลไม่ได้)
    """
    return [
        # Sewing tables (ตารางงานเย็บ/ผลิต)
        'sewing_3rd',                   # ข้อมูลงานเย็บ third party
        'sewing_fb',                    # ข้อมูลงานเย็บ FB
        'sewing_fc',                    # ข้อมูลงานเย็บ FC
        'sewing_man_act',               # ข้อมูลพนักงานจริง (actual manpower)
        'sewing_man_plan',              # วางแผนพนักงาน (planned manpower)
        'sewing_productivity_plan',     # กำหนด productivity target
        'sewing_rb',                    # ข้อมูลงานเย็บ RB
        'sewing_rc',                    # ข้อมูลงานเย็บ RC
        'sewing_sub',                   # ข้อมูลงานเย็บ subcontract
        'sewing_target',                # เป้าหมายการผลิต
        
        # QC tables (ตารางควบคุมคุณภาพ)
        'qc_3rd',                       # ข้อมูล QC third party
        'qc_fb',                        # ข้อมูล QC FB
        'qc_fc',                        # ข้อมูล QC FC
        'qc_issue',                     # เก็บชื่อลักษณะงาน NG (issue types)
        'qc_ng',                        # เก็บข้อมูลงาน NG (defect records)
        'qc_rb',                        # ข้อมูล QC RB
        'qc_rc',                        # ข้อมูล QC RC
        'qc_sub'                        # ข้อมูล QC subcontract
    ]


def get_database_connection() -> SQLDatabase:
    """
    สร้างการเชื่อมต่อฐานข้อมูลสำหรับ Langchain SQLDatabase
    """
    allowed_tables = discover_allowed_tables()
    
    if not allowed_tables:
        print("⚠️  ไม่พบตารางที่อนุญาต กำลังใช้รายชื่อ default")
        allowed_tables = get_default_allowed_tables()
    
    return SQLDatabase.from_uri(
        SQLALCHEMY_URI, 
        include_tables=allowed_tables
    )


def get_table_descriptions() -> dict:
    """
    ส่งกลับคำอธิบายของแต่ละตารางในระบบ
    """
    return {
        # Sewing tables (ตารางงานเย็บ/ผลิต)
        "sewing_man_act": "ข้อมูลพนักงานจริง (actual manpower) - จำนวนพนักงานที่ทำงานจริง",
        "sewing_man_plan": "วางแผนพนักงาน (planned manpower) - แผนการใช้พนักงาน",
        "sewing_productivity_plan": "กำหนด productivity - เป้าหมายประสิทธิภาพการผลิต",
        "sewing_target": "เป้าหมายการผลิต - เป้าหมายปริมาณการผลิต",
        "sewing_lot": "ข้อมูล lot การผลิต - การจัดกลุ่มงานผลิต",
        "sewing_3rd": "ข้อมูลงานเย็บ third party - งานจ้างเหมาภายนอก",
        "sewing_fb": "ข้อมูลงานเย็บ FB - งานแผนก FB",
        "sewing_fc": "ข้อมูลงานเย็บ FC - งานแผนก FC", 
        "sewing_rb": "ข้อมูลงานเย็บ RB - งานแผนก RB",
        "sewing_rc": "ข้อมูลงานเย็บ RC - งานแผนก RC",
        "sewing_sub": "ข้อมูลงานเย็บ subcontract - งานจ้างเหมาช่วง",
        
        # QC tables (ตารางควบคุมคุณภาพ)
        "qc_ng": "เก็บข้อมูลงาน NG - บันทึกสินค้าไม่ผ่านมาตรฐาน",
        "qc_issue": "เก็บชื่อลักษณะงาน NG - ประเภทและลักษณะของปัญหา",
        "qc_3rd": "ข้อมูล QC third party - ควบคุมคุณภาพงานจ้างเหมาภายนอก",
        "qc_fb": "ข้อมูล QC FB - ควบคุมคุณภาพแผนก FB",
        "qc_fc": "ข้อมูล QC FC - ควบคุมคุณภาพแผนก FC",
        "qc_rb": "ข้อมูล QC RB - ควบคุมคุณภาพแผนก RB", 
        "qc_rc": "ข้อมูล QC RC - ควบคุมคุณภาพแผนก RC",
        "qc_sub": "ข้อมูล QC subcontract - ควบคุมคุณภาพงานจ้างเหมาช่วง"
    }


def test_database_connection() -> dict:
    """
    ทดสอบการเชื่อมต่อฐานข้อมูลและส่งกลับสถานะ
    """
    try:
        engine = create_engine(SQLALCHEMY_URI)
        
        with engine.connect() as conn:
            # ทดสอบการเชื่อมต่อพื้นฐาน
            conn.execute(text("SELECT 1"))
            
            # นับจำนวนตารางที่อนุญาต
            allowed_tables = discover_allowed_tables()
            
            return {
                "status": "success",
                "message": "เชื่อมต่อฐานข้อมูลสำเร็จ",
                "tables_count": len(allowed_tables),
                "allowed_tables": allowed_tables,
                "table_descriptions": get_table_descriptions()
            }
            
    except Exception as e:
        return {
            "status": "error",
            "message": f"ไม่สามารถเชื่อมต่อฐานข้อมูลได้: {str(e)}",
            "tables_count": 0,
            "allowed_tables": [],
            "table_descriptions": {}
        }