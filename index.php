<?php
require_once 'config.php';

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit;

// ====================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ======================

function apiRequest($method, $parameters) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function editMessage($chat_id, $message_id, $text, $reply_markup = null) {
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageText?" . http_build_query($data);
    @file_get_contents($url);
}

function getUserRole($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    if ($stmt->fetch()) return 'it';
    
    $stmt = $pdo->prepare("SELECT id FROM requesters WHERE user_id = ?");
    $stmt->execute([$telegram_id]);
    if ($stmt->fetch()) return 'requester';
    
    return 'unauthorized';
}

function addUserToSystem($request_data) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
        $stmt->execute([$request_data['user_id']]);
        if ($stmt->fetch()) return false;
        
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
    } catch (Exception $e) {
        error_log("Add user error: " . $e->getMessage());
        return false;
    }
}

function addRequesterToSystem($request_data) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id FROM requesters WHERE user_id = ?");
        $stmt->execute([$request_data['user_id']]);
        if ($stmt->fetch()) return false;
        
        $stmt = $pdo->prepare("
            INSERT INTO requesters (user_id, username, first_name, last_name, surname, created_at)
            VALUES (?, ?, ?, ?, '', NOW())
        ");
        $stmt->execute([
            $request_data['user_id'],
            $request_data['username'] ?? '',
            $request_data['first_name'] ?? '',
            $request_data['last_name'] ?? ''
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Add requester error: " . $e->getMessage());
        return false;
    }
}

function showRoleSelection($chat_id, $message_id, $request_id, $admin_user_id) {
    $text = "👤 Выберите роль для пользователя:\n\n";
    $text .= "Заявка #{$request_id}";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '👨‍💻 IT-специалист', 'callback_data' => "approve_role_{$request_id}_it"],
                ['text' => '🙋 Заявитель', 'callback_data' => "approve_role_{$request_id}_requester"],
            ],
            [
                ['text' => '❌ Отмена', 'callback_data' => "cancel_role_selection_{$request_id}"]
            ]
        ]
    ];
    editMessage($chat_id, $message_id, $text, $keyboard);
}

/**
 * Новая функция для обработки изменения статуса заявки заявителя (по кнопкам)
 */
