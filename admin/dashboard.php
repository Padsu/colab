<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Load configurations
require_once __DIR__ . '/../config/config_database.php';

// Get statistics data
$stats = [
    'total_pelanggan' => 0,
    'total_pendapatan' => 0,
    'client_belum_bayar' => 0,
    'total_voucher_active' => 0
];

try {
    // Total Pelanggan (menggunakan tabel data_pelanggan)
    $result = $mysqli->query("SELECT COUNT(*) as total FROM data_pelanggan");
    if ($result) {
        $stats['total_pelanggan'] = $result->fetch_assoc()['total'];
    }
    
    // Total Pendapatan (dari tabel pembayaran)
    $result = $mysqli->query("SELECT SUM(jumlah_bayar) as total FROM pembayaran");
    if ($result) {
        $stats['total_pendapatan'] = $result->fetch_assoc()['total'] ?? 0;
    }
    
    // Client Belum Bayar (tagihan dengan status belum_bayar atau terlambat)
    $result = $mysqli->query("SELECT COUNT(*) as total FROM tagihan WHERE status_tagihan IN ('belum_bayar', 'terlambat')");
    if ($result) {
        $stats['client_belum_bayar'] = $result->fetch_assoc()['total'];
    }
    
    // Total Voucher Active (dari tabel hotspot_users)
    $result = $mysqli->query("SELECT COUNT(*) as total FROM hotspot_users WHERE status = 'aktif'");
    if ($result) {
        $stats['total_voucher_active'] = $result->fetch_assoc()['total'];
    }
} catch (Exception $e) {
    // Log error but don't break the page
    error_log("Database error: " . $e->getMessage());
}

// MikroTik System Resources
$mikrotik_connected = false;
$system_resources = [
    'uptime' => 'N/A',
    'cpu_load' => 'N/A',
    'memory_usage' => 'N/A',
    'disk_usage' => 'N/A',
    'board_name' => 'N/A',
    'cpu_count' => 'N/A',
    'cpu_frequency' => 'N/A'
];

if (file_exists(__DIR__ . '/../config/config_mikrotik.php')) {
    try {
        require_once __DIR__ . '/../config/config_mikrotik.php';
        if (isset($mikrotik_connected) && $mikrotik_connected) {
            // Get system resources
            $api->write('/system/resource/print');
            $resources = $api->read();
            
            if (is_array($resources) && count($resources) > 0) {
                $resource = $resources[0];
                $system_resources['uptime'] = $resource['uptime'] ?? 'N/A';
                $system_resources['cpu_load'] = $resource['cpu-load'] ?? 'N/A';
                $system_resources['board_name'] = $resource['board-name'] ?? 'N/A';
                $system_resources['cpu_count'] = $resource['cpu-count'] ?? 'N/A';
                $system_resources['cpu_frequency'] = $resource['cpu-frequency'] ?? 'N/A';
                
                // Calculate memory usage
                if (isset($resource['free-memory']) && isset($resource['total-memory'])) {
                    $used_memory = $resource['total-memory'] - $resource['free-memory'];
                    $system_resources['memory_usage'] = round(($used_memory / $resource['total-memory']) * 100, 1) . '%';
                }
                
                // Calculate disk usage
                if (isset($resource['free-hdd-space']) && isset($resource['total-hdd-space'])) {
                    $used_disk = $resource['total-hdd-space'] - $resource['free-hdd-space'];
                    $system_resources['disk_usage'] = round(($used_disk / $resource['total-hdd-space']) * 100, 1) . '%';
                }
            }
        }
    } catch (Exception $e) {
        error_log("MikroTik error: " . $e->getMessage());
    }
}

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
    <title>Dashboard | Admin</title>
    <!-- Gentelella Styles -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
/* Gentelella Admin Template inspired styles with Segoe UI font */
body {
    font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif !important;
}

.content-card {
    background: #fff;
    padding: 20px;
    border-radius: 5px;
    margin-bottom: 20px;
    border: 1px solid #e6e9ed;
    transition: all .3s ease;
    height: 100%;
    font-family: "Segoe UI", sans-serif;
}

