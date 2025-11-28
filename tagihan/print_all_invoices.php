<?php
session_start();
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database connection
require_once __DIR__ . '/../config/config_database.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$bulan_filter = $_GET['bulan'] ?? '';
$tahun_filter = $_GET['tahun'] ?? '';

try {
    // Build query with same logic as data_tagihan.php
    $today = date('Y-m-d');
    $h_plus_10 = date('Y-m-d', strtotime('+10 days'));
    
    $where_conditions = [];
    $params = [];
    $types = '';
    
    $base_query = "SELECT t.*, dp.nama_pelanggan, dp.alamat as alamat_pelanggan, dp.no_telp, 
                          pi.nama_paket, pi.harga,
                          DATEDIFF(t.tgl_jatuh_tempo, '$today') as days_to_due
                   FROM tagihan t 
                   JOIN data_pelanggan dp ON t.id_pelanggan = dp.id_pelanggan 
                   LEFT JOIN paket_internet pi ON dp.id_paket = pi.id_paket";
    
    // Same H-10 logic as data_tagihan.php
    $where_conditions[] = "(
        (t.status_tagihan = 'belum_bayar' AND t.tgl_jatuh_tempo BETWEEN '$today' AND '$h_plus_10') OR
        (t.status_tagihan = 'terlambat' AND DATEDIFF('$today', t.tgl_jatuh_tempo) <= 10) OR
        t.status_tagihan = 'sudah_bayar'
    )";
    
    // Apply filters
    if (!empty($search)) {
        $where_conditions[] = "(dp.nama_pelanggan LIKE ? OR pi.nama_paket LIKE ? OR dp.mikrotik_username LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'sss';
    }
    
    if (!empty($status_filter)) {
        if ($status_filter === 'terlambat') {
            $where_conditions[] = "t.status_tagihan = 'terlambat'";
        } else {
            $where_conditions[] = "t.status_tagihan = ?";
            $params[] = $status_filter;
            $types .= 's';
        }
    }
    
    if (!empty($bulan_filter)) {
        $where_conditions[] = "t.bulan_tagihan = ?";
        $params[] = intval($bulan_filter);
        $types .= 'i';
    }
    
    if (!empty($tahun_filter)) {
        $where_conditions[] = "t.tahun_tagihan = ?";
        $params[] = intval($tahun_filter);
        $types .= 'i';
    }
    
    $where_clause = " WHERE " . implode(" AND ", $where_conditions);
    
    $main_query = $base_query . $where_clause . " ORDER BY 
        CASE 
            WHEN t.status_tagihan = 'terlambat' THEN 1
            WHEN t.status_tagihan = 'belum_bayar' THEN 2
            WHEN t.status_tagihan = 'sudah_bayar' THEN 3
        END,
        t.tgl_jatuh_tempo ASC";
    
    $stmt = $mysqli->prepare($main_query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    
    $invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Calculate total amount
    $total_tagihan = 0;
    foreach ($invoices as $invoice) {
        $total_tagihan += $invoice['jumlah_tagihan'];
    }

} catch (Exception $e) { 
    die("<div class='alert alert-danger'>Database Error: " . $e->getMessage() . "</div>");
}

// Build filter description
$filter_description = [];
if (!empty($search)) $filter_description[] = "Search: '$search'";
if (!empty($status_filter)) {
    $status_labels = [
        'sudah_bayar' => 'Sudah Bayar',
        'belum_bayar' => 'Belum Bayar', 
        'terlambat' => 'Terlambat'
    ];
    $filter_description[] = "Status: " . ($status_labels[$status_filter] ?? $status_filter);
}
if (!empty($bulan_filter)) {
    $bulan_names = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $filter_description[] = "Bulan: " . ($bulan_names[$bulan_filter] ?? $bulan_filter);
}
if (!empty($tahun_filter)) $filter_description[] = "Tahun: $tahun_filter";

