<?php
require_once __DIR__ . '/../../../../config/session.php';
session_destroy();
header("Location: /app/Modules/Auth/Views/login.php");
exit();
