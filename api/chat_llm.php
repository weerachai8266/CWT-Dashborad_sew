<?php
/**
 * API Endpoint สำหรับเชื่อมต่อกับ LLM Chat System
 * รับคำถามจาก Frontend และส่งต่อไปยัง FastAPI LLM Server
 */

// เพิ่ม execution time เพื่อรอคำตอบจาก AI
set_time_limit(300); // 5 นาที
ini_set('max_execution_time', 300);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// กำหนดค่า LLM Server
define('LLM_SERVER_HOST', 'localhost');
define('LLM_SERVER_PORT', '8000');
define('LLM_API_ENDPOINT', 'http://' . LLM_SERVER_HOST . ':' . LLM_SERVER_PORT . '/chat');

/**
 * ส่งคำถามไปยัง LLM Server
 */
function sendQuestionToLLM($question) {
    $data = json_encode(['question' => $question]);
    
    $ch = curl_init(LLM_API_ENDPOINT);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180); // Timeout 180 วินาที (3 นาที)
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Connection timeout 10 วินาที
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1); // ป้องกัน timeout signals
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'error' => 'การเชื่อมต่อกับ LLM Server ล้มเหลว: ' . $error
        ];
    }
    
    if ($httpCode !== 200) {
        return [
            'success' => false,
            'error' => 'LLM Server ตอบกลับด้วย HTTP Code: ' . $httpCode
        ];
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'ไม่สามารถแปลงข้อมูลจาก LLM Server ได้'
        ];
    }
    
    return [
        'success' => true,
        'data' => $result
    ];
}

/**
 * ตรวจสอบสถานะของ LLM Server
 */
function checkLLMServerHealth() {
    $healthUrl = 'http://' . LLM_SERVER_HOST . ':' . LLM_SERVER_PORT . '/health';
    
    $ch = curl_init($healthUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
}

// ==================== Main Logic ====================

try {
    // รับ request method
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        // ตรวจสอบสถานะของ LLM Server
        $isHealthy = checkLLMServerHealth();
        
        echo json_encode([
            'success' => true,
            'status' => $isHealthy ? 'online' : 'offline',
            'message' => $isHealthy 
                ? 'LLM Server พร้อมใช้งาน' 
                : 'LLM Server ไม่พร้อมใช้งาน',
            'server' => LLM_SERVER_HOST . ':' . LLM_SERVER_PORT
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($method === 'POST') {
        // รับข้อมูลจาก request body
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        // ตรวจสอบว่ามีคำถามหรือไม่
        if (!isset($input['question']) || empty(trim($input['question']))) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'กรุณาระบุคำถาม'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $question = trim($input['question']);
        
        // ส่งคำถามไปยัง LLM Server
        $result = sendQuestionToLLM($question);
        
        if (!$result['success']) {
            http_response_code(500);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // ส่งคำตอบกลับไปยัง Frontend
        echo json_encode([
            'success' => true,
            'question' => $question,
            'answer' => $result['data']['answer'] ?? 'ไม่ได้รับคำตอบจาก LLM',
            'chart' => $result['data']['chart'] ?? null,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Method ไม่ถูกต้อง
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use GET or POST'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
