<?php
require '/home/claude/work/proj/config/session.php';
$_SESSION['user_id'] = 1;
echo json_encode(['csrf' => csrfToken()]);
