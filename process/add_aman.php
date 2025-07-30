<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include(__DIR__ . "/../connect.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $man_shift = $_POST['man-shift'];
    $man_hour = $_POST['man-hour'];
    $man_fc = $_POST['man-fc'];
    $man_fb = $_POST['man-fb'];
    $man_rc = $_POST['man-rc'];
    $man_rb = $_POST['man-rb'];
    $man_3rd = $_POST['man-3rd'];
    $man_sub = $_POST['man-sub'];


    // เพิ่มข้อมูล
    $stmt = $conn->prepare("INSERT INTO sewing_aman (shift, thour, fc_act, fb_act, rc_act, rb_act, 3rd_act, subass_act)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $man_shift,
        $man_hour,
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