function handleRequesterStatusChange($ticket_id, $new_status, $admin_chat_id, $message_id, $admin_user_id) {
    global $pdo;
    
    if ($admin_user_id != ADMIN_ID) {
        editMessage($admin_chat_id, $message_id, "❌ У вас нет прав для этого действия.");
        return;
    }
    
    try {
        // Получаем информацию о тикете и заявителе
        $stmt = $pdo->prepare("
            SELECT t.*, r.user_id as requester_telegram_id, r.surname 
            FROM tickets t 
            LEFT JOIN requesters r ON t.user_id = r.id
            WHERE t.id = ? AND t.source = 'requester'
        ");
        $stmt->execute([$ticket_id]);
        $ticket = $stmt->fetch();
        
        if (!$ticket) {
            editMessage($admin_chat_id, $message_id, "❌ Тикет не найден.");
            return;
        }
        
        $stmt = $pdo->prepare("UPDATE tickets SET requester_status = ? WHERE id = ?");
        $stmt->execute([$new_status, $ticket_id]);
        
        $status_text = ($new_status == 'in_progress') ? '▶️ В работе' : '⏳ На рассмотрении';
        
        // Формируем сообщение с датой и кратким описанием
        $short_task = mb_substr($ticket['task'], 0, 50);
        if (mb_strlen($ticket['task']) > 50) $short_task .= '…';
        
        $message = "🔄 <b>Статус вашей заявки #{$ticket_id} изменён</b>\n\n";
        $message .= "📅 Дата: {$ticket['ticket_date']}\n";
        $message .= "📝 Задача: {$short_task}\n";
        $message .= "Новый статус: <b>{$status_text}</b>";
        
        sendTelegramMessage($ticket['requester_telegram_id'], $message);
        
        $new_text = "✅ Заявка #{$ticket_id} от {$ticket['surname']} переведена в статус <b>{$status_text}</b>.\n\n";
        $new_text .= "Заявитель уведомлён.";
        editMessage($admin_chat_id, $message_id, $new_text);
        
    } catch (Exception $e) {
        error_log("Requester status change error: " . $e->getMessage());
        editMessage($admin_chat_id, $message_id, "❌ Ошибка при изменении статуса.");
    }
}

// ====================== ОБРАБОТКА CALLBACK-ЗАПРОСОВ ======================

if (isset($update["callback_query"])) {
    $callback = $update["callback_query"];
    $chat_id = $callback["message"]["chat"]["id"];
    $message_id = $callback["message"]["message_id"];
    $data = $callback["data"];
    $callback_id = $callback["id"];
    $from_id = $callback["from"]["id"];
    
    apiRequest("answerCallbackQuery", ["callback_query_id" => $callback_id]);
    
    // ---- Заявки на регистрацию ----
    if (strpos($data, 'request_') === 0) {
        if ($data == 'request_send') {
            handleRegistrationRequest($from_id, $callback["from"], $chat_id, $message_id);
        }
        elseif (strpos($data, 'request_approve_') === 0) {
            $request_id = str_replace('request_approve_', '', $data);
            $stmt = $pdo->prepare("SELECT * FROM registration_requests WHERE request_id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            if ($request && $request['status'] == 'pending' && $from_id == ADMIN_ID) {
                showRoleSelection($chat_id, $message_id, $request_id, $from_id);
            } else {
                editMessage($chat_id, $message_id, "❌ Невозможно обработать заявку.");
            }
        }
        elseif (strpos($data, 'request_reject_') === 0) {
            $request_id = str_replace('request_reject_', '', $data);
            handleRequestDecision($request_id, 'reject', $chat_id, $message_id, $from_id);
        }
    }
    // ---- Выбор роли при одобрении ----
    elseif (strpos($data, 'approve_role_') === 0) {
        $parts = explode('_', $data);
        $request_id = $parts[2];
        $role = $parts[3];
        
        if ($from_id != ADMIN_ID) {
            editMessage($chat_id, $message_id, "❌ У вас нет прав для этого действия.");
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM registration_requests WHERE request_id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if (!$request) {
            editMessage($chat_id, $message_id, "❌ Заявка не найдена.");
            exit;
        }
        if ($request['status'] != 'pending') {
            editMessage($chat_id, $message_id, "⚠️ Заявка уже обработана.");
            exit;
        }
        
        if ($role == 'it') {
            $success = addUserToSystem($request);
            $role_text = '👨‍💻 IT-специалист';
        } else {
            $success = addRequesterToSystem($request);
            $role_text = '🙋 Заявитель';
        }
        
        if ($success) {
            $stmt = $pdo->prepare("UPDATE registration_requests SET status = 'approved' WHERE request_id = ?");
            $stmt->execute([$request_id]);
            
            $message = "✅ <b>Ваша заявка одобрена!</b>\n\n";
            $message .= "Роль: {$role_text}\n";
            $message .= "Теперь вы можете пользоваться ботом. Отправьте /start для начала работы.";
            sendTelegramMessage($request['user_id'], $message);
            
            $text = "✅ <b>Заявка #{$request_id} одобрена.</b>\n\n";
            $text .= "Пользователь добавлен как {$role_text}.";
            editMessage($chat_id, $message_id, $text);
        } else {
            editMessage($chat_id, $message_id, "❌ Ошибка при добавлении пользователя.");
        }
    }
    elseif (strpos($data, 'cancel_role_selection_') === 0) {
        $request_id = str_replace('cancel_role_selection_', '', $data);
        editMessage($chat_id, $message_id, "⚡ Выбор роли отменён. Заявка остаётся в статусе 'pending'.");
    }
    // ---- Обработка новых кнопок для заявок заявителей ----
    elseif (strpos($data, 'ticket_progress_') === 0) {
        $ticket_id = str_replace('ticket_progress_', '', $data);
        handleRequesterStatusChange($ticket_id, 'in_progress', $chat_id, $message_id, $from_id);
    }
    elseif (strpos($data, 'ticket_pending_') === 0) {
        $ticket_id = str_replace('ticket_pending_', '', $data);
        handleRequesterStatusChange($ticket_id, 'pending', $chat_id, $message_id, $from_id);
    }
    
    exit;
}

// ====================== ОБРАБОТКА СООБЩЕНИЙ ======================

if (isset($update["message"])) {
    $message = $update["message"];
    $chat_id = $message["chat"]["id"];
    $text = $message["text"] ?? '';
    $from = $message["from"];
    
    $role = getUserRole($from["id"]);
    
    if ($role == 'unauthorized') {
        $userCount = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch()['count'];
        if ($userCount == 0) {
            // Первый пользователь — администратор
            $stmt = $pdo->prepare("INSERT INTO users (telegram_id, username, first_name, last_name, is_admin) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$from["id"], $from["username"] ?? '', $from["first_name"] ?? '', $from["last_name"] ?? '']);
            sendTelegramMessage($chat_id, "✅ Вы зарегистрированы как первый администратор системы!");
            $role = 'it';
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '📨 Отправить заявку', 'callback_data' => 'request_send']]
                ]
            ];
            $message_text = "❌ <b>Регистрация новых пользователей временно приостановлена.</b>\n\nВы можете отправить заявку администратору на доступ к боту.";
            sendTelegramMessage($chat_id, $message_text, $keyboard);
            exit;
        }
    }
    
    if ($role == 'it' || $role == 'requester') {
        switch ($text) {
            case '/start':
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '📋 Открыть приложение', 'web_app' => ['url' => 'https://3шага.site/tickets/webapp/']]
                        ]
                    ]
                ];
                $greeting = ($role == 'it') ? "IT-специалист" : "Заявитель";
                sendTelegramMessage($chat_id, "Добро пожаловать в систему учета тикетов! Ваша роль: {$greeting}", $keyboard);
                break;
            case '/menu':
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '📋 Открыть приложение', 'web_app' => ['url' => 'https://3шага.site/tickets/webapp/']]
                        ]
                    ]
                ];
                sendTelegramMessage($chat_id, "Главное меню:", $keyboard);
                break;
            default:
                sendTelegramMessage($chat_id, "Используйте /start для начала работы или /menu для открытия меню");
        }
    }
}

