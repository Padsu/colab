<?php
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configurations
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../config/config_mikrotik.php';

// Status koneksi
$api_connected = $mikrotik_connected;
$router_name = "Main Router";
$router_ip = $mikrotik_ip;

// ================================
// HANDLE DELETE PROFILE - FIXED
// ================================

if (isset($_GET['delete'])) {
    $id_profile = intval($_GET['delete']);
    
    try {
        // Get profile name before deleting
        $get_profile = $mysqli->prepare("SELECT nama_profile FROM mikrotik_hotspot_profiles WHERE id_profile = ?");
        $get_profile->bind_param("i", $id_profile);
        $get_profile->execute();
        $result = $get_profile->get_result();
        $profile_data = $result->fetch_assoc();
        
        if (!$profile_data) {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Profile tidak ditemukan!'];
        } else {
            $profile_name = $profile_data['nama_profile'];
            
            // Check if profile is used by any user (optional check - adjust table name as needed)
            // Uncomment and modify if you have a users table
            /*
            $check = $mysqli->prepare("SELECT COUNT(*) as count FROM hotspot_users WHERE profile = ?");
            $check->bind_param("s", $profile_name);
            $check->execute();
            $check_result = $check->get_result();
            $count = $check_result->fetch_assoc()['count'];
            
            if ($count > 0) {
                $_SESSION['alert'] = ['type' => 'danger', 'message' => "Tidak dapat menghapus profile '$profile_name' karena masih digunakan oleh $count user!"];
                header("Location: list_profile.php");
                exit();
            }
            */
            
            // Try to delete from MikroTik first if connected
            $mikrotik_deleted = false;
            if ($api_connected) {
                try {
                    // Get profile ID from MikroTik
                    $mikrotik_profiles = $api->comm('/ip/hotspot/user/profile/print', array("?name" => $profile_name));
                    
                    if (!empty($mikrotik_profiles)) {
                        $mikrotik_id = $mikrotik_profiles[0]['.id'];
                        $api->comm('/ip/hotspot/user/profile/remove', array(".id" => $mikrotik_id));
                        $mikrotik_deleted = true;
                    }
                } catch (Exception $e) {
                    error_log("Gagal menghapus profile dari MikroTik: " . $e->getMessage());
                    // Continue with database deletion even if MikroTik deletion fails
                }
            }
            
            // Delete from database
            $delete = $mysqli->prepare("DELETE FROM mikrotik_hotspot_profiles WHERE id_profile = ?");
            $delete->bind_param("i", $id_profile);
            
            if ($delete->execute()) {
                if ($delete->affected_rows > 0) {
                    $message = "Profile '$profile_name' berhasil dihapus dari database";
                    if ($mikrotik_deleted) {
                        $message .= " dan MikroTik!";
                    } elseif ($api_connected) {
                        $message .= ", tetapi gagal dihapus dari MikroTik.";
                    } else {
                        $message .= ". MikroTik tidak terhubung.";
                    }
                    $_SESSION['alert'] = ['type' => 'success', 'message' => $message];
                } else {
                    $_SESSION['alert'] = ['type' => 'warning', 'message' => 'Profile tidak ditemukan di database.'];
                }
            } else {
                $_SESSION['alert'] = ['type' => 'danger', 'message' => "Gagal menghapus profile dari database: " . $delete->error];
            }
        }
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => "Error saat menghapus profile: " . $e->getMessage()];
    }
    
    header("Location: list_profile.php");
    exit();
}

// ================================
// HANDLE SYNC TO MIKROTIK
// ================================

