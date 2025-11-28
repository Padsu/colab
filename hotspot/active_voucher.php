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

// Status koneksi dan API object
$api_connected = $mikrotik_connected;
$router_name = "Main Router";
$router_ip = $mikrotik_ip;

// Database connection sudah tersedia dari config_database.php sebagai $mysqli

// Function to format bytes
function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

// Function to format uptime
function formatUptime($uptime) {
    if (empty($uptime)) return '-';
    
    // Parse uptime format like "1d2h3m4s" or "2h30m15s"
    $uptime = str_replace(['d', 'h', 'm', 's'], [':', ':', ':', ''], $uptime);
    $parts = explode(':', $uptime);
    
    $formatted = '';
    if (count($parts) >= 4) {
        $formatted = $parts[0] . 'd ' . $parts[1] . 'h ' . $parts[2] . 'm';
    } elseif (count($parts) >= 3) {
        $formatted = $parts[0] . 'h ' . $parts[1] . 'm';
    } else {
        return $uptime;
    }
    
    return $formatted;
}

// Handle disconnect user
if (isset($_GET['disconnect']) && $api_connected) {
    $username = $_GET['disconnect'];
    
    try {
        // Find and remove active session
        $api->write('/ip/hotspot/active/print', false);
        $api->write('?user=' . $username);
        $active_sessions = $api->read();
        
        foreach ($active_sessions as $session) {
            if (isset($session['.id'])) {
                $api->comm('/ip/hotspot/active/remove', [
                    '.id' => $session['.id']
                ]);
            }
        }
        
        echo "<div class='alert alert-success'>User " . htmlspecialchars($username) . " berhasil didisconnect!</div>";
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Gagal disconnect user: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Get active users from MikroTik
$active_users = [];
$mikrotik_users = [];

if ($api_connected) {
    try {
        // Get active hotspot sessions
        $api->write('/ip/hotspot/active/print');
        $active_sessions = $api->read();
        
        // Get all hotspot users for profile info
        $api->write('/ip/hotspot/user/print');
        $all_users = $api->read();
        
        // Create lookup array for user profiles
        $user_profiles = [];
        foreach ($all_users as $user) {
            if (isset($user['name'], $user['profile'])) {
                $user_profiles[$user['name']] = $user['profile'];
            }
        }
        
        // Process active sessions
        foreach ($active_sessions as $session) {
            if (isset($session['user'])) {
                $username = $session['user'];
                $mikrotik_users[] = [
                    'id' => $session['.id'] ?? '',
                    'username' => $username,
                    'address' => $session['address'] ?? '-',
                    'mac_address' => $session['mac-address'] ?? '-',
                    'uptime' => $session['uptime'] ?? '-',
                    'bytes_in' => intval($session['bytes-in'] ?? 0),
                    'bytes_out' => intval($session['bytes-out'] ?? 0),
                    'profile' => $user_profiles[$username] ?? '-',
                    'server' => $session['server'] ?? '-',
                    'login_time' => $session['login-time'] ?? '-'
                ];
            }
        }
        
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Gagal mengambil data dari MikroTik: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Get database user info for active users
$db_users = [];
if (!empty($mikrotik_users)) {
    $usernames = array_map(function($u) { return "'" . $mysqli->real_escape_string($u['username']) . "'"; }, $mikrotik_users);
    $username_list = implode(',', $usernames);
    
    $query = "SELECT username, profile_name, uptime_limit, bytes_in_limit, keterangan, created_at 
              FROM hotspot_users 
              WHERE username IN ($username_list)";
    $result = $mysqli->query($query);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $db_users[$row['username']] = $row;
        }
    }
}

// Merge MikroTik and database data
foreach ($mikrotik_users as &$user) {
    if (isset($db_users[$user['username']])) {
        $user = array_merge($user, $db_users[$user['username']]);
    }
}

// Search functionality
$search = $_GET['search'] ?? '';
if (!empty($search)) {
    $mikrotik_users = array_filter($mikrotik_users, function($user) use ($search) {
        return stripos($user['username'], $search) !== false || 
               stripos($user['address'], $search) !== false ||
               stripos($user['profile'], $search) !== false;
    });
}

// Statistics
$total_active = count($mikrotik_users);
$total_bytes_in = array_sum(array_column($mikrotik_users, 'bytes_in'));
$total_bytes_out = array_sum(array_column($mikrotik_users, 'bytes_out'));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Hotspot Aktif</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Custom Styles -->
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
      font-family: "Segoe UI", Roboto, Arial, sans-serif;
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

    .page-title .page-subtitle {
      color: var(--secondary);
      font-size: 13px;
      margin: 5px 0 0 0;
    }

    .card {
      border: none;
      border-radius: 5px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      margin-bottom: 20px;
      background-color: white;
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

    .alert-warning {
      background-color: rgba(248, 172, 89, 0.1);
      color: var(--warning);
    }

    .badge {
      font-weight: 500;
      font-size: 12px;
      padding: 5px 8px;
    }

    .badge-online {
      background-color: var(--success);
    }

    .badge-offline {
      background-color: var(--secondary);
    }

    .badge-primary {
      background-color: var(--primary);
    }

    .badge-info {
      background-color: var(--info);
    }

    .badge-warning {
      background-color: var(--warning);
    }

    .badge-danger {
      background-color: var(--danger);
    }

    .table-responsive {
      max-height: 600px;
      overflow-y: auto;
    }

    table.dataTable {
      margin-top: 10px !important;
      margin-bottom: 15px !important;
      border-collapse: collapse !important;
    }

    table.dataTable thead th {
      border-bottom: 1px solid #ddd;
      font-weight: 500;
      font-size: 13px;
      color: var(--dark);
      background-color: white;
    }

    table.dataTable tbody td {
      font-size: 13px;
      vertical-align: middle;
      padding: 12px 15px;
      border-top: 1px solid #f1f1f1;
    }

    table.dataTable tbody tr:hover {
      background-color: rgba(26, 187, 156, 0.05);
    }

    .btn {
      border-radius: 3px;
      font-size: 13px;
      padding: 8px 15px;
      font-weight: 500;
      border: none;
    }

    .btn-primary {
      background-color: var(--primary);
    }

    .btn-primary:hover {
      background-color: #169F85;
    }

    .btn-success {
      background-color: var(--success);
    }

    .btn-info {
      background-color: var(--info);
    }

    .btn-warning {
      background-color: var(--warning);
    }

    .btn-danger {
      background-color: var(--danger);
    }

    .btn-secondary {
      background-color: var(--secondary);
    }

    .btn-sm {
      padding: 5px 10px;
      font-size: 12px;
    }

    .status-online {
      color: var(--success);
      animation: pulse 2s infinite;
    }

    @keyframes pulse {
      0% { opacity: 1; }
      50% { opacity: 0.5; }
      100% { opacity: 1; }
    }

    .refresh-btn {
      animation: spin 2s linear infinite;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    .stat-card {
      border-left: 4px solid;
      border-radius: 3px;
    }

    .stat-card .card-body {
      padding: 15px;
    }

    .stat-card .card-title {
      font-size: 14px;
      margin-bottom: 5px;
      color: var(--secondary);
    }

    .stat-card .card-value {
      font-size: 22px;
      font-weight: 500;
    }

    .stat-card-success {
      border-left-color: var(--success);
    }

    .stat-card-info {
      border-left-color: var(--info);
    }

    .stat-card-warning {
      border-left-color: var(--warning);
    }

    .stat-card-primary {
      border-left-color: var(--primary);
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
    <main class="main-content" id="mainContent">
        <div class="page-title">
            <h1><i class="fas fa-wifi me-2"></i>User Hotspot Aktif</h1>
            <p class="page-subtitle">Daftar pengguna yang sedang terkoneksi ke jaringan</p>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Daftar Pengguna Aktif</h5>
                        <div>
                            <span class="badge badge-online me-2">
                                <i class="fas fa-circle me-1"></i> <?= count($mikrotik_users) ?> Online
                            </span>
                            <button onclick="window.location.reload()" class="btn btn-sm btn-primary">
                                <i class="fas fa-sync-alt me-1"></i> Refresh
                            </button>
                            <a href="list_hotspot_users.php" class="btn btn-sm btn-secondary">
                                <i class="fas fa-list me-1"></i> Semua User
                            </a>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if (!$api_connected): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <strong>Error:</strong> Tidak terhubung ke MikroTik. Tidak dapat menampilkan user aktif.
                            </div>
                        <?php else: ?>
                            
                            <!-- Search Form -->
                            <form method="GET" class="row g-3 mb-4">
                                <div class="col-md-8">
                                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari username, IP, atau profile..." value="<?= htmlspecialchars($search) ?>">
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-sm btn-primary w-100">
                                        <i class="fas fa-search me-1"></i> Cari
                                    </button>
                                </div>
                                <div class="col-md-2 text-end">
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i> Auto refresh: 30s
                                    </small>
                                </div>
                            </form>
                            
                            <!-- Statistics -->
                            <div class="row mb-4">
                                <div class="col-md-3">
                                    <div class="card stat-card stat-card-success">
                                        <div class="card-body">
                                            <h5 class="card-title">User Online</h5>
                                            <div class="card-value text-success"><?= $total_active ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card stat-card stat-card-info">
                                        <div class="card-body">
                                            <h5 class="card-title">Total Download</h5>
                                            <div class="card-value text-info"><?= formatBytes($total_bytes_in) ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card stat-card stat-card-warning">
                                        <div class="card-body">
                                            <h5 class="card-title">Total Upload</h5>
                                            <div class="card-value text-warning"><?= formatBytes($total_bytes_out) ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card stat-card stat-card-primary">
                                        <div class="card-body">
                                            <h5 class="card-title">Total Traffic</h5>
                                            <div class="card-value text-primary"><?= formatBytes($total_bytes_in + $total_bytes_out) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th width="5%">No</th>
                                            <th>Username</th>
                                            <th>IP Address</th>
                                            <th>MAC Address</th>
                                            <th>Profile</th>
                                            <th>Uptime</th>
                                            <th class="text-end">Download</th>
                                            <th class="text-end">Upload</th>
                                            <th>Server</th>
                                            <th width="10%">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($mikrotik_users)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center py-4">
                                                    <i class="fas fa-user-slash me-2"></i>
                                                    <?= $api_connected ? 'Tidak ada user yang sedang online' : 'Tidak terhubung ke MikroTik' ?>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php $no = 1; ?>
                                            <?php foreach ($mikrotik_users as $user): ?>
                                                <tr>
                                                    <td><?= $no++; ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($user['username']); ?></strong>
                                                        <br><small class="text-muted">
                                                            <?= isset($user['created_at']) ? date('d/m/Y', strtotime($user['created_at'])) : '' ?>
                                                        </small>
                                                    </td>
                                                    <td><code><?= htmlspecialchars($user['address']); ?></code></td>
                                                    <td><small><?= htmlspecialchars($user['mac_address']); ?></small></td>
                                                    <td>
                                                        <span class="badge bg-info"><?= htmlspecialchars($user['profile']); ?></span>
                                                    </td>
                                                    <td><?= formatUptime($user['uptime']); ?></td>
                                                    <td class="text-end">
                                                        <span class="text-success"><?= formatBytes($user['bytes_in']); ?></span>
                                                    </td>
                                                    <td class="text-end">
                                                        <span class="text-warning"><?= formatBytes($user['bytes_out']); ?></span>
                                                    </td>
                                                    <td><?= htmlspecialchars($user['server']); ?></td>
                                                    <td>
                                                        <a href="?disconnect=<?= urlencode($user['username']); ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                                                           class="btn btn-sm btn-danger" 
                                                           title="Disconnect User" 
                                                           onclick="return confirm('Disconnect user <?= htmlspecialchars($user['username']) ?>?')">
                                                            <i class="fas fa-sign-out-alt"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="mt-3 text-muted text-center">
                                <i class="fas fa-info-circle me-1"></i> Total <?= count($mikrotik_users) ?> user sedang online
                                <?php if (!empty($search)): ?>
                                    (filtered by: "<?= htmlspecialchars($search) ?>")
                                <?php endif; ?>
                            </div>
                            
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Info Panel -->
        <div class="row mt-4">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-server me-2"></i>Status Koneksi MikroTik</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($api_connected): ?>
                            <div class="alert alert-success d-flex align-items-center">
                                <i class="fas fa-check-circle me-2"></i>
                                <div>
                                    <strong>Terhubung ke <?= htmlspecialchars($router_ip) ?></strong><br>
                                    <small>Data real-time tersedia</small>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="fas fa-times-circle me-2"></i>
                                <div>
                                    <strong>Gagal terhubung ke <?= htmlspecialchars($router_ip) ?></strong><br>
                                    <small>Periksa koneksi internet dan kredensial MikroTik di config.</small>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-info-circle me-2"></i>Informasi Sistem</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li class="mb-2"><i class="fas fa-sync-alt me-2 text-primary"></i> <strong>Update:</strong> Real-time dari MikroTik</li>
                            <li class="mb-2"><i class="fas fa-clock me-2 text-primary"></i> <strong>Auto Refresh:</strong> Setiap 30 detik</li>
                            <li class="mb-2"><i class="fas fa-globe-asia me-2 text-primary"></i> <strong>Timezone:</strong> Asia/Jakarta</li>
                            <li class="mb-0"><i class="fas fa-circle me-2 text-success"></i> <strong>Status:</strong> Live</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Bootstrap 5 JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Auto refresh every 30 seconds
    setTimeout(function() {
        window.location.reload();
    }, 30000);
</script>

<?php 
// Include footer
require_once __DIR__ . '/../templates/footer.php';

// End output buffering and flush
ob_end_flush();

// Disconnect from Mikrotik if connected
if ($mikrotik_connected) {
    $api->disconnect();
}
?>