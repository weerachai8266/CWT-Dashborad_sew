#!/bin/bash

###############################################################################
# Script สำหรับเริ่มระบบ LLM Chat
# ใช้งาน: ./start_llm.sh [start|stop|restart|status]
###############################################################################

# สี
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# ตำแหน่งไฟล์
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LLM_DIR="$SCRIPT_DIR/llm"
PID_FILE="$LLM_DIR/llm.pid"
LOG_FILE="$LLM_DIR/llm.log"
HOST="0.0.0.0"
PORT="8000"

# ตรวจสอบว่ามี virtual environment หรือไม่
if [ -d "$HOME/venv" ]; then
    VENV_PATH="$HOME/venv"
elif [ -d "$LLM_DIR/venv" ]; then
    VENV_PATH="$LLM_DIR/venv"
else
    VENV_PATH=""
fi

###############################################################################
# ฟังก์ชันต่างๆ
###############################################################################

# แสดงข้อความ
print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# ตรวจสอบว่า LLM กำลังทำงานอยู่หรือไม่
is_running() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p $PID > /dev/null 2>&1; then
            return 0
        else
            rm -f "$PID_FILE"
            return 1
        fi
    fi
    return 1
}

# เริ่มระบบ LLM
start_llm() {
    print_info "กำลังเริ่มระบบ LLM Chat..."
    
    # ตรวจสอบว่ากำลังทำงานอยู่แล้วหรือไม่
    if is_running; then
        print_warning "LLM Chat กำลังทำงานอยู่แล้ว (PID: $(cat $PID_FILE))"
        return 1
    fi
    
    # ตรวจสอบว่ามีโฟลเดอร์ llm หรือไม่
    if [ ! -d "$LLM_DIR" ]; then
        print_error "ไม่พบโฟลเดอร์ $LLM_DIR"
        return 1
    fi
    
    # เข้าไปในโฟลเดอร์ llm
    cd "$LLM_DIR" || exit 1
    
    # เปิดใช้งาน virtual environment (ถ้ามี)
    if [ -n "$VENV_PATH" ] && [ -d "$VENV_PATH" ]; then
        print_info "เปิดใช้งาน virtual environment: $VENV_PATH"
        source "$VENV_PATH/bin/activate"
    fi
    
    # ตรวจสอบ dependencies
    print_info "ตรวจสอบ dependencies..."
    if ! python3 -c "import fastapi" 2>/dev/null; then
        print_warning "กำลังติดตั้ง dependencies..."
        pip install -r requirements.txt
    fi
    
    # ตรวจสอบว่า Ollama กำลังทำงานหรือไม่ (ถ้าใช้ Ollama)
    if grep -q "LLM_PROVIDER.*ollama" config.py 2>/dev/null || [ ! -f ".env" ]; then
        OLLAMA_HOST=$(grep -oP 'OLLAMA_HOST=\K.*' .env 2>/dev/null || echo "http://192.168.0.9:11434")
        print_info "ตรวจสอบ Ollama Server: $OLLAMA_HOST"
        
        if ! curl -s "$OLLAMA_HOST/api/tags" > /dev/null 2>&1; then
            print_warning "ไม่สามารถเชื่อมต่อกับ Ollama Server ได้"
            print_info "หากใช้ Gemini กรุณาตั้งค่า LLM_PROVIDER=gemini ใน .env"
        fi
    fi
    
    # เริ่มระบบ
    print_info "เริ่มต้น LLM Server บน $HOST:$PORT..."
    
    # เปลี่ยนไปที่ parent directory และรันด้วย module path
    cd "$SCRIPT_DIR" || exit 1
    
    # ใช้ nohup เพื่อให้ทำงานใน background
    nohup python3 -m uvicorn llm.app:app --host "$HOST" --port "$PORT" > "$LOG_FILE" 2>&1 &
    
    # บันทึก PID
    echo $! > "$PID_FILE"
    
    # รอสักครู่เพื่อให้ server เริ่มต้น
    sleep 3
    
    # ตรวจสอบว่า server เริ่มต้นสำเร็จหรือไม่
    if is_running; then
        print_success "LLM Chat เริ่มต้นสำเร็จ!"
        print_info "PID: $(cat $PID_FILE)"
        print_info "URL: http://localhost:$PORT"
        print_info "Logs: $LOG_FILE"
        
        # ทดสอบ health check
        sleep 2
        if curl -s "http://localhost:$PORT/health" > /dev/null 2>&1; then
            print_success "Health check ผ่าน ✓"
        else
            print_warning "Health check ล้มเหลว (ระบบอาจยังไม่พร้อม)"
        fi
    else
        print_error "ไม่สามารถเริ่ม LLM Chat ได้"
        print_info "ตรวจสอบ log: $LOG_FILE"
        tail -20 "$LOG_FILE"
        return 1
    fi
}

