<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include(__DIR__ . "/../connect.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = $_POST['id'];

    $stmt = $conn->prepare("DELETE FROM sewing_aman WHERE id = ?");
    $stmt->execute([$id]);

    echo "<script>alert('🗑️ ลบข้อมูลเรียบร้อยแล้ว'); location.href='../index.php';</script>";
}
?>
