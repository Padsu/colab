<?php
ob_start(); // Start output buffering at the VERY TOP

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configurations
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../config/config_mikrotik.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Variabel untuk voucher limit
$max_vouchers = 1000;

// Status koneksi dan API object
$api_connected = $mikrotik_connected;
$router_name = "Main Router";
$router_ip = $mikrotik_ip;

// Fungsi helper untuk mengecek table exists
function tableExists($mysqli, $table_name) {
    if (!$mysqli) return false;
    try {
        $result = $mysqli->query("SHOW TABLES LIKE '$table_name'");
        return $result && $result->num_rows > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Fungsi untuk membuat table voucher_history
function createVoucherHistoryTable($mysqli) {
    if (!$mysqli) return false;
    try {
        $create_table_query = "CREATE TABLE IF NOT EXISTS voucher_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            profile_name VARCHAR(100) NOT NULL,
            jumlah INT NOT NULL,
            harga DECIMAL(15,2) NOT NULL,
            total_nilai DECIMAL(15,2) NOT NULL,
            batch_id VARCHAR(10) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_profile_name (profile_name),
            INDEX idx_created_at (created_at),
            INDEX idx_batch_id (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        return $mysqli->query($create_table_query);
    } catch (Exception $e) {
        return false;
    }
}

// Get hotspot profiles from database mikrotik_hotspot_profiles
$db_profiles = [];
if (isset($mysqli) && $mysqli) {
    try {
        $profile_query = "SELECT * FROM mikrotik_hotspot_profiles WHERE status_profile = 'aktif'";
        $profile_result = $mysqli->query($profile_query);
        if ($profile_result) {
            while ($row = $profile_result->fetch_assoc()) {
                $db_profiles[$row['nama_profile']] = $row;
            }
        }
    } catch (Exception $e) {
        // Ignore error
    }
}

// Initialize sales data variables
$last_sales_data = [];
$total_last_sales = 0;
$last_sales_count = 0;
$last_sales_date = '';

// Get data penjualan voucher session sebelumnya
if (isset($mysqli) && $mysqli) {
    try {
        // PRIORITAS 1: Ambil dari tabel hotspot_sales (data yang sudah terintegrasi dengan mutasi keuangan)
        $sales_query = "SELECT 
                            'hotspot_sales' as source_table,
                            COUNT(*) as total_vouchers,
                            AVG(harga_jual) as harga_per_voucher,
                            SUM(harga_jual) as total_nilai,
                            MAX(created_at) as last_date,
                            'Penjualan Voucher' as profile_name
                        FROM hotspot_sales 
                        WHERE DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
        
        $sales_result = $mysqli->query($sales_query);
        if ($sales_result) {
            $row = $sales_result->fetch_assoc();
            if ($row['total_vouchers'] > 0) {
                $last_sales_data[] = $row;
                $total_last_sales += floatval($row['total_nilai']);
                $last_sales_count += intval($row['total_vouchers']);
                $last_sales_date = $row['last_date'];
            }
        }
        
        // PRIORITAS 2: Jika tidak ada data dari hotspot_sales, cek voucher_history
        if (empty($last_sales_data)) {
            $table_exists = tableExists($mysqli, 'voucher_history');
            
            if (!$table_exists) {
                createVoucherHistoryTable($mysqli);
                $table_exists = tableExists($mysqli, 'voucher_history');
            }
            
            if ($table_exists) {
                $sales_query = "SELECT 
                                    profile_name,
                                    SUM(jumlah) as total_vouchers,
                                    AVG(harga) as harga_per_voucher,
                                    SUM(total_nilai) as total_nilai,
                                    MAX(created_at) as last_date
                                FROM voucher_history 
                                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                                GROUP BY profile_name 
                                ORDER BY MAX(created_at) DESC";
                
                $sales_result = $mysqli->query($sales_query);
                if ($sales_result) {
                    while ($row = $sales_result->fetch_assoc()) {
                        $last_sales_data[] = $row;
                        $total_last_sales += floatval($row['total_nilai']);
                        $last_sales_count += intval($row['total_vouchers']);
                        if (empty($last_sales_date)) {
                            $last_sales_date = $row['last_date'];
                        }
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        // Ignore database errors
    }
    
    // PRIORITAS 3: Fallback ke session jika tidak ada data dari database
    if (empty($last_sales_data) && isset($_SESSION['last_voucher_sales'])) {
        $last_sales_data = $_SESSION['last_voucher_sales'];
        $total_last_sales = floatval($_SESSION['last_sales_total'] ?? 0);
        $last_sales_count = intval($_SESSION['last_sales_count'] ?? 0);
        $last_sales_date = $_SESSION['last_sales_date'] ?? '';
    }
}

// Fungsi generate random string
function generateRandomString($length = 5, $character_type = 'numbers') {
    $chars_map = [
        'uppercase' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'lowercase' => 'abcdefghijklmnopqrstuvwxyz',
        'numbers' => '0123456789',
        'upper_numbers' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        'lower_numbers' => 'abcdefghijklmnopqrstuvwxyz0123456789'
    ];
    
    $chars = $chars_map[$character_type] ?? $chars_map['numbers'];
    $rand = '';
    for ($i = 0; $i < $length; $i++) {
        $rand .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $rand;
}

// Fungsi get hotspot servers
function getHotspotServers($api) {
    if (!$api) return [];
    try {
        $api->write('/ip/hotspot/print');
        $data = $api->read();
        $list = [];
        foreach ($data as $d) {
            if (isset($d['name'])) $list[] = $d['name'];
        }
        return $list;
    } catch (Exception $e) {
        return [];
    }
}

// Fungsi get hotspot profiles from MikroTik
function getHotspotProfiles($api) {
    if (!$api) return [];
    try {
        $api->write('/ip/hotspot/user/profile/print');
        $data = $api->read();
        $list = [];
        foreach ($data as $d) {
            if (isset($d['.id'], $d['name'])) $list[$d['.id']] = $d['name'];
        }
        return $list;
    } catch (Exception $e) {
        return [];
    }
}

// Handle POST Generate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    if (!$api_connected) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Error: Router tidak terhubung!'];
    } else {
        $profile = $_POST['profile'] ?? '';
        $server = $_POST['server'] ?? '';
        $quantity = min($max_vouchers, intval($_POST['quantity'] ?? 0));
        $length = intval($_POST['length'] ?? 8);
        $prefix = $_POST['prefix'] ?? '';
        $uptimeLimit = $_POST['uptime_limit'] ?? '10:00:00';
        $voucher_model = $_POST['voucher_model'] ?? 'username_password';
        $character_type = $_POST['character_type'] ?? 'numbers';
        $data_limit = $_POST['data_limit'] ?? '';
        $custom_comment = $_POST['custom_comment'] ?? '';

        if ($quantity > 0 && $length > 0 && $profile && $server) {
            $tanggal = date('dmY');
            $batch_id = generateRandomString(5, 'numbers');
            $vouchers = [];
            $voucher_ids = []; // Untuk menyimpan ID voucher yang dibuat

            try {
                // Ambil harga dari database profile
                $harga_per_voucher = isset($db_profiles[$profile]) ? floatval($db_profiles[$profile]['harga']) : 0;
                $total_nilai_penjualan = $quantity * $harga_per_voucher;
                
                for ($i = 0; $i < $quantity; $i++) {
                    $username = $prefix . generateRandomString($length, $character_type);
                    
                    // Generate password berdasarkan model voucher
                    if ($voucher_model === 'username_password') {
                        $password = $prefix . generateRandomString($length, $character_type);
                    } else {
                        $password = $username; // username = password
                    }
                    
                    // Buat comment
                    if (!empty($custom_comment)) {
                        $comment = $custom_comment;
                    } else {
                        $comment = "VCH-{$batch_id}-{$tanggal}";
                    }

                    // Parameter untuk API MikroTik
                    $api_params = [
                        'server' => $server,
                        'name' => $username,
                        'password' => $password,
                        'profile' => $profile,
                        'limit-uptime' => $uptimeLimit,
                        'comment' => $comment,
                    ];
                    
                    // Tambahkan data limit jika diisi
                    if (!empty($data_limit)) {
                        $data_limit_bytes = intval($data_limit) * 1024 * 1024;
                        $api_params['limit-bytes-total'] = $data_limit_bytes;
                    }

                    $api->comm('/ip/hotspot/user/add', $api_params);

                    $vouchers[] = [
                        'name' => $username,
                        'password' => $password,
                        'profile' => $profile,
                        'server' => $server,
                        'uptime_limit' => $uptimeLimit,
                        'data_limit' => !empty($data_limit) ? $data_limit . ' MB' : '',
                        'router' => $router_name,
                        'comment' => $comment,
                    ];
                    
                    // STEP 1: Simpan voucher ke tabel hotspot_users
                    if (isset($mysqli) && $mysqli && tableExists($mysqli, 'hotspot_users')) {
                        try {
                            // Ambil ID profile dari database
                            $profile_id = null;
                            if (isset($db_profiles[$profile])) {
                                $profile_id = $db_profiles[$profile]['id_profile'];
                            }
                            
                            $insert_user_query = "INSERT INTO hotspot_users 
                                (username, password, id_profile, profile_name, nama_voucher, keterangan, 
                                 uptime_limit, status, batch_id, mikrotik_comment, created_by, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, 'aktif', ?, ?, ?, NOW())";
                            
                            $stmt = $mysqli->prepare($insert_user_query);
                            if ($stmt) {
                                $nama_voucher = "Voucher-{$profile}-{$username}";
                                $keterangan = "Generated voucher - Batch {$batch_id}";
                                $user_id = $_SESSION['user_id'] ?? 1;
                                
                                $stmt->bind_param('ssissssssi', 
                                    $username, $password, $profile_id, $profile, 
                                    $nama_voucher, $keterangan, $uptimeLimit, 
                                    $batch_id, $comment, $user_id
                                );
                                
                                if ($stmt->execute()) {
                                    $voucher_ids[] = $mysqli->insert_id;
                                }
                                $stmt->close();
                            }
                        } catch (Exception $e) {
                            // Log error tapi lanjutkan proses
                            error_log("Error saving voucher user: " . $e->getMessage());
                        }
                    }
                }

                // STEP 2: Simpan data penjualan ke session
                $_SESSION['last_voucher_sales'] = [
                    [
                        'profile_name' => $profile,
                        'total_vouchers' => $quantity,
                        'total_nilai' => $total_nilai_penjualan,
                        'harga_per_voucher' => $harga_per_voucher,
                        'last_date' => date('Y-m-d H:i:s')
                    ]
                ];
                $_SESSION['last_sales_total'] = $total_nilai_penjualan;
                $_SESSION['last_sales_count'] = $quantity;
                $_SESSION['last_sales_date'] = date('Y-m-d H:i:s');

                // STEP 3: Simpan ke tabel voucher_history (untuk backup)
                if (isset($mysqli) && $mysqli && tableExists($mysqli, 'voucher_history')) {
                    try {
                        $insert_history_query = "INSERT INTO voucher_history (profile_name, jumlah, harga, total_nilai, created_at, batch_id) 
                                               VALUES (?, ?, ?, ?, NOW(), ?)";
                        $stmt = $mysqli->prepare($insert_history_query);
                        if ($stmt) {
                            $stmt->bind_param('siids', $profile, $quantity, $harga_per_voucher, $total_nilai_penjualan, $batch_id);
                            $stmt->execute();
                            $stmt->close();
                        }
                    } catch (Exception $e) {
                        error_log("Error saving voucher history: " . $e->getMessage());
                    }
                }

                // STEP 4: *** KUNCI UTAMA *** Simpan ke tabel hotspot_sales untuk integrasi dengan mutasi keuangan
                if (isset($mysqli) && $mysqli && tableExists($mysqli, 'hotspot_sales') && !empty($voucher_ids)) {
                    try {
                        // Ambil ID voucher pertama sebagai representative (atau bisa dibuat per voucher)
                        $representative_voucher_id = $voucher_ids[0];
                        
                        $insert_sales_query = "INSERT INTO hotspot_sales 
                            (id_user_hotspot, tanggal_jual, harga_jual, nama_pembeli, keterangan, id_user_penjual, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, NOW())";
                        
                        $stmt = $mysqli->prepare($insert_sales_query);
                        if ($stmt) {
                            $tanggal_jual = date('Y-m-d');
                            $nama_pembeli = "Batch Generate"; // Bisa diganti dengan input dari user
                            $keterangan_sales = "Generate {$quantity} voucher {$profile} - Batch {$batch_id}";
                            $user_penjual = $_SESSION['user_id'] ?? 1;
                            
                            $stmt->bind_param('isdssi', 
                                $representative_voucher_id,
                                $tanggal_jual,
                                $total_nilai_penjualan,
                                $nama_pembeli,
                                $keterangan_sales,
                                $user_penjual
                            );
                            
                            if ($stmt->execute()) {
                                $_SESSION['alert'] = [
                                    'type' => 'success', 
                                    'message' => "Berhasil generate {$quantity} voucher dan data sudah masuk ke mutasi keuangan!"
                                ];
                            } else {
                                throw new Exception("Gagal menyimpan data penjualan: " . $stmt->error);
                            }
                            $stmt->close();
                        }
                    } catch (Exception $e) {
                        // Jika gagal simpan ke hotspot_sales, tetap berhasil generate voucher
                        error_log("Error saving to hotspot_sales: " . $e->getMessage());
                        $_SESSION['alert'] = [
                            'type' => 'warning', 
                            'message' => "Voucher berhasil dibuat, tapi gagal masuk ke mutasi keuangan. Error: " . $e->getMessage()
                        ];
                    }
                }

                // Redirect to print
                $_SESSION['voucher_print_data'] = [
                    'vouchers' => $vouchers,
                    'company' => 'LJN - ANUNET'
                ];
                header("Location: voucher.php");
                exit;
                
            } catch (Exception $e) {
                $_SESSION['alert'] = ['type' => 'danger', 'message' => "Error saat generate voucher: " . $e->getMessage()];
            }
        } else {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Data tidak lengkap!'];
        }
    }
    header("Location: generate_voucher.php");
    exit;
}

// Ambil data untuk dropdown
$servers = [];
$profiles = [];
if ($api_connected) {
    $servers = getHotspotServers($api);
    $profiles = getHotspotProfiles($api);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Voucher Hotspot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
    :root {
      --primary: #1ABB9C; --success: #26B99A; --info: #23C6C8; --warning: #F8AC59;
      --danger: #ED5565; --secondary: #73879C; --dark: #2A3F54; --light: #F7F7F7;
    }
    body { background-color: #F7F7F7; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; color: #73879C; }
    .main-content { 
        padding: 20px; 
        margin-left: 220px; 
        min-height: calc(100vh - 52px);
        transition: margin-left 0.3s ease;
    }
    .page-title { margin-bottom: 30px; }
    .page-title h1 { font-size: 24px; color: var(--dark); margin: 0; font-weight: 400; }
    .page-title .page-subtitle { color: var(--secondary); font-size: 13px; margin: 5px 0 0 0; }
    .card { border: none; border-radius: 5px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); margin-bottom: 20px; }
    .card-header { background-color: white; border-bottom: 1px solid #e5e5e5; padding: 15px 20px; border-radius: 5px 5px 0 0 !important; }
    .card-header h5 { font-size: 16px; font-weight: 500; color: var(--dark); margin: 0; }
    .alert { padding: 10px 15px; font-size: 13px; border-radius: 3px; border: none; }
    .alert-danger { background-color: rgba(237, 85, 101, 0.1); color: var(--danger); }
    .alert-success { background-color: rgba(38, 185, 154, 0.1); color: var(--success); }
    .alert-warning { background-color: rgba(248, 172, 89, 0.1); color: var(--warning); }
    .alert-info { background-color: rgba(35, 198, 200, 0.1); color: var(--info); }
    .badge { font-weight: 500; font-size: 12px; padding: 5px 8px; }
    .badge-success { background-color: var(--success); }
    .badge-danger { background-color: var(--danger); }
    .badge-primary { background-color: var(--primary); }
    .badge-secondary { background-color: var(--secondary); }
    .form-control, .form-select { border-radius: 3px; border: 1px solid #D5D5D5; font-size: 13px; padding: 8px 12px; height: auto; }
    .form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 0.2rem rgba(26, 187, 156, 0.25); }
    .form-select { background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e"); background-size: 12px 12px; padding: 8px 30px 8px 12px; }
    .btn { border-radius: 3px; font-size: 13px; padding: 8px 15px; font-weight: 500; }
    .btn-primary { background-color: var(--primary); border-color: var(--primary); }
    .btn-primary:hover { background-color: #169F85; border-color: #169F85; }
    .btn-success { background-color: var(--success); border-color: var(--success); }
    .btn-success:hover { background-color: #1e9e8a; border-color: #1e9e8a; }
    .btn-warning { background-color: var(--warning); border-color: var(--warning); }
    .btn-danger { background-color: var(--danger); border-color: var(--danger); }
    .btn-info { background-color: var(--info); border-color: var(--info); }
    .btn-sm { padding: 5px 10px; font-size: 12px; }
    .input-group-text { background-color: #f5f5f5; border: 1px solid #ddd; font-size: 13px; }
    .guide-card { background-color: white; border: 1px solid #e5e5e5; border-radius: 5px; padding: 15px; }
    .guide-card h5 { font-size: 15px; color: var(--dark); margin-bottom: 15px; }
    .guide-card ul { padding-left: 20px; font-size: 13px; }
    .guide-card li { margin-bottom: 5px; }
    .profile-info-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f0f0f0; margin-bottom: 8px; }
    .profile-info-item:last-child { border-bottom: none; }
    .profile-info-label { font-weight: 500; color: var(--secondary); }
    .profile-info-value { color: var(--dark); }
    .sales-summary { background: linear-gradient(135deg, #1ABB9C 0%, #26B99A 100%); color: white; border-radius: 8px; padding: 20px; margin-bottom: 15px; }
    .sales-summary .sales-amount { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
    .sales-summary .sales-count { font-size: 14px; opacity: 0.9; }
    .integration-info { background: linear-gradient(135deg, #23C6C8 0%, #1ABB9C 100%); color: white; border-radius: 8px; padding: 15px; margin-bottom: 15px; font-size: 12px; }
    
    /* Sidebar responsive classes */
    .sidebar-collapsed .main-content {
        margin-left: 60px;
    }
    
    @media (max-width: 992px) { 
        .main-content { 
            margin-left: 0; 
            padding: 15px; 
        }
        .sidebar-collapsed .main-content {
            margin-left: 0;
        }
        .sales-summary { text-align: center; }
        .sales-summary .sales-amount { font-size: 20px; }
        .profile-info-item { flex-direction: column; align-items: flex-start !important; }
        .profile-info-label { margin-bottom: 2px; }
        .card-body .row .col-6 { margin-bottom: 10px; }
    }
    </style>
</head>
<body>
    <main class="main-content" id="mainContent">
        <div class="page-title">
            <h1>Generate Voucher Hotspot</h1>
            <p class="page-subtitle">Buat voucher hotspot untuk pelanggan</p>
        </div>
        
        <?php if (isset($_SESSION['alert'])): ?>
            <div class="alert alert-<?= $_SESSION['alert']['type'] ?> d-flex align-items-center">
                <i class="fas fa-<?= $_SESSION['alert']['type'] === 'danger' ? 'exclamation-circle' : ($_SESSION['alert']['type'] === 'success' ? 'check-circle' : 'info-circle') ?> me-2"></i>
                <?= htmlspecialchars($_SESSION['alert']['message']) ?>
            </div>
            <?php unset($_SESSION['alert']); ?>
        <?php endif; ?>
        
        <div class="row">
            <!-- Form Generate Voucher -->
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-ticket-alt me-2"></i>Form Generate Voucher</h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="action" value="generate">
                            
                            <?php if (!$api_connected): ?>
                                <div class="alert alert-warning d-flex align-items-center">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Router tidak terhubung. Pastikan koneksi router aktif sebelum generate voucher.
                                </div>
                            <?php endif; ?>
                            
                            <div class="row g-3">
                                <!-- Quantity -->
                                <div class="col-md-12">
                                    <label class="form-label">Jumlah <span class="text-danger">*</span></label>
                                    <input type="number" name="quantity" id="quantity" value="50" min="1" max="<?= $max_vouchers ?>" class="form-control" required>
                                    <small class="text-muted">Maksimal: <?= $max_vouchers ?> voucher</small>
                                </div>

                                <!-- Server -->
                                <div class="col-md-12">
                                    <label class="form-label">Server <span class="text-danger">*</span></label>
                                    <select name="server" class="form-select" required <?= !$api_connected ? 'disabled' : '' ?>>
                                        <option value="">-- Pilih Server --</option>
                                        <option value="all">All Servers</option>
                                        <?php foreach ($servers as $s): ?>
                                            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- Model Voucher -->
                                <div class="col-md-12">
                                    <label class="form-label">Model Voucher</label>
                                    <select name="voucher_model" class="form-select" required>
                                        <option value="username_password">Username + Password (Berbeda)</option>
                                        <option value="username_equals_password" selected>Username = Password (Sama)</option>
                                    </select>
                                </div>
                                
                                <!-- Character Type -->
                                <div class="col-md-12">
                                    <label class="form-label">Tipe Karakter</label>
                                    <select name="character_type" class="form-select" required>
                                        <option value="numbers" selected>Random Angka - 1234567890</option>
                                        <option value="uppercase">Random Huruf Besar - ABCDEF</option>
                                        <option value="lower_numbers">Random Angka & Huruf Kecil - 6ab54c32de</option>
                                        <option value="upper_numbers">Random Angka & Huruf Besar - 6AB54C32DE</option>
                                    </select>
                                </div>
                                
                                <!-- Length -->
                                <div class="col-md-12">
                                    <label class="form-label">Panjang Kode <span class="text-danger">*</span></label>
                                    <select name="length" class="form-select" id="length" required>
                                        <option value="8" selected>8</option>
                                        <option value="9">9</option>
                                        <option value="10">10</option>
                                        <option value="11">11</option>
                                        <option value="12">12</option>
                                    </select>
                                </div>
                                
                                <!-- Profile -->
                                <div class="col-md-12">
                                    <label class="form-label">Profile <span class="text-danger">*</span></label>
                                    <select name="profile" class="form-select" id="profile-select" required <?= !$api_connected ? 'disabled' : '' ?>>
                                        <option value="">-- Pilih Profile --</option>
                                        <?php foreach ($profiles as $id => $p): ?>
                                            <option value="<?= htmlspecialchars($p) ?>" 
                                                data-harga="<?= isset($db_profiles[$p]) ? $db_profiles[$p]['harga'] : 0 ?>"
                                                data-deskripsi="<?= isset($db_profiles[$p]) ? htmlspecialchars($db_profiles[$p]['nama_profile']) : '' ?>"
                                                data-rate-limit="<?= isset($db_profiles[$p]) ? htmlspecialchars($db_profiles[$p]['rate_limit_rx_tx']) : '' ?>"
                                                data-shared-users="<?= isset($db_profiles[$p]) ? htmlspecialchars($db_profiles[$p]['shared_users']) : '' ?>"
                                                data-mac-timeout="<?= isset($db_profiles[$p]) ? htmlspecialchars($db_profiles[$p]['mac_cookie_timeout']) : '' ?>"
                                                data-lock-user="<?= isset($db_profiles[$p]) ? htmlspecialchars($db_profiles[$p]['lock_user_enabled']) : 'no' ?>">
                                                <?= htmlspecialchars($p) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Prefix -->
                                <div class="col-md-12">
                                    <label class="form-label">Prefix (opsional)</label>
                                    <input type="text" name="prefix" id="prefix" class="form-control" placeholder="Contoh: HTL-">
                                </div>
                                
                                <!-- Uptime Limit -->
                                <div class="col-md-12">
                                    <label class="form-label">Limit Uptime <span class="text-danger">*</span></label>
                                    <input type="text" name="uptime_limit" class="form-control" value="10:00:00" placeholder="10:00:00" required>
                                    <small class="text-muted">Format: hh:mm:ss atau d hh:mm:ss</small>
                                </div>
                                
                                <!-- Data Limit -->
                                <div class="col-md-12">
                                    <label class="form-label">Data Limit (opsional)</label>
                                    <div class="input-group">
                                        <input type="number" name="data_limit" class="form-control" min="1" placeholder="Dalam MB">
                                        <span class="input-group-text">MB</span>
                                    </div>
                                    <small class="text-muted">Contoh: 1024 = 1GB</small>
                                </div>
                                
                                <!-- Custom Comment -->
                                <div class="col-12">
                                    <label class="form-label">Komentar Tambahan (opsional)</label>
                                    <input type="text" name="custom_comment" class="form-control" placeholder="Default: VCH-{kode5digit}-{tglblnthn}">
                                </div>
                                
                                <!-- Submit Button -->
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-primary" <?= !$api_connected ? 'disabled' : '' ?>>
                                        <i class="fas fa-ticket-alt me-1"></i> Generate Voucher
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Right Sidebar -->
            <div class="col-lg-5">
                <!-- Data Penjualan Session Sebelumnya -->
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-line me-2"></i>Penjualan Session Terakhir</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($last_sales_data) || !empty($_SESSION['last_voucher_sales'])): ?>
                            <!-- Summary Total -->
                            <div class="sales-summary">
                                <div class="sales-amount">
                                    Rp <?= number_format($total_last_sales, 0, ',', '.') ?>
                                </div>
                                <div class="sales-count">
                                    <?= $last_sales_count ?> voucher terjual
                                    <?php if ($last_sales_date): ?>
                                        <br><small><?= date('d/m/Y H:i', strtotime($last_sales_date)) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Detail per Profile -->
                            <div class="mb-3">
                                <h6>Detail Penjualan:</h6>
                                <?php 
                                $sales_data = !empty($last_sales_data) ? $last_sales_data : $_SESSION['last_voucher_sales'];
                                foreach ($sales_data as $sale): 
                                ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                        <div>
                                            <strong><?= htmlspecialchars($sale['profile_name']) ?></strong>
                                            <br><small class="text-muted"><?= $sale['total_vouchers'] ?> voucher</small>
                                        </div>
                                        <div class="text-end">
                                            <strong>Rp <?= number_format($sale['total_nilai'], 0, ',', '.') ?></strong>
                                            <br><small class="text-muted">@ Rp <?= number_format($sale['harga_per_voucher'] ?? 0, 0, ',', '.') ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Statistik Tambahan -->
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="border-end">
                                        <h6 class="text-primary"><?= count($sales_data) ?></h6>
                                        <small class="text-muted">Jenis Profile</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <h6 class="text-success">Rp <?= number_format($total_last_sales / max($last_sales_count, 1), 0, ',', '.') ?></h6>
                                    <small class="text-muted">Rata-rata/voucher</small>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- Tidak ada data penjualan -->
                            <div class="text-center py-4">
                                <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                                <h6 class="text-muted">Belum Ada Data Penjualan</h6>
                                <p class="text-muted small">Data penjualan voucher session sebelumnya akan ditampilkan di sini</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Info Profile Terpilih -->
                <div class="card" id="profile-info-card" style="display: none;">
                    <div class="card-header">
                        <h5><i class="fas fa-user-circle me-2"></i>Info Profile</h5>
                    </div>
                    <div class="card-body" id="profile-info-content">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>

                <!-- Panduan & Status -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5><i class="fas fa-info-circle me-2"></i>Panduan & Status</h5>
                    </div>
                    <div class="card-body">
<div class="guide-card mb-4">
    <h5><i class="fas fa-info-circle me-2"></i>Status Sistem</h5>
    <?php if ($api_connected && !empty($db_profiles)): ?>
        <div class="alert alert-success mt-3">
            <div class="d-flex align-items-start">
                <div>
                    <ul class="list-unstyled mb-0">
                        <li><i class="fas fa-check-circle me-1"></i> Terhubung ke router: <strong><?= $router_ip ?></strong> (<?= $router_name ?>)</li>
                        <li><i class="fas fa-check-circle me-1"></i> <strong><?= count($db_profiles) ?></strong> profile aktif ditemukan</li>
                        <li><i class="fas fa-check-circle me-1"></i> Terkoneksi dengan database dan MikroTik</li>
                    </ul>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-danger mt-3">
            <div class="d-flex align-items-start">
                <i class="fas fa-times-circle me-2 mt-1"></i>
                <div>
                    <p class="mb-2">Perhatian! Ada masalah pada sistem:</p>
                    <ul class="mb-0">
                        <?php if (!$api_connected): ?>
                            <li><i class="fas fa-chevron-right me-1"></i> Router <strong>offline</strong> (Tidak terhubung ke <?= $router_ip ?>)</li>
                        <?php endif; ?>
                        <?php if (empty($db_profiles)): ?>
                            <li><i class="fas fa-chevron-right me-1"></i> Tidak ada profile aktif di database</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <div class="mt-2">
                <p class="text-muted small mb-0">
                    <?php if (!$api_connected && empty($db_profiles)): ?>
                        <i class="fas fa-info-circle me-1"></i> Periksa:
                        <ul class="small">
                            <li>Koneksi internet</li>
                            <li>Kredensial MikroTik di config</li>
                            <li>Profile aktif di menu Manajemen Profile Hotspot</li>
                        </ul>
                    <?php elseif (!$api_connected): ?>
                        <i class="fas fa-info-circle me-1"></i> Periksa:
                        <ul class="small">
                            <li>Koneksi internet</li>
                            <li>Kredensial MikroTik di config</li>
                        </ul>
                    <?php elseif (empty($db_profiles)): ?>
                        <i class="fas fa-info-circle me-1"></i> Pastikan sudah ada profile aktif di menu Manajemen Profile Hotspot
                    <?php endif; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>
</div>
                        
                        <div class="guide-card mb-4">
                            <h5><i class="fas fa-book me-2"></i>Panduan Pengisian</h5>
                            <ul>
                                <li><strong>Model Voucher:</strong> Pilih apakah username dan password berbeda atau sama</li>
                                <li><strong>Tipe Karakter:</strong> Menentukan jenis karakter yang digunakan</li>
                                <li><strong>Limit Uptime:</strong> Format jam:menit:detik atau hari jam:menit:detik</li>
                                <li><strong>Data Limit:</strong> Untuk voucher berbasis kuota dalam MB</li>
                            </ul>
                        </div>
                        
                        <div class="guide-card">
                            <h5><i class="fas fa-lightbulb me-2"></i>Contoh Format</h5>
                            <ul>
                                <li><strong>Uptime:</strong>
                                    <br>• 10:00:00 = 10 jam
                                    <br>• 1d 00:00:00 = 1 hari  
                                    <br>• 5d 00:00:00 = 5 hari
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const profileSelect = document.getElementById('profile-select');
        const profileInfoCard = document.getElementById('profile-info-card');
        const profileInfoContent = document.getElementById('profile-info-content');
        const quantityInput = document.getElementById('quantity');
        
        // Handle sidebar toggle for responsive main content
        const sidebarToggle = document.querySelector('.sidebar-toggle');
        const mainContent = document.getElementById('mainContent');
        
        if (sidebarToggle && mainContent) {
            sidebarToggle.addEventListener('click', function() {
                document.body.classList.toggle('sidebar-collapsed');
            });
        }
        
        // Check if sidebar is already collapsed on page load
        if (document.body.classList.contains('sidebar-collapsed')) {
            mainContent.style.marginLeft = '60px';
        }
        
        // Listen for window resize to handle responsive behavior
        window.addEventListener('resize', function() {
            if (window.innerWidth <= 992) {
                mainContent.style.marginLeft = '0';
            } else {
                if (document.body.classList.contains('sidebar-collapsed')) {
                    mainContent.style.marginLeft = '60px';
                } else {
                    mainContent.style.marginLeft = '220px';
                }
            }
        });
        
        // Update profile info when profile changes
        profileSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            if (this.value) {
                const harga = selectedOption.getAttribute('data-harga') || '0';
                const deskripsi = selectedOption.getAttribute('data-deskripsi') || '-';
                const rateLimit = selectedOption.getAttribute('data-rate-limit') || '-';
                const sharedUsers = selectedOption.getAttribute('data-shared-users') || '-';
                const macTimeout = selectedOption.getAttribute('data-mac-timeout') || '-';
                const lockUser = selectedOption.getAttribute('data-lock-user') || 'no';
                
                const lockUserText = lockUser === 'yes' ? 'Enabled' : 'Disabled';
                const lockUserBadge = lockUser === 'yes' ? 'badge bg-warning' : 'badge bg-secondary';
                
                profileInfoContent.innerHTML = `
                    <div class="profile-info-item">
                        <span class="profile-info-label">Nama Profile:</span>
                        <span class="profile-info-value">${this.value}</span>
                    </div>
                    <div class="profile-info-item">
                        <span class="profile-info-label">Display Name:</span>
                        <span class="profile-info-value">${deskripsi}</span>
                    </div>
                    <div class="profile-info-item">
                        <span class="profile-info-label">Harga:</span>
                        <span class="profile-info-value text-success fw-bold">Rp ${parseInt(harga).toLocaleString('id-ID')}</span>
                    </div>
                    <div class="profile-info-item">
                        <span class="profile-info-label">Rate Limit:</span>
                        <span class="profile-info-value">${rateLimit}</span>
                    </div>
                    <div class="profile-info-item">
                        <span class="profile-info-label">Shared Users:</span>
                        <span class="profile-info-value">${sharedUsers}</span>
                    </div>
                    <div class="profile-info-item">
                        <span class="profile-info-label">MAC Timeout:</span>
                        <span class="profile-info-value">${macTimeout}</span>
                    </div>
                    <div class="profile-info-item">
                        <span class="profile-info-label">Lock User:</span>
                        <span class="${lockUserBadge}">${lockUserText}</span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold">Estimasi Total:</span>
                        <span class="text-primary fw-bold fs-5" id="total-estimate">Rp 0</span>
                    </div>
                `;
                
                profileInfoCard.style.display = 'block';
                updateTotalEstimate();
            } else {
                profileInfoCard.style.display = 'none';
            }
        });
        
        // Update total estimate when quantity changes
        quantityInput.addEventListener('input', updateTotalEstimate);
        
        function updateTotalEstimate() {
            const selectedOption = profileSelect.options[profileSelect.selectedIndex];
            const quantity = parseInt(quantityInput.value) || 0;
            const harga = parseInt(selectedOption.getAttribute('data-harga')) || 0;
            const total = quantity * harga;
            
            const totalEstimateElement = document.getElementById('total-estimate');
            if (totalEstimateElement) {
                totalEstimateElement.textContent = `Rp ${total.toLocaleString('id-ID')}`;
            }
        }
        
        // Format number inputs
        quantityInput.addEventListener('input', function() {
            if (this.value < 1) this.value = 1;
            if (this.value > <?= $max_vouchers ?>) this.value = <?= $max_vouchers ?>;
        });
    });
    </script>
</body>
</html>

<?php

require_once __DIR__ . '/../templates/footer.php';

// Clear output buffer and send
ob_end_flush();
?>