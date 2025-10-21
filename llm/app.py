"""
Main FastAPI application for Automotive Chat LLM
แอปพลิเคชันหลักสำหรับระบบถาม-ตอบฐานข้อมูล Automotive
รองรับการแสดงผลเป็นกราฟอัตโนมัติ
"""
from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import HTMLResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Optional, List, Dict, Any
from pathlib import Path
import os

from .config import (
    LLM_PROVIDER,
    OLLAMA_HOST, OLLAMA_MODEL, OLLAMA_TEMPERATURE,
    GEMINI_MODEL, GEMINI_TEMPERATURE,
    MYSQL_HOST, MYSQL_PORT, MYSQL_DB,
    APP_TITLE, APP_DESCRIPTION, APP_VERSION,
    HIDDEN_CHAT_TOKEN
)

from .database import discover_allowed_tables, get_table_descriptions, test_database_connection
from .llm_agent import (
    ask_automotive_question,
    ask_automotive_question_with_chart,
    get_chat_agent
)

# สร้าง FastAPI app
app = FastAPI(
    title=APP_TITLE,
    description=APP_DESCRIPTION,
    version=APP_VERSION,
)
# CORS Middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# ==================== Pydantic Models ====================

class ChatRequest(BaseModel):
    """คำขอสำหรับการถามคำถาม"""
    question: str

class ChartData(BaseModel):
    """ข้อมูลสำหรับแสดงกราฟ"""
    label: str
    value: float

class ChartConfig(BaseModel):
    """การตั้งค่าการแสดงกราฟ"""
    type: str  # "bar", "line", "pie", "doughnut"
    title: str
    x_label: Optional[str] = None
    y_label: Optional[str] = None
    data: List[ChartData]

class ChatResponse(BaseModel):
    """คำตอบพร้อมกราฟ (ถ้ามี)"""
    answer: str
    chart: Optional[ChartConfig] = None

class HealthResponse(BaseModel):
    """สถานะสุขภาพของระบบ"""
    status: str
    mysql: dict
    llm_provider: str
    model: str

class TablesResponse(BaseModel):
    """รายชื่อตารางที่ใช้งานได้"""
    allowed_tables: list

class QuestionRequest(BaseModel):
    """คำขอสำหรับถามคำถาม (รูปแบบเก่า - backward compatibility)"""
    question: str

class AnswerResponse(BaseModel):
    """คำตอบ (รูปแบบเก่า - backward compatibility)"""
    answer: str
    question: str

# Static files
TEMPLATES_DIR = Path(__file__).parent / "templates"


# ==================== Routes ====================

@app.get("/", response_class=HTMLResponse)
async def web_interface():
    """
    หน้าเว็บหลักสำหรับทดสอบการถาม-ตอบ
    GET /
    """
    template_path = os.path.join(os.path.dirname(__file__), "templates", "index.html")
    
    try:
        with open(template_path, "r", encoding="utf-8") as f:
            return f.read()
    except FileNotFoundError:
        return HTMLResponse(
            """
            <html><body style='font-family: sans-serif; text-align: center; padding-top: 10%;'>
                <h1>❌ ไม่พบไฟล์ template</h1>
                <p>กรุณาตรวจสอบไฟล์ <code>templates/index.html</code></p>
            </body></html>
            """,
            status_code=500
        )


@app.get("/hidden/chat", response_class=HTMLResponse)
async def hidden_chat_interface(request: Request):
    """
    หน้าเว็บถามตอบแบบซ่อน (ต้องใช้ token)
    GET /hidden/chat?token=YOUR_TOKEN
    """
    token = request.query_params.get("token")

    if token != HIDDEN_CHAT_TOKEN:
        return HTMLResponse(
            """
            <html><body style='font-family: sans-serif; background: #111; color: #f8f9fa; text-align: center; padding-top: 10%;'>
                <h1>🔒 403 Forbidden</h1>
                <p>Invalid or missing access token.</p>
            </body></html>
            """,
            status_code=403,
        )

    template_path = os.path.join(os.path.dirname(__file__), "templates", "hidden_chat.html")

    try:
        with open(template_path, "r", encoding="utf-8") as f:
            return f.read()
    except FileNotFoundError:
        return HTMLResponse(
            """
            <html><body style='font-family: sans-serif; text-align: center; padding-top: 10%;'>
                <h1>❌ ไม่พบไฟล์ template</h1>
                <p>กรุณาตรวจสอบไฟล์ <code>templates/hidden_chat.html</code></p>
            </body></html>
            """,
            status_code=500,
        )


