<?php
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['telegram_id'])) {
    echo json_encode(['success' => false, 'error' => 'Telegram ID required']);
    exit;
}

$telegram_id = $data['telegram_id'];

try {
    // 1. Поиск в users (IT)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch();
    if ($user) {
        $user['role'] = 'it';
        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }
    
    // 2. Поиск в requesters
    $stmt = $pdo->prepare("SELECT * FROM requesters WHERE user_id = ?");
    $stmt->execute([$telegram_id]);
    $requester = $stmt->fetch();
    if ($requester) {
        $user = [
            'id' => $requester['id'],
            'telegram_id' => $requester['user_id'],
            'username' => $requester['username'] ?? '',
            'first_name' => $requester['first_name'] ?? '',
            'last_name' => $requester['last_name'] ?? '',
            'surname' => $requester['surname'] ?? '',
            'is_admin' => 0,
            'role' => 'requester',
            'created_at' => $requester['created_at']
        ];
        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'User not registered']);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>