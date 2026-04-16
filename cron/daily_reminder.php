<?php
/**
 * Ежедневное напоминание о тикетах со статусом "Не в SN" (is_in_db = 0)
 * Запуск по cron: 0 8 * * * php /var/www/u3336189/data/www/3шага.site/tickets/cron/daily_reminder.php
 */

require_once __DIR__ . '/../config.php';

/**
 * Отправка сообщения через Telegram Bot API
 */


try {
    error_log("Daily reminder started at " . date('Y-m-d H:i:s'));

    // Получаем всех IT-пользователей (users), у которых есть хотя бы один тикет с is_in_db = 0
    // JOIN с таблицей tickets, дополнительно фильтруем по source = 'it' для безопасности
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.telegram_id, u.first_name, u.last_name
        FROM users u
        INNER JOIN tickets t ON t.user_id = u.id
        WHERE t.is_in_db = 0 AND t.source = 'it'
    ");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    error_log("Found " . count($users) . " users with pending tickets.");

    foreach ($users as $user) {
        // Получаем тикеты этого пользователя со статусом "Не в SN" (источник = it)
        $stmt = $pdo->prepare("
            SELECT 
                t.id,
                t.task,
                DATE_FORMAT(t.created_at, '%d.%m.%Y %H:%i') as created,
                e.full_name as employee_name
            FROM tickets t
            LEFT JOIN employees e ON t.employee_id = e.id
            WHERE t.user_id = ? AND t.is_in_db = 0 AND t.source = 'it'
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$user['id']]);
        $tickets = $stmt->fetchAll();
        
        $userName = trim($user['first_name'] . ' ' . $user['last_name']) ?: 'Пользователь';
        
        if (empty($tickets)) {
            // Теоретически сюда не попадём, потому что выше уже отфильтровали пользователей без тикетов,
            // но оставим для полноты.
            $message = "✅ <b>На сегодня тикетов со статусом \"Не в SN\" нет.</b>\n\nХорошего дня!";
        } else {
            $message = "🔔 <b>Напоминание: тикеты, не внесённые в ServiceNow</b>\n\n";
            $message .= "Уважаемый(ая) {$userName}, у вас есть незавершённые тикеты:\n\n";
            
            foreach ($tickets as $ticket) {
                $shortTask = mb_substr($ticket['task'], 0, 50);
                if (mb_strlen($ticket['task']) > 50) $shortTask .= '…';
                
                $message .= "• #{$ticket['id']} - {$ticket['created']} от {$ticket['employee_name']}\n";
                $message .= "  <i>{$shortTask}</i>\n\n";
            }
            
            $message .= "Пожалуйста, внесите их в систему и отметьте статус.";
        }
        
        sendTelegramMessage($user['telegram_id'], $message);
        error_log("Reminder sent to user {$user['id']} (telegram_id: {$user['telegram_id']}) with " . count($tickets) . " tickets.");
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Daily reminders sent successfully.\n";
    error_log("Daily reminder finished at " . date('Y-m-d H:i:s'));
    
} catch (Exception $e) {
    error_log("Daily reminder error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}