@app.get("/health", response_model=HealthResponse)
async def health_check():
    """
    ตรวจสอบสุขภาพของระบบ
    GET /health
    
    Returns:
        HealthResponse: สถานะระบบพร้อมข้อมูล MySQL และ LLM
    """
    try:
        allowed_tables = discover_allowed_tables()
        
        model_name = OLLAMA_MODEL if LLM_PROVIDER == "ollama" else GEMINI_MODEL
        
        return HealthResponse(
            status="ok",
            mysql={
                "host": MYSQL_HOST,
                "db": MYSQL_DB,
                "allowed_tables": allowed_tables,
            },
            llm_provider=LLM_PROVIDER,
            model=model_name
        )
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"ระบบไม่พร้อมใช้งาน: {str(e)}")


@app.get("/tables", response_model=TablesResponse)
async def get_tables():
    """
    ดูรายชื่อตารางที่อนุญาตให้ LLM ใช้
    GET /tables
    
    Returns:
        TablesResponse: รายชื่อตารางทั้งหมด
    """
    try:
        allowed_tables = discover_allowed_tables()
        return TablesResponse(allowed_tables=allowed_tables)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"ไม่สามารถโหลดรายชื่อตารางได้: {str(e)}")


@app.get("/tables/descriptions")
async def get_table_descriptions_endpoint():
    """
    ดูรายละเอียดและคำอธิบายของแต่ละตาราง
    GET /tables/descriptions
    
    Returns:
        dict: คำอธิบายของแต่ละตาราง
    """
    try:
        descriptions = get_table_descriptions()
        allowed_tables = discover_allowed_tables()
        
        # กรองเฉพาะตารางที่มีจริงในฐานข้อมูล
        filtered_descriptions = {
            table: descriptions.get(table, "ไม่มีคำอธิบาย")
            for table in allowed_tables
        }
        
        return {
            "table_descriptions": filtered_descriptions,
            "total_tables": len(filtered_descriptions)
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"ไม่สามารถโหลดคำอธิบายตารางได้: {str(e)}")


@app.post("/chat", response_model=ChatResponse)
async def chat_endpoint(request: ChatRequest):
    """
    รับคำถามและส่งคืนคำตอบพร้อมกราฟ (ถ้ามี)
    POST /chat
    
    Request Body:
    {
      "question": "คำถามของคุณ"
    }
    
    Response:
    {
      "answer": "คำตอบ",
      "chart": {
        "type": "bar",
        "title": "ชื่อกราฟ",
        "data": [...]
      }
    }
    """
    if not request.question or not request.question.strip():
        raise HTTPException(status_code=400, detail="กรุณาใส่คำถาม")
    
    try:
        print(f"📝 รับคำถาม: {request.question}")
        
        # ตรวจสอบ Agent ก่อน
        agent = get_chat_agent()
        if not agent.sql_query_chain or not agent.database:
            raise HTTPException(status_code=503, detail="LLM Agent ไม่พร้อมใช้งาน")
        
        # ส่งคำถามและรับคำตอบพร้อมกราฟ
        result = ask_automotive_question_with_chart(request.question.strip())
        
        return ChatResponse(
            answer=result["answer"],
            chart=result.get("chart")
        )
        
    except HTTPException:
        raise
    except Exception as e:
        error_detail = f"เกิดข้อผิดพลาด: {str(e)}"
        print(f"❌ Error: {error_detail}")
        raise HTTPException(status_code=500, detail=error_detail)


@app.post("/ask", response_model=AnswerResponse)
async def ask_endpoint(request: QuestionRequest):
    """
    ถามคำถาม (รูปแบบเก่า - backward compatibility)
    POST /ask
    
    Request Body:
    {
      "question": "คำถามของคุณ"
    }
    
    Response:
    {
      "answer": "คำตอบ",
      "question": "คำถามเดิม"
    }
    """
    if not request.question or not request.question.strip():
        raise HTTPException(status_code=400, detail="กรุณาใส่คำถาม")
    
    try:
        print(f"📝 รับคำถาม: {request.question}")
        
        agent = get_chat_agent()
        if not agent.sql_query_chain or not agent.database:
            raise HTTPException(status_code=503, detail="LLM Agent ไม่พร้อมใช้งาน")
        
        answer = ask_automotive_question(request.question.strip())
        
        if not answer or answer.strip() == "":
            answer = "❌ ไม่ได้รับคำตอบจากระบบ"
        
        print(f"📤 ส่งคำตอบ: {answer[:100]}...")
        
        return AnswerResponse(
            answer=answer,
            question=request.question
        )
        
    except HTTPException:
        raise
    except Exception as e:
        error_detail = f"เกิดข้อผิดพลาด: {str(e)}"
        print(f"❌ Error: {error_detail}")
        raise HTTPException(status_code=500, detail=error_detail)


