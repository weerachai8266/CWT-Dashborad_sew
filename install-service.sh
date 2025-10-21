#!/bin/bash

###############################################################################
# Script สำหรับติดตั้ง LLM Chat เป็น systemd service
# ใช้งาน: sudo ./install-service.sh
###############################################################################

# สี
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

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

# ตรวจสอบว่ารันด้วย sudo หรือไม่
if [ "$EUID" -ne 0 ]; then 
    print_error "กรุณารันสคริปต์นี้ด้วย sudo"
    echo "ใช้คำสั่ง: sudo ./install-service.sh"
    exit 1
fi

echo "=========================================="
echo "🚀 ติดตั้ง LLM Chat Service"
echo "=========================================="
echo ""

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVICE_FILE="$SCRIPT_DIR/llm-chat.service"
SYSTEMD_DIR="/etc/systemd/system"
SERVICE_NAME="llm-chat.service"

# 1. ตรวจสอบว่ามีไฟล์ service หรือไม่
if [ ! -f "$SERVICE_FILE" ]; then
    print_error "ไม่พบไฟล์ $SERVICE_FILE"
    exit 1
fi

print_info "พบไฟล์ service: $SERVICE_FILE"

# 2. หยุด service เดิม (ถ้ามี)
if systemctl is-active --quiet $SERVICE_NAME; then
    print_info "หยุด service เดิมก่อน..."
    systemctl stop $SERVICE_NAME
fi

# 3. คัดลอกไฟล์ service
print_info "คัดลอกไฟล์ service ไปยัง $SYSTEMD_DIR..."
cp "$SERVICE_FILE" "$SYSTEMD_DIR/$SERVICE_NAME"

if [ $? -ne 0 ]; then
    print_error "ไม่สามารถคัดลอกไฟล์ได้"
    exit 1
fi

print_success "คัดลอกไฟล์สำเร็จ"

# 4. โหลด systemd daemon ใหม่
print_info "โหลด systemd daemon ใหม่..."
systemctl daemon-reload

if [ $? -ne 0 ]; then
    print_error "ไม่สามารถโหลด daemon ได้"
    exit 1
fi

print_success "โหลด daemon สำเร็จ"

# 5. Enable service (เริ่มอัตโนมัติเมื่อบูต)
print_info "ตั้งค่าให้เริ่มอัตโนมัติเมื่อบูต..."
systemctl enable $SERVICE_NAME

if [ $? -ne 0 ]; then
    print_error "ไม่สามารถ enable service ได้"
    exit 1
fi

print_success "Enable service สำเร็จ"

# 6. เริ่ม service
print_info "เริ่มต้น service..."
systemctl start $SERVICE_NAME

sleep 3

# 7. ตรวจสอบสถานะ
if systemctl is-active --quiet $SERVICE_NAME; then
    print_success "LLM Chat Service เริ่มต้นสำเร็จ!"
    echo ""
    echo "=========================================="
    echo "✅ การติดตั้งเสร็จสมบูรณ์!"
    echo "=========================================="
    echo ""
    echo "📋 คำสั่งที่ใช้งานได้:"
    echo "  • systemctl status llm-chat    - ตรวจสอบสถานะ"
    echo "  • systemctl start llm-chat     - เริ่มต้น service"
    echo "  • systemctl stop llm-chat      - หยุด service"
    echo "  • systemctl restart llm-chat   - รีสตาร์ท service"
    echo "  • systemctl disable llm-chat   - ปิดการเริ่มอัตโนมัติ"
    echo "  • journalctl -u llm-chat -f    - ดู logs แบบ real-time"
    echo ""
    echo "🎉 LLM Chat จะเริ่มทำงานอัตโนมัติทุกครั้งที่เปิดเครื่อง!"
    echo ""
else
    print_error "ไม่สามารถเริ่ม service ได้"
    echo ""
    print_info "ดูรายละเอียด error:"
    echo "  journalctl -u llm-chat -n 50 --no-pager"
    echo ""
    print_info "หรือดู log file:"
    echo "  tail -50 /var/www/html/sewing/llm/llm.log"
    exit 1
fi

# 8. แสดงสถานะ
echo "=========================================="
echo "📊 สถานะ Service"
echo "=========================================="
systemctl status $SERVICE_NAME --no-pager

exit 0