.content-card:hover {
    box-shadow: 0 1px 8px rgba(0,0,0,0.1);
}

.icon-circle {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #fff;
    font-family: "Segoe UI", sans-serif;
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: white;
    margin-right: 15px;
    font-family: "Segoe UI", sans-serif;
}

.resource-card {
    padding: 15px;
    background-color: #f9f9f9;
    border-radius: 4px;
    margin-bottom: 15px;
    font-family: "Segoe UI", sans-serif;
}

.progress {
    height: 10px;
    border-radius: 16px;
    background: #f5f5f5;
    margin-top: 5px;
}

.progress-bar {
    border-radius: 16px;
}

.empty-state {
    text-align: center;
    padding: 30px 0;
    color: #73879C;
    font-family: "Segoe UI", sans-serif;
}

.table {
    width: 100%;
    max-width: 100%;
    margin-bottom: 20px;
    font-family: "Segoe UI", sans-serif;
}

.table th {
    border-top: none;
    border-bottom: 1px solid #e6e9ed;
    color: #73879C;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 12px;
    padding: 12px 8px;
    font-family: "Segoe UI", sans-serif;
}

.table td {
    padding: 12px 8px;
    vertical-align: middle;
    border-top: 1px solid #e6e9ed;
    color: #5A738E;
    font-family: "Segoe UI", sans-serif;
}

.table-hover tbody tr:hover {
    background-color: #f5f7fa;
}

.badge {
    padding: 4px 8px;
    font-weight: 500;
    font-size: 11px;
    border-radius: 10px;
    text-transform: uppercase;
    font-family: "Segoe UI", sans-serif;
}

.text-muted {
    color: #73879C !important;
    font-family: "Segoe UI", sans-serif;
}

.page-title {
    padding: 10px 0;
    margin-bottom: 20px;
    border-bottom: 1px solid #e6e9ed;
    font-family: "Segoe UI", sans-serif;
}

.page-title h1 {
    font-size: 24px;
    margin: 0;
    color: #333;
    font-weight: 600;
    font-family: "Segoe UI", sans-serif;
}

.page-subtitle {
    color: #73879C;
    margin-top: 5px;
    font-size: 13px;
    font-family: "Segoe UI", sans-serif;
}

