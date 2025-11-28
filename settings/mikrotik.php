<?php
// /pengaturan/mikrotik.php
ob_start(); // Start output buffering at the VERY TOP

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load templates only (removed database config)
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Function to read current settings from config file
function readConfigFile() {
    $config_file = __DIR__ . '/../config/config_mikrotik.php';
    $default_settings = [
        'mikrotik_ip' => '',
        'mikrotik_user' => '',
        'mikrotik_pass' => '',
        'mikrotik_port' => 8728
    ];
    
    if (file_exists($config_file)) {
        // Read file content and extract variables
        $content = file_get_contents($config_file);
        
        // Extract IP
        if (preg_match("/\\\$mikrotik_ip\s*=\s*['\"]([^'\"]*)['\"];/", $content, $matches)) {
            $default_settings['mikrotik_ip'] = $matches[1];
        }
        
        // Extract User
        if (preg_match("/\\\$mikrotik_user\s*=\s*['\"]([^'\"]*)['\"];/", $content, $matches)) {
            $default_settings['mikrotik_user'] = $matches[1];
        }
        
        // Extract Password
        if (preg_match("/\\\$mikrotik_pass\s*=\s*['\"]([^'\"]*)['\"];/", $content, $matches)) {
            $default_settings['mikrotik_pass'] = $matches[1];
        }
        
        // Extract Port
        if (preg_match("/\\\$mikrotik_port\s*=\s*(\d+);/", $content, $matches)) {
            $default_settings['mikrotik_port'] = (int)$matches[1];
        }
    }
    
    return $default_settings;
}

