<?php
// /pelanggan/delete_pelanggan.php - Debug Version

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Enable error logging for debugging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/delete_pelanggan.log');

// Load configurations
require_once __DIR__ . '/../config/config_mikrotik.php';
require_once __DIR__ . '/../config/config_database.php';

// Debug: Log all incoming data
error_log("DELETE PELANGGAN DEBUG - Request Method: " . $_SERVER['REQUEST_METHOD']);
error_log("DELETE PELANGGAN DEBUG - POST Data: " . print_r($_POST, true));
error_log("DELETE PELANGGAN DEBUG - GET Data: " . print_r($_GET, true));
error_log("DELETE PELANGGAN DEBUG - Session: " . print_r($_SESSION, true));

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    error_log("DELETE PELANGGAN DEBUG - User not logged in, redirecting to login");
    header("Location: ../login.php");
    exit();
}

$response = ['success' => false, 'message' => '', 'debug' => []];

try {
    // Support both GET and POST methods for flexibility
    $request_method = $_SERVER['REQUEST_METHOD'];
    $response['debug'][] = "Request method: " . $request_method;
    
    if ($request_method !== 'POST' && $request_method !== 'GET') {
        throw new Exception('Metode request tidak valid: ' . $request_method);
    }

    // Get pelanggan ID from POST or GET
    $id_pelanggan = 0;
    if ($request_method === 'POST') {
        $id_pelanggan = isset($_POST['id_pelanggan']) ? (int)$_POST['id_pelanggan'] : 0;
        $response['debug'][] = "ID Pelanggan from POST: " . $id_pelanggan;
        
        // Validate CSRF token for POST requests (optional for debugging)
        if (!isset($_POST['csrf_token'])) {
            $response['debug'][] = "CSRF token not found in POST";
        } else if (!isset($_SESSION['csrf_token'])) {
            $response['debug'][] = "CSRF token not found in session";
        } else if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $response['debug'][] = "CSRF token mismatch - POST: " . $_POST['csrf_token'] . " vs Session: " . $_SESSION['csrf_token'];
            // For debugging, we'll warn but not fail
            // throw new Exception('Token keamanan tidak valid. Silakan refresh halaman dan coba lagi.');
        } else {
            $response['debug'][] = "CSRF token valid";
        }
    } else {
        // For GET requests (fallback compatibility)
        $id_pelanggan = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $response['debug'][] = "ID Pelanggan from GET: " . $id_pelanggan;
    }
    
    if ($id_pelanggan <= 0) {
        throw new Exception('ID pelanggan tidak valid: ' . $id_pelanggan);
    }

    $response['debug'][] = "Searching for customer with ID: " . $id_pelanggan;

    // Get customer data before deletion
    $query = "SELECT dp.*, pi.nama_paket, pi.profile_name 
              FROM data_pelanggan dp 
              LEFT JOIN paket_internet pi ON dp.id_paket = pi.id_paket 
              WHERE dp.id_pelanggan = ?";
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        throw new Exception('Error preparing customer query: ' . $mysqli->error);
    }
    
    $stmt->bind_param("i", $id_pelanggan);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Data pelanggan tidak ditemukan dengan ID: ' . $id_pelanggan);
    }
    
    $pelanggan = $result->fetch_assoc();
    $response['debug'][] = "Customer found: " . $pelanggan['nama_pelanggan'];
    $response['debug'][] = "Mikrotik username: " . ($pelanggan['mikrotik_username'] ?? 'NULL');
    
    // Check if customer has unpaid bills
    $check_tagihan = $mysqli->prepare("SELECT COUNT(*) as total FROM tagihan WHERE id_pelanggan = ? AND status_tagihan != 'sudah_bayar'");
    $check_tagihan->bind_param("i", $id_pelanggan);
    $check_tagihan->execute();
    $tagihan_result = $check_tagihan->get_result();
    $unpaid_bills = $tagihan_result->fetch_assoc()['total'];
    $response['debug'][] = "Unpaid bills: " . $unpaid_bills;
    
    // Check if customer has payment history
    $check_pembayaran = $mysqli->prepare("SELECT COUNT(*) as total FROM pembayaran WHERE id_pelanggan = ?");
    $check_pembayaran->bind_param("i", $id_pelanggan);
    $check_pembayaran->execute();
    $pembayaran_result = $check_pembayaran->get_result();
    $payment_history = $pembayaran_result->fetch_assoc()['total'];
    $response['debug'][] = "Payment history count: " . $payment_history;
    
    // Check active connections
    $check_active = $mysqli->prepare("SELECT COUNT(*) as total FROM monitoring_pppoe WHERE id_pelanggan = ? AND status = 'active'");
    $check_active->bind_param("i", $id_pelanggan);
    $check_active->execute();
    $active_result = $check_active->get_result();
    $active_connections = $active_result->fetch_assoc()['total'];
    $response['debug'][] = "Active connections: " . $active_connections;
    
    // Start database transaction
    $response['debug'][] = "Starting database transaction";
    $mysqli->autocommit(false);

    try {
        $mikrotik_message = '';
        
        // 1. Delete from Mikrotik first (if username exists)
        if (!empty($pelanggan['mikrotik_username'])) {
            $response['debug'][] = "Attempting Mikrotik deletion for user: " . $pelanggan['mikrotik_username'];
            
            // Check if RouterosAPI class exists
            if (!class_exists('RouterosAPI')) {
                $response['debug'][] = "RouterosAPI class not found - including routeros_api.php";
                require_once __DIR__ . '/../config/routeros_api.php';
            }
            
            if (class_exists('RouterosAPI')) {
                $api = new RouterosAPI();
                $response['debug'][] = "RouterosAPI instance created";
                
                // Check if Mikrotik variables are set
                if (!isset($mikrotik_ip) || !isset($mikrotik_user) || !isset($mikrotik_pass)) {
                    $response['debug'][] = "Mikrotik connection variables not set";
                    $mikrotik_message = " (konfigurasi Mikrotik tidak lengkap)";
                } else {
                    $response['debug'][] = "Connecting to Mikrotik: " . $mikrotik_ip;
                    
                    if ($api->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass)) {
                        $response['debug'][] = "Connected to Mikrotik successfully";
                        
                        try {
                            // Find and remove active PPPoE connections first
                            $response['debug'][] = "Searching for active connections";
                            $active_connections_mt = $api->comm('/ppp/active/print', [
                                '?name' => $pelanggan['mikrotik_username']
                            ]);
                            
                            if (!empty($active_connections_mt)) {
                                $response['debug'][] = "Found " . count($active_connections_mt) . " active connections";
                                foreach ($active_connections_mt as $connection) {
                                    if (isset($connection['.id'])) {
                                        $api->comm('/ppp/active/remove', [
                                            '.id' => $connection['.id']
                                        ]);
                                        $response['debug'][] = "Removed active connection: " . $connection['.id'];
                                    }
                                }
                            } else {
                                $response['debug'][] = "No active connections found";
                            }
                            
                            // Find and remove PPPoE secret
                            $response['debug'][] = "Searching for PPPoE secrets";
                            $secrets = $api->comm('/ppp/secret/print', [
                                '?name' => $pelanggan['mikrotik_username']
                            ]);
                            
                            if (!empty($secrets)) {
                                $response['debug'][] = "Found " . count($secrets) . " secrets";
                                foreach ($secrets as $secret) {
                                    if (isset($secret['.id'])) {
                                        $api->comm('/ppp/secret/remove', [
                                            '.id' => $secret['.id']
                                        ]);
                                        $response['debug'][] = "Removed secret: " . $secret['.id'];
                                    }
                                }
                                $mikrotik_message = " dan user PPPoE '{$pelanggan['mikrotik_username']}' berhasil dihapus dari Mikrotik";
                            } else {
                                $response['debug'][] = "No secrets found in Mikrotik";
                                $mikrotik_message = " (user PPPoE tidak ditemukan di Mikrotik)";
                            }
                            
                        } catch (Exception $mt_e) {
                            $response['debug'][] = "Mikrotik operation error: " . $mt_e->getMessage();
                            $mikrotik_message = " (warning: " . $mt_e->getMessage() . ")";
                            // Don't fail the deletion for Mikrotik errors
                        }
                        
                        $api->disconnect();
                        $response['debug'][] = "Disconnected from Mikrotik";
                    } else {
                        $response['debug'][] = "Failed to connect to Mikrotik";
                        $mikrotik_message = " (Mikrotik tidak dapat terhubung)";
                        // Log warning but don't fail the deletion
                        error_log("Warning: Could not connect to Mikrotik to remove user: " . $pelanggan['mikrotik_username']);
                    }
                }
            } else {
                $response['debug'][] = "RouterosAPI class still not available";
                $mikrotik_message = " (RouterosAPI tidak tersedia)";
            }
        } else {
            $response['debug'][] = "No Mikrotik username to delete";
        }

        // 2. Delete monitoring data
        $response['debug'][] = "Deleting monitoring data";
        $delete_monitoring = $mysqli->prepare("DELETE FROM monitoring_pppoe WHERE id_pelanggan = ?");
        if (!$delete_monitoring) {
            throw new Exception('Error preparing monitoring delete query: ' . $mysqli->error);
        }
        $delete_monitoring->bind_param("i", $id_pelanggan);
        $delete_monitoring->execute();
        $monitoring_deleted = $delete_monitoring->affected_rows;
        $response['debug'][] = "Monitoring records deleted: " . $monitoring_deleted;

        // 3. Delete payment records
        $response['debug'][] = "Deleting payment records";
        $delete_pembayaran = $mysqli->prepare("DELETE FROM pembayaran WHERE id_pelanggan = ?");
        if (!$delete_pembayaran) {
            throw new Exception('Error preparing payment delete query: ' . $mysqli->error);
        }
        $delete_pembayaran->bind_param("i", $id_pelanggan);
        $delete_pembayaran->execute();
        $pembayaran_deleted = $delete_pembayaran->affected_rows;
        $response['debug'][] = "Payment records deleted: " . $pembayaran_deleted;

        // 4. Delete billing records
        $response['debug'][] = "Deleting billing records";
        $delete_tagihan = $mysqli->prepare("DELETE FROM tagihan WHERE id_pelanggan = ?");
        if (!$delete_tagihan) {
            throw new Exception('Error preparing billing delete query: ' . $mysqli->error);
        }
        $delete_tagihan->bind_param("i", $id_pelanggan);
        $delete_tagihan->execute();
        $tagihan_deleted = $delete_tagihan->affected_rows;
        $response['debug'][] = "Billing records deleted: " . $tagihan_deleted;

        // 5. Delete customer data
        $response['debug'][] = "Deleting customer data";
        $delete_pelanggan = $mysqli->prepare("DELETE FROM data_pelanggan WHERE id_pelanggan = ?");
        if (!$delete_pelanggan) {
            throw new Exception('Error preparing customer delete query: ' . $mysqli->error);
        }
        $delete_pelanggan->bind_param("i", $id_pelanggan);
        $delete_pelanggan->execute();
        $pelanggan_deleted = $delete_pelanggan->affected_rows;
        $response['debug'][] = "Customer records deleted: " . $pelanggan_deleted;
        
        if ($pelanggan_deleted == 0) {
            throw new Exception('Tidak ada data pelanggan yang terhapus. Kemungkinan ID tidak ditemukan.');
        }

        // 6. Log activity
        if (isset($_SESSION['id_user'])) {
            $response['debug'][] = "Logging activity for user ID: " . $_SESSION['id_user'];
            $log_query = "INSERT INTO log_aktivitas (id_user, username, aktivitas, tabel_terkait, id_data_terkait, ip_address) 
                         VALUES (?, ?, ?, 'data_pelanggan', ?, ?)";
            $log_stmt = $mysqli->prepare($log_query);
            if ($log_stmt) {
                $aktivitas = "Menghapus pelanggan: " . $pelanggan['nama_pelanggan'] . " (Username: " . ($pelanggan['mikrotik_username'] ?? 'N/A') . ")";
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $log_stmt->bind_param("issss", $_SESSION['id_user'], $_SESSION['username'], $aktivitas, $id_pelanggan, $ip_address);
                $log_stmt->execute();
                $response['debug'][] = "Activity logged successfully";
            } else {
                $response['debug'][] = "Failed to prepare log query: " . $mysqli->error;
            }
        } else {
            $response['debug'][] = "No user ID in session for logging";
        }

        // Commit transaction
        $response['debug'][] = "Committing transaction";
        $mysqli->commit();
        $response['debug'][] = "Transaction committed successfully";
        
        $response['success'] = true;
        $response['message'] = "Pelanggan '{$pelanggan['nama_pelanggan']}' berhasil dihapus dari database{$mikrotik_message}";
        $response['mikrotik_removed'] = !empty($pelanggan['mikrotik_username']);
        $response['records_deleted'] = [
            'monitoring' => $monitoring_deleted,
            'pembayaran' => $pembayaran_deleted,
            'tagihan' => $tagihan_deleted,
            'pelanggan' => $pelanggan_deleted
        ];

    } catch (Exception $e) {
        $response['debug'][] = "Error in transaction, rolling back: " . $e->getMessage();
        $mysqli->rollback();
        throw $e;
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    $response['debug'][] = "Exception caught: " . $e->getMessage();
    error_log("DELETE PELANGGAN ERROR: " . $e->getMessage());
}

