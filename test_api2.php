<?php
use App\Controllers\Api\ChatController;

ob_start();
try {
    session_start();
    $_SESSION['user_id'] = 1;
    $_GET['project_id'] = 1;
    $_GET['since_id'] = 0;
    
    require_once __DIR__ . '/vendor/autoload.php';
    $controller = new ChatController();
    $controller->getMessages();

} catch (Throwable $e) {
    echo $e->getMessage();
}
$out = ob_get_clean();
echo "OUTPUT: \n" . $out;