/* Gentelella Color Scheme */
.bg-primary { background-color: #3498DB; }
.bg-success { background-color: #1ABB9C; }
.bg-info { background-color: #9B59B6; }
.bg-warning { background-color: #F39C12; }
.bg-danger { background-color: #E74C3C; }
.bg-secondary { background-color: #34495E; }

.text-primary { color: #3498DB !important; }
.text-success { color: #1ABB9C !important; }
.text-info { color: #9B59B6 !important; }
.text-warning { color: #F39C12 !important; }
.text-danger { color: #E74C3C !important; }
.text-dark { color: #34495E !important; }

/* Buttons */
.btn {
    border-radius: 3px;
    font-size: 13px;
    padding: 6px 12px;
    font-weight: 500;
    font-family: "Segoe UI", sans-serif;
}

.btn-primary { 
    background-color: #3498DB;
    border-color: #3498DB;
}
.btn-primary:hover {
    background-color: #2980B9;
    border-color: #2980B9;
}

.btn-success { 
    background-color: #1ABB9C;
    border-color: #1ABB9C;
}
.btn-success:hover {
    background-color: #169F85;
    border-color: #169F85;
}

/* List group */
.list-group-item {
    border: none;
    border-bottom: 1px solid #e6e9ed;
    padding: 15px 0;
    font-family: "Segoe UI", sans-serif;
}

.list-group-item:last-child {
    border-bottom: none;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .icon-circle {
        width: 40px;
        height: 40px;
        font-size: 18px;
    }
    
    .page-title h1 {
        font-size: 20px;
    }
    
    .table-responsive {
        border: none;
    }
}

/* Animation for refresh */
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.fa-spin {
    animation: spin 1s infinite linear;
}

/* Status indicators */
.status-indicator {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 5px;
}

.status-online {
    background-color: #1ABB9C;
}

.status-offline {
    background-color: #E74C3C;
}

/* Card headers */
.card-header {
    background-color: transparent;
    border-bottom: 1px solid #e6e9ed;
    padding: 15px 20px;
    position: relative;
    font-family: "Segoe UI", sans-serif;
}

.card-header h3 {
    font-size: 16px;
    margin: 0;
    color: #333;
    font-weight: 600;
    font-family: "Segoe UI", sans-serif;
}

.card-header h3 i {
    margin-right: 10px;
    font-size: 16px;
}

/* Additional Segoe UI font implementations */
h1, h2, h3, h4, h5, h6 {
    font-family: "Segoe UI", sans-serif !important;
}

input, select, textarea, button {
    font-family: "Segoe UI", sans-serif !important;
}

.navbar, .sidebar {
    font-family: "Segoe UI", sans-serif !important;
}
</style>
</head>
<body>

<main class="main-content" id="mainContent">
    <div class="page-title">
        <h1>Dashboard Overview</h1>
        <p class="page-subtitle">Selamat datang, <?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></p>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <!-- Total Pelanggan -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0"><?= number_format($stats['total_pelanggan']) ?></h3>
                        <p class="text-muted mb-0">Total Pelanggan</p>
                    </div>
                    <div class="icon-circle bg-primary">
                        <i class="fas fa-users text-white"></i>
                    </div>
                </div>

            </div>
        </div>
        
        <!-- Total Pendapatan -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0">Rp <?= number_format($stats['total_pendapatan'], 0, ',', '.') ?></h3>
                        <p class="text-muted mb-0">Total Pendapatan</p>
                    </div>
                    <div class="icon-circle bg-success">
                        <i class="fas fa-money-bill-wave text-white"></i>
                    </div>
                </div>

            </div>
        </div>
        
        <!-- Client Belum Bayar -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0"><?= number_format($stats['client_belum_bayar']) ?></h3>
                        <p class="text-muted mb-0">Belum Bayar</p>
                    </div>
                    <div class="icon-circle bg-warning">
                        <i class="fas fa-exclamation-triangle text-white"></i>
                    </div>
                </div>

            </div>
        </div>
        
        <!-- Voucher Active -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0"><?= number_format($stats['total_voucher_active']) ?></h3>
                        <p class="text-muted mb-0">Voucher Aktif</p>
                    </div>
                    <div class="icon-circle bg-info">
                        <i class="fas fa-ticket-alt text-white"></i>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="row">
        <!-- System Resources -->
<!-- System Resources -->
<div class="col-lg-6 mb-4">
    <div class="content-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">
                <i class="fas fa-server text-primary me-2"></i>System Resources
                <?php if ($mikrotik_connected): ?>
                    <span class="badge bg-success ms-2">Online</span>
                <?php else: ?>
                    <span class="badge bg-danger ms-2">Offline</span>
                <?php endif; ?>
            </h3>
            <small class="text-muted">Last updated: <?= date('H:i:s') ?></small>
        </div>
        
        <?php if ($mikrotik_connected): ?>
            <div class="row">
                <!-- Board Name -->
                <div class="col-12 mb-3">
                    <div class="resource-card">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-microchip text-primary me-2"></i>
                            <span><strong>Board:</strong> <?= $system_resources['board_name'] ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- CPU Info -->
                <div class="col-md-6 mb-3">
                    <div class="resource-card">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-microchip text-primary me-2"></i>
                            <span><strong>CPU:</strong> <?= $system_resources['cpu_count'] ?> core @ <?= $system_resources['cpu_frequency'] ?> MHz</span>
                        </div>
                    </div>
                </div>
                
                <!-- CPU Usage -->
                <div class="col-md-6 mb-3">
                    <div class="resource-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <i class="fas fa-microchip text-primary me-2"></i>
                                <span>CPU Load</span>
                            </div>
                            <span class="badge bg-<?= is_numeric($system_resources['cpu_load']) && $system_resources['cpu_load'] > 80 ? 'danger' : (is_numeric($system_resources['cpu_load']) && $system_resources['cpu_load'] > 60 ? 'warning' : 'success') ?>">
                                <?= $system_resources['cpu_load'] ?><?= is_numeric($system_resources['cpu_load']) ? '%' : '' ?>
                            </span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-<?= is_numeric($system_resources['cpu_load']) && $system_resources['cpu_load'] > 80 ? 'danger' : (is_numeric($system_resources['cpu_load']) && $system_resources['cpu_load'] > 60 ? 'warning' : 'success') ?>" 
                                 role="progressbar" 
                                 style="width: <?= is_numeric($system_resources['cpu_load']) ? $system_resources['cpu_load'] : 0 ?>%" 
                                 aria-valuenow="<?= is_numeric($system_resources['cpu_load']) ? $system_resources['cpu_load'] : 0 ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Memory Usage -->
                <div class="col-md-6 mb-3">
                    <div class="resource-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <i class="fas fa-memory text-info me-2"></i>
                                <span>Memory Usage</span>
                            </div>
                            <span class="badge bg-<?= str_replace('%', '', $system_resources['memory_usage']) > 80 ? 'danger' : (str_replace('%', '', $system_resources['memory_usage']) > 60 ? 'warning' : 'success') ?>">
                                <?= $system_resources['memory_usage'] ?>
                            </span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-<?= str_replace('%', '', $system_resources['memory_usage']) > 80 ? 'danger' : (str_replace('%', '', $system_resources['memory_usage']) > 60 ? 'warning' : 'success') ?>" 
                                 role="progressbar" 
                                 style="width: <?= str_replace('%', '', $system_resources['memory_usage']) ?>%" 
                                 aria-valuenow="<?= str_replace('%', '', $system_resources['memory_usage']) ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Disk Usage -->
                <div class="col-md-6 mb-3">
                    <div class="resource-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <i class="fas fa-hdd text-warning me-2"></i>
                                <span>Disk Usage</span>
                            </div>
                            <span class="badge bg-<?= str_replace('%', '', $system_resources['disk_usage']) > 80 ? 'danger' : (str_replace('%', '', $system_resources['disk_usage']) > 60 ? 'warning' : 'success') ?>">
                                <?= $system_resources['disk_usage'] ?>
                            </span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-<?= str_replace('%', '', $system_resources['disk_usage']) > 80 ? 'danger' : (str_replace('%', '', $system_resources['disk_usage']) > 60 ? 'warning' : 'success') ?>" 
                                 role="progressbar" 
                                 style="width: <?= str_replace('%', '', $system_resources['disk_usage']) ?>%" 
                                 aria-valuenow="<?= str_replace('%', '', $system_resources['disk_usage']) ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
				
				<div class="col-12 mb-3">
                    <div class="resource-card">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-clock text-secondary me-2"></i>
                            <span><strong>Uptime:</strong> <?= $system_resources['uptime'] ?></span>
                        </div>
                    </div>
                </div>
            </div>
            

        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                <h5 class="text-muted">MikroTik Not Connected</h5>
                <p class="text-muted">Unable to retrieve system resources. Please check your MikroTik configuration.</p>
                <a href="../settings/mikrotik.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-cog me-1"></i> Configure MikroTik
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
        
        <!-- Recent Activity -->
        <div class="col-lg-6 mb-4">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="mb-0">
                        <i class="fas fa-history text-info me-2"></i>Recent Activity
                    </h3>
                </div>
                
                <div class="activity-list">
                    <?php
                    // Get recent activity from database (menggunakan tabel yang ada)
                    $activities = [];
                    try {
                        // Ambil dari tabel log_aktivitas jika ada, atau buat dari tabel pembayaran sebagai alternatif
                        $result = $mysqli->query("
                            SELECT 
                                u.username, 
                                'Payment recorded' as action, 
                                p.created_at as timestamp 
                            FROM pembayaran p 
                            LEFT JOIN users u ON p.id_user_pencatat = u.id_user 
                            ORDER BY p.created_at DESC 
                            LIMIT 6
                        ");
                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                $activities[] = $row;
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Activity log error: " . $e->getMessage());
                    }
                    
                    if (empty($activities)): ?>
                        <div class="empty-state">
                            <i class="fas fa-info-circle fa-2x text-muted mb-3"></i>
                            <p class="text-muted">No recent activity found</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($activities as $activity): ?>
                                <div class="list-group-item border-0 py-2 px-0">
                                    <div class="d-flex align-items-center">
                                        <div class="activity-icon bg-<?= 
                                            strpos($activity['action'], 'Payment') !== false ? 'success' : 
                                            (strpos($activity['action'], 'updated') !== false ? 'primary' : 
                                            (strpos($activity['action'], 'deleted') !== false ? 'danger' : 'secondary')) 
                                        ?>">
                                            <i class="fas fa-<?= 
                                                strpos($activity['action'], 'Payment') !== false ? 'money-bill-wave' : 
                                                (strpos($activity['action'], 'pelanggan') !== false ? 'user' : 
                                                (strpos($activity['action'], 'voucher') !== false ? 'ticket-alt' : 'cog')) 
                                            ?>"></i>
                                        </div>
                                        <div class="ms-3 flex-grow-1">
                                            <div class="d-flex justify-content-between">
                                                <strong><?= htmlspecialchars($activity['username'] ?? 'System') ?></strong>
                                                <small class="text-muted"><?= date('H:i', strtotime($activity['timestamp'])) ?></small>
                                            </div>
                                            <p class="mb-0"><?= htmlspecialchars($activity['action']) ?></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Stats -->
    <div class="row">
        <!-- Recent Payments -->
        <div class="col-lg-6 mb-4">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="mb-0">
                        <i class="fas fa-money-bill-wave text-success me-2"></i>Recent Payments
                    </h3>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Pelanggan</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Method</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get recent payments (menggunakan tabel pembayaran dan data_pelanggan)
                            $payments = [];
                            try {
                                $result = $mysqli->query("
                                    SELECT p.*, dp.nama_pelanggan 
                                    FROM pembayaran p
                                    JOIN data_pelanggan dp ON p.id_pelanggan = dp.id_pelanggan
                                    ORDER BY p.tanggal_bayar DESC 
                                    LIMIT 5
                                ");
                                if ($result) {
                                    $payments = $result->fetch_all(MYSQLI_ASSOC);
                                }
                            } catch (Exception $e) {
                                error_log("Payments error: " . $e->getMessage());
                            }
                            
                            if (empty($payments)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">
                                        <i class="fas fa-info-circle me-2"></i>No recent payments found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($payment['nama_pelanggan']) ?></td>
                                        <td>Rp <?= number_format($payment['jumlah_bayar'], 0, ',', '.') ?></td>
                                        <td><?= date('d M Y', strtotime($payment['tanggal_bayar'])) ?></td>
                                        <td>
                                            <span class="badge bg-info">
                                                <?= ucfirst($payment['metode_bayar'] ?? 'CASH') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Expiring Soon -->
        <div class="col-lg-6 mb-4">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="mb-0">
                        <i class="fas fa-clock text-warning me-2"></i>Expiring Soon
                    </h3>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Pelanggan</th>
                                <th>Paket</th>
                                <th>Expires</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get expiring soon customers (menggunakan tabel data_pelanggan dan paket_internet)
                            $expiring = [];
                            try {
                                $result = $mysqli->query("
                                    SELECT dp.nama_pelanggan, dp.tgl_expired, dp.status_aktif, pi.nama_paket 
                                    FROM data_pelanggan dp
                                    LEFT JOIN paket_internet pi ON dp.id_paket = pi.id_paket
                                    WHERE dp.tgl_expired BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                                    ORDER BY dp.tgl_expired ASC 
                                    LIMIT 5
                                ");
                                if ($result) {
                                    $expiring = $result->fetch_all(MYSQLI_ASSOC);
                                }
                            } catch (Exception $e) {
                                error_log("Expiring error: " . $e->getMessage());
                            }
                            
                            if (empty($expiring)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">
                                        <i class="fas fa-check-circle me-2"></i>No expiring customers in next 7 days
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expiring as $customer): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($customer['nama_pelanggan']) ?></td>
                                        <td><?= htmlspecialchars($customer['nama_paket'] ?? 'N/A') ?></td>
                                        <td><?= date('d M Y', strtotime($customer['tgl_expired'])) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $customer['status_aktif'] === 'aktif' ? 'success' : ($customer['status_aktif'] === 'nonaktif' ? 'danger' : 'warning') ?>">
                                                <?= ucfirst($customer['status_aktif']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
// Disconnect from Mikrotik if connected
if (isset($api) && $mikrotik_connected) {
    $api->disconnect();
}
?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

<style>
/* Gentelella-inspired styles */
.content-card {
    background-color: white;
    padding: 20px;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 20px;
    height: 100%;
}

.icon-circle {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: white;
}

.resource-card {
    padding: 15px;
    background-color: #f9f9f9;
    border-radius: 4px;
}

.progress {
    border-radius: 10px;
}

.progress-bar {
    border-radius: 10px;
}

.empty-state {
    text-align: center;
    padding: 30px 0;
}

.table th {
    border-top: none;
    color: #73879C;
    font-weight: 500;
    text-transform: uppercase;
    font-size: 12px;
}

.table td {
    vertical-align: middle;
}

.badge {
    padding: 5px 10px;
    font-weight: 500;
    font-size: 12px;
    border-radius: 10px;
}

.text-muted {
    color: #73879C !important;
}

/* Color classes */
.bg-primary { background-color: #1ABB9C; }
.bg-success { background-color: #26B99A; }
.bg-info { background-color: #23C6C8; }
.bg-warning { background-color: #F8AC59; }
.bg-danger { background-color: #ED5565; }
.bg-secondary { background-color: #73879C; }

.text-primary { color: #1ABB9C !important; }
.text-success { color: #26B99A !important; }
.text-info { color: #23C6C8 !important; }
.text-warning { color: #F8AC59 !important; }
.text-danger { color: #ED5565 !important; }

.btn-primary { 
    background-color: #1ABB9C;
    border-color: #1ABB9C;
}
.btn-primary:hover {
    background-color: #169F85;
    border-color: #169F85;
}

.btn-outline-primary {
    color: #1ABB9C;
    border-color: #1ABB9C;
}
.btn-outline-primary:hover {
    background-color: #1ABB9C;
    border-color: #1ABB9C;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .icon-circle {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
}
</style>

<script>
// Auto refresh system resources every 60 seconds
setInterval(function() {
    if (window.location.pathname.endsWith('dashboard2.php')) {
        // Auto refresh logic (if refresh_resources.php exists)
        $.ajax({
            url: 'refresh_resources.php',
            method: 'GET',
            success: function(data) {
                // Update data if response format is correct
                if (data && typeof data === 'object') {
                    // Update timestamp
                    $('[class*="Last updated"]').text('Last updated: ' + new Date().toLocaleTimeString());
                }
            },
            error: function() {
                // Silently handle error
            }
        });
    }
}, 60000);

// Manual refresh button
$('#refreshResources').click(function() {
    $(this).html('<i class="fas fa-spinner fa-spin me-1"></i> Refreshing...');
    
    // Simulate refresh (since we don't have the actual refresh_resources.php)
    setTimeout(function() {
        $('#refreshResources').html('<i class="fas fa-sync-alt me-1"></i> Refresh Data');
        // Update timestamp
        $('small.text-muted').text('Last updated: ' + new Date().toLocaleTimeString());
    }, 1000);
});
</script>