// Log final response for debugging
error_log("DELETE PELANGGAN DEBUG - Final Response: " . print_r($response, true));

// Return JSON response for AJAX requests
if ((isset($_POST['ajax']) && $_POST['ajax'] === '1') || 
    (isset($_GET['ajax']) && $_GET['ajax'] === '1')) {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// For debugging, show debug info if not AJAX
if (!$response['success'] && (isset($_GET['debug']) || isset($_POST['debug']))) {
    echo "<h3>Debug Information:</h3>";
    echo "<pre>" . print_r($response, true) . "</pre>";
    echo "<p><a href='data_pelanggan.php'>Kembali ke daftar pelanggan</a></p>";
    exit();
}

// Redirect for regular form submissions
if ($response['success']) {
    $_SESSION['success_message'] = $response['message'];
} else {
    $_SESSION['error_message'] = $response['message'] . " (Debug: tambahkan ?debug=1 di URL untuk info detail)";
}

// Determine redirect destination
$redirect_url = 'data_pelanggan.php';

// Preserve filter parameters if they exist
$params = [];
if (isset($_POST['return_page'])) {
    $params['page'] = $_POST['return_page'];
}
if (isset($_POST['return_search'])) {
    $params['search'] = $_POST['return_search'];
}
if (isset($_POST['return_status'])) {
    $params['status'] = $_POST['return_status'];
}
if (isset($_POST['return_paket'])) {
    $params['paket'] = $_POST['return_paket'];
}

// Also check GET parameters for fallback
if (isset($_GET['return_to'])) {
    $redirect_url = $_GET['return_to'];
}

if (!empty($params)) {
    $redirect_url .= '?' . http_build_query($params);
}

header("Location: {$redirect_url}");
exit();
?>