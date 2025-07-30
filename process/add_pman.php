<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include(__DIR__ . "/../connect.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $man_fc = $_POST['pman-fc'];
    $man_fb = $_POST['pman-fb'];
    $man_rc = $_POST['pman-rc'];
    $man_rb = $_POST['pman-rb'];
    $man_3rd = $_POST['pman-3rd'];
    $man_sub = $_POST['pman-sub'];


    // เพิ่มข้อมูล
    $stmt = $conn->prepare("INSERT INTO sewing_pman (fc_plan, fb_plan, rc_plan, rb_plan, 3rd_plan, subass_plan)
                            VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $man_fc,
        $man_fb,
        $man_rc,
        $man_rb,
        $man_3rd,
        $man_sub
    ]);

    echo "<script>alert('✅ เพิ่มข้อมูลเรียบร้อยแล้ว'); location.href='../index.php';</script>";
}
?>
