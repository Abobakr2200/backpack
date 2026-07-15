<?php
require_once __DIR__ . '/../../../../config/env.php';

// هذا الملف بيكشف تفاصيل حساسة عن قاعدة البيانات، لازم يكون متاح
// فقط في بيئة التطوير (APP_DEBUG=true في ملف .env)
if (!env('APP_DEBUG', false)) {
    http_response_code(404);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Message.php';

$result = [];

// Test 1: session
$result['session'] = isLoggedIn() ? 'OK user_id=' . getUserId() : 'NOT LOGGED IN';

if (!isLoggedIn()) {
    echo json_encode($result);
    exit();
}

$user_id = getUserId();

// Test 2: DB connection
try {
    require_once __DIR__ . '/../../../../config/database.php';
    $conn = connectDB();
    $result['db'] = 'OK';
    
    // Test 3: messages table structure
    $r = $conn->query("SHOW COLUMNS FROM messages");
    if ($r) {
        $cols = [];
        while($row = $r->fetch_assoc()) $cols[] = $row['Field'];
        $result['messages_columns'] = $cols;
    } else {
        $result['messages_columns'] = 'ERROR: ' . $conn->error;
    }
    
    // Test 4: users table
    $r2 = $conn->query("SHOW COLUMNS FROM users");
    if ($r2) {
        $cols2 = [];
        while($row = $r2->fetch_assoc()) $cols2[] = $row['Field'];
        $result['users_columns'] = $cols2;
    }
    
    // Test 5: simple messages query
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM messages WHERE sender_id=? OR receiver_id=?");
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $result['my_messages_count'] = $stmt->get_result()->fetch_assoc()['cnt'];
    
    // Test 6: getLastMessages query
    $stmt2 = $conn->prepare(
        "SELECT u.id AS user_id, u.username, u.profile_photo, u.status,
         m.message_text AS last_message, m.message_type, m.created_at AS last_message_time
         FROM users u
         JOIN messages m ON m.id = (
             SELECT MAX(id) FROM messages
             WHERE (sender_id = u.id AND receiver_id = ?)
                OR (sender_id = ? AND receiver_id = u.id)
         )
         WHERE u.id != ?
         ORDER BY m.id DESC"
    );
    if (!$stmt2) {
        $result['convs_query'] = 'PREPARE ERROR: ' . $conn->error;
    } else {
        $stmt2->bind_param("iii", $user_id, $user_id, $user_id);
        if ($stmt2->execute()) {
            $convs = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            $result['convs_query'] = 'OK count=' . count($convs);
            $result['convs'] = $convs;
        } else {
            $result['convs_query'] = 'EXECUTE ERROR: ' . $stmt2->error;
        }
    }
    
} catch(Exception $e) {
    $result['db'] = 'ERROR: ' . $e->getMessage();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
