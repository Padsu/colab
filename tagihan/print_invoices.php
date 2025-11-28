<?php
// print_invoice.php

session_start();
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load configurations
require_once __DIR__ . '/../config/config_database.php';

// Database connection already available from config_database.php as $mysqli

// Function to convert image to base64
function getImageAsBase64($imagePath) {
    if (file_exists($imagePath) && is_readable($imagePath)) {
        clearstatcache(true, $imagePath);
        $image_data = file_get_contents($imagePath);
        if ($image_data !== false) {
            $image_info = getimagesize($imagePath);
            $mime_type = $image_info['mime'] ?? 'image/png';
            return 'data:' . $mime_type . ';base64,' . base64_encode($image_data);
        }
    }
    return null;
}

// Function to get invoice data from database
function getInvoiceFromDatabase($invoiceId, $mysqli) {
    // FIXED: Updated column names to match actual database structure
    $stmt = $mysqli->prepare("SELECT t.*, 
                             p.nama_pelanggan, 
                             p.alamat_pelanggan as alamat, 
                             p.telepon_pelanggan as no_telp, 
                             pi.nama_paket, pi.harga as harga_paket,
                             pb.tanggal_bayar, pb.metode_bayar, pb.keterangan
                             FROM tagihan t 
                             JOIN data_pelanggan p ON t.id_pelanggan = p.id_pelanggan 
                             LEFT JOIN paket_internet pi ON p.id_paket = pi.id_paket
                             LEFT JOIN pembayaran pb ON t.id_tagihan = pb.id_tagihan
                             WHERE t.id_tagihan = ?");
    $stmt->bind_param("s", $invoiceId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Get invoice data from session or database
$data = null;
$invoice = null;

// Get company data from database
$company_query = "SELECT * FROM pengaturan_perusahaan ORDER BY id_pengaturan DESC LIMIT 1";
$company_result = $mysqli->query($company_query);
$company_data = $company_result->fetch_assoc();

// Default company data if not found in database
if (!$company_data) {
    $company_data = [
        'nama_perusahaan' => 'PT. AREA NEAR URBAN NETSINDO (ANUNET)',
        'alamat_perusahaan' => 'Kp. Tangkolo RT. 03 RW. 01, Desa Tugubandung, Kec. Kabandungan - Kab. Sukabumi',
        'telepon_perusahaan' => '+6285211352239',
        'email_perusahaan' => 'info@anunet.id',
        'bank_nama' => 'BRI CABANG PALASARI GIRANG',
        'bank_atas_nama' => 'YANI MASLIAN',
        'bank_no_rekening' => '4098-0104-1754-534',
        'logo_perusahaan' => null
    ];
}

// Convert logo to base64 untuk bypass .htaccess
$company_logo_base64 = null;
$debug_logo_info = []; // Debug info

if (!empty($company_data['logo_perusahaan'])) {
    // Try database path first
    $logo_path = __DIR__ . '/' . $company_data['logo_perusahaan'];
    $debug_logo_info[] = "DB Logo Path: " . $company_data['logo_perusahaan'];
    $debug_logo_info[] = "Full Logo Path: " . $logo_path;
    $debug_logo_info[] = "File Exists: " . (file_exists($logo_path) ? 'YES' : 'NO');
    
    $company_logo_base64 = getImageAsBase64($logo_path);
    $debug_logo_info[] = "Base64 from DB path: " . ($company_logo_base64 ? 'YES' : 'NO');
    
    // Try alternative paths if failed
    if (!$company_logo_base64) {
        $alternative_paths = [
            __DIR__ . '/../' . $company_data['logo_perusahaan'],
            __DIR__ . '/../../' . $company_data['logo_perusahaan'],
            $company_data['logo_perusahaan'] // Relative path
        ];
        
        foreach ($alternative_paths as $alt_path) {
            $debug_logo_info[] = "Trying: " . $alt_path . " -> " . (file_exists($alt_path) ? 'EXISTS' : 'NOT FOUND');
            if (file_exists($alt_path)) {
                $company_logo_base64 = getImageAsBase64($alt_path);
                if ($company_logo_base64) {
                    $debug_logo_info[] = "SUCCESS with: " . $alt_path;
                    break;
                }
            }
        }
    }
} else {
    // If database empty, check default locations
    $debug_logo_info[] = "Database logo empty, checking defaults...";
    $default_logo_paths = [
        __DIR__ . '/../img/logo.png',
        __DIR__ . '/../../img/logo.png',
        __DIR__ . '/../assets/images/logo.png',
        __DIR__ . '/../../assets/images/logo.png',
        __DIR__ . '/../login.png',
        __DIR__ . '/../../login.png'
    ];
    
    foreach ($default_logo_paths as $path) {
        $debug_logo_info[] = "Checking: " . $path . " -> " . (file_exists($path) ? 'EXISTS' : 'NOT FOUND');
        if (file_exists($path)) {
            $company_logo_base64 = getImageAsBase64($path);
            if ($company_logo_base64) {
                $debug_logo_info[] = "SUCCESS with: " . $path;
                break;
            }
        }
    }
}

$debug_logo_info[] = "Final Base64 Result: " . ($company_logo_base64 ? 'YES' : 'NO');

if (isset($_SESSION['invoice_data'])) {
    // Data from payment confirmation
    $data = $_SESSION['invoice_data'];
    $invoice = $data['invoice'];
    unset($_SESSION['invoice_data']);
} elseif (isset($_GET['invoice_id']) && !empty($_GET['invoice_id'])) {
    // Direct access with invoice ID
    $invoiceId = $_GET['invoice_id'];
    $invoice = getInvoiceFromDatabase($invoiceId, $mysqli);
    
    if (!$invoice) {
        $_SESSION['error_message'] = 'Tagihan tidak ditemukan';
        header("Location: data_tagihan.php");
        exit;
    }
    
    // Build data structure for consistent handling
    $data = [
        'invoice' => $invoice,
        'amount' => $invoice['jumlah_tagihan'],
        'diskon' => 0, // Diskon info stored in keterangan
        'metode_pembayaran' => $invoice['metode_bayar'] ?? 'Transfer',
        'tanggal_bayar' => $invoice['tanggal_bayar'] ?? date('Y-m-d'),
        'invoice_number' => $invoice['id_tagihan']
    ];
} else {
    $_SESSION['error_message'] = 'Data invoice tidak tersedia';
    header("Location: data_tagihan.php");
    exit;
}

// Extract data for easier use
$customer = $data['invoice'];
$amount = $data['amount'];
$diskon = $data['diskon'];
$metodePembayaran = $data['metode_pembayaran'];
$tanggalBayar = $data['tanggal_bayar'];
$invoiceNumber = $data['invoice_number'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Invoice - <?= htmlspecialchars($invoiceNumber) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body {
                font-size: 12pt;
                line-height: 1.2;
                padding: 0;
                margin: 0;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
            }
            .invoice-container {
                width: 100%;
                max-width: none;
                margin: 0;
                border: none;
                padding: 15px;
                box-shadow: none;
            }
            .container { padding: 0; margin: 0; }
            .card { border: none; box-shadow: none; }
            .page-break { page-break-after: always; }
        }

        body {
            background-color: #f8f9fa;
            font-size: 14px;
            line-height: 1.4;
        }

        .invoice-container {
            max-width: 900px;
            margin: 20px auto;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        .invoice-header {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: #2c3e50;
            padding: 30px;
            margin-bottom: 0;
            border-bottom: 3px solid #1abc9c;
        }

        .company-info {
            text-align: center;
            margin-bottom: 20px;
        }

        .invoice-details {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .invoice-details h6 {
            font-size: 0.8em;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .table th {
            background-color: #e9ecef;
            font-weight: 600;
            border-top: none;
        }

        .amount-row {
            font-size: 1.1em;
            font-weight: bold;
        }

        .payment-info {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 20px 0;
        }

        .footer-note {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .invoice-container {
                margin: 10px;
                width: calc(100% - 20px);
            }
            .invoice-header {
                padding: 20px;
            }
        }

        .logo-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .logo-container img {
            max-height: 120px;
            max-width: 200px;
            object-fit: contain;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            background-color: #28a745;
            color: white;
            border-radius: 20px;
            font-weight: bold;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="invoice-header">
            <div class="logo-container">
                <div>
                    <?php if ($company_logo_base64): ?>
                        <img src="<?= $company_logo_base64 ?>" alt="Logo Perusahaan" style="max-height: 120px; max-width: 200px; margin-bottom: 15px; object-fit: contain;">
                    <?php endif; ?>
                    <h2 class="mb-0"><?= htmlspecialchars($company_data['nama_perusahaan']) ?></h2>
                    <p class="mb-0">Internet Service Provider</p>
                </div>
                <div class="text-end">
                    <h4>INVOICE</h4>
                    <p class="mb-0"><?= htmlspecialchars($invoiceNumber) ?></p>
                    <span class="status-badge">LUNAS</span>
                </div>
            </div>
            
            <div class="company-info">
                <p class="mb-1">
                    <i class="bi bi-geo-alt-fill"></i> 
                    <?= htmlspecialchars($company_data['alamat_perusahaan']) ?>
                </p>
                <p class="mb-0">
                    <?php if (!empty($company_data['telepon_perusahaan'])): ?>
                        <i class="bi bi-telephone-fill"></i> <?= htmlspecialchars($company_data['telepon_perusahaan']) ?>
                    <?php endif; ?>
                    <?php if (!empty($company_data['email_perusahaan'])): ?>
                        <?= !empty($company_data['telepon_perusahaan']) ? ' | ' : '' ?>
                        <i class="bi bi-envelope-fill"></i> <?= htmlspecialchars($company_data['email_perusahaan']) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="card-body p-4">
            <!-- Invoice Details -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="invoice-details">
                        <h6 class="text-uppercase text-muted mb-3">Tagihan Kepada:</h6>
                        <h5 class="mb-2"><?= htmlspecialchars($customer['nama_pelanggan']) ?></h5>
                        <p class="mb-1"><?= htmlspecialchars($customer['alamat']) ?></p>
                        <p class="mb-1">
                            <i class="bi bi-telephone"></i> <?= htmlspecialchars($customer['no_telp']) ?>
                        </p>
                        <p class="mb-0">
                            <strong>ID Pelanggan:</strong> <?= htmlspecialchars($customer['id_pelanggan']) ?>
                        </p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="invoice-details">
                        <h6 class="text-uppercase text-muted mb-3">Detail Pembayaran:</h6>
                        <p class="mb-1"><strong>Tanggal Invoice:</strong> <?= date('d/m/Y', strtotime($tanggalBayar)) ?></p>
                        <p class="mb-1"><strong>Periode Tagihan:</strong> <?= date('F Y', strtotime($customer['tahun_tagihan'] . '-' . $customer['bulan_tagihan'] . '-01')) ?></p>
                        <p class="mb-1"><strong>Jatuh Tempo:</strong> <?= date('d/m/Y', strtotime($customer['tgl_jatuh_tempo'])) ?></p>
                        <p class="mb-1"><strong>Tanggal Bayar:</strong> <?= date('d/m/Y', strtotime($tanggalBayar)) ?></p>
                        <p class="mb-0"><strong>Metode Pembayaran:</strong> <?= htmlspecialchars($metodePembayaran) ?></p>
                    </div>
                </div>
            </div>

            <!-- Invoice Items -->
            <div class="table-responsive mb-4">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th width="60%">Deskripsi Layanan</th>
                            <th width="20%" class="text-center">Periode</th>
                            <th width="20%" class="text-end">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($customer['nama_paket']) ?></strong>
                                <br>
                                <small class="text-muted">Layanan Internet Bulanan</small>
                            </td>
                            <td class="text-center">
                                <?= date('F Y', strtotime($customer['tahun_tagihan'] . '-' . $customer['bulan_tagihan'] . '-01')) ?>
                            </td>
                            <td class="text-end">Rp <?= number_format($customer['jumlah_tagihan'], 0, ',', '.') ?></td>
                        </tr>
                        
                        <?php if ($diskon > 0): ?>
                        <tr>
                            <td colspan="2" class="text-end"><em>Diskon:</em></td>
                            <td class="text-end text-success">- Rp <?= number_format($diskon, 0, ',', '.') ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <tr class="table-primary amount-row">
                            <td colspan="2" class="text-end"><strong>TOTAL PEMBAYARAN:</strong></td>
                            <td class="text-end"><strong>Rp <?= number_format($amount, 0, ',', '.') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Payment Information -->
            <div class="payment-info">
                <h6 class="mb-3"><i class="bi bi-credit-card"></i> Informasi Pembayaran Selanjutnya</h6>
                <div class="row">
                    <div class="col-md-6">
                        <strong>Transfer Bank:</strong>
                        <ul class="list-unstyled mt-2 mb-0">
                            <li><strong>Bank:</strong> <?= htmlspecialchars($company_data['bank_nama'] ?? 'BRI CABANG PALASARI GIRANG') ?></li>
                            <li><strong>No. Rekening:</strong> <?= htmlspecialchars($company_data['bank_no_rekening'] ?? '4098-0104-1754-534') ?></li>
                            <li><strong>Atas Nama:</strong> <?= htmlspecialchars($company_data['bank_atas_nama'] ?? 'YANI MASLIAN') ?></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <strong>E-Wallet:</strong>
                        <ul class="list-unstyled mt-2 mb-0">
                            <li><strong>DANA/OVO/GOPAY:</strong> <?= htmlspecialchars(preg_replace('/[^0-9]/', '', $company_data['telepon_perusahaan'] ?? '085211352239')) ?></li>
                            <li><strong>Atas Nama:</strong> <?= htmlspecialchars($company_data['bank_atas_nama'] ?? 'YANI MASLIAN') ?></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Footer Note -->
            <div class="footer-note">
                <h6 class="mb-2"><i class="bi bi-info-circle"></i> Catatan Penting:</h6>
                <ul class="mb-0">
                    <li>Invoice ini adalah bukti pembayaran yang sah</li>
                    <li>Simpan invoice ini sebagai arsip</li>
                    <li>Untuk pertanyaan, hubungi customer service di +6285211352239</li>
                    <li>Terima kasih atas kepercayaan Anda menggunakan layanan ANUNET</li>
                </ul>
            </div>

            <!-- Signature -->
            <div class="row mt-4">
                <div class="col-6">
                    <p class="mb-1"><strong>Hormat kami,</strong></p>
                    <br><br>
                    <p class="mb-0">
                        <strong>Tim Finance</strong><br>
                        <small class="text-muted"><?= htmlspecialchars($company_data['nama_perusahaan']) ?></small>
                    </p>
                </div>
                <div class="col-6 text-end">
                    <div class="border rounded p-3" style="background-color: #f8f9fa;">
                        <strong>Status Pembayaran</strong><br>
                        <span class="badge bg-success fs-6">LUNAS</span><br>
                        <small>Dibayar pada: <?= date('d/m/Y H:i', strtotime($tanggalBayar)) ?></small>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex justify-content-between no-print mt-4 pt-3 border-top">
                <a href="data_tagihan.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Kembali ke Daftar Tagihan
                </a>
                <div>
                    <button onclick="window.print(); return false;" class="btn btn-primary me-2">
                        <i class="bi bi-printer"></i> Cetak Invoice
                    </button>
                    <button onclick="downloadPDF()" class="btn btn-success">
                        <i class="bi bi-download"></i> Download PDF
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto print if coming from payment confirmation
        if (window.location.search.includes('print=auto')) {
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 1000);
            };
        }

        // Handle after print
        window.onafterprint = function() {
            // Optional: redirect after printing
            setTimeout(function() {
                if (confirm('Kembali ke daftar tagihan?')) {
                    window.location.href = 'data_tagihan.php';
                }
            }, 500);
        };

        // Download PDF function (placeholder - requires PDF generation library)
        function downloadPDF() {
            alert('Fitur download PDF akan segera tersedia. Untuk saat ini, gunakan fungsi Print dan pilih "Save as PDF" di browser Anda.');
        }

        // Print function
        function printInvoice() {
            window.print();
        }
    </script>
</body>
</html>