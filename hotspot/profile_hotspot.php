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

// Fungsi untuk mengambil profile yang ada
function getHotspotProfiles($api) {
    if (!$api) return [];
    try {
        $api->write('/ip/hotspot/user/profile/print');
        $data = $api->read();
        $list = [];
        foreach ($data as $d) {
            if (isset($d['.id'], $d['name'])) {
                $list[] = [
                    'id' => $d['.id'],
                    'name' => $d['name'],
                    'rate_limit' => $d['rate-limit'] ?? '',
                    'session_timeout' => $d['session-timeout'] ?? '',
                    'idle_timeout' => $d['idle-timeout'] ?? '',
                    'shared_users' => $d['shared-users'] ?? ''
                ];
            }
        }
        return $list;
    } catch (Exception $e) {
        return [];
    }
}

// Handle POST Add Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'add_profile') {
    if (!$api_connected) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Error: Router tidak terhubung!'];
    } else {
        $profile_name = $_POST['profile_name'] ?? '';
        $rate_limit = $_POST['rate_limit'] ?? '';
        $session_timeout = $_POST['session_timeout'] ?? '';
        $idle_timeout = $_POST['idle_timeout'] ?? '';
        $shared_users = $_POST['shared_users'] ?? '1';
        $address_pool = $_POST['address_pool'] ?? '';
        $dns_name = $_POST['dns_name'] ?? '';
        $login_by = $_POST['login_by'] ?? '';

        if (!empty($profile_name)) {
            try {
                $params = [
                    'name' => $profile_name
                ];
                
                if (!empty($rate_limit)) $params['rate-limit'] = $rate_limit;
                if (!empty($session_timeout)) $params['session-timeout'] = $session_timeout;
                if (!empty($idle_timeout)) $params['idle-timeout'] = $idle_timeout;
                if (!empty($shared_users)) $params['shared-users'] = $shared_users;
                if (!empty($address_pool)) $params['address-pool'] = $address_pool;
                if (!empty($dns_name)) $params['dns-name'] = $dns_name;
                if (!empty($login_by)) $params['login-by'] = $login_by;

                $api->comm('/ip/hotspot/user/profile/add', $params);
                
                $_SESSION['alert'] = ['type' => 'success', 'message' => "Profile hotspot '$profile_name' berhasil ditambahkan!"];
                
            } catch (Exception $e) {
                $_SESSION['alert'] = ['type' => 'danger', 'message' => "Error saat menambah profile: " . $e->getMessage()];
            }
        } else {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Nama profile wajib diisi!'];
        }
    }
    header("Location: profile_hotspot.php");
    exit();
}

// Handle POST Delete Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'delete_profile') {
    if (!$api_connected) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Error: Router tidak terhubung!'];
    } else {
        $profile_id = $_POST['profile_id'] ?? '';
        
        if (!empty($profile_id)) {
            try {
                $api->comm('/ip/hotspot/user/profile/remove', [
                    '.id' => $profile_id
                ]);
                
                $_SESSION['alert'] = ['type' => 'success', 'message' => 'Profile berhasil dihapus!'];
                
            } catch (Exception $e) {
                $_SESSION['alert'] = ['type' => 'danger', 'message' => "Error saat menghapus profile: " . $e->getMessage()];
            }
        }
    }
    header("Location: profile_hotspot.php");
    exit();
}

