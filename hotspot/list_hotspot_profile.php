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

// Handle sync to Mikrotik request
if (isset($_GET['sync_mikrotik'])) {
    if (!$api_connected) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Tidak dapat menyinkronkan ke Mikrotik. Periksa koneksi.'];
    } else {
        try {
            // Get all active profiles from database
            $profiles = $mysqli->query("SELECT * FROM hotspot_profiles WHERE status_profile = 'aktif'");
            
            // Get existing profiles from Mikrotik
            $api->write('/ip/hotspot/user/profile/print');
            $mikrotik_profiles = $api->read();
            $mikrotik_profile_names = array_column($mikrotik_profiles, 'name');
            
            $synced = 0;
            $errors = 0;
            
            while ($profile = $profiles->fetch_assoc()) {
                try {
                    $profile_name = $profile['profile_name'];
                    $rate_limit = $profile['rate_limit_rx'] ? $profile['rate_limit_rx'] . '/' . $profile['rate_limit_tx'] : '';
                    $session_timeout = $profile['session_timeout'] ? $profile['session_timeout'] : '';
                    
                    $profile_data = [
                        'name' => $profile_name,
                        'rate-limit' => $rate_limit,
                        'session-timeout' => $session_timeout,
                        'shared-users' => '1'
                    ];
                    
                    if (in_array($profile_name, $mikrotik_profile_names)) {
                        // Update existing profile
                        $api->comm('/ip/hotspot/user/profile/set', [
                            '.id' => $profile_name,
                            'rate-limit' => $rate_limit,
                            'session-timeout' => $session_timeout
                        ]);
                    } else {
                        // Add new profile
                        $api->comm('/ip/hotspot/user/profile/add', $profile_data);
                    }
                    
                    $synced++;
                } catch (Exception $e) {
                    error_log("Gagal menyinkronkan profile {$profile['nama_profile']}: " . $e->getMessage());
                    $errors++;
                }
            }
            
            if ($errors > 0) {
                $_SESSION['alert'] = ['type' => 'warning', 'message' => "Berhasil menyinkronkan $synced profile, tetapi $errors profile gagal."];
            } else {
                $_SESSION['alert'] = ['type' => 'success', 'message' => "Berhasil menyinkronkan $synced profile ke Mikrotik!"];
            }
            
        } catch (Exception $e) {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => "Gagal menyinkronkan ke Mikrotik: " . $e->getMessage()];
        }
    }
    header("Location: list_hotspot_profile.php");
    exit();
}

// Handle delete request
if (isset($_GET['delete'])) {
    $id_profile = intval($_GET['delete']);
    
    // Check if profile is used by any user
    $check = $mysqli->prepare("SELECT COUNT(*) FROM hotspot_users WHERE id_profile = ?");
    $check->bind_param("i", $id_profile);
    $check->execute();
    $result = $check->get_result();
    $count = $result->fetch_row()[0];
    
    if ($count > 0) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Tidak dapat menghapus profile karena masih digunakan oleh voucher hotspot!'];
    } else {
        try {
            // Get profile name before deleting
            $get_profile = $mysqli->prepare("SELECT profile_name FROM hotspot_profiles WHERE id_profile = ?");
            $get_profile->bind_param("i", $id_profile);
            $get_profile->execute();
            $result = $get_profile->get_result();
            $profile_name = $result->fetch_row()[0] ?? null;
            
            // Delete from database
            $delete = $mysqli->prepare("DELETE FROM hotspot_profiles WHERE id_profile = ?");
            $delete->bind_param("i", $id_profile);
            $delete->execute();
            
            // Try to delete from Mikrotik if connected
            if ($api_connected && $profile_name) {
                try {
                    $api->comm('/ip/hotspot/user/profile/remove', [
                        '.id' => $profile_name
                    ]);
                } catch (Exception $e) {
                    error_log("Gagal menghapus profile dari Mikrotik: " . $e->getMessage());
                }
            }
            
            $_SESSION['alert'] = ['type' => 'success', 'message' => 'Hotspot profile berhasil dihapus!'];
        } catch (Exception $e) {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => "Gagal menghapus hotspot profile: " . $e->getMessage()];
        }
    }
    header("Location: list_hotspot_profile.php");
    exit();
}

// Handle status change
if (isset($_GET['toggle_status'])) {
    $id_profile = intval($_GET['toggle_status']);
    
    try {
        $get = $mysqli->prepare("SELECT status_profile, profile_name FROM hotspot_profiles WHERE id_profile = ?");
        $get->bind_param("i", $id_profile);
        $get->execute();
        $result = $get->get_result();
        $profile = $result->fetch_assoc();
        
        $current_status = $profile['status_profile'];
        $new_status = ($current_status == 'aktif') ? 'nonaktif' : 'aktif';
        
        $update = $mysqli->prepare("UPDATE hotspot_profiles SET status_profile = ? WHERE id_profile = ?");
        $update->bind_param("si", $new_status, $id_profile);
        $update->execute();
        
        $_SESSION['alert'] = ['type' => 'success', 'message' => 'Status hotspot profile berhasil diubah!'];
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => "Gagal mengubah status profile: " . $e->getMessage()];
    }
    header("Location: list_hotspot_profile.php");
    exit();
}

