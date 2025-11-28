<?php
ob_start(); // Start output buffering at the VERY TOP

// /pelanggan/edit_pelanggan.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load konfigurasi
require_once __DIR__ . '/../config/config_mikrotik.php';
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

$error = '';
$success = '';
$pelanggan = null;
$paket_list = [];

// Get ID pelanggan dari URL
$id_pelanggan = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_pelanggan <= 0) {
    $error = "ID pelanggan tidak valid!";
    header("Location: data_pelanggan.php");
    exit;
}

// 1. Ambil data paket internet dari database
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

// 2. Ambil data pelanggan untuk diedit
try {
    $pelanggan_query = "SELECT 
                        dp.*,
                        pi.nama_paket,
                        pi.harga,
                        pi.rate_limit_rx,
                        pi.rate_limit_tx,
                        pi.profile_name
                        FROM data_pelanggan dp
                        LEFT JOIN paket_internet pi ON dp.id_paket = pi.id_paket
                        WHERE dp.id_pelanggan = ?";
    
    $stmt = $mysqli->prepare($pelanggan_query);
    $stmt->bind_param("i", $id_pelanggan);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $error = "Data pelanggan tidak ditemukan!";
        header("Location: data_pelanggan.php");
        exit;
    } else {
        $pelanggan = $result->fetch_assoc();
    }
} catch (Exception $e) {
    $error = "Gagal mengambil data pelanggan: " . $e->getMessage();
}

