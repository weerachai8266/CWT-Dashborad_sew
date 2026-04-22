<?php
$servername = "127.0.0.1";         // IP หรือชื่อเซิร์ฟเวอร์ MySQL
$username   = "";               // ชื่อผู้ใช้ MySQL
$password   = "";         // รหัสผ่าน
$dbname     = "";       // ชื่อฐานข้อมูล

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // echo "✅ Database connected"; // ใช้สำหรับ debug ได้
} catch (PDOException $e) {
    die("❌ Database connection failed: " . $e->getMessage());
}
?>
