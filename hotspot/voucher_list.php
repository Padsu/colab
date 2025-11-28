<?php
ob_start();
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

// Status koneksi
$api_connected = $mikrotik_connected;
$router_ip = $mikrotik_ip;

// Get voucher sales from database
$sales_query = "SELECT * FROM hotspot_sales ORDER BY tanggal_jual DESC";
$sales_result = $mysqli->query($sales_query);
$sales_data = $sales_result->fetch_all(MYSQLI_ASSOC);

// Get voucher data directly from MikroTik
$vouchers = [];
if ($api_connected) {
    try {
        $vouchers = $api->comm('/ip/hotspot/user/print');
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Gagal mengambil data voucher dari MikroTik: " . $e->getMessage();
    }
}

// Handle delete request
if (isset($_GET['delete']) && $api_connected) {
    $username = $_GET['delete'];
    
    try {
        $api->comm('/ip/hotspot/user/remove', [
            '.id' => $username
        ]);
        $_SESSION['success_message'] = "Voucher berhasil dihapus dari MikroTik!";
        header("Location: list_hotspot_vouchers.php");
        exit();
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Gagal menghapus voucher: " . $e->getMessage();
        header("Location: list_hotspot_vouchers.php");
        exit();
    }
}

// Handle status change
if (isset($_GET['toggle_status']) && $api_connected) {
    $username = $_GET['toggle_status'];
    
    try {
        $user = $api->comm('/ip/hotspot/user/print', [
            '?name' => $username
        ]);
        
        if (!empty($user)) {
            $current_status = $user[0]['disabled'] == 'false' ? 'aktif' : 'nonaktif';
            $new_status = $current_status == 'aktif' ? 'nonaktif' : 'aktif';
            
            $api->comm('/ip/hotspot/user/set', [
                '.id' => $username,
                'disabled' => ($new_status == 'aktif' ? 'no' : 'yes')
            ]);
            
            $_SESSION['success_message'] = "Status voucher berhasil diubah!";
            header("Location: list_hotspot_vouchers.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Gagal mengubah status voucher: " . $e->getMessage();
        header("Location: list_hotspot_vouchers.php");
        exit();
    }
}

// Pagination settings
$limit = 50;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;
$total_records = count($vouchers);
$total_pages = ceil($total_records / $limit);
$paginated_vouchers = array_slice($vouchers, $offset, $limit);

// Get statistics
$stats = [
    'total' => count($vouchers),
    'aktif' => 0,
    'nonaktif' => 0
];

foreach ($vouchers as $voucher) {
    if ($voucher['disabled'] == 'false') {
        $stats['aktif']++;
    } else {
        $stats['nonaktif']++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>List Voucher Hotspot</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Custom Style -->
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
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
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

    .alert-info {
      background-color: rgba(35, 198, 200, 0.1);
      color: var(--info);
    }

    .badge {
      font-weight: 500;
      font-size: 12px;
      padding: 5px 8px;
    }

    .badge-success {
      background-color: var(--success);
    }

    .badge-danger {
      background-color: var(--danger);
    }

    .badge-primary {
      background-color: var(--primary);
    }

    .badge-secondary {
      background-color: var(--secondary);
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

    .btn-success {
      background-color: var(--success);
      border-color: var(--success);
    }

    .btn-success:hover {
      background-color: #1e9e8a;
      border-color: #1e9e8a;
    }

    .btn-warning {
      background-color: var(--warning);
      border-color: var(--warning);
    }

    .btn-danger {
      background-color: var(--danger);
      border-color: var(--danger);
    }

    .btn-info {
      background-color: var(--info);
      border-color: var(--info);
    }

    /* Tambahkan di bagian style */
.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    line-height: 1.5;
    min-width: 30px;
}

.d-flex.gap-1 {
    gap: 0.25rem;
}



    .table {
      color: var(--secondary);
      font-size: 13px;
    }

    .table thead th {
      background-color: var(--dark);
      color: white;
      border-color: var(--dark);
      font-weight: 500;
    }

    .table-striped tbody tr:nth-of-type(odd) {
      background-color: rgba(0, 0, 0, 0.02);
    }

    .table-responsive {
      border-radius: 5px;
    }

    .stat-card {
      text-align: center;
      padding: 15px;
      border-radius: 5px;
      background-color: white;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
	
	/* Tambahkan di bagian style */
.btn-outline {
    background-color: transparent;
    border: 1px solid;
}

.btn-outline-success {
    color: var(--success);
    border-color: var(--success);
}

.btn-outline-warning {
    color: var(--warning);
    border-color: var(--warning);
}

.btn-outline-danger {
    color: var(--danger);
    border-color: var(--danger);
}

.btn-outline-success:hover {
    background-color: var(--success);
    color: white;
}

.btn-outline-warning:hover {
    background-color: var(--warning);
    color: white;
}

.btn-outline-danger:hover {
    background-color: var(--danger);
    color: white;
}

.fa-xs {
    font-size: 0.7rem;
}

    .stat-card h5 {
      font-size: 24px;
      margin-bottom: 5px;
    }

    .stat-card p {
      margin: 0;
      color: var(--secondary);
      font-size: 13px;
    }

    .pagination .page-item.active .page-link {
      background-color: var(--primary);
      border-color: var(--primary);
    }

    .pagination .page-link {
      color: var(--primary);
      font-size: 13px;
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
            <h1><i class="fas fa-wifi me-2"></i>List Voucher Hotspot</h1>
            <p class="page-subtitle">Daftar voucher hotspot yang tersedia di MikroTik</p>
        </div>
        
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success d-flex align-items-center">
                <i class="fas fa-check-circle me-2"></i>
                <?= $_SESSION['success_message'] ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger d-flex align-items-center">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?= $_SESSION['error_message'] ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5>Daftar Voucher</h5>
                        <a href="generate_voucher.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i> Generate Baru
                        </a>
                    </div>
                    <div class="card-body">
                        <!-- Statistics -->
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <h5 class="text-primary"><?= $stats['total'] ?></h5>
                                    <p>Total Voucher</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <h5 class="text-success"><?= $stats['aktif'] ?></h5>
                                    <p>Voucher Aktif</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <h5 class="text-warning"><?= $stats['nonaktif'] ?></h5>
                                    <p>Voucher Nonaktif</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Voucher Table -->
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead>
                                    <tr>
                                        <th width="5%">No</th>
                                        <th>Username</th>
                                        <th>Password</th>
                                        <th>Profile</th>
                                        <th>Uptime Limit</th>
                                        <th>Data Limit</th>
                                        <th>Status</th>
                                        <th width="12%">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($paginated_vouchers)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">
                                                <?= $api_connected ? 'Tidak ada data voucher' : 'Tidak terkoneksi ke MikroTik' ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php $no = $offset + 1; ?>
                                        <?php foreach ($paginated_vouchers as $voucher): ?>
                                            <?php if ($voucher['name'] == 'default') continue; ?>
                                            <tr>
                                                <td><?= $no++; ?></td>
                                                <td><strong><?= htmlspecialchars($voucher['name'] ?? '-'); ?></strong></td>
                                                <td><code><?= htmlspecialchars($voucher['password'] ?? '-'); ?></code></td>
                                                <td><?= htmlspecialchars($voucher['profile'] ?? '-'); ?></td>
                                                <td><?= htmlspecialchars($voucher['uptime-limit'] ?? '-'); ?></td>
                                                <td>
                                                    <?php if (isset($voucher['limit-bytes-in'])): ?>
                                                        <?= number_format($voucher['limit-bytes-in'] / (1024*1024), 0) ?> MB
                                                    <?php else: ?>
                                                        Unlimited
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= ($voucher['disabled'] ?? 'false') == 'false' ? 'success' : 'warning' ?>">
                                                        <?= ($voucher['disabled'] ?? 'false') == 'false' ? 'Aktif' : 'Nonaktif' ?>
                                                    </span>
                                                </td>
                                                <td>
    <div class="d-flex gap-1">
        <!-- Tombol Toggle Status -->
        <a href="?toggle_status=<?= $voucher['name']; ?>" 
           class="btn btn-outline-<?= ($voucher['disabled'] ?? 'false') == 'false' ? 'warning' : 'success' ?> btn-sm border-1" 
           title="<?= ($voucher['disabled'] ?? 'false') == 'false' ? 'Nonaktifkan' : 'Aktifkan' ?>" 
           onclick="return confirm('Ubah status voucher ini?')"
           style="padding: 0.15rem 0.4rem;">
            <i class="fas fa-<?= ($voucher['disabled'] ?? 'false') == 'false' ? 'times' : 'check' ?> fa-xs"></i>
        </a>
        
        <!-- Tombol Delete -->
        <a href="?delete=<?= $voucher['name']; ?>" 
           class="btn btn-outline-danger btn-sm border-1" 
           title="Hapus" 
           onclick="return confirm('Apakah Anda yakin ingin menghapus voucher ini?')"
           style="padding: 0.15rem 0.4rem;">
            <i class="fas fa-trash fa-xs"></i>
        </a>
    </div>
</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation" class="mt-3">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                        
                        <div class="mt-3 text-muted text-center">
                            Menampilkan <?= count($paginated_vouchers) ?> dari <?= $total_records ?> total voucher
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Status Panel -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-plug me-2"></i>Status Koneksi MikroTik</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($api_connected): ?>
                            <div class="alert alert-success d-flex align-items-center">
                                <i class="fas fa-check-circle me-2"></i>
                                Terhubung ke <?= $router_ip ?> - Data voucher ditampilkan langsung dari MikroTik
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="fas fa-times-circle me-2"></i>
                                Tidak terhubung ke <?= $router_ip ?>
                            </div>
                            <p class="text-muted small">Periksa koneksi internet dan kredensial MikroTik di config.</p>
                        <?php endif; ?>
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

<?php 
require_once __DIR__ . '/../templates/footer.php';

// Disconnect from Mikrotik if connected
if (isset($api) && $mikrotik_connected) {
    $api->disconnect();
}

ob_end_flush();
?>