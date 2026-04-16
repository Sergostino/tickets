<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Telegram-User-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$user = checkAuth();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Только администраторы
if ($user['role'] !== 'it' || !$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin only']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                r.*,
                COUNT(t.id) as tickets_count
            FROM requesters r
            LEFT JOIN tickets t ON t.user_id = r.id AND t.source = 'requester'
            GROUP BY r.id
            ORDER BY r.created_at DESC
        ");
        $stmt->execute();
        $requesters = $stmt->fetchAll();
        echo json_encode(['success' => true, 'requesters' => $requesters]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Каскадное удаление заявителя и всех его тикетов
    $user_id = $_GET['user_id'] ?? 0;
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'user_id required']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Получаем внутренний id заявителя
        $stmt = $pdo->prepare("SELECT id FROM requesters WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $requester = $stmt->fetch();
        if (!$requester) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Requester not found']);
            $pdo->rollBack();
            exit;
        }
        $internalId = $requester['id'];
        
        // Удаляем все тикеты заявителя (source = 'requester')
        $stmt = $pdo->prepare("DELETE FROM tickets WHERE user_id = ? AND source = 'requester'");
        $stmt->execute([$internalId]);
        
        // Удаляем самого заявителя
        $stmt = $pdo->prepare("DELETE FROM requesters WHERE id = ?");
        $stmt->execute([$internalId]);
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Повышение заявителя до IT-специалиста
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['user_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'user_id required']);
        exit;
    }
    
    $user_id = $data['user_id'];
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM requesters WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $requester = $stmt->fetch();
        if (!$requester) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Requester not found']);
            $pdo->rollBack();
            exit;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO users (telegram_id, username, first_name, last_name, is_admin, created_at)
            VALUES (?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([
            $requester['user_id'],
            $requester['username'] ?? '',
            $requester['first_name'] ?? '',
            $requester['last_name'] ?? ''
        ]);
        
        $stmt = $pdo->prepare("DELETE FROM requesters WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>