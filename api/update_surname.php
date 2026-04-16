<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Telegram-User-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$user = checkAuth();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($user['role'] !== 'requester') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only for requesters']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['surname']) || empty(trim($data['surname']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Surname is required']);
    exit;
}

$surname = trim($data['surname']);

try {
    $stmt = $pdo->prepare("UPDATE requesters SET surname = ? WHERE user_id = ?");
    $stmt->execute([$surname, $user['telegram_id']]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        // Если строка не обновлена (например, фамилия уже такая же) – считаем успехом
        echo json_encode(['success' => true, 'message' => 'No changes']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>