<?php
// make_admin.php - запустите один раз в браузере
require_once 'config.php';

$telegram_id = 1527954983; // Ваш ID из логов

try {
    $stmt = $pdo->prepare("UPDATE users SET is_admin = 1 WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    
    if ($stmt->rowCount() > 0) {
        echo "✅ Пользователь $telegram_id назначен администратором!";
    } else {
        echo "❌ Пользователь $telegram_id не найден.";
        
        // Создаем пользователя, если его нет
        $stmt = $pdo->prepare("INSERT INTO users (telegram_id, username, first_name, last_name, is_admin) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$telegram_id, 'Samalazoff', 'Sergei', 'S.']);
        echo "<br>✅ Пользователь создан и назначен администратором!";
    }
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage();
}
?>