// Get all hotspot profiles
$profiles = [];
try {
    $query = "SELECT * FROM hotspot_profiles ORDER BY status_profile DESC, nama_profile ASC";
    $result = $mysqli->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $profiles[] = $row;
        }
    }
} catch (Exception $e) {
    $_SESSION['alert'] = ['type' => 'danger', 'message' => "Gagal mengambil data profile: " . $e->getMessage()];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>List Profile Hotspot</title>
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

    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
      font-size: 13px;
    }

    .dataTables_wrapper .dataTables_filter input {
      border: 1px solid #ddd;
      padding: 5px 10px;
      border-radius: 3px;
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
            <h1>Daftar Profile Hotspot</h1>
            <p class="page-subtitle">Kelola semua profile hotspot yang tersedia</p>
        </div>
        
        <?php if (isset($_SESSION['alert'])): ?>
            <div class="alert alert-<?= $_SESSION['alert']['type'] ?> d-flex align-items-center">
                <i class="fas fa-<?= $_SESSION['alert']['type'] === 'danger' ? 'exclamation-circle' : ($_SESSION['alert']['type'] === 'success' ? 'check-circle' : 'info-circle') ?> me-2"></i>
                <?= $_SESSION['alert']['message'] ?>
            </div>
            <?php unset($_SESSION['alert']); ?>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Daftar Profile</h5>
                        <div>
                            <a href="profile_hotspot.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus me-1"></i> Tambah Baru
                            </a>
                            <?php if ($api_connected): ?>
                                <a href="?sync_mikrotik=1" class="btn btn-success btn-sm ms-2" onclick="return confirm('Sinkronkan semua profile aktif ke Mikrotik?')">
                                    <i class="fas fa-sync-alt me-1"></i> Sync Mikrotik
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if (!$api_connected): ?>
                            <div class="alert alert-warning d-flex align-items-center">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Tidak terhubung ke Mikrotik. Beberapa fitur sinkronisasi tidak tersedia.
                            </div>
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table id="profilesTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr>
                                        <th width="5%">No</th>
                                        <th>Nama Profile</th>
                                        <th>Nama Mikrotik</th>
                                        <th>Harga</th>
                                        <th>Rate Limit</th>
                                        <th>Session Timeout</th>
                                        <th>Status</th>
                                        <th width="15%">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($profiles)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">Tidak ada data profile hotspot</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($profiles as $index => $profile): ?>
                                            <tr>
                                                <td><?= $index + 1 ?></td>
                                                <td><strong><?= htmlspecialchars($profile['nama_profile']) ?></strong></td>
                                                <td><code><?= htmlspecialchars($profile['profile_name']) ?></code></td>
                                                <td>Rp <?= number_format($profile['harga'], 0, ',', '.') ?></td>
                                                <td>
                                                    <?= $profile['rate_limit_rx'] ? htmlspecialchars($profile['rate_limit_rx']) : '-' ?> / 
                                                    <?= $profile['rate_limit_tx'] ? htmlspecialchars($profile['rate_limit_tx']) : '-' ?>
                                                </td>
                                                <td><?= $profile['session_timeout'] ? htmlspecialchars($profile['session_timeout']) : '<em>Unlimited</em>' ?></td>
                                                <td>
                                                    <span class="badge bg-<?= ($profile['status_profile'] == 'aktif') ? 'success' : 'danger' ?>">
                                                        <?= ucfirst($profile['status_profile']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="edit_hotspot_profile.php?id=<?= $profile['id_profile'] ?>" class="btn btn-info" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="?toggle_status=<?= $profile['id_profile'] ?>" class="btn btn-<?= ($profile['status_profile'] == 'aktif') ? 'warning' : 'success' ?>" title="<?= ($profile['status_profile'] == 'aktif') ? 'Nonaktifkan' : 'Aktifkan' ?>" onclick="return confirm('Ubah status profile ini?')">
                                                            <i class="fas fa-<?= ($profile['status_profile'] == 'aktif') ? 'times' : 'check' ?>"></i>
                                                        </a>
                                                        <a href="?delete=<?= $profile['id_profile'] ?>" class="btn btn-danger" title="Hapus" onclick="return confirm('Apakah Anda yakin ingin menghapus profile ini?')">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </a>
                                                    </div>
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
        </div>
        
        <!-- Stats Cards -->
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Total Profile</h6>
                                <p class="text-muted mb-0">Semua profile</p>
                            </div>
                            <div class="bg-primary bg-opacity-10 p-3 rounded">
                                <i class="fas fa-list text-primary"></i>
                            </div>
                        </div>
                        <h3 class="mt-3 mb-0"><?= count($profiles) ?></h3>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Profile Aktif</h6>
                                <p class="text-muted mb-0">Tersedia untuk digunakan</p>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded">
                                <i class="fas fa-check-circle text-success"></i>
                            </div>
                        </div>
                        <h3 class="mt-3 mb-0"><?= count(array_filter($profiles, fn($p) => $p['status_profile'] == 'aktif')) ?></h3>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Status Mikrotik</h6>
                                <p class="text-muted mb-0">Koneksi router</p>
                            </div>
                            <div class="<?= $api_connected ? 'bg-success' : 'bg-danger' ?> bg-opacity-10 p-3 rounded">
                                <i class="fas fa-<?= $api_connected ? 'check' : 'times' ?> text-<?= $api_connected ? 'success' : 'danger' ?>"></i>
                            </div>
                        </div>
                        <h5 class="mt-3 mb-0">
                            <span class="badge bg-<?= $api_connected ? 'success' : 'danger' ?>">
                                <?= $api_connected ? 'Connected' : 'Disconnected' ?>
                            </span>
                        </h5>
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
            { "orderable": false, "targets": [7] } // Disable sorting for action column
        ]
    });
});
</script>

<?php 
require_once __DIR__ . '/../templates/footer.php';

// Disconnect from Mikrotik if connected
if (isset($api) && $api_connected) {
    $api->disconnect();
}

ob_end_flush();
?>