# หยุดระบบ LLM
stop_llm() {
    print_info "กำลังหยุดระบบ LLM Chat..."
    
    if ! is_running; then
        print_warning "LLM Chat ไม่ได้ทำงานอยู่"
        return 1
    fi
    
    PID=$(cat "$PID_FILE")
    print_info "หยุดการทำงาน (PID: $PID)..."
    
    kill "$PID" 2>/dev/null
    
    # รอให้ process หยุด
    for i in {1..10}; do
        if ! ps -p "$PID" > /dev/null 2>&1; then
            break
        fi
        sleep 1
    done
    
    # ถ้ายังไม่หยุด ใช้ force kill
    if ps -p "$PID" > /dev/null 2>&1; then
        print_warning "ใช้ force kill..."
        kill -9 "$PID" 2>/dev/null
    fi
    
    rm -f "$PID_FILE"
    print_success "LLM Chat หยุดการทำงานแล้ว"
}

# รีสตาร์ทระบบ LLM
restart_llm() {
    print_info "กำลัง Restart LLM Chat..."
    stop_llm
    sleep 2
    start_llm
}

# ตรวจสอบสถานะ
status_llm() {
    echo "=========================================="
    echo "🔍 สถานะระบบ LLM Chat"
    echo "=========================================="
    
    if is_running; then
        PID=$(cat "$PID_FILE")
        print_success "ระบบกำลังทำงาน"
        echo "  • PID: $PID"
        echo "  • URL: http://localhost:$PORT"
        echo "  • Logs: $LOG_FILE"
        
        # ตรวจสอบ memory usage
        if command -v ps &> /dev/null; then
            MEM=$(ps -p "$PID" -o %mem --no-headers 2>/dev/null | xargs)
            echo "  • Memory: ${MEM}%"
        fi
        
        # ตรวจสอบ health
        if curl -s "http://localhost:$PORT/health" > /dev/null 2>&1; then
            print_success "Health Check: OK"
        else
            print_warning "Health Check: FAILED"
        fi
    else
        print_warning "ระบบไม่ได้ทำงานอยู่"
    fi
    
    echo "=========================================="
}

# แสดง log
show_logs() {
    if [ -f "$LOG_FILE" ]; then
        print_info "แสดง log ล่าสุด 50 บรรทัด..."
        echo "=========================================="
        tail -50 "$LOG_FILE"
        echo "=========================================="
        print_info "ติดตาม log แบบ real-time: tail -f $LOG_FILE"
    else
        print_warning "ไม่พบไฟล์ log"
    fi
}

###############################################################################
# Main
###############################################################################

case "$1" in
    start)
        start_llm
        ;;
    stop)
        stop_llm
        ;;
    restart)
        restart_llm
        ;;
    status)
        status_llm
        ;;
    logs)
        show_logs
        ;;
    *)
        echo "=========================================="
        echo "🤖 LLM Chat Management Script"
        echo "=========================================="
        echo "การใช้งาน: $0 {start|stop|restart|status|logs}"
        echo ""
        echo "คำสั่ง:"
        echo "  start   - เริ่มระบบ LLM Chat"
        echo "  stop    - หยุดระบบ LLM Chat"
        echo "  restart - รีสตาร์ทระบบ LLM Chat"
        echo "  status  - ตรวจสอบสถานะระบบ"
        echo "  logs    - แสดง log"
        echo "=========================================="
        exit 1
        ;;
esac

exit 0
