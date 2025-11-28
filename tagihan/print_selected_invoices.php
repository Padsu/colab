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

// Get invoice IDs from URL parameter
$invoice_ids = $_GET['ids'] ?? '';

if (empty($invoice_ids)) {
    die("<div class='alert alert-danger'>Tidak ada ID tagihan yang dipilih.</div>");
}

// Convert comma-separated IDs to array and sanitize
$ids_array = array_map('trim', explode(',', $invoice_ids));
$ids_array = array_filter($ids_array); // Remove empty values

if (empty($ids_array)) {
    die("<div class='alert alert-danger'>ID tagihan tidak valid.</div>");
}

// Create placeholders for prepared statement
$placeholders = str_repeat('?,', count($ids_array) - 1) . '?';

try {
    // Get selected invoices with customer details
    $query = "SELECT t.*, dp.nama_pelanggan, dp.alamat as alamat_pelanggan, dp.no_telp, 
                     pi.nama_paket, pi.harga
              FROM tagihan t 
              JOIN data_pelanggan dp ON t.id_pelanggan = dp.id_pelanggan 
              LEFT JOIN paket_internet pi ON dp.id_paket = pi.id_paket
              WHERE t.id_tagihan IN ($placeholders)
              ORDER BY t.tgl_jatuh_tempo ASC, dp.nama_pelanggan ASC";
    
    $stmt = $mysqli->prepare($query);
    
    // Create string for bind_param (all strings)
    $types = str_repeat('s', count($ids_array));
    $stmt->bind_param($types, ...$ids_array);
    $stmt->execute();
    
    $invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($invoices)) {
        die("<div class='alert alert-warning'>Tidak ada tagihan yang ditemukan untuk ID yang dipilih.</div>");
    }
    
    // Calculate total amount
    $total_tagihan = 0;
    foreach ($invoices as $invoice) {
        $total_tagihan += $invoice['jumlah_tagihan'];
    }

} catch (Exception $e) { 
    die("<div class='alert alert-danger'>Database Error: " . $e->getMessage() . "</div>");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Invoice Terpilih</title>
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
        
        /* Enhanced styling for selected invoices */
        .print-header {
            background: linear-gradient(135deg, #1ABB9C 0%, #16a085 100%);
            color: white;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .print-summary {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            border-left: 4px solid #1ABB9C;
        }
        
        .invoice-id-badge {
            background: #007bff;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="no-print print-header">
        <h4><i class="bi bi-printer"></i> Cetak Invoice Terpilih</h4>
        <p class="mb-0">Sistem Billing Management - PT. Area Near Urban Netsindo</p>
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
            <div class="col-md-4 text-end">
                <strong>Total Invoice: <?= count($invoices) ?></strong><br>
                <strong>Total Tagihan: Rp <?= number_format($total_tagihan, 0, ',', '.') ?></strong>
            </div>
        </div>
        
        <div class="mt-2">
            <small class="text-muted">
                <strong>Invoice IDs:</strong> 
                <?php foreach ($invoices as $index => $invoice): ?>
                    <span class="invoice-id-badge"><?= htmlspecialchars($invoice['id_tagihan']) ?></span>
                    <?php if ($index < count($invoices) - 1): ?> <?php endif; ?>
                <?php endforeach; ?>
            </small>
        </div>
    </div>

    <?php if (empty($invoices)): ?>
        <div class="alert alert-info text-center">
            Tidak ada data tagihan yang ditemukan untuk invoice yang dipilih.
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
                    <li>Tanggal Cetak: <?= date('d M Y H:i:s') ?></li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6><i class="bi bi-person-check"></i> Dicetak oleh:</h6>
                <ul class="small mb-0">
                    <li>User: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></li>
                    <li>Sistem: Billing Management ANUNET</li>
                    <li>Feature: Print Selected Invoices</li>
                </ul>
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
            console.log('Print dialog opened for ' + <?= count($invoices) ?> + ' selected invoices');
        });
        
        window.addEventListener('afterprint', function() {
            console.log('Print dialog closed');
            // Optional: Show notification or redirect
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>