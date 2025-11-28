<?php
ob_start();

// Memuat file konfigurasi database, mikrotik, header, dan sidebar
require_once __DIR__ . '/../config/config_mikrotik.php';
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Mengambil ID paket dari URL, jika tidak ada atau tidak valid, redirect
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = ''; // Variabel untuk menyimpan pesan error
$ip_pools = []; // Array untuk menyimpan IP Pool dari Mikrotik
$mikrotik_connected = false; // Status koneksi MikroTik

// Memastikan ID paket valid
if ($id <= 0) {
    $_SESSION['error_message'] = 'ID paket tidak valid!';
    header('Location: list_paket_pppoe.php');
    exit;
}

// Mengambil data paket yang akan diedit dari database
$query = "SELECT * FROM paket_internet WHERE id_paket = $id";
$result = $mysqli->query($query);

// Memeriksa apakah paket ditemukan
if ($result && $result->num_rows > 0) {
    $paket = $result->fetch_assoc();
} else {
    $_SESSION['error_message'] = 'Paket tidak ditemukan!';
    header('Location: list_paket_pppoe.php');
    exit;
}

// Ambil data IP Pool dari Mikrotik
$api = new RouterosAPI();
try {
    if ($api->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass)) {
        $mikrotik_connected = true;
        
        $pools = $api->comm('/ip/pool/print');
        foreach ($pools as $pool) {
            $ip_pools[] = [
                'name' => $pool['name'],
                'ranges' => $pool['ranges']
            ];
        }
    } else {
        throw new Exception("Gagal konek ke Mikrotik: " . $api->error_str);
    }
} catch (Exception $e) {
    $error = "Warning: Gagal ambil IP Pool dari Mikrotik: " . $e->getMessage() . ". Form masih bisa digunakan dengan input manual.";
}

