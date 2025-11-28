<?php
ob_start(); // Start output buffering at the VERY TOP

// /paket/tambah_paket_pppoe.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load konfigurasi
require_once __DIR__ . '/../config/config_mikrotik.php';
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

$error = '';
$success = '';
$ip_pools = [];

// 1. Ambil data IP Pool dari Mikrotik
$api = new RouterosAPI();
try {
    if (!$api->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass)) {
        throw new Exception("Gagal konek ke Mikrotik: " . $api->error_str);
    }
    
    $pools = $api->comm('/ip/pool/print');
    foreach ($pools as $pool) {
        $ip_pools[] = [
            'name' => $pool['name'],
            'ranges' => $pool['ranges']
        ];
    }
    $api->disconnect();
} catch (Exception $e) {
    $error = "Gagal ambil IP Pool: " . $e->getMessage();
}

// 2. Proses simpan ke Mikrotik dan Database (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nama_paket = isset($_POST['nama_paket']) ? str_replace(' ', '-', trim($_POST['nama_paket'])) : '';
    $local_address = isset($_POST['local_address']) ? trim($_POST['local_address']) : '';
    $remote_address = isset($_POST['remote_address']) ? trim($_POST['remote_address']) : '';
    $rate_limit_rx = isset($_POST['rate_limit_rx']) ? strtoupper(trim($_POST['rate_limit_rx'])) : '';
    $rate_limit_tx = isset($_POST['rate_limit_tx']) ? strtoupper(trim($_POST['rate_limit_tx'])) : '';
    $burst_limit_rx = isset($_POST['burst_limit_rx']) ? strtoupper(trim($_POST['burst_limit_rx'])) : null;
    $burst_limit_tx = isset($_POST['burst_limit_tx']) ? strtoupper(trim($_POST['burst_limit_tx'])) : null;
    $burst_threshold_rx = isset($_POST['burst_threshold_rx']) ? strtoupper(trim($_POST['burst_threshold_rx'])) : null;
    $burst_threshold_tx = isset($_POST['burst_threshold_tx']) ? strtoupper(trim($_POST['burst_threshold_tx'])) : null;
    $burst_time_rx = isset($_POST['burst_time_rx']) ? trim($_POST['burst_time_rx']) : null;
    $burst_time_tx = isset($_POST['burst_time_tx']) ? trim($_POST['burst_time_tx']) : null;
    $harga = isset($_POST['harga']) ? (float)$_POST['harga'] : 0;

    // Validasi
    if (empty($nama_paket) || empty($local_address) || empty($remote_address) || 
        empty($rate_limit_rx) || empty($rate_limit_tx) || $harga <= 0) {
        $error = "Semua field wajib diisi dan harga harus lebih dari 0!";
    } else {
        try {
            // 1. Simpan ke Mikrotik
            $profile_name = strtolower($nama_paket);
            $mikrotik_data = [
                'name' => $profile_name,
                'local-address' => $local_address,
                'remote-address' => $remote_address,
                'rate-limit' => $rate_limit_rx . '/' . $rate_limit_tx,
                'only-one' => 'yes',
                'change-tcp-mss' => 'yes'
            ];

            // Tambahkan burst limit jika diisi
            if (!empty($burst_limit_rx) && !empty($burst_limit_tx)) {
                $mikrotik_data['rate-limit'] .= " " . $burst_limit_rx . "/" . $burst_limit_tx;
                
                if (!empty($burst_threshold_rx) && !empty($burst_threshold_tx)) {
                    $mikrotik_data['rate-limit'] .= " " . $burst_threshold_rx . "/" . $burst_threshold_tx;
                    
                    if (!empty($burst_time_rx) && !empty($burst_time_tx)) {
                        $mikrotik_data['rate-limit'] .= " " . $burst_time_rx . "/" . $burst_time_tx;
                    }
                }
            }

            if (!$api->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass)) {
                throw new Exception("Gagal konek ke Mikrotik!");
            }
            $api->comm('/ppp/profile/add', $mikrotik_data);
            $api->disconnect();

            // 2. Simpan ke Database
            $db_data = [
                'nama_paket' => $nama_paket,
                'profile_name' => $profile_name,
                'harga' => $harga,
                'local_address' => $local_address,
                'remote_address' => $remote_address,
                'rate_limit_rx' => $rate_limit_rx,
                'rate_limit_tx' => $rate_limit_tx,
                'burst_limit_rx' => $burst_limit_rx,
                'burst_limit_tx' => $burst_limit_tx,
                'burst_threshold_rx' => $burst_threshold_rx,
                'burst_threshold_tx' => $burst_threshold_tx,
                'burst_time_rx' => $burst_time_rx,
                'burst_time_tx' => $burst_time_tx,
                'sync_mikrotik' => 'yes',
                'last_sync' => date('Y-m-d H:i:s')
            ];

            // Query INSERT
            $columns = implode(", ", array_keys($db_data));
            $values = "'" . implode("', '", array_values($db_data)) . "'";
            $query = "INSERT INTO paket_internet ($columns) VALUES ($values)";

            if (!$mysqli->query($query)) {
                throw new Exception("Gagal simpan ke database: " . $mysqli->error);
            }

            $success = "Paket {$nama_paket} berhasil disimpan!";
            header("Refresh: 2; url=../paket/list_paket_pppoe.php");
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
    <title>Tambah Paket PPPoE</title>
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

    .alert-success {
      background-color: rgba(38, 185, 154, 0.1);
      color: var(--success);
    }

    .alert-danger {
      background-color: rgba(237, 85, 101, 0.1);
      color: var(--danger);
    }

    .alert-info {
      background-color: rgba(35, 198, 200, 0.1);
      color: var(--info);
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

    .settings-section {
      background-color: white;
      border-radius: 5px;
      padding: 20px;
      margin-bottom: 20px;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    }

    .settings-section h6 {
      color: var(--dark);
      font-size: 14px;
      font-weight: 500;
      margin-bottom: 15px;
      padding-bottom: 10px;
      border-bottom: 1px solid #eee;
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

    .text-muted {
      font-size: 12px;
    }

    code {
      color: var(--danger);
      background-color: rgba(237, 85, 101, 0.1);
      padding: 2px 4px;
      border-radius: 3px;
      font-size: 90%;
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
            <h1><i class="fas fa-network-wired me-2"></i>Tambah Paket PPPoE</h1>
            <p class="page-subtitle">Buat paket internet PPPoE baru</p>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Form Tambah Paket</h5>
                        <a href="../paket/list_paket_pppoe.php" class="btn btn-sm btn-success">
                            <i class="fas fa-arrow-left me-1"></i> Kembali
                        </a>
                    </div>
                    
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <div><?= htmlspecialchars($error) ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success d-flex align-items-center">
                                <i class="fas fa-check-circle me-2"></i>
                                <div><?= htmlspecialchars($success) ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post">
                            <!-- Section 1: Pengaturan Umum -->
                            <div class="settings-section">
                                <h6><i class="fas fa-cog me-2"></i>Pengaturan Umum</h6>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="nama_paket" class="form-label">Nama Paket</label>
                                            <input type="text" class="form-control" id="nama_paket" name="nama_paket" 
                                                   placeholder="Contoh: Paket-10M (tanpa spasi)" required>
                                            <small class="text-muted">Nama profile PPPoE di Mikrotik</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="harga" class="form-label">Harga</label>
                                            <div class="input-group">
                                                <span class="input-group-text">Rp</span>
                                                <input type="number" class="form-control" id="harga" name="harga" 
                                                       min="1000" step="1000" placeholder="Dalam Rupiah" required>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="local_address" class="form-label">Local Address</label>
                                            <input type="text" class="form-control" id="local_address" name="local_address" 
                                                   placeholder="Contoh: 192.168.1.1" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="remote_address" class="form-label">Remote Address</label>
                                            <select class="form-select" id="remote_address" name="remote_address" required>
                                                <option value="">-- Pilih IP Pool --</option>
                                                <?php foreach ($ip_pools as $pool): ?>
                                                    <option value="<?= htmlspecialchars($pool['name']) ?>">
                                                        <?= htmlspecialchars($pool['name']) ?> (<?= htmlspecialchars($pool['ranges']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 2: Rate Limit -->
                            <div class="settings-section">
                                <h6><i class="fas fa-tachometer-alt me-2"></i>Pengaturan Bandwidth</h6>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Download</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-download"></i></span>
                                                <input type="text" class="form-control" name="rate_limit_rx" 
                                                       placeholder="Contoh: 10M" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Upload</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-upload"></i></span>
                                                <input type="text" class="form-control" name="rate_limit_tx" 
                                                       placeholder="Contoh: 2M" required>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 3: Burst Limit -->
                            <div class="settings-section">
                                <h6><i class="fas fa-bolt me-2"></i>Pengaturan Burst (Optional)</h6>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Burst Limit Download</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-download"></i></span>
                                                <input type="text" class="form-control" name="burst_limit_rx" 
                                                       placeholder="Contoh: 20M">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Burst Limit Upload</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-upload"></i></span>
                                                <input type="text" class="form-control" name="burst_limit_tx" 
                                                       placeholder="Contoh: 4M">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Burst Threshold Download</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-download"></i></span>
                                                <input type="text" class="form-control" name="burst_threshold_rx" 
                                                       placeholder="Contoh: 15M">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Burst Threshold Upload</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-upload"></i></span>
                                                <input type="text" class="form-control" name="burst_threshold_tx" 
                                                       placeholder="Contoh: 3M">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Burst Time Download (detik)</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-download"></i></span>
                                                <input type="number" class="form-control" name="burst_time_rx" 
                                                       placeholder="Contoh: 10">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Burst Time Upload (detik)</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-upload"></i></span>
                                                <input type="number" class="form-control" name="burst_time_tx" 
                                                       placeholder="Contoh: 10">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info mt-3 mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Format burst di Mikrotik: <code>rate-limit=rx/tx burst-rx/burst-tx burst-threshold-rx/burst-threshold-tx burst-time-rx/burst-time-tx</code>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-end gap-2 mt-4">
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo me-1"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Simpan Paket
                                </button>
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
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    $(document).ready(function() {
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            $('.alert').fadeOut('slow');
        }, 5000);
    });
    </script>

    <?php 
    // Include footer
    require_once __DIR__ . '/../templates/footer.php';

    // End output buffering and flush
    ob_end_flush();

    // Disconnect from Mikrotik if connected
    if (isset($api) && $api->connected) {
        $api->disconnect();
    }
    ?>
</body>
</html>