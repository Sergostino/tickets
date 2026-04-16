<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Telegram-User-ID');

// Handle preflight requests
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

// Только админы могут управлять заявками
if (!$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin only']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Получение списка заявок
    $status = $_GET['status'] ?? 'pending';
    $limit = $_GET['limit'] ?? 50;
    
    try {
        // Получаем заявки
        $sql = "SELECT * FROM registration_requests WHERE 1=1";
        $params = [];
        
        if ($status !== 'all') {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = intval($limit);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll();
        
        // Получаем статистику
        $stats = getRequestsStats();
        
        echo json_encode([
            'success' => true, 
            'requests' => $requests,
            'stats' => $stats
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Обработка заявки (одобрение/отклонение)
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['request_id']) || !isset($data['action'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }
    
    $request_id = $data['request_id'];
    $action = $data['action'];
    
    if (!in_array($action, ['approve', 'reject'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit;
    }
    
    try {
        // Получаем информацию о заявке
        $stmt = $pdo->prepare("SELECT * FROM registration_requests WHERE request_id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if (!$request) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Request not found']);
            exit;
        }
        
        if ($request['status'] != 'pending') {
            echo json_encode(['success' => false, 'error' => 'Request already processed']);
            exit;
        }
        
        // Обновляем статус заявки
        $new_status = ($action == 'approve') ? 'approved' : 'rejected';
        $stmt = $pdo->prepare("UPDATE registration_requests SET status = ? WHERE request_id = ?");
        $stmt->execute([$new_status, $request_id]);
        
        if ($action == 'approve') {
            // Добавляем пользователя в систему
            addUserFromRequest($request);
            
            // Отправляем уведомление пользователю через Telegram API
            sendTelegramMessage($request['user_id'], 
                "🎉 <b>Ваша заявка одобрена!</b>\n\nТеперь вы можете пользоваться ботом. Отправьте /start для начала работы.");
        } else {
            // Отправляем уведомление об отклонении
            sendTelegramMessage($request['user_id'], 
                "❌ <b>Ваша заявка отклонена администратором.</b>\n\nПо вопросам обращайтесь к администратору.");
        }
        
        echo json_encode(['success' => true]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

// Функция для получения статистики заявок
function getRequestsStats() {
    global $pdo;
    
    $stats = [
        'total' => 0,
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0
    ];
    
    try {
        $query = "SELECT status, COUNT(*) as count FROM registration_requests GROUP BY status";
        $stmt = $pdo->query($query);
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $stats[$row['status']] = $row['count'];
            $stats['total'] += $row['count'];
        }
        
        return $stats;
    } catch (Exception $e) {
        return $stats;
    }
}

// Функция для добавления пользователя из заявки
function addUserFromRequest($request_data) {
    global $pdo;
    
    try {
        // Проверяем, нет ли уже такого пользователя
        $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
        $stmt->execute([$request_data['user_id']]);
        $existing_user = $stmt->fetch();
        
        if (!$existing_user) {
            $stmt = $pdo->prepare("
                INSERT INTO users (telegram_id, username, first_name, last_name, is_admin, created_at) 
                VALUES (?, ?, ?, ?, 0, NOW())
            ");
            
            $stmt->execute([
                $request_data['user_id'],
                $request_data['username'] ?? '',
                $request_data['first_name'] ?? '',
                $request_data['last_name'] ?? ''
            ]);
            
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Add user from request error: " . $e->getMessage());
        return false;
    }
}

// Функция отправки сообщения через Telegram API
function sendTelegramMessage($chat_id, $text) {
    global $botToken;
    
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage?" . http_build_query($data);
    @file_get_contents($url);
}
?>