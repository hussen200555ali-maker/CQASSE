<?php
$m = new mysqli("127.0.0.1", "root", "", "eqas_db", 3306);
if ($m->connect_error) {
    echo "FAIL: " . $m->connect_error . "\n";
} else {
    echo "OK: " . $m->host_info . "\n";
    $r = $m->query("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE()");
    $row = $r->fetch_assoc();
    echo "Tables: " . $row['c'] . "\n";
    $m->close();
}