// Function to test MikroTik connection
function testMikrotikConnection($ip, $user, $pass, $port = 8728) {
    if (file_exists(__DIR__ . '/../config/routeros_api.php')) {
        require_once __DIR__ . '/../config/routeros_api.php';
        
        $api = new RouterosAPI();
        try {
            $connected = $api->connect($ip, $user, $pass, $port);
            if ($connected) {
                $api->disconnect();
                return ['success' => true, 'message' => 'Koneksi berhasil!'];
            } else {
                return ['success' => false, 'message' => 'Koneksi gagal: Authentication failed'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    } else {
        return ['success' => false, 'message' => 'RouterOS API class tidak ditemukan'];
    }
}

// Function to generate config file content
function generateConfigFile($ip, $user, $pass, $port) {
    return "<?php
// Konfigurasi Mikrotik
\$mikrotik_ip = '$ip'; // IP Mikrotik
\$mikrotik_user = '$user'; // Username Mikrotik
\$mikrotik_pass = '$pass'; // Password Mikrotik
\$mikrotik_port = $port; // Port API Mikrotik

// Include class RouterOS API
require_once __DIR__ . '/routeros_api.php';

// Inisialisasi koneksi
\$api = new RouterosAPI();
\$mikrotik_connected = false;

// Coba koneksi ke Mikrotik
try {
    \$mikrotik_connected = \$api->connect(\$mikrotik_ip, \$mikrotik_user, \$mikrotik_pass, \$mikrotik_port);
    
    if (!\$mikrotik_connected) {
        // Gunakan properti error yang sudah ada di class
        \$error_msg = \"Gagal terhubung ke Mikrotik. Error: \" . \$api->error_no . \" - \" . \$api->error_str;
        error_log(\$error_msg);
    }
} catch (Exception \$e) {
    \$error_msg = \"Exception saat koneksi ke Mikrotik: \" . \$e->getMessage();
    error_log(\$error_msg);
    \$mikrotik_connected = false;
}

// Fungsi untuk mendapatkan pesan error koneksi
function get_mikrotik_error() {
    global \$api;
    return \$api->error_no . \" - \" . \$api->error_str;
}
?>";
}

// Variables to store messages
$save_success_message = '';
$save_error_message = '';
$connection_error_message = '';

// Handle POST Save Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'save_settings') {
    $mikrotik_ip = $_POST['mikrotik_ip'] ?? '';
    $mikrotik_user = $_POST['mikrotik_user'] ?? '';
    $mikrotik_pass = $_POST['mikrotik_pass'] ?? '';
    $mikrotik_port = (int)($_POST['mikrotik_port'] ?? 8728);

    if (!empty($mikrotik_ip) && !empty($mikrotik_user) && !empty($mikrotik_pass)) {
        // Test connection first
        $test_result = testMikrotikConnection($mikrotik_ip, $mikrotik_user, $mikrotik_pass, $mikrotik_port);
        
        if ($test_result['success']) {
            // Generate config file content
            $config_content = generateConfigFile($mikrotik_ip, $mikrotik_user, $mikrotik_pass, $mikrotik_port);
            $config_path = __DIR__ . '/../config/config_mikrotik.php';
            
            // Try to create/update config file
            if (@file_put_contents($config_path, $config_content)) {
                $save_success_message = "Pengaturan MikroTik berhasil disimpan ke file konfigurasi!";
            } else {
                $save_error_message = "Gagal menulis file konfigurasi. Periksa permission folder/file.";
                $config_manual_display = "<div class='alert alert-info'>
                    <button class='btn btn-sm btn-secondary' onclick='showConfig()'>Tampilkan Config Manual</button>
                    <div id='config-display' style='display:none; margin-top:10px;'>
                        <p><strong>Path:</strong> <code>/config/config_mikrotik.php</code></p>
                        <textarea class='form-control' rows='15' readonly>" . htmlspecialchars($config_content) . "</textarea>
                    </div>
                </div>";
            }
        } else {
            $connection_error_message = "Gagal menyimpan: " . $test_result['message'];
        }
    } else {
        $save_error_message = "Semua field wajib diisi!";
    }
}

// Handle test connection
$test_connection_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'test_connection') {
    $test_ip = $_POST['test_ip'] ?? '';
    $test_user = $_POST['test_user'] ?? '';
    $test_pass = $_POST['test_pass'] ?? '';
    $test_port = (int)($_POST['test_port'] ?? 8728);
    
    if (!empty($test_ip) && !empty($test_user) && !empty($test_pass)) {
        $test_connection_result = testMikrotikConnection($test_ip, $test_user, $test_pass, $test_port);
    } else {
        $test_connection_result = ['success' => false, 'message' => 'Isi semua field untuk test koneksi!'];
    }
}

// Get current settings from config file
$current_settings = readConfigFile();

// Test current connection if config exists
$current_connection = ['success' => false, 'message' => 'Belum ada konfigurasi'];
if (!empty($current_settings['mikrotik_ip'])) {
    $current_connection = testMikrotikConnection(
        $current_settings['mikrotik_ip'], 
        $current_settings['mikrotik_user'], 
        $current_settings['mikrotik_pass'], 
        $current_settings['mikrotik_port']
    );
}

// Check if config file exists and readable
$config_file = __DIR__ . '/../config/config_mikrotik.php';
$config_file_exists = file_exists($config_file);
$config_file_readable = $config_file_exists && is_readable($config_file);
$config_file_writable = is_writable(dirname($config_file));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan MikroTik</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
    :root {
      --primary: #1ABB9C;
      --success: #26B99A;
      --info: #23C6C8;
      --warning: #F8AC59;
      --danger: #ED5565;
      --secondary: #73879C;
      --dark: #2A3F54;
      --light: #F7F7F7;
    }

    body {
      background-color: #F7F7F7;
      font-family: "Segoe UI", "Helvetica Neue", Roboto, Arial, sans-serif;
      color: #73879C;
    }

    .main-content {
      padding: 20px;
      margin-left: 220px;
      min-height: calc(100vh - 52px);
    }

    .page-title {
      margin-bottom: 30px;
    }

    .page-title h1 {
      font-size: 24px;
      color: var(--dark);
      margin: 0;
      font-weight: 400;
    }

    .card {
      border: none;
      border-radius: 5px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      margin-bottom: 20px;
    }

    .card-header {
      background-color: white;
      border-bottom: 1px solid #e5e5e5;
      padding: 15px 20px;
      border-radius: 5px 5px 0 0 !important;
    }

    .card-header h5 {
      font-size: 16px;
      font-weight: 500;
      color: var(--dark);
      margin: 0;
    }

    .settings-section {
      background: white;
      padding: 15px;
      margin-bottom: 20px;
      border-radius: 5px;
      border: 1px solid #e5e5e5;
    }

    .form-label {
      font-weight: 500;
      font-size: 13px;
      color: #555;
      margin-bottom: 5px;
    }

    .form-control, .form-select {
      border-radius: 3px;
      border: 1px solid #D5D5D5;
      font-size: 13px;
      padding: 8px 12px;
      height: auto;
    }

    .form-control:focus, .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 0.2rem rgba(26, 187, 156, 0.25);
    }

    .required {
      color: var(--danger);
      font-weight: bold;
    }

    .btn {
      border-radius: 3px;
      font-size: 13px;
      padding: 8px 15px;
      font-weight: 500;
    }

    .btn-primary {
      background-color: var(--primary);
      border-color: var(--primary);
    }

    .btn-primary:hover {
      background-color: #169F85;
      border-color: #169F85;
    }

    .btn-outline-secondary {
      color: var(--secondary);
      border-color: var(--secondary);
    }

    .btn-outline-secondary:hover {
      background-color: var(--secondary);
      color: white;
    }

    .btn-outline-danger {
      color: var(--danger);
      border-color: var(--danger);
    }

    .btn-outline-danger:hover {
      background-color: var(--danger);
      border-color: var(--danger);
      color: white;
    }

    .btn-success {
      background-color: var(--success);
      border-color: var(--success);
    }

    .btn-info {
      background-color: var(--info);
      border-color: var(--info);
    }

    .alert {
      padding: 10px 15px;
      font-size: 13px;
      border-radius: 3px;
      border: none;
    }

    .alert-danger {
      background-color: rgba(237, 85, 101, 0.1);
      color: var(--danger);
    }

    .alert-success {
      background-color: rgba(38, 185, 154, 0.1);
      color: var(--success);
    }

    .alert-info {
      background-color: rgba(35, 198, 200, 0.1);
      color: var(--info);
    }

    .alert-warning {
      background-color: rgba(248, 172, 89, 0.1);
      color: var(--warning);
    }

    .alert-auto-close {
      animation: fadeOut 1s ease-in-out 2s forwards;
    }
    
    @keyframes fadeOut {
        0% { opacity: 1; }
        100% { opacity: 0; display: none; }
    }

    .guide-list {
      list-style-type: none;
      padding-left: 0;
    }

    .guide-list li {
      padding: 5px 0;
      border-bottom: 1px solid #eee;
    }

    .guide-list li:last-child {
      border-bottom: none;
    }

    .guide-list strong {
      color: var(--dark);
    }

    .file-status {
      font-size: 11px;
      padding: 2px 6px;
      border-radius: 2px;
    }

    @media (max-width: 992px) {
      .main-content {
        margin-left: 0;
        padding: 15px;
      }
    }
    </style>