// ====================== СТАНДАРТНЫЕ ФУНКЦИИ ЗАЯВОК ======================

function handleRegistrationRequest($user_id, $user_info, $chat_id, $message_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM registration_requests WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$user_id]);
        if ($stmt->fetch()) {
            editMessage($chat_id, $message_id, "⏳ Ваша заявка уже отправлена и находится на рассмотрении.");
            return;
        }
        $request_id = 'REQ' . strtoupper(substr(md5(uniqid()), 0, 8));
        $stmt = $pdo->prepare("INSERT INTO registration_requests (request_id, user_id, username, first_name, last_name, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$request_id, $user_id, $user_info['username'] ?? '', $user_info['first_name'] ?? '', $user_info['last_name'] ?? '']);
        editMessage($chat_id, $message_id, "✅ Ваша заявка отправлена администратору. Ожидайте решения.");
        notifyAdminAboutRequest($request_id, $user_id, $user_info);
    } catch (Exception $e) {
        error_log("Registration request error: " . $e->getMessage());
        editMessage($chat_id, $message_id, "❌ Произошла ошибка при отправке заявки. Попробуйте позже.");
    }
}

function notifyAdminAboutRequest($request_id, $user_id, $user_info) {
    $text = "📨 <b>НОВАЯ ЗАЯВКА НА РЕГИСТРАЦИЮ</b>\n\n";
    $text .= "<b>ID заявки:</b> #{$request_id}\n";
    $text .= "<b>ID пользователя:</b> {$user_id}\n";
    $text .= "<b>Username:</b> @" . ($user_info['username'] ?? 'нет') . "\n";
    $text .= "<b>Имя:</b> " . ($user_info['first_name'] ?? 'Не указано') . " " . ($user_info['last_name'] ?? '') . "\n";
    $text .= "<b>Дата:</b> " . date('d.m.Y H:i:s') . "\n";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✅ Одобрить', 'callback_data' => 'request_approve_' . $request_id],
                ['text' => '❌ Отклонить', 'callback_data' => 'request_reject_' . $request_id]
            ]
        ]
    ];
    sendTelegramMessage(ADMIN_ID, $text, $keyboard);
}

function handleRequestDecision($request_id, $decision, $admin_chat_id, $message_id, $admin_user_id) {
    global $pdo;
    if ($admin_user_id != ADMIN_ID) {
        editMessage($admin_chat_id, $message_id, "❌ У вас нет прав для этого действия.");
        return;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM registration_requests WHERE request_id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        if (!$request) {
            editMessage($admin_chat_id, $message_id, "❌ Заявка не найдена.");
            return;
        }
        if ($request['status'] != 'pending') {
            editMessage($admin_chat_id, $message_id, "⚠️ Эта заявка уже была обработана.");
            return;
        }
        
        if ($decision == 'reject') {
            $stmt = $pdo->prepare("UPDATE registration_requests SET status = 'rejected' WHERE request_id = ?");
            $stmt->execute([$request_id]);
            sendTelegramMessage($request['user_id'], "❌ <b>Ваша заявка отклонена администратором.</b>\n\nПо вопросам обращайтесь к администратору.");
            editMessage($admin_chat_id, $message_id, "❌ <b>Заявка #{$request_id} отклонена.</b>");
        }
    } catch (Exception $e) {
        error_log("Request decision error: " . $e->getMessage());
        editMessage($admin_chat_id, $message_id, "❌ Произошла ошибка при обработке заявки.");
    }
}
?>