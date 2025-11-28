<?php
session_start();
require_once __DIR__ . '/../config/config_mikrotik.php';
require_once __DIR__ . '/../config/config_database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$username = $_POST['username'] ?? '';
$service = $_POST['service'] ?? '';

if (empty($username) || empty($service)) {
    echo json_encode(['success' => false, 'message' => 'Username and service required']);
    exit();
}

try {
    $api = new RouterosAPI();
    if ($api->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass)) {
        // Disconnect based on service type
        if ($service === 'PPPoE') {
            $api->write('/ppp/active/print', [
                '?name' => $username
            ]);
            $active = $api->read();
            
            if (count($active) > 0) {
                $api->write('/ppp/active/remove', [
                    '.id' => $active[0]['.id']
                ]);
                $api->read();
            }
        }
        // Add other service types (hotspot, etc) as needed
        
        $api->disconnect();
        
        // Log the action
        $log_message = "Disconnected $service user: $username";
        $mysqli->query("INSERT INTO activity_log (username, action) VALUES ('".$_SESSION['username']."', '".$log_message."')");
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to connect to MikroTik']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>