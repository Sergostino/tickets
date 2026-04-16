<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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

// Только администраторы (is_admin = 1) могут управлять пользователями
if (!$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin only']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $roleFilter = $_GET['role'] ?? 'all';
    
    try {
        $sql = "SELECT * FROM users";
        $params = [];
        
        if ($roleFilter === 'it') {
            $sql .= " WHERE role = 'it'";
        } elseif ($roleFilter === 'requester') {
            $sql .= " WHERE role = 'requester'";
        }
        
        $sql .= " ORDER BY is_admin DESC, created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'users' => $users]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Добавление нового пользователя (админ)
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['telegram_id']) || empty($data['telegram_id'])) {
        echo json_encode(['success' => false, 'error' => 'Telegram ID required']);
        exit;
    }
    
    // Проверка на существование
    $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
    $stmt->execute([$data['telegram_id']]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'User already exists']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (telegram_id, username, first_name, last_name, role, is_admin, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $role = $data['role'] ?? 'it';
        $is_admin = ($role === 'it' && isset($data['is_admin'])) ? intval($data['is_admin']) : 0;
        
        $success = $stmt->execute([
            $data['telegram_id'],
            $data['username'] ?? '',
            $data['first_name'] ?? '',
            $data['last_name'] ?? '',
            $role,
            $is_admin
        ]);
        
        if ($success) {
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Обновление пользователя: изменение роли или is_admin
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }
    
    // Нельзя изменить себя
    if ($data['id'] == $user['id']) {
        echo json_encode(['success' => false, 'error' => 'Cannot change your own status']);
        exit;
    }
    
    try {
        if (isset($data['role'])) {
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$data['role'], $data['id']]);
        } elseif (isset($data['is_admin'])) {
            $stmt = $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
            $stmt->execute([$data['is_admin'], $data['id']]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Nothing to update']);
            exit;
        }
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Каскадное удаление пользователя и всех его тикетов
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID required']);
        exit;
    }
    
    try {
        // Нельзя удалить себя
        if ($id == $user['id']) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete yourself']);
            exit;
        }
        
        // Транзакция
        $pdo->beginTransaction();
        
        // Удаляем все тикеты пользователя
        $stmt = $pdo->prepare("DELETE FROM tickets WHERE user_id = ?");
        $stmt->execute([$id]);
        
        // Удаляем пользователя
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>