// Memproses data yang dikirimkan melalui form
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nama_paket = $mysqli->real_escape_string(trim($_POST['nama_paket']));
    $harga = (float)$_POST['harga'];
    $local_address = $mysqli->real_escape_string(trim($_POST['local_address']));
    $remote_address = $mysqli->real_escape_string(trim($_POST['remote_address']));
    $rate_limit_rx = $mysqli->real_escape_string(strtoupper(trim($_POST['rate_limit_rx'])));
    $rate_limit_tx = $mysqli->real_escape_string(strtoupper(trim($_POST['rate_limit_tx'])));
    $burst_limit_rx = $mysqli->real_escape_string(strtoupper(trim($_POST['burst_limit_rx'])));
    $burst_limit_tx = $mysqli->real_escape_string(strtoupper(trim($_POST['burst_limit_tx'])));
    $burst_threshold_rx = $mysqli->real_escape_string(strtoupper(trim($_POST['burst_threshold_rx'])));
    $burst_threshold_tx = $mysqli->real_escape_string(strtoupper(trim($_POST['burst_threshold_tx'])));
    $burst_time_rx = $mysqli->real_escape_string(trim($_POST['burst_time_rx']));
    $burst_time_tx = $mysqli->real_escape_string(trim($_POST['burst_time_tx']));

    // Validasi input wajib diisi
    if (empty($nama_paket) || $harga <= 0 || empty($local_address) || empty($remote_address) || empty($rate_limit_rx) || empty($rate_limit_tx)) {
        $error = "Semua field dengan tanda (*) wajib diisi!";
    } else {
        $profile_name = strtolower(str_replace(' ', '-', $nama_paket));
        
        // Mulai transaksi database
        $mysqli->begin_transaction();
        
        try {
            // Update data paket di database
            $stmt = $mysqli->prepare("UPDATE paket_internet SET 
                nama_paket = ?,
                profile_name = ?,
                harga = ?,
                local_address = ?,
                remote_address = ?,
                rate_limit_rx = ?,
                rate_limit_tx = ?,
                burst_limit_rx = ?,
                burst_limit_tx = ?,
                burst_threshold_rx = ?,
                burst_threshold_tx = ?,
                burst_time_rx = ?,
                burst_time_tx = ?,
                last_sync = NOW()
                WHERE id_paket = ?");
            
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
            }
            
            $stmt->bind_param("ssdssssssssssi", 
                $nama_paket, $profile_name, $harga, $local_address, $remote_address,
                $rate_limit_rx, $rate_limit_tx, $burst_limit_rx, $burst_limit_tx,
                $burst_threshold_rx, $burst_threshold_tx, $burst_time_rx, $burst_time_tx, $id);
            
            if (!$stmt->execute()) {
                throw new Exception("Gagal update paket: " . $stmt->error);
            }
            $stmt->close();
            
            // Jika terhubung ke MikroTik, update/create profile
            if ($mikrotik_connected) {
                // Cek apakah profile sudah ada di MikroTik
                $profile_exists = false;
                $profiles = $api->comm('/ppp/profile/print', [
                    '?name' => $profile_name
                ]);
                
                if (count($profiles) > 0) {
                    $profile_exists = true;
                }
                
                // Persiapkan parameter untuk profile
                $profile_params = [
                    'local-address' => $local_address,
                    'remote-address' => $remote_address,
                    'rate-limit' => $rate_limit_rx . '/' . $rate_limit_tx,
                    'only-one' => 'yes',
                    'change-tcp-mss' => 'yes'
                ];
                
                // Tambahkan burst settings jika diisi
                if (!empty($burst_limit_rx) && !empty($burst_limit_tx)) {
                    $profile_params['burst-limit'] = $burst_limit_rx . '/' . $burst_limit_tx;
                    
                    if (!empty($burst_threshold_rx) && !empty($burst_threshold_tx)) {
                        $profile_params['burst-threshold'] = $burst_threshold_rx . '/' . $burst_threshold_tx;
                    }
                    
                    if (!empty($burst_time_rx) && !empty($burst_time_tx)) {
                        $profile_params['burst-time'] = $burst_time_rx . '/' . $burst_time_tx;
                    }
                }
                
                // Jika profile sudah ada, update
                if ($profile_exists) {
                    $api->comm('/ppp/profile/set', array_merge(
                        ['.id' => $profiles[0]['.id']],
                        $profile_params
                    ));
                } 
                // Jika profile belum ada, buat baru
                else {
                    $api->comm('/ppp/profile/add', array_merge(
                        ['name' => $profile_name],
                        $profile_params
                    ));
                }
            }
            
            // Commit transaksi jika semua berhasil
            $mysqli->commit();
            
            $_SESSION['success_message'] = 'Paket berhasil diupdate!' . ($mikrotik_connected ? ' Profile PPPoE juga telah diupdate/dibuat di MikroTik.' : '');
            header("Location: detail_paket_pppoe.php?id=$id");
            exit;
            
        } catch (Exception $e) {
            // Rollback jika terjadi error
            $mysqli->rollback();
            $error = "Gagal update paket: " . $e->getMessage();
            
            // Jika ada koneksi MikroTik, tutup
            if ($mikrotik_connected) {
                $api->disconnect();
            }
        }
    }
}

