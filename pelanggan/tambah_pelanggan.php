<?php
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load configurations
require_once __DIR__ . '/../config/config_mikrotik.php';
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

$error = '';
$success = '';
$paket_list = [];
$odp_list = [];

// Get internet packages
try {
    $query = "SELECT id_paket, nama_paket, profile_name, harga, 
              CONCAT(rate_limit_rx, '/', rate_limit_tx) as kecepatan 
              FROM paket_internet WHERE status_paket = 'aktif' ORDER BY harga ASC";
    $result = $mysqli->query($query);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $paket_list[] = $row;
        }
    }
} catch (Exception $e) {
    $error = "Gagal mengambil data paket: " . $e->getMessage();
}

// Get ODP data (simplified - removed complex JOINs)
try {
    $query = "SELECT id, nama_odp, lokasi FROM ftth_odp WHERE status = 'active' ORDER BY nama_odp";
    $result = $mysqli->query($query);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $odp_list[] = $row;
        }
    }
} catch (Exception $e) {
    $error = "Gagal mengambil data ODP: " . $e->getMessage();
}

// Generate invoice ID
function generateTagihanId() {
    global $mysqli;
    
    $prefix = 'INV' . date('Ym');
    
    $query = "SELECT id_tagihan FROM tagihan 
              WHERE id_tagihan LIKE '{$prefix}%' 
              ORDER BY id_tagihan DESC LIMIT 1";
    $result = $mysqli->query($query);
    
    if ($result && $result->num_rows > 0) {
        $last_id = $result->fetch_assoc()['id_tagihan'];
        $last_number = (int)substr($last_id, -4);
        $new_number = $last_number + 1;
    } else {
        $new_number = 1;
    }
    
    return $prefix . sprintf('%04d', $new_number);
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nama_pelanggan = trim($_POST['nama_pelanggan'] ?? '');
    $alamat_pelanggan = trim($_POST['alamat_pelanggan'] ?? '');
    $telepon_pelanggan = trim($_POST['telepon_pelanggan'] ?? '');
    $id_paket = (int)($_POST['id_paket'] ?? 0);
    $odp_id = (int)($_POST['odp_id'] ?? 0);
    $tgl_daftar = $_POST['tgl_daftar'] ?? date('Y-m-d');
    $tgl_expired = $_POST['tgl_expired'] ?? '';
    $mikrotik_username = trim($_POST['mikrotik_username'] ?? '');
    $mikrotik_password = trim($_POST['mikrotik_password'] ?? '');

    // Validation
    if (empty($nama_pelanggan) || empty($alamat_pelanggan) || empty($telepon_pelanggan) || 
        $id_paket <= 0 || $odp_id <= 0 || empty($tgl_daftar) || empty($tgl_expired) ||
        empty($mikrotik_username) || empty($mikrotik_password)) {
        $error = "Semua field wajib diisi!";
    } else {
        $mysqli->begin_transaction();
        
        try {
            // Check if username exists
            $check_username = $mysqli->prepare("SELECT id_pelanggan FROM data_pelanggan WHERE mikrotik_username = ?");
            $check_username->bind_param("s", $mikrotik_username);
            $check_username->execute();
            $result_check = $check_username->get_result();
            
            if ($result_check->num_rows > 0) {
                throw new Exception("Username {$mikrotik_username} sudah digunakan!");
            }
            
            // Get package data
            $paket_query = $mysqli->prepare("SELECT profile_name, harga FROM paket_internet WHERE id_paket = ?");
            $paket_query->bind_param("i", $id_paket);
            $paket_query->execute();
            $paket_result = $paket_query->get_result();
            
            if ($paket_result->num_rows == 0) {
                throw new Exception("Paket tidak ditemukan!");
            }
            
            $paket_data = $paket_result->fetch_assoc();
            $profile_name = $paket_data['profile_name'];
            $harga_paket = $paket_data['harga'];
            
            // Create Mikrotik PPPoE user
            if (class_exists('RouterosAPI')) {
                $api = new RouterosAPI();
                if ($api->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass)) {
                    $api->comm('/ppp/secret/add', [
                        'name' => $mikrotik_username,
                        'password' => $mikrotik_password,
                        'profile' => $profile_name,
                        'service' => 'pppoe',
                        'comment' => "Pelanggan: {$nama_pelanggan}"
                    ]);
                    $api->disconnect();
                } else {
                    throw new Exception("Gagal konek ke Mikrotik");
                }
            }
            
            // Save customer to database
            $insert_query = "INSERT INTO data_pelanggan 
                (nama_pelanggan, alamat_pelanggan, telepon_pelanggan, 
                 id_paket, odp_id, tgl_daftar, tgl_expired, mikrotik_username, mikrotik_password, 
                 mikrotik_profile, sync_mikrotik, last_sync) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'yes', NOW())";
            
            $stmt = $mysqli->prepare($insert_query);
            $stmt->bind_param("sssiisssss", 
                $nama_pelanggan, $alamat_pelanggan, $telepon_pelanggan,
                $id_paket, $odp_id, $tgl_daftar, $tgl_expired, $mikrotik_username, $mikrotik_password,
                $profile_name
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Gagal simpan pelanggan: " . $mysqli->error);
            }
            
            $pelanggan_id = $mysqli->insert_id;
            
            // Create first invoice
            $id_tagihan = generateTagihanId();
            $bulan_daftar = (int)date('m', strtotime($tgl_daftar));
            $tahun_daftar = (int)date('Y', strtotime($tgl_daftar));
            $deskripsi_tagihan = "Tagihan perdana pelanggan {$nama_pelanggan}";
            
            $tagihan_query = "INSERT INTO tagihan 
                (id_tagihan, id_pelanggan, bulan_tagihan, tahun_tagihan, jumlah_tagihan, 
                 tgl_jatuh_tempo, status_tagihan, deskripsi, auto_generated, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'belum_bayar', ?, 'no', NOW())";
            
            $tagihan_stmt = $mysqli->prepare($tagihan_query);
            $tagihan_stmt->bind_param("siiiiss", 
                $id_tagihan, $pelanggan_id, $bulan_daftar, $tahun_daftar, 
                $harga_paket, $tgl_expired, $deskripsi_tagihan
            );
            
            if (!$tagihan_stmt->execute()) {
                throw new Exception("Gagal membuat tagihan: " . $mysqli->error);
            }
            
            $mysqli->commit();
            
            $success = "Pelanggan {$nama_pelanggan} berhasil ditambahkan!";
            
            // Clear form
            $_POST = array();
            
            echo "<script>setTimeout(() => window.location.href = 'data_pelanggan.php', 3000);</script>";
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>

<style>
:root { --primary: #1ABB9C; --success: #26B99A; --info: #23C6C8; --warning: #F8AC59; --danger: #ED5565; --secondary: #73879C; --dark: #2A3F54; --light: #F7F7F7; }
body { background-color: #F7F7F7; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; color: #73879C; }
.main-content { padding: 20px; margin-left: 220px; min-height: calc(100vh - 52px); transition: margin-left 0.3s ease; }
.sidebar-collapsed .main-content { margin-left: 60px; }
@media (max-width: 992px) { .main-content { margin-left: 0; padding: 15px; } .sidebar-collapsed .main-content { margin-left: 0; } }
.page-title { margin-bottom: 30px; }
.page-title h1 { font-size: 24px; color: var(--dark); margin: 0; font-weight: 400; }
.page-title .page-subtitle { color: var(--secondary); font-size: 13px; margin: 5px 0 0 0; }
.card { border: none; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); margin-bottom: 20px; background: white; border-radius: 6px; }
.card-header { background-color: white; border-bottom: 1px solid #e9ecef; padding: 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 6px 6px 0 0; }
.card-header h5 { font-size: 16px; font-weight: 500; color: var(--dark); margin: 0; }
.card-body { padding: 20px; }
.alert { padding: 12px 20px; font-size: 14px; border: none; margin-bottom: 20px; border-radius: 4px; }
.alert-danger { background-color: rgba(237, 85, 101, 0.1); color: var(--danger); border-left: 4px solid var(--danger); }
.alert-success { background-color: rgba(38, 185, 154, 0.1); color: var(--success); border-left: 4px solid var(--success); }
.alert-warning { background-color: rgba(248, 172, 89, 0.1); color: var(--warning); border-left: 4px solid var(--warning); }
.alert-info { background-color: rgba(35, 198, 200, 0.1); color: var(--info); border-left: 4px solid var(--info); }
.btn { font-size: 14px; padding: 8px 16px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s ease-in-out; text-decoration: none; display: inline-block; text-align: center; border-radius: 4px; }
.btn-primary { background-color: var(--primary); color: white; }
.btn-primary:hover { background-color: #169F85; color: white; }
.btn-secondary { background-color: var(--secondary); color: white; }
.btn-secondary:hover { background-color: #5a6c7d; color: white; }
.btn-info { background-color: var(--info); color: white; }
.btn-info:hover { background-color: #1aa1a3; color: white; }
.btn-warning { background-color: var(--warning); color: white; }
.btn-warning:hover { background-color: #e09b3d; color: white; }
.btn-success { background-color: var(--success); color: white; }
.btn-success:hover { background-color: #1e9a81; color: white; }
.btn-danger { background-color: var(--danger); color: white; }
.btn-danger:hover { background-color: #d63449; color: white; }
.btn-sm { padding: 6px 12px; font-size: 12px; }
.form-section { margin-bottom: 30px; }
.form-section h6 { font-size: 14px; font-weight: 600; color: var(--dark); margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid var(--primary); }
.form-label { font-size: 13px; font-weight: 500; color: var(--dark); margin-bottom: 5px; }
.required { color: var(--danger); }
.form-control, .form-select { border: 1px solid #ddd; border-radius: 4px; padding: 8px 12px; font-size: 14px; transition: border-color 0.15s ease-in-out; }
.form-control:focus, .form-select:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 0.1rem rgba(26, 187, 156, 0.25); }
.row { display: flex; flex-wrap: wrap; margin-left: -15px; margin-right: -15px; }
.col-md-6 { flex: 0 0 50%; max-width: 50%; padding-left: 15px; padding-right: 15px; }
.mb-3 { margin-bottom: 1rem; }
.d-flex { display: flex; }
.gap-2 { gap: 0.5rem; }
.me-1 { margin-right: 0.25rem; }
.me-2 { margin-right: 0.5rem; }
.close { background: none; border: none; font-size: 20px; cursor: pointer; color: #aaa; }
.close:hover { color: #000; }
@media (max-width: 768px) { 
    .col-md-6 { flex: 0 0 100%; max-width: 100%; } 
    .d-flex { flex-direction: column; }
    .gap-2 { gap: 0.5rem; }
}
</style>

<main class="main-content" id="mainContent">
    <div class="page-title">
        <h1>Tambah Pelanggan Baru</h1>
        <div class="page-subtitle">Form pendaftaran pelanggan FTTH baru</div>
    </div>

    <!-- Alert Messages -->
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
        <i class="fa fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
        <i class="fa fa-check"></i> <?php echo htmlspecialchars($success); ?>
    </div>
    <?php endif; ?>

    <form method="post">
        <!-- Data Pelanggan -->
        <div class="card">
            <div class="card-header">
                <h5><i class="fa fa-user me-2"></i>Data Pelanggan</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nama Lengkap <span class="required">*</span></label>
                        <input type="text" class="form-control" name="nama_pelanggan"
                               value="<?= htmlspecialchars($_POST['nama_pelanggan'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">No. Telepon <span class="required">*</span></label>
                        <input type="tel" class="form-control" name="telepon_pelanggan"
                               value="<?= htmlspecialchars($_POST['telepon_pelanggan'] ?? '') ?>" required>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Alamat Lengkap <span class="required">*</span></label>
                    <textarea class="form-control" name="alamat_pelanggan" rows="3" required><?= htmlspecialchars($_POST['alamat_pelanggan'] ?? '') ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tanggal Daftar <span class="required">*</span></label>
                        <input type="date" class="form-control" name="tgl_daftar"
                               value="<?= $_POST['tgl_daftar'] ?? date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tanggal Expired <span class="required">*</span></label>
                        <input type="date" class="form-control" name="tgl_expired"
                               value="<?= $_POST['tgl_expired'] ?? date('Y-m-d', strtotime('+30 days')) ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <!-- Paket & Lokasi -->
        <div class="card">
            <div class="card-header">
                <h5><i class="fa fa-wifi me-2"></i>Paket & Lokasi</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Paket Internet <span class="required">*</span></label>
                        <select class="form-select" name="id_paket" required>
                            <option value="">-- Pilih Paket --</option>
                            <?php foreach ($paket_list as $paket): ?>
                                <option value="<?= $paket['id_paket'] ?>" 
                                        <?= (($_POST['id_paket'] ?? '') == $paket['id_paket']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($paket['nama_paket']) ?> - <?= htmlspecialchars($paket['kecepatan']) ?> - Rp <?= number_format($paket['harga']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">ODP <span class="required">*</span></label>
                        <select class="form-select" name="odp_id" required>
                            <option value="">-- Pilih ODP --</option>
                            <?php foreach ($odp_list as $odp): ?>
                                <option value="<?= $odp['id'] ?>" 
                                        <?= (($_POST['odp_id'] ?? '') == $odp['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($odp['nama_odp']) ?> - <?= htmlspecialchars($odp['lokasi']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Konfigurasi PPPoE -->
        <div class="card">
            <div class="card-header">
                <h5><i class="fa fa-cogs me-2"></i>Konfigurasi PPPoE</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Username PPPoE <span class="required">*</span></label>
                        <input type="text" class="form-control" name="mikrotik_username"
                               value="<?= htmlspecialchars($_POST['mikrotik_username'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Password PPPoE <span class="required">*</span></label>
                        <input type="text" class="form-control" name="mikrotik_password"
                               value="<?= htmlspecialchars($_POST['mikrotik_password'] ?? '') ?>" required>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="d-flex gap-2">
            <a href="data_pelanggan.php" class="btn btn-secondary">
                <i class="fa fa-arrow-left me-1"></i> Kembali
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-save me-1"></i> Simpan Pelanggan
            </button>
        </div>
    </form>
</main>

<script>
// Auto set expired date 30 days from registration
document.querySelector('input[name="tgl_daftar"]').addEventListener('change', function() {
    const tglDaftar = new Date(this.value);
    if (!isNaN(tglDaftar.getTime())) {
        tglDaftar.setDate(tglDaftar.getDate() + 30);
        const expired = tglDaftar.toISOString().split('T')[0];
        document.querySelector('input[name="tgl_expired"]').value = expired;
    }
});

// Form validation
document.querySelector('form').addEventListener('submit', function(e) {
    const username = document.querySelector('input[name="mikrotik_username"]').value;
    const password = document.querySelector('input[name="mikrotik_password"]').value;
    
    if (username.length < 4) {
        alert('Username minimal 4 karakter!');
        e.preventDefault();
        return;
    }
    
    if (password.length < 6) {
        alert('Password minimal 6 karakter!');
        e.preventDefault();
        return;
    }
    
    if (!confirm('Yakin ingin menambahkan pelanggan ini?')) {
        e.preventDefault();
    }
});
</script>

<?php 
require_once __DIR__ . '/../templates/footer.php';
ob_end_flush();
?>