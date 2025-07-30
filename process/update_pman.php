<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include(__DIR__ . "/../connect.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $man_fc_plan = $_POST['pman-fc'];
    $man_fb_plan = $_POST['pman-fb'];
    $man_rc_plan = $_POST['pman-rc'];
    $man_rb_plan = $_POST['pman-rb'];
    $man_3rd_plan = $_POST['pman-3rd'];
    $man_sub_plan = $_POST['pman-sub'];

    // อัปเดตข้อมูล
    $stmt = $conn->prepare("UPDATE sewing_pman SET
        fc_plan = ?, 
        fb_plan = ?, 
        rc_plan = ?, 
        rb_plan = ?, 
        3rd_plan = ?, 
        subass_plan = ? 
        WHERE id = ?");
        
    $stmt->execute([
        $man_fc_plan,
        $man_fb_plan,
        $man_rc_plan,
        $man_rb_plan,
        $man_3rd_plan,
        $man_sub_plan,
        $id
    ]);

    echo "<script>alert('✅ บันทึกข้อมูลสำเร็จ'); location.href='../index.php';</script>";
}
?>
