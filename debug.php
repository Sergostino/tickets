<?php
// debug.php - для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

echo "<h1>Debug Information</h1>";

// Проверка базы данных
try {
    $stmt = $pdo->query("SELECT * FROM users WHERE telegram_id = 1527954983");
    $user = $stmt->fetch();
    
    echo "<h3>Текущий пользователь:</h3>";
    echo "<pre>";
    print_r($user);
    echo "</pre>";
    
    echo "<h3>is_admin статус:</h3>";
    echo $user['is_admin'] ? "✅ Администратор" : "❌ Не администратор";
    
    // Проверка таблицы employees
    $stmt = $pdo->query("SELECT * FROM employees LIMIT 10");
    $employees = $stmt->fetchAll();
    
    echo "<h3>Сотрудники в базе:</h3>";
    echo "<pre>";
    print_r($employees);
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Ошибка БД: " . $e->getMessage() . "</p>";
}

// Проверка вебхука
echo "<h3>Проверка вебхука:</h3>";
$webhookInfo = @file_get_contents("https://api.telegram.org/bot" . BOT_TOKEN . "/getWebhookInfo");
if ($webhookInfo) {
    $info = json_decode($webhookInfo, true);
    echo "<pre>";
    print_r($info);
    echo "</pre>";
}
?>