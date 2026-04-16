<?php
// Конфигурация базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'u3336189_tickets_bot');
define('DB_USER', 'u3336189_samalazoff');
define('DB_PASS', 'Cqh5scsAQ36PHyP');

// Токен Telegram бота
define('BOT_TOKEN', '8695972843:AAGujAuE8tnAqmETn4ij5JW5qYb1WinAow0');

// ID администратора
define('ADMIN_ID', 1527954983);

// Секретный ключ для Web App
define('WEBAPP_SECRET', 'Sverdlovsk23');

// Включение отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Автозагрузка классов
spl_autoload_register(function ($class) {
    include __DIR__ . '/libs/' . $class . '.php';
});

// Создание подключения к БД
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

/**
 * Отправка сообщения через Telegram Bot API
 */
function sendTelegramMessage($chat_id, $text, $reply_markup = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage?" . http_build_query($data);
    @file_get_contents($url);
}

/**
 * Проверка авторизации (ищет в users и requesters)
 */
function checkAuth() {
    global $pdo;
    
    $user_id = null;
    if (isset($_SERVER['HTTP_X_TELEGRAM_USER_ID'])) {
        $user_id = $_SERVER['HTTP_X_TELEGRAM_USER_ID'];
    } elseif (isset($_GET['user_id']) && isset($_GET['hash'])) {
        $user_id = $_GET['user_id'];
        $hash = $_GET['hash'];
        $expected_hash = md5($user_id . WEBAPP_SECRET);
        if ($hash !== $expected_hash) return false;
    } else {
        return false;
    }
    
    try {
        // 1. Ищем в users (IT)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if ($user) {
            $user['role'] = 'it';
            return $user;
        }
        
        // 2. Ищем в requesters
        $stmt = $pdo->prepare("SELECT * FROM requesters WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $requester = $stmt->fetch();
        if ($requester) {
            return [
                'id' => $requester['id'],
                'telegram_id' => $requester['user_id'],
                'username' => $requester['username'] ?? '',
                'first_name' => $requester['first_name'] ?? '',
                'last_name' => $requester['last_name'] ?? '',
                'surname' => $requester['surname'] ?? '',
                'is_admin' => 0,
                'role' => 'requester',
                'created_at' => $requester['created_at']
            ];
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Auth error: " . $e->getMessage());
        return false;
    }
}
?>