@app.get("/status")
async def system_status():
    """
    ตรวจสอบสถานะระบบอย่างละเอียด
    GET /status
    
    Returns:
        dict: สถานะของ database และ LLM agent
    """
    try:
        # ทดสอบฐานข้อมูล
        db_status = test_database_connection()
        
        # ตรวจสอบ Agent
        agent = get_chat_agent()
        agent_status = agent.get_agent_status()
        
        # สร้าง response ตาม LLM provider
        if LLM_PROVIDER == "ollama":
            llm_info = {
                "provider": "Ollama",
                "model": OLLAMA_MODEL,
                "host": OLLAMA_HOST,
                "temperature": OLLAMA_TEMPERATURE
            }
        else:
            llm_info = {
                "provider": "Gemini",
                "model": GEMINI_MODEL,
                "temperature": GEMINI_TEMPERATURE
            }
        
        return {
            "status": "running",
            "database": db_status,
            "agent": agent_status,
            "llm": llm_info,
            "mysql": {
                "host": MYSQL_HOST,
                "port": MYSQL_PORT,
                "database": MYSQL_DB
            },
            "overall_status": "ready" if db_status.get("status") == "success" and agent_status.get("agent_ready") else "not_ready"
        }
    except Exception as e:
        return JSONResponse(
            status_code=500,
            content={
                "status": "error",
                "error": str(e)
            }
        )


@app.post("/reload")
async def reload_agent():
    """
    รีโหลด LLM Agent
    POST /reload
    
    ใช้เมื่อต้องการรีเฟรช Agent (เช่น เปลี่ยน config)
    """
    try:
        agent = get_chat_agent()
        agent.reload_agent()
        return {
            "status": "success",
            "message": "Agent ถูกรีโหลดเรียบร้อยแล้ว"
        }
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"ไม่สามารถรีโหลด Agent ได้: {str(e)}"
        )


# ==================== Event Handlers ====================

@app.on_event("startup")
async def startup_event():
    """เริ่มต้นระบบเมื่อ app เริ่มทำงาน"""
    print("\n" + "="*60)
    print("🚀 กำลังเริ่มต้นระบบ Automotive Chat LLM...")
    print(f"📍 LLM Provider: {LLM_PROVIDER.upper()}")
    
    if LLM_PROVIDER == "ollama":
        print(f"🤖 Model: {OLLAMA_MODEL}")
        print(f"🔗 Host: {OLLAMA_HOST}")
    else:
        print(f"🤖 Model: {GEMINI_MODEL}")
    
    print(f"🗄️  Database: {MYSQL_HOST}:{MYSQL_PORT}/{MYSQL_DB}")
    print("="*60 + "\n")


@app.on_event("shutdown")
async def shutdown_event():
    """ปิดระบบ"""
    print("\n" + "="*60)
    print("👋 กำลังปิดระบบ Automotive Chat LLM...")
    print("="*60 + "\n")


# ==================== Error Handlers ====================

@app.exception_handler(404)
async def not_found_handler(request: Request, exc: HTTPException):
    """จัดการ 404 Not Found"""
    return JSONResponse(
        status_code=404,
        content={
            "error": "ไม่พบหน้าที่ต้องการ",
            "path": str(request.url.path),
            "method": request.method
        }
    )


@app.exception_handler(500)
async def internal_error_handler(request: Request, exc: Exception):
    """จัดการ 500 Internal Server Error"""
    return JSONResponse(
        status_code=500,
        content={
            "error": "เกิดข้อผิดพลาดภายในระบบ",
            "detail": str(exc)
        }
    )


# ==================== Main ====================

if __name__ == "__main__":
    import uvicorn
    
    print("\n" + "="*60)
    print("🚀 เริ่มรัน Automotive Chat LLM Server...")
    print("="*60 + "\n")
    
    uvicorn.run(
        "chat_llm.app:app",
        host="0.0.0.0",
        port=8000,
        reload=True,
        log_level="info"
    )