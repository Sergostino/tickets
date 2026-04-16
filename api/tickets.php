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

// ====================== GET ======================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if ($user['role'] === 'it') {
            // IT-специалист или админ
            if (isset($_GET['source']) && $_GET['source'] === 'requester' && $user['is_admin']) {
                // Админ запрашивает все тикеты заявителей
                $sql = "SELECT t.*, e.full_name as employee_name, r.surname as requester_surname
                        FROM tickets t 
                        LEFT JOIN employees e ON t.employee_id = e.id
                        LEFT JOIN requesters r ON t.user_id = r.id
                        WHERE t.source = 'requester'";
                $params = [];
            } elseif (isset($_GET['source']) && $_GET['source'] === 'it' && $user['is_admin']) {
                // Админ запрашивает все IT-тикеты (всех IT-специалистов)
                $sql = "SELECT t.*, e.full_name as employee_name, u.first_name, u.last_name
                        FROM tickets t 
                        LEFT JOIN employees e ON t.employee_id = e.id
                        LEFT JOIN users u ON t.user_id = u.id
                        WHERE t.source = 'it'";
                $params = [];
            } else {
                // Обычный IT-специалист видит только свои тикеты
                $sql = "SELECT t.*, e.full_name as employee_name 
                        FROM tickets t 
                        LEFT JOIN employees e ON t.employee_id = e.id 
                        WHERE t.user_id = ?";
                $params = [$user['id']];
            }
        } else {
            // Заявитель видит только свои тикеты
            $sql = "SELECT t.*, e.full_name as employee_name 
                    FROM tickets t 
                    LEFT JOIN employees e ON t.employee_id = e.id 
                    WHERE t.user_id = ? AND t.source = 'requester'";
            $params = [$user['id']]; // внутренний ID заявителя
        }
        
        $sql .= " ORDER BY t.ticket_date DESC, t.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'tickets' => $tickets]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