// 3. Proses update pelanggan
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $pelanggan) {
    $nama_pelanggan = isset($_POST['nama_pelanggan']) ? trim($_POST['nama_pelanggan']) : '';
    $alamat_pelanggan = isset($_POST['alamat_pelanggan']) ? trim($_POST['alamat_pelanggan']) : '';
    $telepon_pelanggan = isset($_POST['telepon_pelanggan']) ? trim($_POST['telepon_pelanggan']) : '';
    $email_pelanggan = isset($_POST['email_pelanggan']) ? trim($_POST['email_pelanggan']) : '';
    $id_paket = isset($_POST['id_paket']) ? (int)$_POST['id_paket'] : 0;
    $tgl_daftar = isset($_POST['tgl_daftar']) ? $_POST['tgl_daftar'] : '';
    $tgl_expired = isset($_POST['tgl_expired']) ? $_POST['tgl_expired'] : '';
    $mikrotik_username = isset($_POST['mikrotik_username']) ? trim($_POST['mikrotik_username']) : '';
    $mikrotik_password = isset($_POST['mikrotik_password']) ? trim($_POST['mikrotik_password']) : '';
    $status_aktif = isset($_POST['status_aktif']) ? $_POST['status_aktif'] : 'aktif';

    // Validasi
    if (empty($nama_pelanggan) || empty($alamat_pelanggan) || empty($telepon_pelanggan) || 
        $id_paket <= 0 || empty($tgl_daftar) || empty($tgl_expired) ||
        empty($mikrotik_username) || empty($mikrotik_password)) {
        $error = "Semua field wajib diisi!";
    } else {
        try {
            // 1. Cek apakah username sudah ada di database (kecuali milik pelanggan ini sendiri)
            $check_username = $mysqli->prepare("SELECT id_pelanggan FROM data_pelanggan WHERE mikrotik_username = ? AND id_pelanggan != ?");
            $check_username->bind_param("si", $mikrotik_username, $id_pelanggan);
            $check_username->execute();
            $result_check = $check_username->get_result();
            
            if ($result_check->num_rows > 0) {
                throw new Exception("Username {$mikrotik_username} sudah digunakan pelanggan lain!");
            }
            
            // 2. Ambil data paket untuk profile Mikrotik
            $paket_query = $mysqli->prepare("SELECT profile_name FROM paket_internet WHERE id_paket = ?");
            $paket_query->bind_param("i", $id_paket);
            $paket_query->execute();
            $paket_result = $paket_query->get_result();
            
            if ($paket_result->num_rows == 0) {
                throw new Exception("Paket tidak ditemukan!");
            }
            
            $paket_data = $paket_result->fetch_assoc();
            $profile_name = $paket_data['profile_name'];
            
            // 3. Update user di Mikrotik PPPoE (jika username atau password berubah)
            if ($mikrotik_username != $pelanggan['mikrotik_username'] || $mikrotik_password != $pelanggan['mikrotik_password'] || $profile_name != $pelanggan['profile_name']) {
                $api = new RouterosAPI();
                if ($api->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass)) {
                    // Hapus user lama
                    $api->comm('/ppp/secret/remove', array('.id' => $pelanggan['mikrotik_username']));
                    
                    // Buat user baru
                    $mikrotik_user_data = [
                        'name' => $mikrotik_username,
                        'password' => $mikrotik_password,
                        'profile' => $profile_name,
                        'service' => 'pppoe',
                        'comment' => "Pelanggan: {$nama_pelanggan}"
                    ];
                    
                    $api->comm('/ppp/secret/add', $mikrotik_user_data);
                    $api->disconnect();
                }
            }
            
            // 4. Update database
            $update_query = "UPDATE data_pelanggan SET 
                            nama_pelanggan = ?, 
                            alamat_pelanggan = ?, 
                            telepon_pelanggan = ?, 
                            email_pelanggan = ?,
                            id_paket = ?, 
                            tgl_daftar = ?, 
                            tgl_expired = ?,
                            mikrotik_username = ?, 
                            mikrotik_password = ?, 
                            mikrotik_profile = ?,
                            status_aktif = ?,
                            sync_mikrotik = 'yes',
                            last_sync = NOW(),
                            updated_at = NOW()
                            WHERE id_pelanggan = ?";
            
            $stmt = $mysqli->prepare($update_query);
            $stmt->bind_param("ssssissssssi", 
                $nama_pelanggan, $alamat_pelanggan, $telepon_pelanggan, $email_pelanggan,
                $id_paket, $tgl_daftar, $tgl_expired, $mikrotik_username, $mikrotik_password,
                $profile_name, $status_aktif, $id_pelanggan
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Gagal update ke database: " . $mysqli->error);
            }

            $success = "Data pelanggan {$nama_pelanggan} berhasil diupdate!";
            // Redirect ke halaman data_pelanggan.php setelah update berhasil
            header("Location: data_pelanggan.php?msg=success&nama_pelanggan=" . urlencode($nama_pelanggan));
            exit;
            
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pelanggan | Admin</title>
    <style>
    /* Gentelella Custom Styles */
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
      font-family: "Segoe UI", Roboto, Arial, sans-serif;
      font-size: 13px;
      color: var(--secondary);
      background-color: var(--light);
    }
    
    .page-title h1 {
      font-size: 24px;
      color: var(--dark);
      margin: 0;
      font-weight: 400;
    }
    
    .page-subtitle {
      color: var(--secondary);
      font-size: 13px;
      margin: 5px 0 0 0;
    }
    
    .card {
      border: none;
      border-radius: 5px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      margin-bottom: 20px;
      background: white;
    }
    
    .card-header {
      background: white;
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
      margin-bottom: 30px;
      padding-bottom: 20px;
      border-bottom: 1px solid #eee;
    }
    
    .settings-section h6 {
      color: var(--dark);
      font-size: 14px;
      font-weight: 500;
      margin-bottom: 20px;
      padding-bottom: 5px;
      border-bottom: 1px solid #eee;
    }
    
    .settings-section h6 i {
      color: var(--primary);
      margin-right: 8px;
    }
    
    .form-label {
      font-weight: 500;
      font-size: 13px;
      color: var(--secondary);
      margin-bottom: 5px;
    }
    
    .required {
      color: var(--danger);
    }
    
    .form-control, .form-select {
      border-radius: 3px;
      font-size: 13px;
      height: calc(2.25rem + 2px);
      border: 1px solid #ddd;
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
      text-transform: uppercase;
      letter-spacing: 0.5px;
      transition: all 0.3s;
    }
    
    .btn-primary {
      background-color: var(--primary);
      border-color: var(--primary);
    }
    
    .btn-primary:hover {
      background-color: #169F85;
      border-color: #169F85;
    }
    
    .btn-secondary {
      background-color: var(--secondary);
      border-color: var(--secondary);
    }
    
    .btn-info {
      background-color: var(--info);
      border-color: var(--info);
    }
    
    .btn-outline-secondary {
      border-color: var(--secondary);
      color: var(--secondary);
    }
    
    .btn-outline-secondary:hover {
      background-color: var(--secondary);
      color: white;
    }
    
    .btn-outline-info {
      border-color: var(--info);
      color: var(--info);
    }
    
    .btn-outline-info:hover {
      background-color: var(--info);
      color: white;
    }
    
    .btn-generate {
      background-color: #f0f0f0;
      color: var(--secondary);
      border: 1px solid #ddd;
      font-size: 12px;
    }
    
    .btn-generate:hover {
      background-color: #e0e0e0;
    }
    
    .alert {
      border-radius: 3px;
      font-size: 13px;
      padding: 10px 15px;
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
    
    .input-group-text {
      font-size: 13px;
      background-color: #f8f9fa;
    }
    
    /* Custom select styling */
    .form-select {
      display: block;
      width: 100%;
      padding: 0.4rem 1.75rem 0.4rem 0.75rem;
      font-size: 13px;
      font-weight: 400;
      line-height: 1.5;
      color: #495057;
      background-color: #fff;
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
      background-repeat: no-repeat;
      background-position: right 0.75rem center;
      background-size: 16px 12px;
      border: 1px solid #ced4da;
      border-radius: 3px;
      -webkit-appearance: none;
      -moz-appearance: none;
      appearance: none;
    }
    </style>
</head>
<body>
    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="container-fluid">
            <div class="page-title">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1><i class="fas fa-user-edit me-2"></i>Edit Pelanggan</h1>
                        <p class="page-subtitle">Edit data pelanggan internet</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="data_pelanggan.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Kembali
                        </a>
                        <?php if ($pelanggan): ?>
                            <a href="detail_pelanggan.php?id=<?= $pelanggan['id_pelanggan'] ?>" class="btn btn-info">
                                <i class="fas fa-eye me-1"></i> Detail
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Data Pelanggan</h5>
                        </div>
                        <div class="card-body">
                            <!-- Alert Messages -->
                            <?php if ($error): ?>
                                <div class="alert alert-danger d-flex align-items-center">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    <?= htmlspecialchars($error) ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($success): ?>
                                <div class="alert alert-success d-flex align-items-center">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <?= htmlspecialchars($success) ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($pelanggan): ?>
                            <form method="post">
                                <!-- Section 1: Data Pribadi Pelanggan -->
                                <div class="settings-section">
                                    <h6><i class="fas fa-user me-2"></i>Data Pribadi Pelanggan</h6>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label for="nama_pelanggan" class="form-label">Nama Lengkap <span class="required">*</span></label>
                                                <input type="text" class="form-control" id="nama_pelanggan" name="nama_pelanggan"
                                                       value="<?= htmlspecialchars($pelanggan['nama_pelanggan']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label for="telepon_pelanggan" class="form-label">No. Telepon <span class="required">*</span></label>
                                                <input type="tel" class="form-control" id="telepon_pelanggan" name="telepon_pelanggan"
                                                       value="<?= htmlspecialchars($pelanggan['telepon_pelanggan']) ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label for="email_pelanggan" class="form-label">Email</label>
                                                <input type="email" class="form-control" id="email_pelanggan" name="email_pelanggan"
                                                       value="<?= htmlspecialchars($pelanggan['email_pelanggan'] ?? '') ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label for="status_aktif" class="form-label">Status <span class="required">*</span></label>
                                                <select class="form-select" id="status_aktif" name="status_aktif" required>
                                                    <option value="aktif" <?= $pelanggan['status_aktif'] == 'aktif' ? 'selected' : '' ?>>Aktif</option>
                                                    <option value="nonaktif" <?= $pelanggan['status_aktif'] == 'nonaktif' ? 'selected' : '' ?>>Non Aktif</option>
                                                    <option value="isolir" <?= $pelanggan['status_aktif'] == 'isolir' ? 'selected' : '' ?>>Isolir</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="form-group mb-3">
                                                <label for="alamat_pelanggan" class="form-label">Alamat Lengkap <span class="required">*</span></label>
                                                <textarea class="form-control" id="alamat_pelanggan" name="alamat_pelanggan"
                                                          rows="3" required><?= htmlspecialchars($pelanggan['alamat_pelanggan']) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label for="tgl_daftar" class="form-label">Tanggal Daftar <span class="required">*</span></label>
                                                <input type="date" class="form-control" id="tgl_daftar" name="tgl_daftar"
                                                       value="<?= $pelanggan['tgl_daftar'] ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label for="tgl_expired" class="form-label">Tanggal Expired <span class="required">*</span></label>
                                                <input type="date" class="form-control" id="tgl_expired" name="tgl_expired"
                                                       value="<?= $pelanggan['tgl_expired'] ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Section 2: Paket Internet -->
                                <div class="settings-section">
                                    <h6><i class="fas fa-wifi me-2"></i>Paket Internet</h6>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label for="id_paket" class="form-label">Pilih Paket <span class="required">*</span></label>
                                                <select class="form-select" id="id_paket" name="id_paket" required onchange="updateHarga()">
                                                    <option value="">-- Pilih Paket Internet --</option>
                                                    <?php foreach ($paket_list as $paket): ?>
                                                        <option value="<?= $paket['id_paket'] ?>" 
                                                                data-harga="<?= $paket['harga'] ?>"
                                                                data-kecepatan="<?= htmlspecialchars($paket['kecepatan']) ?>"
                                                                <?= $pelanggan['id_paket'] == $paket['id_paket'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($paket['nama_paket']) ?> - <?= htmlspecialchars($paket['kecepatan']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label for="harga_display" class="form-label">Harga Paket</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">Rp</span>
                                                    <input type="text" class="form-control" id="harga_display" readonly>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Section 3: Konfigurasi PPPoE -->
                                <div class="settings-section">
                                    <h6><i class="fas fa-cogs me-2"></i>Konfigurasi PPPoE Mikrotik</h6>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label for="mikrotik_username" class="form-label">Username PPPoE <span class="required">*</span></label>
                                                <div class="input-group">
                                                    <input type="text" class="form-control" id="mikrotik_username" name="mikrotik_username"
                                                           value="<?= htmlspecialchars($pelanggan['mikrotik_username']) ?>" required>
                                                    <button type="button" class="btn btn-generate" onclick="generateUsername()">
                                                        <i class="fas fa-refresh"></i> Generate
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label for="mikrotik_password" class="form-label">Password PPPoE <span class="required">*</span></label>
                                                <div class="input-group">
                                                    <input type="text" class="form-control" id="mikrotik_password" name="mikrotik_password"
                                                           value="<?= htmlspecialchars($pelanggan['mikrotik_password']) ?>" required>
                                                    <button type="button" class="btn btn-generate" onclick="generatePassword()">
                                                        <i class="fas fa-key"></i> Generate
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info mt-3 mb-0">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Perhatian:</strong> Jika username atau password diubah, konfigurasi di Mikrotik akan ikut diupdate otomatis.
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-end gap-2 mt-4">
                                    <a href="data_pelanggan.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-1"></i> Batal
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Update Pelanggan
                                    </button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
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
        // Update harga otomatis saat paket dipilih
        function updateHarga() {
            const paketSelect = document.getElementById('id_paket');
            const hargaDisplay = document.getElementById('harga_display');
            
            if (paketSelect.value === '') {
                hargaDisplay.value = '';
            } else {
                const selectedOption = paketSelect.options[paketSelect.selectedIndex];
                const harga = selectedOption.getAttribute('data-harga');
                const kecepatan = selectedOption.getAttribute('data-kecepatan');
                
                // Format harga dengan titik sebagai separator ribuan
                const hargaFormatted = new Intl.NumberFormat('id-ID').format(harga);
                hargaDisplay.value = hargaFormatted + ' / bulan (' + kecepatan + ')';
            }
        }

        // Generate username otomatis berdasarkan nama
        function generateUsername() {
            const nama = document.getElementById('nama_pelanggan').value;
            if (nama.trim() === '') {
                alert('Masukkan nama pelanggan terlebih dahulu!');
                return;
            }
            
            // Ambil 3 huruf pertama dari nama
            const namaBersih = nama.replace(/[^a-zA-Z]/g, '').toLowerCase().substring(0, 3);
            const randomNum = Math.floor(Math.random() * 999) + 1;
            const username = namaBersih + randomNum.toString().padStart(3, '0');
            
            document.getElementById('mikrotik_username').value = username;
        }

        // Generate password random
        function generatePassword() {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let password = '';
            for (let i = 0; i < 8; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('mikrotik_password').value = password;
        }

        // Set harga saat halaman dimuat
        document.addEventListener('DOMContentLoaded', function() {
            updateHarga();
        });

        // Auto refresh alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);

        // Validasi form sebelum submit
        document.querySelector('form').addEventListener('submit', function(e) {
            const username = document.getElementById('mikrotik_username').value;
            const password = document.getElementById('mikrotik_password').value;
            
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
        });
    </script>
</body>
</html>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>