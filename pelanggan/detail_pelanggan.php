<?php
ob_start(); // Start output buffering at the VERY TOP

// /pelanggan/detail_pelanggan.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load konfigurasi
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

$error = '';
$pelanggan = null;
$tagihan_list = [];
$pembayaran_list = [];

// Get ID pelanggan dari URL
$id_pelanggan = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_pelanggan <= 0) {
    $error = "ID pelanggan tidak valid!";
} else {
    try {
        // Query untuk mengambil detail pelanggan
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
        } else {
            $pelanggan = $result->fetch_assoc();
            
            // Query untuk mengambil riwayat tagihan
            $tagihan_query = "SELECT * FROM tagihan 
                             WHERE id_pelanggan = ? 
                             ORDER BY tahun_tagihan DESC, bulan_tagihan DESC 
                             LIMIT 12";
            $tagihan_stmt = $mysqli->prepare($tagihan_query);
            $tagihan_stmt->bind_param("i", $id_pelanggan);
            $tagihan_stmt->execute();
            $tagihan_result = $tagihan_stmt->get_result();
            
            while ($row = $tagihan_result->fetch_assoc()) {
                $tagihan_list[] = $row;
            }
            
            // Query untuk mengambil riwayat pembayaran
            $pembayaran_query = "SELECT p.*, t.bulan_tagihan, t.tahun_tagihan 
                                FROM pembayaran p
                                LEFT JOIN tagihan t ON p.id_tagihan = t.id_tagihan
                                WHERE p.id_pelanggan = ? 
                                ORDER BY p.created_at DESC 
                                LIMIT 10";
            $pembayaran_stmt = $mysqli->prepare($pembayaran_query);
            $pembayaran_stmt->bind_param("i", $id_pelanggan);
            $pembayaran_stmt->execute();
            $pembayaran_result = $pembayaran_stmt->get_result();
            
            while ($row = $pembayaran_result->fetch_assoc()) {
                $pembayaran_list[] = $row;
            }
        }
        
    } catch (Exception $e) {
        $error = "Gagal mengambil data: " . $e->getMessage();
    }
}

// Function untuk format rupiah
function format_rupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Function untuk format tanggal
function format_date($date) {
    if (empty($date) || $date == '0000-00-00') return '-';
    return date('d/m/Y', strtotime($date));
}

// Function untuk format datetime
function format_datetime($datetime) {
    if (empty($datetime)) return '-';
    return date('d/m/Y H:i', strtotime($datetime));
}

