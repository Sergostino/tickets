<?php
// Файл для настройки вебхука Telegram бота
// Запустите этот файл один раз через браузер

$botToken = '8695972843:AAGujAuE8tnAqmETn4ij5JW5qYb1WinAow0'; // Замените на реальный токен
$webhookUrl = 'https://3шага.site/tickets/index.php'; // Замените на ваш домен

// URL для установки вебхука
$url = "https://api.telegram.org/bot{$botToken}/setWebhook?url={$webhookUrl}";

// Отправляем запрос
$result = file_get_contents($url);

// Проверяем результат
if ($result === FALSE) {
    echo "Ошибка при установке вебхука. Проверьте токен и домен.";
} else {
    $response = json_decode($result, true);
    echo "<h2>Результат установки вебхука:</h2>";
    echo "<pre>";
    print_r($response);
    echo "</pre>";
    
    if ($response['ok']) {
        echo "<p style='color: green;'>✅ Вебхук успешно установлен!</p>";
        echo "<p>Бот теперь будет получать обновления по адресу: {$webhookUrl}</p>";
    } else {
        echo "<p style='color: red;'>❌ Ошибка: " . $response['description'] . "</p>";
    }
}

// Дополнительно: можно проверить текущий вебхук
echo "<hr><h3>Проверка текущего вебхука:</h3>";
$checkUrl = "https://api.telegram.org/bot{$botToken}/getWebhookInfo";
$checkResult = file_get_contents($checkUrl);
$checkResponse = json_decode($checkResult, true);

echo "<pre>";
print_r($checkResponse);
echo "</pre>";
?>