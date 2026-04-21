// ==================== AI Chat System ====================

let isChatOpen = false;
let isLLMOnline = false;
let chatHistory = [];

// Initialize chat system
function initAIChatSystem() {
    const chatToggleBtn = document.getElementById('chat-toggle-btn');
    const chatCloseBtn = document.getElementById('chat-close-btn');
    const chatContainer = document.getElementById('chat-container');
    const chatInput = document.getElementById('chat-input');
    const chatSendBtn = document.getElementById('chat-send-btn');

    chatToggleBtn?.addEventListener('click', toggleChat);
    chatCloseBtn?.addEventListener('click', toggleChat);
    chatSendBtn?.addEventListener('click', sendChatMessage);

    chatInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendChatMessage();
        }
    });

    chatInput?.addEventListener('input', () => {
        chatInput.style.height = 'auto';
        chatInput.style.height = chatInput.scrollHeight + 'px';
    });

    checkLLMStatus();
}

// Toggle chat window
function toggleChat() {
    isChatOpen = !isChatOpen;
    const chatContainer = document.getElementById('chat-container');
    const chatToggleBtn = document.getElementById('chat-toggle-btn');

    if (isChatOpen) {
        chatContainer.style.display = 'flex';
        chatToggleBtn.style.display = 'none';
        document.getElementById('chat-input')?.focus();
    } else {
        chatContainer.style.display = 'none';
        chatToggleBtn.style.display = 'flex';
    }
}

// Check LLM server status
async function checkLLMStatus() {
    const statusElement = document.getElementById('llm-status');

    try {
        const response = await fetch('api/chat_llm.php');
        const data = await response.json();

        isLLMOnline = data.success && data.status === 'online';

        if (statusElement) {
            if (isLLMOnline) {
                statusElement.innerHTML = '<i class="fas fa-circle status-online"></i> พร้อมใช้งาน';
                statusElement.className = 'text-white-50';
            } else {
                statusElement.innerHTML = '<i class="fas fa-circle status-offline"></i> ไม่พร้อมใช้งาน';
                statusElement.className = 'text-white-50';
            }
        }
    } catch (error) {
        console.error('Error checking LLM status:', error);
        isLLMOnline = false;
        if (statusElement) {
            statusElement.innerHTML = '<i class="fas fa-circle status-offline"></i> เกิดข้อผิดพลาด';
            statusElement.className = 'text-white-50';
        }
    }
}

// Send chat message
async function sendChatMessage() {
    const chatInput = document.getElementById('chat-input');
    const chatSendBtn = document.getElementById('chat-send-btn');
    const question = chatInput.value.trim();

    if (!question) return;

    if (!isLLMOnline) {
        addChatMessage('กรุณารอสักครู่ ระบบ AI กำลังเริ่มต้น...', 'bot');
        await checkLLMStatus();
        if (!isLLMOnline) {
            addChatMessage('ขออภัยครับ ระบบ AI ยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ', 'bot');
            return;
        }
    }

    addChatMessage(question, 'user');

    chatInput.value = '';
    chatInput.style.height = 'auto';

    chatSendBtn.disabled = true;

    showTypingIndicator();

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 180000); // 3 minutes timeout

        const response = await fetch('api/chat_llm.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ question }),
            signal: controller.signal
        });

        clearTimeout(timeoutId);
        const data = await response.json();

        removeTypingIndicator();

        if (data.success) {
            addChatMessage(data.answer, 'bot', data.chart);

            chatHistory.push({
                question,
                answer: data.answer,
                chart: data.chart,
                timestamp: data.timestamp
            });
        } else {
            addChatMessage('ขออภัยครับ เกิดข้อผิดพลาด: ' + data.error, 'bot');
        }
    } catch (error) {
        console.error('Error sending message:', error);
        removeTypingIndicator();

        if (error.name === 'AbortError') {
            addChatMessage('⏱️ ขออภัยครับ คำถามนี้ใช้เวลานานเกินไป (มากกว่า 3 นาที) กรุณาลองถามคำถามที่สั้นกว่า หรือเฉพาะเจาะจงมากขึ้น', 'bot');
        } else {
            addChatMessage('❌ ขออภัยครับ ไม่สามารถเชื่อมต่อกับระบบ AI ได้ กรุณาลองใหม่อีกครั้ง\n\n' +
                          'เคล็ดลับ: ลองถามคำถามที่ชัดเจนและเฉพาะเจาะจงมากขึ้น', 'bot');
        }
    } finally {
        chatSendBtn.disabled = false;
        chatInput.focus();
    }
}

