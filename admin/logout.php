<?php
/** logout.php — ออกจากระบบ */
require dirname(__DIR__) . '/includes/init.php';
session_unset();
session_destroy();
redirect('admin/login.php');
