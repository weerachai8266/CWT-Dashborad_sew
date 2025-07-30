<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include(__DIR__ . "/../connect.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $shift = $_POST['shift'];
    $man_hour = $_POST['hour'];
    $man_fc_act = $_POST['fc_act'];
    $man_fb_act = $_POST['fb_act'];
    $man_rc_act = $_POST['rc_act'];
    $man_rb_act = $_POST['rb_act'];
    $man_3rd_act = $_POST['3rd_act'];
    $man_sub_act = $_POST['sub_act'];

    // อัปเดตข้อมูล
    $stmt = $conn->prepare("UPDATE sewing_aman SET
        shift = ?,
        thour = ?,
        fc_act = ?,
        fb_act = ?,
        rc_act = ?,
        rb_act = ?,
        3rd_act = ?,
        subass_act = ?
        WHERE id = ?");
        
    $stmt->execute([
        $shift,
        $man_hour,
        $man_fc_act,
        $man_fb_act,
        $man_rc_act,
        $man_rb_act,
        $man_3rd_act,
        $man_sub_act,
        $id
    ]);

    echo "<script>alert('✅ แก้ไขข้อมูลสำเร็จ'); location.href='../index.php';</script>";
}
?>