// ====================== POST ======================
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
        exit;
    }
    
    if ($user['role'] === 'it') {
        $required = ['employee_id', 'date', 'task'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO tickets (user_id, employee_id, ticket_date, task, is_done, is_in_db, source, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'it', NOW())
            ");
            $stmt->execute([
                $user['id'],
                intval($data['employee_id']),
                $data['date'],
                trim($data['task']),
                isset($data['is_done']) ? intval($data['is_done']) : 0,
                isset($data['is_in_db']) ? intval($data['is_in_db']) : 0
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    elseif ($user['role'] === 'requester') {
        if (empty($user['surname'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Surname not set', 'code' => 'SURNAME_REQUIRED']);
            exit;
        }
        
        $required = ['date', 'task'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        try {
            $employee_id = null; // после удаления внешнего ключа можно null
            
            $stmt = $pdo->prepare("
                INSERT INTO tickets (user_id, employee_id, ticket_date, task, is_done, is_in_db, source, requester_name, requester_status, created_at) 
                VALUES (?, ?, ?, ?, 0, 0, 'requester', ?, 'pending', NOW())
            ");
            $stmt->execute([
                $user['id'], // ВАЖНО: внутренний ID заявителя
                $employee_id,
                $data['date'],
                trim($data['task']),
                $user['surname']
            ]);
            $ticket_id = $pdo->lastInsertId();
            
            // Отправляем уведомление администратору
            $admin_text = "📨 <b>Новая заявка от заявителя</b>\n\n";
            $admin_text .= "От: {$user['surname']} (@{$user['username']})\n";
            $admin_text .= "Описание: " . mb_substr(trim($data['task']), 0, 100) . (mb_strlen(trim($data['task'])) > 100 ? '…' : '') . "\n\n";
            $admin_text .= "ID тикета: #{$ticket_id}";
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '▶️ В работу', 'callback_data' => "ticket_progress_{$ticket_id}"],
                        ['text' => '⏳ На рассмотрении', 'callback_data' => "ticket_pending_{$ticket_id}"]
                    ]
                ]
            ];
            sendTelegramMessage(ADMIN_ID, $admin_text, $keyboard);
            
            echo json_encode(['success' => true, 'id' => $ticket_id]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
    }
}
// ====================== PUT ======================
elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['id']) || !isset($data['field']) || !isset($data['value'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }
    
    $ticketId = intval($data['id']);
    $field = $data['field'];
    $value = $data['value'];
    
    // Проверка принадлежности тикета
    try {
        if ($user['role'] === 'it') {
            $stmt = $pdo->prepare("SELECT id, user_id, source, ticket_date, task FROM tickets WHERE id = ?");
            $stmt->execute([$ticketId]);
        } else {
            $stmt = $pdo->prepare("SELECT id, user_id, source, ticket_date, task FROM tickets WHERE id = ? AND user_id = ? AND source = 'requester'");
            $stmt->execute([$ticketId, $user['id']]); // внутренний ID заявителя
        }
        $ticket = $stmt->fetch();
        if (!$ticket) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Ticket not found or access denied']);
            exit;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
    
    $allowed_fields = ['is_done', 'is_in_db', 'task', 'requester_status'];
    if (!in_array($field, $allowed_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid field']);
        exit;
    }
    
    try {
        if ($field === 'task') {
            $stmt = $pdo->prepare("UPDATE tickets SET task = ? WHERE id = ?");
            $params = [trim($value), $ticketId];
        } elseif ($field === 'requester_status') {
            $stmt = $pdo->prepare("UPDATE tickets SET requester_status = ? WHERE id = ?");
            $params = [$value, $ticketId];
        } else {
            $value = intval($value);
            $stmt = $pdo->prepare("UPDATE tickets SET $field = ? WHERE id = ?");
            $params = [$value, $ticketId];
        }
        
        $success = $stmt->execute($params);
        
        if ($success) {
            // Если тикет от заявителя — отправляем уведомление
            if ($ticket['source'] === 'requester') {
                // Получаем Telegram ID заявителя из таблицы requesters по внутреннему ID (user_id)
                $stmt2 = $pdo->prepare("SELECT user_id, surname FROM requesters WHERE id = ?");
                $stmt2->execute([$ticket['user_id']]);
                $requester = $stmt2->fetch();
                
                if ($requester) {
                    $status_text = '';
                    if ($field === 'is_done') {
                        $status_text = $value ? '✅ Выполнено' : '▶️ В работе';
                    } elseif ($field === 'is_in_db') {
                        $status_text = $value ? '📦 Внесено в SN' : '📤 Не в SN';
                    } elseif ($field === 'task') {
                        $status_text = '📝 Описание изменено';
                    } elseif ($field === 'requester_status') {
                        $status_text = ($value === 'in_progress') ? '▶️ В работе' : '⏳ На рассмотрении';
                    }
                    
                    // Используем данные из $ticket (полученные до изменения)
                    $short_task = mb_substr($ticket['task'], 0, 50);
                    if (mb_strlen($ticket['task']) > 50) $short_task .= '…';
                    
                    $message = "🔄 <b>Статус вашей заявки #{$ticketId} изменён</b>\n\n";
                    $message .= "📅 Дата: {$ticket['ticket_date']}\n";
                    $message .= "📝 Задача: {$short_task}\n";
                    $message .= "Новый статус: <b>{$status_text}</b>";
                    
                    sendTelegramMessage($requester['user_id'], $message);
                }
            }
            
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Update failed']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}
// ====================== DELETE ======================
// ====================== DELETE ======================
elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? 0;
    if (!$id || !is_numeric($id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid ID required']);
        exit;
    }
    
    try {
        if ($user['role'] === 'it') {
            $stmt = $pdo->prepare("SELECT id, source FROM tickets WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user['id']]);
        } else {
            $stmt = $pdo->prepare("SELECT id, source FROM tickets WHERE id = ? AND user_id = ? AND source = 'requester'");
            $stmt->execute([$id, $user['id']]); // внутренний ID заявителя
        }
        $ticket = $stmt->fetch();
        
        if (!$ticket) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Ticket not found or access denied']);
            exit;
        }
        
        $stmt = $pdo->prepare("DELETE FROM tickets WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Ticket not found']);
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