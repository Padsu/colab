<?php
// File: install.php - Installer AnuBill lengkap dengan konfigurasi MikroTik
ini_set('display_errors', 1);
session_start();

// Langkah-langkah instalasi
$steps = array(
    'check_requirements',
    'database_config',
    'create_database',
    'create_tables',
    'insert_data',
    'create_config',
    'mikrotik_config',
    'finish'
);

$current_step = isset($_GET['step']) ? $_GET['step'] : 'check_requirements';
$success = '';

// ==================== FUNGSI UTAMA ====================

function check_php_extensions() {
    $required = array('mysqli', 'pdo_mysql', 'json', 'session');
    $missing = array();
    
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            array_push($missing, $ext);
        }
    }
    
    return $missing;
}

function check_folder_permissions() {
    $folders = array('.', 'config');
    $unwritable = array();
    
    foreach ($folders as $folder) {
        if (!is_writable($folder)) {
            array_push($unwritable, $folder);
        }
    }
    
    return $unwritable;
}

function create_db_connection($host, $user, $pass, $port = 3306, $dbname = '') {
    try {
        $conn = new mysqli($host, $user, $pass, $dbname, $port);
        if ($conn->connect_error) {
            return array('success' => false, 'message' => 'Koneksi gagal: ' . $conn->connect_error);
        }
        return array('success' => true, 'connection' => $conn);
    } catch (Exception $e) {
        return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
    }
}

function create_database($host, $user, $pass, $db_name, $port = 3306) {
    try {
        $conn_result = create_db_connection($host, $user, $pass, $port);
        if (!$conn_result['success']) {
            return $conn_result;
        }
        
        $conn = $conn_result['connection'];
        $sql = "CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
        if ($conn->query($sql)) {
            $conn->close();
            return array('success' => true);
        } else {
            $error = $conn->error;
            $conn->close();
            return array('success' => false, 'message' => 'Gagal membuat database: ' . $error);
        }
    } catch (Exception $e) {
        return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
    }
}