</head>
<body>
    <!-- Main Content -->
    <main class="main-content">
        <div class="page-title">
            <h1><i class="fas fa-server"></i> Pengaturan MikroTik</h1>
            <p class="page-subtitle">Konfigurasi koneksi ke router MikroTik (Sumber: File Konfigurasi)</p>
        </div>
        
        <div class="row">
            <div class="col-lg-8">
                <?php if ($save_success_message): ?>
                    <div class="alert alert-success alert-auto-close">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($save_success_message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($save_error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle"></i> <?= htmlspecialchars($save_error_message) ?>
                    </div>
                    <?php if (isset($config_manual_display)): ?>
                        <?= $config_manual_display ?>
                    <?php endif; ?>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-cog"></i> Konfigurasi Koneksi</h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="action" value="save_settings">
                            
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label for="mikrotik_ip" class="form-label">IP Address MikroTik <span class="required">*</span></label>
                                    <input type="text" id="mikrotik_ip" name="mikrotik_ip" class="form-control" 
                                           value="<?= htmlspecialchars($current_settings['mikrotik_ip'] ?? '') ?>" required>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="mikrotik_port" class="form-label">Port API <span class="required">*</span></label>
                                    <input type="number" id="mikrotik_port" name="mikrotik_port" class="form-control" 
                                           value="<?= htmlspecialchars($current_settings['mikrotik_port'] ?? '8728') ?>" 
                                           min="1" max="65535" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="mikrotik_user" class="form-label">Username <span class="required">*</span></label>
                                    <input type="text" id="mikrotik_user" name="mikrotik_user" class="form-control" 
                                           value="<?= htmlspecialchars($current_settings['mikrotik_user'] ?? '') ?>" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="mikrotik_pass" class="form-label">Password <span class="required">*</span></label>
                                    <input type="password" id="mikrotik_pass" name="mikrotik_pass" class="form-control" 
                                           value="<?= htmlspecialchars($current_settings['mikrotik_pass'] ?? '') ?>" required>
                                </div>
                                
                                <div class="col-12 d-flex gap-2 mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Simpan ke File Config
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="loadFromFile()">
                                        <i class="fas fa-refresh"></i> Reload dari File
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Test Connection Form -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-plug"></i> Test Koneksi</h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="action" value="test_connection">
                            
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <input type="text" name="test_ip" class="form-control" 
                                           value="<?= htmlspecialchars($current_settings['mikrotik_ip'] ?? '') ?>" 
                                           placeholder="IP Address">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" name="test_port" class="form-control" 
                                           value="<?= htmlspecialchars($current_settings['mikrotik_port'] ?? '8728') ?>" 
                                           placeholder="Port">
                                </div>
                                <div class="col-md-3">
                                    <input type="text" name="test_user" class="form-control" 
                                           value="<?= htmlspecialchars($current_settings['mikrotik_user'] ?? '') ?>" 
                                           placeholder="Username">
                                </div>
                                <div class="col-md-3">
                                    <input type="password" name="test_pass" class="form-control" 
                                           value="<?= htmlspecialchars($current_settings['mikrotik_pass'] ?? '') ?>" 
                                           placeholder="Password">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-info">
                                        <i class="fas fa-plug"></i> Test Koneksi
                                    </button>
                                    <button type="button" class="btn btn-outline-danger ms-2" onclick="clearAllFields()">
                                        <i class="fas fa-trash-alt"></i> CLEAR
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php if ($test_connection_result): ?>
                    <?php if ($test_connection_result['success']): ?>
                        <div class="alert alert-success alert-auto-close">
                            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($test_connection_result['message']) ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-times-circle"></i> <?= htmlspecialchars($test_connection_result['message']) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($connection_error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle"></i> <?= htmlspecialchars($connection_error_message) ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Status Koneksi</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($current_settings['mikrotik_ip'])): ?>
                            <?php if ($current_connection['success']): ?>
                                <div class="alert alert-success d-flex align-items-center">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <div>
                                        <strong>Koneksi berhasil</strong><br>
                                        <small><?= htmlspecialchars($current_settings['mikrotik_ip']) ?>:<?= $current_settings['mikrotik_port'] ?></small>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-danger d-flex align-items-center">
                                    <i class="fas fa-times-circle me-2"></i>
                                    <div>
                                        <strong>Koneksi gagal</strong><br>
                                        <small><?= htmlspecialchars($current_connection['message']) ?></small>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-warning d-flex align-items-center">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <span>Belum ada konfigurasi MikroTik</span>
                            </div>
                        <?php endif; ?>
                        
                        <h6 class="mt-3"><i class="fas fa-book"></i> Panduan Konfigurasi</h6>
                        <ul class="guide-list">
                            <li><strong>IP Address:</strong> IP public atau lokal MikroTik</li>
                            <li><strong>Port API:</strong> Default 8728 (pastikan port ini terbuka)</li>
                            <li><strong>Username:</strong> User dengan hak akses API</li>
                            <li><strong>Password:</strong> Password untuk user tersebut</li>
                        </ul>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-file-code"></i> Status File Konfigurasi</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <strong>Path:</strong> <code>/config/config_mikrotik.php</code>
                        </div>
                        
                        <div class="mb-2">
                            <strong>File Exists:</strong> 
                            <?php if ($config_file_exists): ?>
                                <span class="file-status bg-success text-white">✓ Ya</span>
                            <?php else: ?>
                                <span class="file-status bg-danger text-white">✗ Tidak</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-2">
                            <strong>Readable:</strong> 
                            <?php if ($config_file_readable): ?>
                                <span class="file-status bg-success text-white">✓ Ya</span>
                            <?php else: ?>
                                <span class="file-status bg-warning text-dark">⚠ Tidak</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Writable Directory:</strong> 
                            <?php if ($config_file_writable): ?>
                                <span class="file-status bg-success text-white">✓ Ya</span>
                            <?php else: ?>
                                <span class="file-status bg-danger text-white">✗ Tidak</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($config_file_exists && $config_file_readable): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> File konfigurasi siap digunakan
                            </div>
                        <?php elseif (!$config_file_writable): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle"></i> 
                                Folder tidak writable. Periksa permission folder <code>/config/</code>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-info-circle"></i> File konfigurasi belum dibuat
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($config_file_exists): ?>
                            <small class="text-muted">
                                Last modified: <?= date('d/m/Y H:i:s', filemtime($config_file)) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

<!-- Bootstrap 5 JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
function showConfig() {
    const display = document.getElementById('config-display');
    display.style.display = display.style.display === 'none' ? 'block' : 'none';
}

function loadFromFile() {
    // Reload page to get fresh data from file
    window.location.reload();
}

function clearAllFields() {
    // Clear main form
    document.getElementById('mikrotik_ip').value = '';
    document.getElementById('mikrotik_user').value = '';
    document.getElementById('mikrotik_pass').value = '';
    document.getElementById('mikrotik_port').value = '8728';
    
    // Clear test form
    document.querySelector('input[name="test_ip"]').value = '';
    document.querySelector('input[name="test_user"]').value = '';
    document.querySelector('input[name="test_pass"]').value = '';
    document.querySelector('input[name="test_port"]').value = '8728';
}

// Auto close success alerts
document.addEventListener('DOMContentLoaded', function() {
    const autoCloseAlerts = document.querySelectorAll('.alert-auto-close');
    autoCloseAlerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.display = 'none';
        }, 2000);
    });
});
</script>

<?php 
// Include footer
require_once __DIR__ . '/../templates/footer.php';

// End output buffering and flush
ob_end_flush();
?>