if (isset($_GET['sync_mikrotik'])) {
    if (!$api_connected) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Tidak dapat menyinkronkan ke Mikrotik. Periksa koneksi.'];
    } else {
        try {
            // Get all active profiles from database
            $profiles = $mysqli->query("SELECT * FROM mikrotik_hotspot_profiles WHERE status_profile = 'aktif'");
            
            // Get existing profiles from Mikrotik
            $mikrotik_profiles = $api->comm('/ip/hotspot/user/profile/print');
            $mikrotik_profile_names = array_column($mikrotik_profiles, 'name');
            
            $synced = 0;
            $errors = 0;
            
            while ($profile = $profiles->fetch_assoc()) {
                try {
                    $profile_name = $profile['nama_profile'];
                    $rate_limit = $profile['rate_limit_rx_tx'] ? $profile['rate_limit_rx_tx'] : '';
                    
                    $profile_data = [
                        'name' => $profile_name,
                        'shared-users' => $profile['shared_users'] ?? '1'
                    ];
                    
                    // Add rate limit if exists
                    if (!empty($rate_limit)) {
                        $profile_data['rate-limit'] = $rate_limit;
                    }
                    
                    // Add optional fields
                    if (!empty($profile['mac_cookie_timeout'])) {
                        $profile_data['mac-cookie-timeout'] = $profile['mac_cookie_timeout'];
                    }
                    if (!empty($profile['address_list'])) {
                        $profile_data['address-list'] = $profile['address_list'];
                    }
                    if (!empty($profile['queue'])) {
                        $profile_data['parent-queue'] = $profile['queue'];
                    }
                    
                    // Add lock user script if enabled
                    if ($profile['lock_user_enabled'] === 'yes') {
                        $profile_data['on-login'] = '[:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]';
                    }
                    
                    if (in_array($profile_name, $mikrotik_profile_names)) {
                        // Update existing profile
                        $existing_profile = array_filter($mikrotik_profiles, fn($p) => $p['name'] === $profile_name);
                        $existing_profile = reset($existing_profile);
                        $profile_data['.id'] = $existing_profile['.id'];
                        $api->comm('/ip/hotspot/user/profile/set', $profile_data);
                    } else {
                        // Add new profile
                        $api->comm('/ip/hotspot/user/profile/add', $profile_data);
                    }
                    
                    // Update sync status in database
                    $update_stmt = $mysqli->prepare("UPDATE mikrotik_hotspot_profiles SET sync_mikrotik = 'yes', last_sync = NOW() WHERE id_profile = ?");
                    $update_stmt->bind_param("i", $profile['id_profile']);
                    $update_stmt->execute();
                    
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
    header("Location: list_profile.php");
    exit();
}

// ================================
// HANDLE STATUS CHANGE
// ================================

if (isset($_GET['toggle_status'])) {
    $id_profile = intval($_GET['toggle_status']);
    
    try {
        $get = $mysqli->prepare("SELECT status_profile, nama_profile FROM mikrotik_hotspot_profiles WHERE id_profile = ?");
        $get->bind_param("i", $id_profile);
        $get->execute();
        $result = $get->get_result();
        $profile = $result->fetch_assoc();
        
        if ($profile) {
            $current_status = $profile['status_profile'];
            $new_status = ($current_status == 'aktif') ? 'nonaktif' : 'aktif';
            
            $update = $mysqli->prepare("UPDATE mikrotik_hotspot_profiles SET status_profile = ?, sync_mikrotik = 'no' WHERE id_profile = ?");
            $update->bind_param("si", $new_status, $id_profile);
            $update->execute();
            
            $_SESSION['alert'] = ['type' => 'success', 'message' => "Status profile '{$profile['nama_profile']}' berhasil diubah menjadi $new_status!"];
        } else {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Profile tidak ditemukan!'];
        }
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => "Gagal mengubah status profile: " . $e->getMessage()];
    }
    header("Location: list_profile.php");
    exit();
}

// ================================
// GET ALL PROFILES DATA
// ================================

$profiles = [];
try {
    $query = "SELECT * FROM mikrotik_hotspot_profiles ORDER BY status_profile DESC, nama_profile ASC";
    $result = $mysqli->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $profiles[] = $row;
        }
    }
} catch (Exception $e) {
    $_SESSION['alert'] = ['type' => 'danger', 'message' => "Gagal mengambil data profile: " . $e->getMessage()];
}