// Add message to chat
function addChatMessage(message, type = 'bot', chartData = null) {
    const chatMessages = document.getElementById('chat-messages');
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${type}-message`;

    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';

    if (type === 'bot') {
        const icon = '<i class="fas fa-robot me-2"></i>';
        contentDiv.innerHTML = icon + message;
    } else {
        contentDiv.textContent = message;
    }

    messageDiv.appendChild(contentDiv);

    if (chartData && type === 'bot') {
        const chartContainer = createChatChart(chartData);
        if (chartContainer) {
            contentDiv.appendChild(chartContainer);
        }
    }

    chatMessages.appendChild(messageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// Create chart in chat message
function createChatChart(chartData) {
    if (!chartData || !chartData.data || chartData.data.length === 0) {
        return null;
    }

    const container = document.createElement('div');
    container.className = 'chat-chart-container';

    const canvas = document.createElement('canvas');
    canvas.id = 'chat-chart-' + Date.now();
    container.appendChild(canvas);

    setTimeout(() => {
        const ctx = canvas.getContext('2d');
        const labels = chartData.data.map(item => item.label);
        const values = chartData.data.map(item => item.value);

        const colors = generateChartColors(values.length);

        new Chart(ctx, {
            type: chartData.type || 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: chartData.title || 'ข้อมูล',
                    data: values,
                    backgroundColor: colors.background,
                    borderColor: colors.border,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: chartData.title || 'กราฟแสดงผล'
                    }
                },
                scales: chartData.type !== 'pie' && chartData.type !== 'doughnut' ? {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: !!chartData.y_label,
                            text: chartData.y_label || ''
                        }
                    },
                    x: {
                        title: {
                            display: !!chartData.x_label,
                            text: chartData.x_label || ''
                        }
                    }
                } : {}
            }
        });
    }, 100);

    return container;
}

// Generate chart colors
function generateChartColors(count) {
    const baseColors = [
        '#667eea', '#764ba2', '#f093fb', '#4facfe',
        '#43e97b', '#fa709a', '#fee140', '#30cfd0'
    ];

    const background = [];
    const border = [];

    for (let i = 0; i < count; i++) {
        const color = baseColors[i % baseColors.length];
        background.push(color + '80');
        border.push(color);
    }

    return { background, border };
}

// Show typing indicator
function showTypingIndicator() {
    const chatMessages = document.getElementById('chat-messages');
    const typingDiv = document.createElement('div');
    typingDiv.className = 'message bot-message';
    typingDiv.id = 'typing-indicator';

    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';
    contentDiv.style.display = 'flex';
    contentDiv.style.flexDirection = 'column';
    contentDiv.style.gap = '8px';

    const indicatorDiv = document.createElement('div');
    indicatorDiv.className = 'typing-indicator';
    indicatorDiv.innerHTML = '<span></span><span></span><span></span>';

    const textDiv = document.createElement('div');
    textDiv.style.fontSize = '12px';
    textDiv.style.color = '#666';
    textDiv.innerHTML = '<i class="fas fa-robot me-1"></i> กำลังคิด... (อาจใช้เวลา 30-60 วินาที)';

    contentDiv.appendChild(indicatorDiv);
    contentDiv.appendChild(textDiv);
    typingDiv.appendChild(contentDiv);
    chatMessages.appendChild(typingDiv);

    chatMessages.scrollTop = chatMessages.scrollHeight;

    let elapsedSeconds = 0;
    const updateInterval = setInterval(() => {
        elapsedSeconds += 15;
        if (document.getElementById('typing-indicator')) {
            if (elapsedSeconds === 15) {
                textDiv.innerHTML = '<i class="fas fa-robot me-1"></i> กำลังวิเคราะห์ข้อมูล...';
            } else if (elapsedSeconds === 30) {
                textDiv.innerHTML = '<i class="fas fa-robot me-1"></i> กำลังสร้างคำตอบ...';
            } else if (elapsedSeconds === 45) {
                textDiv.innerHTML = '<i class="fas fa-robot me-1"></i> เกือบเสร็จแล้ว...';
            } else if (elapsedSeconds >= 60) {
                textDiv.innerHTML = '<i class="fas fa-robot me-1"></i> กำลังประมวลผลคำถามที่ซับซ้อน...';
            }
        } else {
            clearInterval(updateInterval);
        }
    }, 15000);

    typingDiv.dataset.intervalId = updateInterval;
}

// Remove typing indicator
function removeTypingIndicator() {
    const typingIndicator = document.getElementById('typing-indicator');
    if (typingIndicator) {
        const intervalId = typingIndicator.dataset.intervalId;
        if (intervalId) {
            clearInterval(parseInt(intervalId));
        }
        typingIndicator.remove();
    }
}

// Initialize chat system when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initAIChatSystem();
});