// Jika ada koneksi MikroTik yang masih terbuka, tutup
if ($mikrotik_connected) {
    $api->disconnect();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Paket PPPoE | Admin</title>
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

    .alert-warning {
      background-color: rgba(248, 172, 89, 0.1);
      color: var(--warning);
    }

    .section-header {
      font-size: 16px;
      font-weight: 500;
      color: var(--dark);
      padding-bottom: 10px;
      border-bottom: 1px solid #eee;
      margin-bottom: 20px;
    }

    .form-label {
      font-weight: 500;
      font-size: 13px;
      color: var(--dark);
      margin-bottom: 5px;
    }

    .form-control, .form-select {
      font-size: 13px;
      padding: 8px 12px;
      border-radius: 3px;
      border: 1px solid #ddd;
    }

    .form-control:focus, .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 0.2rem rgba(26, 187, 156, 0.25);
    }

    .input-group-text {
      font-size: 13px;
      background-color: #f8f9fa;
      color: var(--secondary);
    }

    .text-muted {
      font-size: 12px;
      color: var(--secondary) !important;
    }

    .invalid-feedback {
      font-size: 12px;
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

    .btn-secondary {
      background-color: var(--secondary);
    }

    .btn-outline-secondary {
      color: var(--secondary);
      border-color: var(--secondary);
    }

    .btn-outline-secondary:hover {
      background-color: var(--secondary);
      color: white;
    }

    .btn-success {
      background-color: var(--success);
    }

    .btn-sm {
      padding: 5px 10px;
      font-size: 12px;
    }

    .manual-input-toggle {
      margin-top: 5px;
    }

    .manual-input-toggle .btn {
      padding: 2px 8px;
      font-size: 11px;
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
            <h1><i class="fas fa-network-wired me-2"></i>Edit Paket PPPoE</h1>
            <p class="page-subtitle">Perbarui informasi paket internet PPPoE</p>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Paket: <?= htmlspecialchars($paket['nama_paket']) ?></h5>
                        <div>
                            <a href="../paket/list_paket_pppoe.php" class="btn btn-success btn-sm">
                                <i class="fas fa-arrow-left me-1"></i> Kembali
                            </a>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <div><strong>Error!</strong> <?= htmlspecialchars($error) ?></div>
                            </div>
                        <?php endif; ?>

                        <form method="post" class="needs-validation" novalidate>
                            <div class="row g-3">
                                <!-- Informasi Dasar -->
                                <div class="col-12">
                                    <h5 class="section-header"><i class="fas fa-info-circle me-2"></i>Informasi Dasar</h5>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="nama_paket" class="form-label">Nama Paket <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="nama_paket" name="nama_paket" 
                                           value="<?= htmlspecialchars($paket['nama_paket']) ?>" required>
                                    <div class="invalid-feedback">Harap isi nama paket.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="harga" class="form-label">Harga <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rp</span>
                                        <input type="number" class="form-control" id="harga" name="harga" 
                                               value="<?= $paket['harga'] ?>" min="1000" step="1000" required>
                                        <div class="invalid-feedback">Harap isi harga yang valid.</div>
                                    </div>
                                    <small class="form-text text-muted">Dalam Rupiah</small>
                                </div>
                                
                                <!-- Pengaturan Jaringan -->
                                <div class="col-12 mt-4">
                                    <h5 class="section-header"><i class="fas fa-network-wired me-2"></i>Pengaturan Jaringan</h5>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="local_address" class="form-label">Local Address <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="local_address" name="local_address" 
                                           value="<?= htmlspecialchars($paket['local_address']) ?>" required pattern="\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}">
                                    <small class="form-text text-muted">IP Gateway untuk PPPoE Server (Contoh: 192.168.10.1)</small>
                                    <div class="invalid-feedback">Harap isi local address dengan format IP yang valid.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="remote_address" class="form-label">Remote Address <span class="text-danger">*</span></label>
                                    
                                    <!-- Dropdown IP Pool -->
                                    <div id="dropdown_container">
                                        <select class="form-select" id="remote_address_select" name="remote_address" required>
                                            <option value="">-- Pilih IP Pool --</option>
                                            <?php foreach ($ip_pools as $pool): ?>
                                                <option value="<?= htmlspecialchars($pool['name']) ?>" 
                                                        <?= ($paket['remote_address'] == $pool['name']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($pool['name']) ?> (<?= htmlspecialchars($pool['ranges']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="manual" <?= (empty($ip_pools) || !in_array($paket['remote_address'], array_column($ip_pools, 'name'))) ? 'selected' : '' ?>>
                                                🔧 Input Manual
                                            </option>
                                        </select>
                                        <div class="invalid-feedback">Harap pilih IP Pool atau gunakan input manual.</div>
                                    </div>
                                    
                                    <!-- Input Manual (Hidden by default) -->
                                    <div id="manual_container" style="display: none;">
                                        <input type="text" class="form-control" id="remote_address_manual" 
                                               value="<?= htmlspecialchars($paket['remote_address']) ?>" 
                                               placeholder="Contoh: dhcp_pool0 atau 10.10.10.0/24">
                                        <div class="manual-input-toggle">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleInputMode()">
                                                <i class="fas fa-list me-1"></i> Kembali ke Dropdown
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <small class="form-text text-muted">IP Pool untuk client PPPoE</small>
                                </div>
                                
                                <!-- Pengaturan Bandwidth -->
                                <div class="col-12 mt-4">
                                    <h5 class="section-header"><i class="fas fa-tachometer-alt me-2"></i>Pengaturan Bandwidth</h5>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="rate_limit_rx" class="form-label">Download Speed <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="rate_limit_rx" name="rate_limit_rx" 
                                           value="<?= htmlspecialchars($paket['rate_limit_rx']) ?>" placeholder="Contoh: 10M" required pattern="^\d+[KMGkMgm]$">
                                    <small class="form-text text-muted">Format: angka + satuan (K/M/G). Contoh: 10M, 512K</small>
                                    <div class="invalid-feedback">Harap isi download speed dengan format yang benar (misal: 10M).</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="rate_limit_tx" class="form-label">Upload Speed <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="rate_limit_tx" name="rate_limit_tx" 
                                           value="<?= htmlspecialchars($paket['rate_limit_tx']) ?>" placeholder="Contoh: 2M" required pattern="^\d+[KMGkMgm]$">
                                    <small class="form-text text-muted">Format: angka + satuan (K/M/G). Contoh: 2M, 256K</small>
                                    <div class="invalid-feedback">Harap isi upload speed dengan format yang benar (misal: 2M).</div>
                                </div>
                                
                                <!-- Pengaturan Burst (Opsional) -->
                                <div class="col-12 mt-4">
                                    <h5 class="section-header"><i class="fas fa-bolt me-2"></i>Pengaturan Burst (Opsional)</h5>
                                    <p class="text-muted small">Fitur Burst memungkinkan kecepatan sementara di atas limit utama untuk periode singkat.</p>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="burst_limit_rx" class="form-label">Burst Limit Download</label>
                                    <input type="text" class="form-control" id="burst_limit_rx" name="burst_limit_rx" 
                                           value="<?= htmlspecialchars($paket['burst_limit_rx']) ?>" placeholder="Contoh: 20M" pattern="^\d*[KMGkMgm]?$">
                                    <small class="form-text text-muted">Kecepatan maksimum saat burst (Contoh: 20M)</small>
                                    <div class="invalid-feedback">Format burst limit download tidak valid.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="burst_limit_tx" class="form-label">Burst Limit Upload</label>
                                    <input type="text" class="form-control" id="burst_limit_tx" name="burst_limit_tx" 
                                           value="<?= htmlspecialchars($paket['burst_limit_tx']) ?>" placeholder="Contoh: 4M" pattern="^\d*[KMGkMgm]?$">
                                    <small class="form-text text-muted">Kecepatan maksimum saat burst (Contoh: 4M)</small>
                                    <div class="invalid-feedback">Format burst limit upload tidak valid.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="burst_threshold_rx" class="form-label">Burst Threshold Download</label>
                                    <input type="text" class="form-control" id="burst_threshold_rx" name="burst_threshold_rx" 
                                           value="<?= htmlspecialchars($paket['burst_threshold_rx']) ?>" placeholder="Contoh: 15M" pattern="^\d*[KMGkMgm]?$">
                                    <small class="form-text text-muted">Persentase dari rate limit saat burst aktif (Contoh: 15M)</small>
                                    <div class="invalid-feedback">Format burst threshold download tidak valid.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="burst_threshold_tx" class="form-label">Burst Threshold Upload</label>
                                    <input type="text" class="form-control" id="burst_threshold_tx" name="burst_threshold_tx" 
                                           value="<?= htmlspecialchars($paket['burst_threshold_tx']) ?>" placeholder="Contoh: 3M" pattern="^\d*[KMGkMgm]?$">
                                    <small class="form-text text-muted">Persentase dari rate limit saat burst aktif (Contoh: 3M)</small>
                                    <div class="invalid-feedback">Format burst threshold upload tidak valid.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="burst_time_rx" class="form-label">Burst Time Download</label>
                                    <input type="number" class="form-control" id="burst_time_rx" name="burst_time_rx" 
                                           value="<?= htmlspecialchars($paket['burst_time_rx']) ?>" placeholder="Contoh: 10" min="0">
                                    <small class="form-text text-muted">Durasi burst dalam detik (Contoh: 10)</small>
                                    <div class="invalid-feedback">Harap isi burst time download yang valid (angka).</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="burst_time_tx" class="form-label">Burst Time Upload</label>
                                    <input type="number" class="form-control" id="burst_time_tx" name="burst_time_tx" 
                                           value="<?= htmlspecialchars($paket['burst_time_tx']) ?>" placeholder="Contoh: 10" min="0">
                                    <small class="form-text text-muted">Durasi burst dalam detik (Contoh: 10)</small>
                                    <div class="invalid-feedback">Harap isi burst time upload yang valid (angka).</div>
                                </div>
                                
                                <!-- Tombol Aksi -->
                                <div class="col-12 mt-4 pt-3 border-top">
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="detail_paket_pppoe.php?id=<?= $paket['id_paket'] ?>" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-1"></i> Batal
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i> Update Paket
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
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
    // Toggle between dropdown and manual input for remote address
    function toggleInputMode() {
        const dropdownContainer = document.getElementById('dropdown_container');
        const manualContainer = document.getElementById('manual_container');
        const selectElement = document.getElementById('remote_address_select');
        const manualInput = document.getElementById('remote_address_manual');
        
        if (dropdownContainer.style.display === 'none') {
            // Switch to dropdown
            dropdownContainer.style.display = 'block';
            manualContainer.style.display = 'none';
            selectElement.required = true;
            manualInput.required = false;
            selectElement.name = 'remote_address';
            manualInput.name = '';
        } else {
            // Switch to manual
            dropdownContainer.style.display = 'none';
            manualContainer.style.display = 'block';
            selectElement.required = false;
            manualInput.required = true;
            selectElement.name = '';
            manualInput.name = 'remote_address';
        }
    }

    // Handle dropdown change
    document.getElementById('remote_address_select').addEventListener('change', function() {
        if (this.value === 'manual') {
            toggleInputMode();
        }
    });

    // Initialize: Check if current value is not in dropdown options
    document.addEventListener('DOMContentLoaded', function() {
        const selectElement = document.getElementById('remote_address_select');
        const currentValue = '<?= htmlspecialchars($paket['remote_address']) ?>';
        
        // Check if current value exists in dropdown options
        let valueExists = false;
        for (let option of selectElement.options) {
            if (option.value === currentValue && option.value !== 'manual') {
                valueExists = true;
                break;
            }
        }
        
        // If current value doesn't exist in dropdown, switch to manual input
        if (!valueExists && currentValue !== '') {
            selectElement.value = 'manual';
            toggleInputMode();
            document.getElementById('remote_address_manual').value = currentValue;
        }
    });

    // Form validation
    (function () {
        'use strict'
        
        var forms = document.querySelectorAll('.needs-validation')
        
        Array.prototype.slice.call(forms)
            .forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    
                    form.classList.add('was-validated')
                }, false)
            })
    })()
    </script>

    <?php 
    require_once __DIR__ . '/../templates/footer.php';
    $mysqli->close();
    ?>
</body>
</html>