// Include templates AFTER processing
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>List Profile Hotspot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
    :root {
      --primary: #1ABB9C; --success: #26B99A; --info: #23C6C8; --warning: #F8AC59;
      --danger: #ED5565; --secondary: #73879C; --dark: #2A3F54; --light: #F7F7F7;
    }
    body { background-color: #F7F7F7; font-family: "Segoe UI", Roboto, Arial, sans-serif; color: #73879C; }
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
    .badge { font-weight: 500; font-size: 12px; padding: 5px 8px; }
    .badge-success { background-color: var(--success); }
    .badge-danger { background-color: var(--danger); }
    .badge-primary { background-color: var(--primary); }
    .badge-secondary { background-color: var(--secondary); }
    .badge-warning { background-color: var(--warning); }
    .dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_paginate { font-size: 13px; }
    .dataTables_wrapper .dataTables_filter input { border: 1px solid #ddd; padding: 5px 10px; border-radius: 3px; }
    table.dataTable { margin-top: 10px !important; margin-bottom: 15px !important; border-collapse: collapse !important; }
    table.dataTable thead th { border-bottom: 1px solid #ddd; font-weight: 500; font-size: 13px; color: var(--dark); background-color: #f8f9fa; }
    table.dataTable tbody td { font-size: 13px; vertical-align: middle; padding: 12px 15px; border-top: 1px solid #f1f1f1; }
    .btn { border-radius: 3px; font-size: 13px; padding: 8px 15px; font-weight: 500; }
    .btn-primary { background-color: var(--primary); border-color: var(--primary); }
    .btn-primary:hover { background-color: #169F85; border-color: #169F85; }
    .btn-success { background-color: var(--success); border-color: var(--success); }
    .btn-success:hover { background-color: #1e9e8a; border-color: #1e9e8a; }
    .btn-warning { background-color: var(--warning); border-color: var(--warning); }
    .btn-danger { background-color: var(--danger); border-color: var(--danger); }
    .btn-info { background-color: var(--info); border-color: var(--info); }
    .btn-sm { padding: 5px 10px; font-size: 12px; }
    .btn-group-sm > .btn, .btn-sm { padding: 5px 10px; font-size: 12px; }
    .table-responsive { border-radius: 5px; overflow: hidden; }
    
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
    }
    </style>
</head>
<body>
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
        
        <!-- Stats Cards -->
        <div class="row mb-4">
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
                                <h6 class="mb-0">Lock User Enabled</h6>
                                <p class="text-muted mb-0">Profile dengan lock</p>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded">
                                <i class="fas fa-lock text-warning"></i>
                            </div>
                        </div>
                        <h3 class="mt-3 mb-0"><?= count(array_filter($profiles, fn($p) => ($p['lock_user_enabled'] ?? 'no') == 'yes')) ?></h3>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Daftar Profile</h5>
                        <div>
                            <a href="add_profile.php" class="btn btn-primary btn-sm">
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
                                        <th width="15%">Nama Profile</th>
                                        <th width="10%">Harga</th>
                                        <th>Rate Limit</th>
                                        <th>Shared Users</th>
                                        <th>Queue</th>
                                        <th>Lock User</th>
                                        <th>Status</th>
                                        <th>Sync Status</th>
                                        <th width="10%">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($profiles)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4">
                                                <i class="fas fa-info-circle text-muted me-2"></i>
                                                Tidak ada data profile hotspot. <a href="add_profile.php">Tambah profile baru</a>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($profiles as $index => $profile): ?>
                                            <tr>
                                                <td><?= $index + 1 ?></td>
                                                <td><strong><?= htmlspecialchars($profile['nama_profile']) ?></strong></td>
                                                <td>Rp <?= number_format($profile['selling_price'] ?? 0, 0, ',', '.') ?></td>
                                                <td><?= $profile['rate_limit_rx_tx'] ? htmlspecialchars($profile['rate_limit_rx_tx']) : '-' ?></td>
                                                <td><?= htmlspecialchars($profile['shared_users'] ?? '1') ?></td>
                                                <td><?= $profile['queue'] ? htmlspecialchars($profile['queue']) : '-' ?></td>
                                                <td>
                                                    <?php 
                                                    $lock_enabled = $profile['lock_user_enabled'] ?? 'no';
                                                    $lock_badge = $lock_enabled === 'yes' ? 'badge-warning' : 'badge-secondary';
                                                    $lock_text = $lock_enabled === 'yes' ? 'Enabled' : 'Disabled';
                                                    ?>
                                                    <span class="badge <?= $lock_badge ?>">
                                                        <i class="fas fa-<?= $lock_enabled === 'yes' ? 'lock' : 'unlock' ?> me-1"></i>
                                                        <?= $lock_text ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= ($profile['status_profile'] == 'aktif') ? 'success' : 'danger' ?>">
                                                        <?= ucfirst($profile['status_profile']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= ($profile['sync_mikrotik'] == 'yes') ? 'success' : 'warning' ?>">
                                                        <?= ($profile['sync_mikrotik'] == 'yes') ? 'Synced' : 'Not Synced' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="?delete=<?= $profile['id_profile'] ?>" class="btn btn-danger" title="Hapus" onclick="return confirmDelete('<?= htmlspecialchars($profile['nama_profile']) ?>')">
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

        <!-- Connection Status Card -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-server me-2"></i>Status Koneksi & Sistem</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-network-wired me-2"></i>Status MikroTik</h6>
                                <?php if ($api_connected): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle me-2"></i>
                                        <strong>Terhubung</strong> ke <?= $router_ip ?> (<?= $router_name ?>)
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-danger">
                                        <i class="fas fa-times-circle me-2"></i>
                                        <strong>Tidak terhubung</strong> ke <?= $router_ip ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-database me-2"></i>Status Database</h6>
                                <?php if ($mysqli): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle me-2"></i>
                                        <strong>Terhubung</strong> ke database
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-danger">
                                        <i class="fas fa-times-circle me-2"></i>
                                        <strong>Tidak terhubung</strong> ke database
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

    <script>
    $(document).ready(function() {
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
                { "orderable": false, "targets": [9] } // Disable sorting for action column
            ],
            "order": [[8, "desc"], [1, "asc"]], // Sort by status then name
            "pageLength": 25,
            "responsive": true
        });

        // Auto refresh every 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000);
    });

    // Confirm delete function - FIXED
    function confirmDelete(profileName) {
        return confirm(`Apakah Anda yakin ingin menghapus profile "${profileName}"?\n\nProfile akan dihapus dari database dan MikroTik (jika terhubung).`);
    }

    // Confirm status toggle - FIXED
    function confirmToggle(profileName, currentStatus) {
        const newStatus = currentStatus === 'aktif' ? 'nonaktif' : 'aktif';
        return confirm(`Ubah status profile "${profileName}" menjadi ${newStatus}?`);
    }

    // Show toast notification
    function showToast(message, type = 'success') {
        const toast = `
            <div class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        // Create toast container if it doesn't exist
        let toastContainer = document.getElementById('toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            document.body.appendChild(toastContainer);
        }
        
        toastContainer.insertAdjacentHTML('beforeend', toast);
        const toastElement = toastContainer.lastElementChild;
        const bsToast = new bootstrap.Toast(toastElement);
        bsToast.show();
        
        // Remove toast element after it's hidden
        toastElement.addEventListener('hidden.bs.toast', function() {
            toastElement.remove();
        });
    }
    </script>

<?php
require_once __DIR__ . '/../templates/footer.php';

if (isset($api) && $api_connected) {
    $api->disconnect();
}

if (isset($mysqli) && $mysqli) {
    $mysqli->close();
}

ob_end_flush();
?>