$filter_text = empty($filter_description) ? "Semua data (H-10 Logic)" : implode(', ', $filter_description);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Semua Invoice</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @page {
            size: A4 portrait;
            margin: 5mm;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            background-color: white;
            padding: 0;
            margin: 0;
        }
        
        .page {
            height: 287mm;
            width: 200mm;
            page-break-after: always;
            position: relative;
            padding: 0;
            margin: 0;
        }
        
        .invoice-container {
            display: flex;
            flex-direction: column;
            height: 100%;
            justify-content: space-between;
        }
        
        .invoice-card {
            border: 1px solid #000;
            padding: 10px;
            height: 138mm;
            margin-bottom: 5mm;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .invoice-card:last-child {
            margin-bottom: 0;
        }
        
        @media print {
            body {
                padding: 0;
                margin: 0;
                font-size: 12px;
            }
            .no-print {
                display: none !important;
            }
            .page {
                padding: 0;
                margin: 0;
                page-break-after: always;
            }
            .invoice-card {
                border: 1px solid #000;
                padding: 10px;
                height: 138mm;
                margin-bottom: 5mm;
            }
            .invoice-card:last-child {
                margin-bottom: 0;
            }
        }
        
        .invoice-header {
            border-bottom: 1px solid #333;
            padding-bottom: 8px;
            margin-bottom: 8px;
        }
        
        .invoice-title {
            color: #000;
            font-size: 16px;
            font-weight: bold;
            margin: 8px 0;
        }
        
        .company-info {
            font-size: 10px;
            line-height: 1.4;
        }
        
        .customer-info {
            font-size: 11px;
            line-height: 1.4;
        }
        
        .table {
            width: 100%;
            margin-bottom: 8px;
            border-collapse: collapse;
            font-size: 10px;
        }
        
        .table th, .table td {
            padding: 4px;
            border: 1px solid #333;
        }
        
        .table th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        
        .status-unpaid {
            color: #dc3545;
            font-weight: bold;
            font-size: 11px;
        }
        
        .status-paid {
            color: #28a745;
            font-weight: bold;
            font-size: 11px;
        }
        
        .logo { 
            max-height: 45px;
            width: auto;
            margin-bottom: 4px;
        }
        
        .payment-info {
            border: 1px solid #333;
            padding: 6px;
            margin-top: 6px;
            font-size: 9px;
            background-color: #f9f9f9;
            line-height: 1.3;
        }
        
        .text-muted {
            color: #6c757d;
            font-size: 9px;
        }
        
        .badge {
            font-size: 75%;
        }
        
        .small {
            font-size: 9px;
        }
        
        .text-danger {
            font-size: 10px;
        }
        
        .table .text-right {
            text-align: right;
        }
        
        /* Enhanced styling */
        .print-header {
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
            color: white;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .print-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            border-left: 4px solid #6f42c1;
        }
        
        .filter-badge {
            background: #17a2b8;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            display: inline-block;
            margin: 2px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .stat-item {
            background: white;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            text-align: center;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #6f42c1;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>
    <div class="no-print print-header">
        <h4><i class="bi bi-printer-fill"></i> Cetak Semua Invoice</h4>
        <p class="mb-0">Sistem Billing Management - PT. Area Near Urban Netsindo</p>
        <small>Filter: <?= htmlspecialchars($filter_text) ?></small>
    </div>

    <div class="no-print print-summary">
        <div class="row">
            <div class="col-md-8">
                <button onclick="window.print()" class="btn btn-primary btn-sm me-2">
                    <i class="bi bi-printer"></i> Cetak Sekarang
                </button>
                <a href="data_tagihan.php" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Kembali ke Data Tagihan
                </a>
            </div>
            <div class="col-md-4">
                <div class="stats-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="stat-item">
                        <div class="stat-number"><?= count($invoices) ?></div>
                        <div class="stat-label">Total Invoice</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">Rp <?= number_format($total_tagihan / 1000000, 1) ?>M</div>
                        <div class="stat-label">Total Tagihan</div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($filter_description)): ?>
        <div class="mt-2">
            <strong><i class="bi bi-funnel"></i> Filter Aktif:</strong><br>
            <?php foreach ($filter_description as $filter): ?>
                <span class="filter-badge"><?= htmlspecialchars($filter) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div class="mt-3">
            <div class="stats-grid">
                <?php
                $status_counts = [
                    'sudah_bayar' => 0,
                    'belum_bayar' => 0, 
                    'terlambat' => 0
                ];
                foreach ($invoices as $invoice) {
                    $status_counts[$invoice['status_tagihan']]++;
                }
                ?>
                <div class="stat-item">
                    <div class="stat-number text-success"><?= $status_counts['sudah_bayar'] ?></div>
                    <div class="stat-label">Sudah Bayar</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number text-warning"><?= $status_counts['belum_bayar'] ?></div>
                    <div class="stat-label">Belum Bayar</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number text-danger"><?= $status_counts['terlambat'] ?></div>
                    <div class="stat-label">Terlambat</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number text-primary"><?= ceil(count($invoices) / 2) ?></div>
                    <div class="stat-label">Total Halaman</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($invoices)): ?>
        <div class="alert alert-info text-center">
            <i class="bi bi-info-circle"></i>
            Tidak ada data tagihan yang ditemukan untuk filter yang dipilih.
        </div>
    <?php else: ?>
        <?php
        $invoiceCount = count($invoices);
        $invoicesPerPage = 2;
        $pages = ceil($invoiceCount / $invoicesPerPage);

        for ($page = 0; $page < $pages; $page++):
            $startIdx = $page * $invoicesPerPage;
            $currentInvoices = array_slice($invoices, $startIdx, $invoicesPerPage);
        ?>
        <div class="page">
            <div class="invoice-container">
                <?php foreach ($currentInvoices as $idx => $invoice): ?>
                <div class="invoice-card">
                    <div class="invoice-header">
                        <div class="row align-items-start">
                            <div class="col-6">
                                <img src="logo_ljn.png" alt="Logo Kiri" class="logo">
                                <div class="company-info">
                                    <strong>PT. Area Near Urban Netsindo (ANUNET)</strong><br>
                                    Jl. Tirta Atmaja RT. 003 RW. 001<br>
                                    Desa Tugubandung, Kec. Kabandungan<br>
                                    Kab. Sukabumi - 43368<br>
                                    Hotline Service: 0812-9513-6503 <br>
                                </div>
                            </div>
                            <div class="col-6 text-end">
                                <img src="logo_anunet_new.png" alt="Logo Kanan" class="logo" style="display: block; margin-left: auto; margin-right: 0;">
                                <div class="invoice-title text-end">INVOICE TAGIHAN</div>
                                <div class="text-muted small">
                                    No: <?= htmlspecialchars($invoice['id_tagihan']) ?><br>
                                    Tanggal: <?= date('d M Y') ?>
                                    <?php if ($invoice['days_to_due'] !== null): ?>
                                        <br>
                                        <?php if ($invoice['days_to_due'] < 0): ?>
                                            <span style="color: #dc3545; font-weight: bold;">Terlambat <?= abs($invoice['days_to_due']) ?> hari</span>
                                        <?php elseif ($invoice['days_to_due'] <= 3): ?>
                                            <span style="color: #dc3545; font-weight: bold;">H-<?= $invoice['days_to_due'] ?></span>
                                        <?php elseif ($invoice['days_to_due'] <= 7): ?>
                                            <span style="color: #F8AC59; font-weight: bold;">H-<?= $invoice['days_to_due'] ?></span>
                                        <?php else: ?>
                                            <span style="color: #26B99A;">H-<?= $invoice['days_to_due'] ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="customer-info text-end mt-1">
                                    <strong>Kepada Yth:</strong><br>
                                    <strong><?= htmlspecialchars($invoice['nama_pelanggan']) ?></strong><br>
                                    <?= htmlspecialchars($invoice['alamat_pelanggan'] ?? 'Alamat tidak tersedia') ?><br>
                                    Telp: <?= htmlspecialchars($invoice['no_telp'] ?? 'Tidak ada') ?><br>
                                    <span class="badge bg-secondary">ID: <?= $invoice['id_pelanggan'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <table class="table table-bordered">
                        <tr>
                            <td width="35%"><strong>Paket Internet</strong></td>
                            <td><?= htmlspecialchars($invoice['nama_paket'] ?? 'Paket tidak tersedia') ?></td>
                        </tr>
                        <tr>
                            <td><strong>Periode Tagihan</strong></td>
                            <td>
                                <?php 
                                $bulan_indo = [
                                    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                                    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                                    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
                                ];
                                echo $bulan_indo[$invoice['bulan_tagihan']] . ' ' . $invoice['tahun_tagihan'];
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Jatuh Tempo</strong></td>
                            <td><?= date('d M Y', strtotime($invoice['tgl_jatuh_tempo'])) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Status Pembayaran</strong></td>
                            <td class="<?= ($invoice['status_tagihan'] == 'sudah_bayar') ? 'status-paid' : 'status-unpaid' ?>">
                                <?php
                                $status_text = [
                                    'sudah_bayar' => 'LUNAS',
                                    'belum_bayar' => 'BELUM LUNAS',
                                    'terlambat' => 'TERLAMBAT'
                                ];
                                echo $status_text[$invoice['status_tagihan']] ?? 'BELUM LUNAS';
                                ?>
                            </td>
                        </tr>
                    </table>

                    <table class="table table-bordered">
                        <thead>
                            <tr class="text-center">
                                <th width="70%">Deskripsi Layanan</th>
                                <th width="30%">Jumlah (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <strong>Tagihan Internet <?= htmlspecialchars($invoice['nama_paket'] ?? 'Paket Internet') ?></strong><br>
                                    <small>Periode: <?= $bulan_indo[$invoice['bulan_tagihan']] . ' ' . $invoice['tahun_tagihan'] ?></small><br>
                                    <small class="text-muted">Jatuh Tempo: <?= date('d/m/Y', strtotime($invoice['tgl_jatuh_tempo'])) ?></small>
                                </td>
                                <td class="text-end">Rp. <?= number_format($invoice['jumlah_tagihan'], 0, ',', '.') ?></td>
                            </tr>
                            <tr class="total-row">
                                <td class="text-end"><strong>TOTAL TAGIHAN</strong></td>
                                <td class="text-end"> 
                                    <strong>Rp. <?= number_format($invoice['jumlah_tagihan'], 0, ',', '.') ?></strong>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="payment-info small">
                        <div class="row">
                            <div class="col-8">
                                <strong>INFORMASI PEMBAYARAN:</strong><br>
                                <div class="mt-1">
                                    <strong>Transfer Bank:</strong><br>
                                    Bank BRI PALASARI GIRANG<br>
                                    No. Rek: 4098-0104-1754-534<br>
                                    a.n. YANI MASLIAN
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <strong>Batas Pembayaran:</strong><br>
                                <span class="text-danger">
                                    <?= date('d M Y', strtotime($invoice['tgl_jatuh_tempo'])) ?>
                                </span>
                                <br><br>
                                <strong>Status:</strong><br>
                                <span class="<?= ($invoice['status_tagihan'] == 'sudah_bayar') ? 'text-success' : 'text-danger' ?>">
                                    <?= $status_text[$invoice['status_tagihan']] ?? 'BELUM LUNAS' ?>
                                </span>
                            </div>
                        </div>
                        <div class="row mt-1">
                            <div class="col-12">
                                <div class="text-muted">
                                    * Konfirmasi pembayaran: WA 0812-9513-6503<br>
                                    * Layanan akan otomatis terputus jika melewati batas pembayaran<br>
                                    * Invoice ID: <strong><?= htmlspecialchars($invoice['id_tagihan']) ?></strong> 
                                    | Print: <?= date('d/m/Y H:i') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (count($currentInvoices) == 1): ?>
                    <div class="invoice-card" style="border: none; height: 138mm;">
                        <div class="text-center text-muted" style="padding-top: 60mm;">
                            <i class="bi bi-file-earmark-text" style="font-size: 48px; opacity: 0.3;"></i><br>
                            <small>Halaman kosong - hanya 1 invoice pada halaman ini</small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endfor; ?>
    <?php endif; ?>

    <div class="no-print" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
        <div class="row">
            <div class="col-md-6">
                <h6><i class="bi bi-info-circle"></i> Informasi Cetak:</h6>
                <ul class="small mb-0">
                    <li>Format: A4 Portrait (2 invoice per halaman)</li>
                    <li>Total Invoice: <?= count($invoices) ?> buah</li>
                    <li>Total Halaman: <?= $pages ?> halaman</li>
                    <li>Filter: <?= htmlspecialchars($filter_text) ?></li>
                    <li>Tanggal Cetak: <?= date('d M Y H:i:s') ?></li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6><i class="bi bi-person-check"></i> Dicetak oleh:</h6>
                <ul class="small mb-0">
                    <li>User: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></li>
                    <li>Sistem: Billing Management ANUNET</li>
                    <li>Feature: Print All Invoices with Filters</li>
                    <li>H-10 Logic: <?= $h_plus_10 ?></li>
                </ul>
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col-12">
                <h6><i class="bi bi-bar-chart"></i> Ringkasan Status:</h6>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number text-success"><?= $status_counts['sudah_bayar'] ?></div>
                        <div class="stat-label">Lunas</div>
                        <small><?= count($invoices) > 0 ? round($status_counts['sudah_bayar']/count($invoices)*100,1) : 0 ?>%</small>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number text-warning"><?= $status_counts['belum_bayar'] ?></div>
                        <div class="stat-label">Belum Bayar</div>
                        <small><?= count($invoices) > 0 ? round($status_counts['belum_bayar']/count($invoices)*100,1) : 0 ?>%</small>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number text-danger"><?= $status_counts['terlambat'] ?></div>
                        <div class="stat-label">Terlambat</div>
                        <small><?= count($invoices) > 0 ? round($status_counts['terlambat']/count($invoices)*100,1) : 0 ?>%</small>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number text-primary">Rp <?= number_format($total_tagihan, 0, ',', '.') ?></div>
                        <div class="stat-label">Total Value</div>
                        <small>Dalam Rupiah</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.onload = function() {
            // Auto print if requested
            if (window.location.search.indexOf('autoprint') > -1) {
                setTimeout(function() {
                    window.print();
                    if (window.location.search.indexOf('autoclose') > -1) {
                        setTimeout(window.close, 500);
                    }
                }, 1000);
            }
        };
        
        // Print shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
        
        // Print completion tracking
        window.addEventListener('beforeprint', function() {
            console.log('Print dialog opened for all invoices (<?= count($invoices) ?> total)');
            console.log('Filters applied: <?= addslashes($filter_text) ?>');
        });
        
        window.addEventListener('afterprint', function() {
            console.log('Print dialog closed');
            // Optional: Show notification or redirect
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>