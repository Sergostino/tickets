<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
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

// Только IT-специалисты имеют доступ к сотрудникам
if ($user['role'] !== 'it') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied: IT role required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Получение списка сотрудников
    $search = $_GET['search'] ?? '';
    
    try {
        $sql = "SELECT * FROM employees WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $sql .= " AND full_name LIKE ?";
            $params[] = $search . '%';
        }
        
        $sql .= " ORDER BY full_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'employees' => $employees]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Добавление нового сотрудника (только для админа)
    if (!$user['is_admin']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin only']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || empty($data['full_name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Full name is required']);
        exit;
    }
    
    $fullName = trim($data['full_name']);
    
    // Форматируем: Фамилия и первая буква имени
    $parts = explode(' ', $fullName);
    if (count($parts) >= 2) {
        $lastName = $parts[0];
        $firstLetter = mb_strtoupper(mb_substr($parts[1], 0, 1, 'UTF-8'), 'UTF-8');
        $fullName = $lastName . ' ' . $firstLetter . '.';
    }
    
    try {
        // Проверяем, нет ли уже такого сотрудника
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE full_name = ?");
        $stmt->execute([$fullName]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Employee already exists']);
            exit;
        }
        
        $stmt = $pdo->prepare("INSERT INTO employees (full_name, created_by, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$fullName, $user['id']]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Удаление сотрудника (только для админа)
    if (!$user['is_admin']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin only']);
        exit;
    }
    
    $id = $_GET['id'] ?? 0;
    
    if (!$id || !is_numeric($id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid ID required']);
        exit;
    }
    
    try {
        // Проверяем, не используется ли сотрудник в тикетах
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE employee_id = ?");
        $stmt->execute([$id]);
        $usageCount = $stmt->fetch()['count'];
        
        if ($usageCount > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Сотрудник используется в ' . $usageCount . ' тикетах. Удаление невозможно.']);
            exit;
        }
        
        // Удаляем сотрудника
        $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>