// Ambil data profile yang ada
$profiles = [];
if ($api_connected) {
    $profiles = getHotspotProfiles($api);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Profile Hotspot</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Gentelella Style -->
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
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    }

    .form-control:focus, .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 0.2rem rgba(26, 187, 156, 0.25);
    }

    .form-select {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
      background-size: 12px 12px;
      padding: 8px 30px 8px 12px;
    }

    .btn {
      border-radius: 3px;
      font-size: 13px;
      padding: 8px 15px;
      font-weight: 500;
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
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

    .btn-sm {
      padding: 5px 10px;
      font-size: 12px;
    }

    .btn-group-sm > .btn, .btn-sm {
      padding: 5px 10px;
      font-size: 12px;
    }

    .table-responsive {
      border-radius: 5px;
      overflow: hidden;
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
      background-color: #f8f9fa;
    }

    table.dataTable tbody td {
      font-size: 13px;
      vertical-align: middle;
      padding: 12px 15px;
      border-top: 1px solid #f1f1f1;
    }

    .guide-card {
      background-color: white;
      border: 1px solid #e5e5e5;
      border-radius: 5px;
      padding: 15px;
    }

    .guide-card h5 {
      font-size: 15px;
      color: var(--dark);
      margin-bottom: 15px;
    }

    .guide-card ul {
      padding-left: 20px;
      font-size: 13px;
    }

    .guide-card li {
      margin-bottom: 5px;
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
            <h1>Manajemen Profile Hotspot</h1>
            <p class="page-subtitle">Tambah dan kelola profile hotspot MikroTik</p>
        </div>
        
        <?php if (isset($_SESSION['alert'])): ?>
            <div class="alert alert-<?= $_SESSION['alert']['type'] ?> d-flex align-items-center">
                <i class="fas fa-<?= $_SESSION['alert']['type'] === 'danger' ? 'exclamation-circle' : ($_SESSION['alert']['type'] === 'success' ? 'check-circle' : 'info-circle') ?> me-2"></i>
                <?= $_SESSION['alert']['message'] ?>
            </div>
            <?php unset($_SESSION['alert']); ?>
        <?php endif; ?>
        
        <div class="row">
            <!-- Form Tambah Profile -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-plus-circle me-2"></i>Tambah Profile Baru</h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="action" value="add_profile">
                            
                            <?php if (!$api_connected): ?>
                                <div class="alert alert-warning d-flex align-items-center">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Router tidak terhubung. Pastikan koneksi router aktif.
                                </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label class="form-label">Nama Profile <span class="text-danger">*</span></label>
                                <input type="text" name="profile_name" class="form-control" required <?= !$api_connected ? 'disabled' : '' ?>>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Rate Limit (opsional)</label>
                                    <input type="text" name="rate_limit" class="form-control" placeholder="Contoh: 1M/2M" <?= !$api_connected ? 'disabled' : '' ?>>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Session Timeout (opsional)</label>
                                    <input type="text" name="session_timeout" class="form-control" placeholder="Contoh: 1h" <?= !$api_connected ? 'disabled' : '' ?>>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Idle Timeout (opsional)</label>
                                    <input type="text" name="idle_timeout" class="form-control" placeholder="Contoh: 10m" <?= !$api_connected ? 'disabled' : '' ?>>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Shared Users</label>
                                    <select name="shared_users" class="form-select" <?= !$api_connected ? 'disabled' : '' ?>>
                                        <option value="1" selected>1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="unlimited">Unlimited</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Address Pool (opsional)</label>
                                    <input type="text" name="address_pool" class="form-control" <?= !$api_connected ? 'disabled' : '' ?>>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">DNS Name (opsional)</label>
                                    <input type="text" name="dns_name" class="form-control" <?= !$api_connected ? 'disabled' : '' ?>>
                                </div>
                                
                                <div class="col-12">
                                    <label class="form-label">Login By (opsional)</label>
                                    <select name="login_by" class="form-select" <?= !$api_connected ? 'disabled' : '' ?>>
                                        <option value="">-- Pilih --</option>
                                        <option value="cookie">Cookie</option>
                                        <option value="http-chap">HTTP CHAP</option>
                                        <option value="http-pap">HTTP PAP</option>
                                        <option value="https">HTTPS</option>
                                        <option value="mac">MAC</option>
                                        <option value="trial">Trial</option>
                                    </select>
                                </div>
                                
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-primary" <?= !$api_connected ? 'disabled' : '' ?>>
                                        <i class="fas fa-save me-1"></i> Simpan Profile
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Panduan & Status -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-info-circle me-2"></i>Panduan & Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="guide-card mb-4">
                            <h5><i class="fas fa-server me-2"></i>Status Router</h5>
                            <?php if ($api_connected): ?>
                                <div class="alert alert-success d-flex align-items-center">
                                    <i class="fas fa-check-circle me-2"></i>
                                    Terhubung ke <?= $router_ip ?> (<?= $router_name ?>)
                                </div>
                            <?php else: ?>
                                <div class="alert alert-danger d-flex align-items-center">
                                    <i class="fas fa-times-circle me-2"></i>
                                    Tidak terhubung ke <?= $router_ip ?>
                                </div>
                                <p class="text-muted small">Periksa koneksi internet dan kredensial MikroTik di config.</p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="guide-card mb-4">
                            <h5><i class="fas fa-book me-2"></i>Panduan Pengisian</h5>
                            <ul>
                                <li><strong>Nama Profile:</strong> Nama unik untuk profile (contoh: "1Jam", "2000", "Unlimited")</li>
                                <li><strong>Rate Limit:</strong> Batas kecepatan upload/download (contoh: "1M/2M", "512k/1M")</li>
                                <li><strong>Session Timeout:</strong> Waktu maksimal sesi (contoh: "1h", "30m", "1d")</li>
                                <li><strong>Idle Timeout:</strong> Timeout saat tidak aktif (contoh: "10m")</li>
                                <li><strong>Shared Users:</strong> Jumlah user yang bisa login bersamaan</li>
                                <li><strong>Address Pool:</strong> Pool IP yang digunakan</li>
                                <li><strong>DNS Name:</strong> Nama DNS khusus</li>
                                <li><strong>Login By:</strong> Metode autentikasi</li>
                            </ul>
                        </div>
                        
                        <div class="guide-card">
                            <h5><i class="fas fa-lightbulb me-2"></i>Contoh Rate Limit</h5>
                            <ul>
                                <li>1M/2M = Upload 1Mbps, Download 2Mbps</li>
                                <li>512k/1M = Upload 512Kbps, Download 1Mbps</li>
                                <li>256k/512k = Upload 256Kbps, Download 512Kbps</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Daftar Profile yang Ada -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-list me-2"></i>Daftar Profile Hotspot</h5>
                        <small class="text-muted">Total: <?= count($profiles) ?> profile</small>
                    </div>
                    <div class="card-body">
                        <?php if ($api_connected && !empty($profiles)): ?>
                            <div class="table-responsive">
                                <table id="profilesTable" class="table table-hover" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th>Nama Profile</th>
                                            <th>Rate Limit</th>
                                            <th>Session Timeout</th>
                                            <th>Idle Timeout</th>
                                            <th>Shared Users</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($profiles as $profile): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($profile['name']) ?></strong></td>
                                            <td><?= htmlspecialchars($profile['rate_limit']) ?: '-' ?></td>
                                            <td><?= htmlspecialchars($profile['session_timeout']) ?: '-' ?></td>
                                            <td><?= htmlspecialchars($profile['idle_timeout']) ?: '-' ?></td>
                                            <td><?= htmlspecialchars($profile['shared_users']) ?: '-' ?></td>
                                            <td>
                                                <form method="post" style="display: inline;" onsubmit="return confirm('Yakin ingin menghapus profile ini?');">
                                                    <input type="hidden" name="action" value="delete_profile">
                                                    <input type="hidden" name="profile_id" value="<?= htmlspecialchars($profile['id']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="fas fa-trash-alt me-1"></i> Hapus
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ($api_connected): ?>
                            <div class="alert alert-info d-flex align-items-center">
                                <i class="fas fa-info-circle me-2"></i>
                                Tidak ada profile hotspot yang ditemukan.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning d-flex align-items-center">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Tidak dapat mengambil data profile. Router tidak terhubung.
                            </div>
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
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#profilesTable').DataTable({
        "language": {
            "lengthMenu": "Tampilkan _MENU_ profile per halaman",
            "zeroRecords": "Tidak ada profile ditemukan",
            "info": "Menampilkan halaman _PAGE_ dari _PAGES_",
            "infoEmpty": "Tidak ada data tersedia",
            "infoFiltered": "(difilter dari _MAX_ total profile)",
            "search": "Cari:",
            "paginate": {
                "first": "Pertama",
                "last": "Terakhir",
                "next": "Selanjutnya",
                "previous": "Sebelumnya"
            }
        },
        "columnDefs": [
            { "orderable": false, "targets": [5] } // Disable sorting for action column
        ]
    });
});
</script>

<?php 
require_once __DIR__ . '/../templates/footer.php';

// Disconnect from Mikrotik if connected
if (isset($api) && $mikrotik_connected) {
    $api->disconnect();
}

ob_end_flush();
?>