<?php
/**
 * Entry Point for Backpack
 * Redirects to the Chat Module View
 */
require_once __DIR__ . '/config/session.php';

if (!isLoggedIn()) {
    header("Location: app/Modules/Auth/Views/login.php");
    exit();
}

// Redirect to the main chat interface
include 'app/Modules/Chat/Views/chat.php';
?>