function process_sql_file($conn, $file_path) {
    $sql = file_get_contents($file_path);
    if (!$sql) {
        return ['success' => false, 'message' => 'Tidak dapat membaca file SQL'];
    }

    $result = $conn->query("SHOW FULL TABLES WHERE TABLE_TYPE LIKE 'VIEW'");
    while ($row = $result->fetch_row()) {
        $view_name = $row[0];
        $conn->query("DROP VIEW IF EXISTS `$view_name`");
    }

    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $sql = preg_replace('/--.*?(\r\n|\n)/', '', $sql);
    $sql = preg_replace('/DELIMITER \$\$.+?DELIMITER ;/s', '', $sql);
    $sql = str_replace('$$', ';', $sql);
    
    $sql = preg_replace('/CREATE VIEW/', 'CREATE OR REPLACE VIEW', $sql);

    $conn->autocommit(false);
    try {
        $queries = preg_split('/;\s*(?=[A-Z])/', $sql);
        
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query) && strlen($query) > 10) {
                if (!$conn->query($query)) {
                    throw new Exception("Query gagal: " . $conn->error . "\nQuery: " . substr($query, 0, 200));
                }
            }
        }
        
        $conn->commit();
        return ['success' => true];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function create_tables($host, $user, $pass, $db_name, $port = 3306) {
    try {
        $conn_result = create_db_connection($host, $user, $pass, $port, $db_name);
        if (!$conn_result['success']) {
            return $conn_result;
        }
        
        $conn = $conn_result['connection'];
        $conn->query('SET FOREIGN_KEY_CHECKS = 0');
        
        $tables = [];
        $result = $conn->query("SHOW TABLES");
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        
        if (!empty($tables)) {
            // FIXED: Added missing tables including mikrotik_hotspot_profiles
            $orderedTables = [
                'hotspot_sales', 'hotspot_users', 'hotspot_profiles',
                'mikrotik_hotspot_profiles', // <- ADDED THIS LINE
                'monitoring_pppoe', 'pembayaran', 'tagihan', 
                'data_pelanggan', 'paket_internet',
                'ftth_odp_ports', 'ftth_odp', 'ftth_odc_ports', 
                'ftth_odc', 'ftth_pon', 'ftth_olt', 'ftth_pop',
                'activity_log', 'log_aktivitas', 'pengaturan_perusahaan',
                'pengeluaran', 'radcheck', 'system_settings',
                'transaksi_lain', 'users', 'voucher_history', 'voucher_temp'  // <- ADDED voucher_temp too
            ];
            
            foreach ($orderedTables as $table) {
                if (in_array($table, $tables)) {
                    $conn->query("DROP TABLE IF EXISTS `$table`");
                }
            }
        }
        
        $result = process_sql_file($conn, 'billingrtrwnet.sql');
        $conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $conn->close();
        
        return $result;
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->query('SET FOREIGN_KEY_CHECKS = 1');
            $conn->close();
        }
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function create_config_file($host, $user, $pass, $db_name, $port = 3306) {
    $config_content = "<?php
\$db_host = '$host';
\$db_user = '$user';
\$db_pass = '$pass';
\$db_name = '$db_name';
\$db_port = $port;

\$mysqli = new mysqli(\$db_host, \$db_user, \$db_pass, \$db_name, \$db_port);
\$mysqli->set_charset('utf8mb4');

if (\$mysqli->connect_error) {
    die('Database connection failed: ' . \$mysqli->connect_error);
}
?>";

    $config_file = 'config_database.php';
    if (file_put_contents($config_file, $config_content) === false) {
        return array('success' => false, 'message' => 'Gagal membuat file konfigurasi');
    }
    
    chmod($config_file, 0644);
    return array('success' => true);
}

// ==================== FUNGSI MIKROTIK ====================

function saveMikrotikConfig($ip, $user, $pass, $port = 8728) {
    $configContent = "<?php
// Konfigurasi MikroTik
\$mikrotik_ip = '$ip';
\$mikrotik_user = '$user';
\$mikrotik_pass = '$pass';
\$mikrotik_port = $port;

// Include class RouterOS API
require_once __DIR__ . '/routeros_api.php';
?>";

    $configFile = 'config/config_mikrotik.php';
    if (file_put_contents($configFile, $configContent)) {
        return true;
    }
    return false;
}

function testMikrotikConnection($ip, $user, $pass, $port = 8728) {
    require_once 'config/routeros_api.php';
    $api = new RouterosAPI();
    
    if ($api->connect($ip, $user, $pass, $port)) {
        $api->disconnect();
        return ['success' => true, 'message' => 'Koneksi berhasil!'];
    }
    return ['success' => false, 'message' => 'Koneksi gagal'];
}

// ==================== TAMPILAN HTML ====================

function display_header() {
    echo '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalasi AnuBill Internet Billing and Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1ABB9C;
            --primary-dark: #16a085;
            --success-color: #26B99A;
            --info-color: #23C6C8;
            --warning-color: #F8AC59;
            --danger-color: #ED5565;
            --secondary-color: #73879C;
            --dark-color: #2A3F54;
            --light-color: #F7F7F7;
            --white: #ffffff;
            --border-color: #e6e9ed;
            --text-color: #73879C;
            --heading-color: #2A3F54;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body { 
            font-family: "Segoe UI", Roboto, Arial, sans-serif; 
            line-height: 1.6; 
            color: var(--text-color);
            background: var(--light-color);
            min-height: 100vh;
        }

        .navbar {
            background: var(--dark-color);
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        .navbar .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
        }

        .navbar h1 {
            color: var(--white);
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
        }

        .navbar h1 i {
            color: var(--primary-color);
            margin-right: 10px;
        }

        .main-container {
            margin-top: 80px;
            min-height: calc(100vh - 80px);
            display: flex;
        }

        .sidebar {
            width: 300px;
            background: var(--white);
            border-right: 1px solid var(--border-color);
            padding: 30px 0;
            box-shadow: 2px 0 4px rgba(0,0,0,0.05);
        }

        .content-area {
            flex: 1;
            padding: 30px;
            background: var(--light-color);
        }

        .progress-steps {
            list-style: none;
            padding: 0 20px;
        }

        .progress-step {
            padding: 15px 20px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            font-weight: 500;
            color: var(--text-color);
            transition: all 0.3s ease;
            position: relative;
        }

        .progress-step i {
            width: 20px;
            margin-right: 15px;
            font-size: 1.1rem;
        }

        .progress-step.active {
            background: var(--primary-color);
            color: var(--white);
            margin: 0 -20px 5px -20px;
            padding: 15px 40px;
        }

        .progress-step.completed {
            color: var(--success-color);
        }

        .progress-step.completed i {
            color: var(--success-color);
        }

        .card {
            background: var(--white);
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--border-color);
            background: #fafbfc;
        }

        .card-header h2 {
            color: var(--heading-color);
            font-size: 1.4rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-header h2 i {
            color: var(--primary-color);
            font-size: 1.2rem;
        }

        .card-body {
            padding: 25px;
        }

        .alert {
            padding: 15px 20px;
            margin-bottom: 20px;
            border: 1px solid;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .alert i {
            font-size: 1.2rem;
        }

        .alert-success {
            background: rgba(38, 185, 154, 0.1);
            border-color: var(--success-color);
            color: var(--success-color);
        }

        .alert-warning {
            background: rgba(248, 172, 89, 0.1);
            border-color: var(--warning-color);
            color: var(--warning-color);
        }

        .alert-info {
            background: rgba(35, 198, 200, 0.1);
            border-color: var(--info-color);
            color: var(--info-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--heading-color);
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
            color: var(--text-color);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(26, 187, 156, 0.2);
        }

        .btn {
            background: var(--primary-color);
            color: var(--white);
            border: none;
            padding: 12px 25px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            border: 1px solid var(--primary-color);
        }

        .btn:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .btn-success {
            background: var(--success-color);
            border-color: var(--success-color);
        }

        .btn-success:hover {
            background: #229954;
            border-color: #229954;
        }

        .btn-secondary {
            background: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        .btn-secondary:hover {
            background: #5a6c7d;
            border-color: #5a6c7d;
        }

        .requirements {
            margin-top: 20px;
        }

        .requirements h3 {
            color: var(--heading-color);
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            margin-top: 25px;
        }

        .requirement {
            padding: 12px 15px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            border: 1px solid;
        }

        .requirement.ok {
            color: var(--success-color);
            background: rgba(38, 185, 154, 0.1);
            border-color: rgba(38, 185, 154, 0.2);
        }

        .requirement.error {
            color: var(--danger-color);
            background: rgba(237, 85, 101, 0.1);
            border-color: rgba(237, 85, 101, 0.2);
        }

        .requirement i {
            font-size: 1.1rem;
        }

        .info-box {
            background: rgba(26, 187, 156, 0.1);
            border: 1px solid rgba(26, 187, 156, 0.2);
            padding: 20px;
            margin: 20px 0;
        }

        .info-box h4 {
            color: var(--primary-color);
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-box ul {
            list-style: none;
            padding-left: 0;
        }

        .info-box li {
            padding: 4px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-box li i {
            color: var(--primary-color);
            font-size: 0.9rem;
            width: 16px;
        }

        .text-center {
            text-align: center;
        }

        .mt-30 {
            margin-top: 30px;
        }

        .mr-15 {
            margin-right: 15px;
        }

        .mt-20 {
            margin-top: 20px;
        }

        .setup-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        @media (max-width: 768px) {
            .main-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                order: 2;
            }

            .content-area {
                order: 1;
                padding: 20px;
            }

            .progress-steps {
                display: flex;
                overflow-x: auto;
                padding: 10px;
            }

            .progress-step {
                min-width: 120px;
                text-align: center;
                margin-right: 10px;
            }

            .progress-step.active {
                margin: 0 10px 0 0;
                padding: 15px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="container">
            <h1><i class="fas fa-network-wired"></i>AnuBill Installer</h1>
        </div>
    </div>
    
    <div class="main-container">
        <div class="sidebar">
            <ul class="progress-steps">';
}

function display_footer() {
    echo '</ul>
        </div>
        <div class="content-area">';
}

function display_end_footer() {
    echo '</div></div></body></html>';
}

function display_progress($current_step) {
    global $steps;
    
    foreach ($steps as $step) {
        $class = '';
        if ($step == $current_step) {
            $class = 'active';
        } elseif (array_search($step, $steps) < array_search($current_step, $steps)) {
            $class = 'completed';
        }
        
        $step_names = [
            'check_requirements' => 'Cek Sistem',
            'database_config' => 'Konfigurasi DB',
            'create_database' => 'Buat Database',
            'create_tables' => 'Buat Tabel',
            'insert_data' => 'Insert Data',
            'create_config' => 'Buat Config',
            'mikrotik_config' => 'Konfigurasi MikroTik',
            'finish' => 'Selesai'
        ];
        
        $step_icons = [
            'check_requirements' => 'fas fa-clipboard-check',
            'database_config' => 'fas fa-database',
            'create_database' => 'fas fa-plus-circle',
            'create_tables' => 'fas fa-table',
            'insert_data' => 'fas fa-download',
            'create_config' => 'fas fa-file-code',
            'mikrotik_config' => 'fas fa-cogs',
            'finish' => 'fas fa-flag-checkered'
        ];
        
        $step_name = isset($step_names[$step]) ? $step_names[$step] : ucfirst(str_replace('_', ' ', $step));
        $step_icon = isset($step_icons[$step]) ? $step_icons[$step] : 'fas fa-circle';
        
        echo '<li class="progress-step ' . $class . '">';
        echo '<i class="' . $step_icon . '"></i>';
        echo $step_name;
        echo '</li>';
    }
}

// ==================== PROSES INSTALASI ====================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($current_step == 'database_config') {
        $host = trim($_POST['host']);
        $user = trim($_POST['user']);
        $pass = trim($_POST['pass']);
        $name = trim($_POST['name']);
        $port = intval($_POST['port']);
        
        if (empty($host) || empty($user) || empty($name)) {
            $error = 'Host, username, dan nama database harus diisi';
        } else {
            $conn_result = create_db_connection($host, $user, $pass, $port);
            if ($conn_result['success']) {
                $_SESSION['db_config'] = array(
                    'host' => $host,
                    'user' => $user,
                    'pass' => $pass,
                    'name' => $name,
                    'port' => $port
                );
                $conn_result['connection']->close();
                header('Location: install.php?step=create_database');
                exit;
            } else {
                $error = $conn_result['message'];
            }
        }
    } elseif ($current_step == 'create_database') {
        $db_config = $_SESSION['db_config'];
        $result = create_database(
            $db_config['host'],
            $db_config['user'],
            $db_config['pass'],
            $db_config['name'],
            $db_config['port']
        );
        
        if ($result['success']) {
            header('Location: install.php?step=create_tables');
            exit;
        } else {
            $error = $result['message'];
        }
    } elseif ($current_step == 'create_tables') {
        $db_config = $_SESSION['db_config'];
        $result = create_tables(
            $db_config['host'],
            $db_config['user'],
            $db_config['pass'],
            $db_config['name'],
            $db_config['port']
        );
        
        if ($result['success']) {
            header('Location: install.php?step=insert_data');
            exit;
        } else {
            $error = $result['message'];
        }
    } elseif ($current_step == 'insert_data') {
        header('Location: install.php?step=create_config');
        exit;
    } elseif ($current_step == 'create_config') {
        $db_config = $_SESSION['db_config'];
        $result = create_config_file(
            $db_config['host'],
            $db_config['user'],
            $db_config['pass'],
            $db_config['name'],
            $db_config['port']
        );
        
        if ($result['success']) {
            header('Location: install.php?step=mikrotik_config');
            exit;
        } else {
            $error = $result['message'];
        }
    } elseif ($current_step == 'mikrotik_config') {
        $ip = trim($_POST['ip']);
        $user = trim($_POST['user']);
        $pass = trim($_POST['pass']);
        $port = intval($_POST['port']);
        
        if (empty($ip) || empty($user) || empty($pass)) {
            $error = 'IP, Username, dan Password MikroTik harus diisi';
        } else {
            $test_result = testMikrotikConnection($ip, $user, $pass, $port);
            if ($test_result['success']) {
                if (saveMikrotikConfig($ip, $user, $pass, $port)) {
                    header('Location: install.php?step=finish');
                    exit;
                } else {
                    $error = 'Gagal menyimpan konfigurasi MikroTik';
                }
            } else {
                $error = $test_result['message'];
            }
        }
    }
}

// ==================== TAMPILKAN HALAMAN ====================

display_header();
display_progress($current_step);
display_footer();

if (isset($error) && $error) {
    echo '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i>' . htmlspecialchars($error) . '</div>';
}
if ($success) {
    echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i>' . htmlspecialchars($success) . '</div>';
}

switch ($current_step) {
    case 'check_requirements':
        $missing_ext = check_php_extensions();
        $unwritable = check_folder_permissions();
        $all_ok = empty($missing_ext) && empty($unwritable);
        
        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h2><i class="fas fa-clipboard-check"></i>Pemeriksaan Persyaratan Sistem</h2>';
        echo '</div>';
        echo '<div class="card-body">';
        echo '<p>Memverifikasi bahwa server memenuhi persyaratan minimum untuk menjalankan sistem AnuBill...</p>';
        echo '<div class="requirements">';
        echo '<h3>Ekstensi PHP yang Diperlukan:</h3>';
        
        if (empty($missing_ext)) {
            echo '<div class="requirement ok"><i class="fas fa-check"></i>Semua ekstensi PHP yang diperlukan tersedia</div>';
        } else {
            foreach ($missing_ext as $ext) {
                echo '<div class="requirement error"><i class="fas fa-times"></i>Ekstensi PHP ' . $ext . ' tidak ditemukan</div>';
            }
        }
        
        echo '<h3>Permission Folder:</h3>';
        if (empty($unwritable)) {
            echo '<div class="requirement ok"><i class="fas fa-check"></i>Semua folder yang diperlukan dapat ditulisi</div>';
        } else {
            foreach ($unwritable as $folder) {
                echo '<div class="requirement error"><i class="fas fa-times"></i>Folder "' . $folder . '" tidak dapat ditulisi</div>';
            }
        }
        echo '</div>';
        
        if ($all_ok) {
            echo '<div class="mt-30">';
            echo '<form method="get">';
            echo '<input type="hidden" name="step" value="database_config">';
            echo '<button type="submit" class="btn"><i class="fas fa-arrow-right"></i>Lanjutkan ke Konfigurasi Database</button>';
            echo '</form>';
            echo '</div>';
        } else {
            echo '<div class="alert alert-warning mt-30"><i class="fas fa-exclamation-triangle"></i>Sistem tidak memenuhi persyaratan minimum. Harap perbaiki masalah di atas sebelum melanjutkan instalasi.</div>';
        }
        echo '</div>';
        echo '</div>';
        break;
        
    case 'database_config':
        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h2><i class="fas fa-database"></i>Konfigurasi Database</h2>';
        echo '</div>';
        echo '<div class="card-body">';
        echo '<p>Masukkan detail koneksi database MySQL yang akan digunakan untuk menyimpan data sistem.</p>';
        
        echo '<form method="post">';
        echo '<input type="hidden" name="step" value="database_config">';
        
        echo '<div class="form-group">';
        echo '<label class="form-label" for="host"><i class="fas fa-server"></i> Host Database:</label>';
        echo '<input type="text" id="host" name="host" class="form-control" value="localhost" required placeholder="localhost atau IP server database">';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label class="form-label" for="port"><i class="fas fa-plug"></i> Port Database:</label>';
        echo '<input type="number" id="port" name="port" class="form-control" value="3306" required placeholder="3306">';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label class="form-label" for="user"><i class="fas fa-user"></i> Username Database:</label>';
        echo '<input type="text" id="user" name="user" class="form-control" value="root" required placeholder="Username untuk akses database">';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label class="form-label" for="pass"><i class="fas fa-lock"></i> Password Database:</label>';
        echo '<input type="password" id="pass" name="pass" class="form-control" placeholder="Password database (kosongkan jika tidak ada)">';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label class="form-label" for="name"><i class="fas fa-database"></i> Nama Database:</label>';
        echo '<input type="text" id="name" name="name" class="form-control" value="billingrtrwnet" required placeholder="Nama database yang akan dibuat">';
        echo '</div>';
        
        echo '<button type="submit" class="btn"><i class="fas fa-arrow-right"></i>Test Koneksi & Lanjutkan</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
        break;
        
    case 'create_database':
        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h2><i class="fas fa-plus-circle"></i>Membuat Database</h2>';
        echo '</div>';
        echo '<div class="card-body">';
        
        $db_config = $_SESSION['db_config'];
        $result = create_database(
            $db_config['host'],
            $db_config['user'],
            $db_config['pass'],
            $db_config['name'],
            $db_config['port']
        );
        
        if ($result['success']) {
            echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i>Database berhasil dibuat: <strong>' . htmlspecialchars($db_config['name']) . '</strong></div>';
            echo '<form method="post">';
            echo '<input type="hidden" name="step" value="create_database">';
            echo '<button type="submit" class="btn"><i class="fas fa-arrow-right"></i>Lanjutkan ke Pembuatan Tabel</button>';
            echo '</form>';
        } else {
            echo '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i>' . htmlspecialchars($result['message']) . '</div>';
            echo '<a href="install.php?step=database_config" class="btn btn-secondary"><i class="fas fa-arrow-left"></i>Kembali ke Konfigurasi Database</a>';
        }
        echo '</div>';
        echo '</div>';
        break;
        
    case 'create_tables':
        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h2><i class="fas fa-table"></i>Membuat Tabel Database</h2>';
        echo '</div>';
        echo '<div class="card-body">';
        echo '<p>Membangun struktur tabel yang diperlukan untuk system AnuBill...</p>';
        
        $db_config = $_SESSION['db_config'];
        $result = create_tables(
            $db_config['host'],
            $db_config['user'],
            $db_config['pass'],
            $db_config['name'],
            $db_config['port']
        );
        
        if ($result['success']) {
            echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i>Struktur tabel database berhasil dibuat</div>';
            echo '<form method="post">';
            echo '<input type="hidden" name="step" value="create_tables">';
            echo '<button type="submit" class="btn"><i class="fas fa-arrow-right"></i>Lanjutkan ke Insert Data</button>';
            echo '</form>';
        } else {
            echo '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i>' . htmlspecialchars($result['message']) . '</div>';
            echo '<a href="install.php?step=database_config" class="btn btn-secondary"><i class="fas fa-arrow-left"></i>Kembali ke Konfigurasi Database</a>';
        }
        echo '</div>';
        echo '</div>';
        break;
        
    case 'insert_data':
        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h2><i class="fas fa-download"></i>Memasukkan Data Awal</h2>';
        echo '</div>';
        echo '<div class="card-body">';
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i>Data awal dan konfigurasi default berhasil dimasukkan ke database.</div>';
        
        echo '<div class="info-box">';
        echo '<h4><i class="fas fa-info-circle"></i>Data yang telah ditambahkan:</h4>';
        echo '<ul>';
        echo '<li><i class="fas fa-user-shield"></i>Akun administrator default</li>';
        echo '<li><i class="fas fa-cog"></i>Pengaturan sistem dasar</li>';
        echo '<li><i class="fas fa-wifi"></i>Template paket internet</li>';
        echo '<li><i class="fas fa-network-wired"></i>Konfigurasi file lainnya</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<form method="post">';
        echo '<input type="hidden" name="step" value="insert_data">';
        echo '<button type="submit" class="btn"><i class="fas fa-arrow-right"></i>Lanjutkan ke Pembuatan Config</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
        break;
        
    case 'create_config':
        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h2><i class="fas fa-file-code"></i>Membuat File Konfigurasi</h2>';
        echo '</div>';
        echo '<div class="card-body">';
        echo '<p>Membuat file konfigurasi untuk koneksi database sistem...</p>';
        
        $db_config = $_SESSION['db_config'];
        $result = create_config_file(
            $db_config['host'],
            $db_config['user'],
            $db_config['pass'],
            $db_config['name'],
            $db_config['port']
        );
        
        if ($result['success']) {
            echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i>File konfigurasi berhasil dibuat: <strong>config_database.php</strong></div>';
            echo '<form method="post">';
            echo '<input type="hidden" name="step" value="create_config">';
            echo '<button type="submit" class="btn"><i class="fas fa-arrow-right"></i>Lanjutkan ke Konfigurasi MikroTik</button>';
            echo '</form>';
        } else {
            echo '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i>' . htmlspecialchars($result['message']) . '</div>';
            echo '<a href="install.php?step=database_config" class="btn btn-secondary"><i class="fas fa-arrow-left"></i>Kembali ke Konfigurasi Database</a>';
        }
        echo '</div>';
        echo '</div>';
        break;
        
    case 'mikrotik_config':
        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h2><i class="fas fa-router"></i>Konfigurasi MikroTik</h2>';
        echo '</div>';
        echo '<div class="card-body">';
        echo '<p>Masukkan detail koneksi ke router MikroTik Anda:</p>';
        
        echo '<form method="post">';
        echo '<input type="hidden" name="step" value="mikrotik_config">';
        
        echo '<div class="form-group">';
        echo '<label class="form-label" for="mikrotik_ip"><i class="fas fa-server"></i> IP MikroTik:</label>';
        echo '<input type="text" id="mikrotik_ip" name="ip" class="form-control" required placeholder="192.168.88.1">';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label class="form-label" for="mikrotik_port"><i class="fas fa-plug"></i> Port API:</label>';
        echo '<input type="number" id="mikrotik_port" name="port" class="form-control" value="8728" required>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label class="form-label" for="mikrotik_user"><i class="fas fa-user"></i> Username:</label>';
        echo '<input type="text" id="mikrotik_user" name="user" class="form-control" required placeholder="admin">';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label class="form-label" for="mikrotik_pass"><i class="fas fa-lock"></i> Password:</label>';
        echo '<input type="password" id="mikrotik_pass" name="pass" class="form-control" required>';
        echo '</div>';
        
        echo '<button type="submit" class="btn"><i class="fas fa-network-wired"></i>Test & Simpan Konfigurasi</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
        break;
        
    case 'finish':
        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h2><i class="fas fa-trophy"></i>Instalasi Selesai</h2>';
        echo '</div>';
        echo '<div class="card-body">';
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i>Sistem AnuBill berhasil diinstal!</div>';
        
        $db_config = $_SESSION['db_config'];
        echo '<div class="info-box">';
        echo '<h4><i class="fas fa-database"></i>Detail Instalasi Database:</h4>';
        echo '<ul>';
        echo '<li><i class="fas fa-database"></i>Database: <strong>' . htmlspecialchars($db_config['name']) . '</strong></li>';
        echo '<li><i class="fas fa-server"></i>Host: <strong>' . htmlspecialchars($db_config['host']) . '</strong></li>';
        echo '<li><i class="fas fa-plug"></i>Port: <strong>' . htmlspecialchars($db_config['port']) . '</strong></li>';
        echo '<li><i class="fas fa-user"></i>Username: <strong>' . htmlspecialchars($db_config['user']) . '</strong></li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<div class="info-box">';
        echo '<h4><i class="fas fa-sign-in-alt"></i>Informasi Login Default:</h4>';
        echo '<ul>';
        echo '<li><i class="fas fa-link"></i>URL Admin: <a href="login.php" target="_blank"><strong>login.php</strong></a></li>';
        echo '<li><i class="fas fa-user"></i>Username: <strong>superadmin</strong></li>';
        echo '<li><i class="fas fa-key"></i>Password: <strong>superadmin</strong></li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<div class="alert alert-warning">';
        echo '<div style="display: flex; align-items: flex-start; gap: 10px;">';
        echo '<i class="fas fa-shield-alt" style="font-size: 1.2em; margin-top: 3px;"></i>';
        echo '<div>';
        echo '<strong style="display: block; margin-bottom: 8px;">PENTING untuk Keamanan:</strong>';
        echo '<div style="font-size: 0.95em;">';
        echo '<div style="margin-bottom: 5px;"><i class="fas fa-trash-can" style="width: 18px;"></i> Hapus file <code>install.php</code> setelah instalasi selesai</div>';
        echo '<div style="margin-bottom: 5px;"><i class="fas fa-key" style="width: 18px;"></i> Ubah password default setelah login pertama</div>';
        echo '<div><i class="fas fa-lock" style="width: 18px;"></i> Pastikan file konfigurasi tidak dapat diakses publik</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="text-center" style="margin-top: 25px;">';
        echo '<a href="login.php" class="btn btn-success" style="padding: 8px 20px; margin-right: 10px;"><i class="fas fa-sign-in-alt" style="margin-right: 5px;"></i>Masuk ke Sistem</a>';

        
        session_unset();
        session_destroy();
        break;
}

// Handle delete request
if (isset($_GET['delete']) && $_GET['delete'] == '1') {
    if (file_exists(__FILE__)) {
        unlink(__FILE__);
        echo '<script>alert("File install.php berhasil dihapus!");</script>';
    }
    exit;
}

display_end_footer();
?>