// Function untuk mendapatkan badge status
function getStatusBadge($status) {
    $badges = [
        'aktif' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Aktif</span>',
        'nonaktif' => '<span class="badge bg-secondary"><i class="fas fa-times-circle me-1"></i> Non Aktif</span>',
        'isolir' => '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i> Isolir</span>'
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">Unknown</span>';
}

// Function untuk badge status tagihan
function getTagihanBadge($status) {
    $badges = [
        'lunas' => '<span class="badge bg-success">Lunas</span>',
        'belum_bayar' => '<span class="badge bg-danger">Belum Bayar</span>',
        'terlambat' => '<span class="badge bg-warning text-dark">Terlambat</span>',
        'pending' => '<span class="badge bg-info">Pending</span>'
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">Unknown</span>';
}

// Function untuk nama bulan
function getNamaBulan($bulan) {
    $bulan_names = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    return $bulan_names[$bulan] ?? '';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Pelanggan | Admin</title>
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

    .card-header h6 {
      font-size: 16px;
      font-weight: 500;
      color: var(--dark);
      margin: 0;
    }

    .card-body {
      padding: 20px;
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

    .info-row {
      margin-bottom: 15px;
    }

    .info-label {
      font-weight: 500;
      font-size: 13px;
      color: var(--secondary);
      margin-bottom: 5px;
    }

    .info-value {
      font-size: 14px;
      color: var(--dark);
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

    .badge-warning {
      background-color: var(--warning);
    }

    .badge-info {
      background-color: var(--info);
    }

    .badge-secondary {
      background-color: var(--secondary);
    }

    .table {
      font-size: 13px;
      margin-bottom: 0;
    }

    .table thead th {
      background-color: white;
      border-bottom: 1px solid #ddd;
      font-weight: 500;
      font-size: 13px;
      color: var(--dark);
    }

    .table tbody td {
      vertical-align: middle;
      padding: 12px 15px;
      border-top: 1px solid #f1f1f1;
    }

    .table tbody tr:hover {
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

    .btn-secondary {
      background-color: var(--secondary);
    }

    .btn-warning {
      background-color: var(--warning);
    }

    .empty-state {
      text-align: center;
      padding: 30px;
      color: var(--secondary);
    }

    .empty-state i {
      font-size: 40px;
      margin-bottom: 15px;
      color: var(--secondary);
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
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="fas fa-user me-2"></i>Detail Pelanggan</h1>
                    <p class="page-subtitle">Informasi lengkap data pelanggan</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="data_pelanggan.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Kembali
                    </a>
                    <?php if ($pelanggan): ?>
                        <a href="edit_pelanggan.php?id=<?= $pelanggan['id_pelanggan'] ?>" class="btn btn-warning">
                            <i class="fas fa-edit me-1"></i> Edit
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center">
                <i class="fas fa-exclamation-circle me-2"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($pelanggan): ?>
            <!-- Data Pribadi -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-user me-2"></i>Detail Pelanggan <?= htmlspecialchars($pelanggan['nama_pelanggan']) ?></h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="info-row">
                                <div class="info-label">Nama Lengkap</div>
                                <div class="info-value fw-bold"><?= htmlspecialchars($pelanggan['nama_pelanggan']) ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">No. Telepon</div>
                                <div class="info-value">
                                    <i class="fas fa-phone me-1 text-muted"></i>
                                    <?= htmlspecialchars($pelanggan['telepon_pelanggan']) ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Alamat</div>
                                <div class="info-value"><?= htmlspecialchars($pelanggan['alamat_pelanggan']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-row">
                                <div class="info-label">Email</div>
                                <div class="info-value">
                                    <?php if ($pelanggan['email_pelanggan']): ?>
                                        <i class="fas fa-envelope me-1 text-muted"></i>
                                        <?= htmlspecialchars($pelanggan['email_pelanggan']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Tidak ada</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Tanggal Daftar</div>
                                <div class="info-value"><?= format_date($pelanggan['tgl_daftar']) ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Tanggal Expired</div>
                                <div class="info-value"><?= format_date($pelanggan['tgl_expired']) ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Status</div>
                                <div class="info-value"><?= getStatusBadge($pelanggan['status_aktif']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-row">
                                <div class="info-label">Nama Paket</div>
                                <div class="info-value fw-bold">
                                    <?= $pelanggan['nama_paket'] ? htmlspecialchars($pelanggan['nama_paket']) : '<span class="text-muted">Belum dipilih</span>' ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Kecepatan</div>
                                <div class="info-value">
                                    <?php if ($pelanggan['rate_limit_rx'] && $pelanggan['rate_limit_tx']): ?>
                                        <?= htmlspecialchars($pelanggan['rate_limit_rx']) ?>/<?= htmlspecialchars($pelanggan['rate_limit_tx']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Harga Bulanan</div>
                                <div class="info-value fw-bold text-primary">
                                    <?= $pelanggan['harga'] ? format_rupiah($pelanggan['harga']) : '<span class="text-muted">-</span>' ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Profile Mikrotik</div>
                                <div class="info-value">
                                    <?= $pelanggan['profile_name'] ? htmlspecialchars($pelanggan['profile_name']) : '<span class="text-muted">-</span>' ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-row">
                                <div class="info-label">Username PPPoE</div>
                                <div class="info-value">
                                    <code><?= htmlspecialchars($pelanggan['mikrotik_username']) ?></code>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Password PPPoE</div>
                                <div class="info-value">
                                    <code><?= htmlspecialchars($pelanggan['mikrotik_password']) ?></code>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Service</div>
                                <div class="info-value"><?= htmlspecialchars($pelanggan['mikrotik_service'] ?? 'pppoe') ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Sync Status</div>
                                <div class="info-value">
                                    <?php if ($pelanggan['sync_mikrotik'] == 'yes'): ?>
                                        <span class="badge bg-success">Tersinkron</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Belum Sync</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Riwayat Tagihan -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-file-invoice me-2"></i>Riwayat Tagihan (12 Terakhir)</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($tagihan_list)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-invoice"></i>
                            <p>Belum ada tagihan</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Periode</th>
                                        <th>Jumlah</th>
                                        <th>Jatuh Tempo</th>
                                        <th>Status</th>
                                        <th>Tgl Bayar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tagihan_list as $tagihan): ?>
                                        <tr>
                                            <td><?= getNamaBulan($tagihan['bulan_tagihan']) ?> <?= $tagihan['tahun_tagihan'] ?></td>
                                            <td class="fw-bold"><?= format_rupiah($tagihan['jumlah_tagihan']) ?></td>
                                            <td><?= format_date($tagihan['tgl_jatuh_tempo']) ?></td>
                                            <td><?= getTagihanBadge($tagihan['status_tagihan']) ?></td>
                                            <td><?= isset($tagihan['tgl_bayar']) ? format_date($tagihan['tgl_bayar']) : '-' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Riwayat Pembayaran -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-money-bill me-2"></i>Riwayat Pembayaran (10 Terakhir)</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($pembayaran_list)): ?>
                        <div class="empty-state">
                            <i class="fas fa-money-bill"></i>
                            <p>Belum ada pembayaran</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Periode</th>
                                        <th>Jumlah</th>
                                        <th>Metode</th>
                                        <th>Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pembayaran_list as $pembayaran): ?>
                                        <tr>
                                            <td><?= format_datetime($pembayaran['created_at']) ?></td>
                                            <td>
                                                <?php if ($pembayaran['bulan_tagihan'] && $pembayaran['tahun_tagihan']): ?>
                                                    <?= getNamaBulan($pembayaran['bulan_tagihan']) ?> <?= $pembayaran['tahun_tagihan'] ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold text-success"><?= format_rupiah($pembayaran['jumlah_bayar']) ?></td>
                                            <td><?= htmlspecialchars($pembayaran['metode_bayar'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($pembayaran['keterangan'] ?? '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <?php require_once __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>

<?php ob_end_flush(); ?>