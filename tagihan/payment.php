<?php
// payment.php - FIXED VERSION with correct column names and keterangan

ob_start(); // Start output buffering at the VERY TOP

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Load configurations
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Database connection already available from config_database.php as $mysqli

class InvoicePayment {
    private $mysqli;

    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }

    public function getInvoiceDetail($invoiceId) {
        // FIXED: Updated column names to match actual database structure
        $stmt = $this->mysqli->prepare("SELECT t.*, 
                                       p.nama_pelanggan, 
                                       p.alamat_pelanggan as alamat, 
                                       p.telepon_pelanggan as no_telp, 
                                       pi.nama_paket, 
                                       pi.harga as harga_paket
                                       FROM tagihan t 
                                       JOIN data_pelanggan p ON t.id_pelanggan = p.id_pelanggan 
                                       LEFT JOIN paket_internet pi ON p.id_paket = pi.id_paket
                                       WHERE t.id_tagihan = ?");
        $stmt->bind_param("s", $invoiceId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    public function processPayment($invoiceId, $metodePembayaran, $tanggalBayar, $diskon = 0) {
        // Get invoice details
        $invoice = $this->getInvoiceDetail($invoiceId);
        if (!$invoice) {
            throw new Exception("Tagihan tidak ditemukan");
        }

        if ($invoice['status_tagihan'] === 'sudah_bayar') {
            throw new Exception("Tagihan sudah dibayar sebelumnya");
        }

        // Calculate amount after discount
        $amount = $invoice['jumlah_tagihan'] - $diskon;
        
        // Start transaction
        $this->mysqli->begin_transaction();

        try {
            // Update invoice status
            $updateInvoice = $this->mysqli->prepare("UPDATE tagihan 
                                                   SET status_tagihan = 'sudah_bayar'
                                                   WHERE id_tagihan = ?");
            $updateInvoice->bind_param("s", $invoiceId);
            $updateInvoice->execute();

            // FIXED: Create detailed keterangan with customer name
            $bulanIndo = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
            ];
            
            $bulanTagihan = $bulanIndo[$invoice['bulan_tagihan']];
            $tahunTagihan = $invoice['tahun_tagihan'];
            $namaPelanggan = $invoice['nama_pelanggan'];
            
            $keterangan = "Pembayaran tagihan $namaPelanggan - periode $bulanTagihan $tahunTagihan";
            if ($diskon > 0) {
                $keterangan .= " (Diskon: Rp " . number_format($diskon, 0, ',', '.') . ")";
            }

            // Insert payment record
            $insertPayment = $this->mysqli->prepare("INSERT INTO pembayaran 
                (id_tagihan, id_pelanggan, tanggal_bayar, jumlah_bayar, metode_bayar, keterangan, id_user_pencatat) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            $userId = $_SESSION['user_id'] ?? null;
            
            $insertPayment->bind_param("sisdssi", 
                $invoiceId, 
                $invoice['id_pelanggan'], 
                $tanggalBayar, 
                $amount,
                $metodePembayaran,
                $keterangan,
                $userId
            );
            $insertPayment->execute();

            // ADDED: Insert into transaksi_lain for mutasi keuangan tracking
            $keteranganMutasi = "Pembayaran tagihan - $namaPelanggan ($invoiceId) - $bulanTagihan $tahunTagihan";
            if ($diskon > 0) {
                $keteranganMutasi .= " [Diskon: Rp " . number_format($diskon, 0, ',', '.') . "]";
            }
            $keteranganMutasi .= " - Metode: $metodePembayaran";

            $insertMutasi = $this->mysqli->prepare("INSERT INTO transaksi_lain 
                (tanggal, jenis, kategori, keterangan, jumlah, created_by) 
                VALUES (?, 'pemasukan', 'Pembayaran Pelanggan', ?, ?, ?)");
            
            $insertMutasi->bind_param("ssdi", 
                $tanggalBayar, 
                $keteranganMutasi, 
                $amount, 
                $userId
            );
            $insertMutasi->execute();

            // Update customer last paid date and extend expired date if needed
            $newExpiredDate = date('Y-m-d', strtotime($invoice['tgl_jatuh_tempo'] . ' +1 month'));
            $updateCustomer = $this->mysqli->prepare("UPDATE data_pelanggan 
                                                     SET last_paid_date = ?, 
                                                         tgl_expired = GREATEST(COALESCE(tgl_expired, ?), ?)
                                                     WHERE id_pelanggan = ?");
            $updateCustomer->bind_param("sssi", $tanggalBayar, $newExpiredDate, $newExpiredDate, $invoice['id_pelanggan']);
            $updateCustomer->execute();

            $this->mysqli->commit();
            return true;
        } catch (Exception $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    private function validateDate($date) {
        if (empty($date)) return false;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}

// Validate invoice ID
if (!isset($_GET['invoice_id']) || empty($_GET['invoice_id'])) {
    $_SESSION['error_message'] = 'ID Tagihan tidak valid';
    header("Location: data_tagihan.php");
    exit;
}
$invoiceId = $_GET['invoice_id'];

// Initialize payment handler
$paymentHandler = new InvoicePayment($mysqli);

// Process payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate inputs
        $metodePembayaran = isset($_POST['metode_pembayaran']) ? trim($_POST['metode_pembayaran']) : '';
        $tanggalBayar = isset($_POST['tanggal_bayar']) ? trim($_POST['tanggal_bayar']) : '';
        $diskon = isset($_POST['diskon']) ? (int)$_POST['diskon'] : 0;
        $printInvoice = isset($_POST['print_invoice']) ? $_POST['print_invoice'] : 'no';

        $errors = [];
        if (empty($metodePembayaran)) $errors[] = 'Metode pembayaran harus dipilih';
        if (empty($tanggalBayar)) $errors[] = 'Tanggal pembayaran harus diisi';
        if ($diskon < 0) $errors[] = 'Diskon tidak boleh negatif';
        
        if (!empty($errors)) {
            throw new Exception(implode('<br>', $errors));
        }

        // Process payment
        $success = $paymentHandler->processPayment($invoiceId, $metodePembayaran, $tanggalBayar, $diskon);
        
        if ($success) {
            $_SESSION['success_message'] = "Pembayaran berhasil dicatat dan telah ditambahkan ke mutasi keuangan!";
            
            // Get invoice details for invoice printing
            $invoice = $paymentHandler->getInvoiceDetail($invoiceId);
            $_SESSION['invoice_data'] = [
                'invoice' => $invoice,
                'amount' => $invoice['jumlah_tagihan'] - $diskon,
                'diskon' => $diskon,
                'metode_pembayaran' => $metodePembayaran,
                'tanggal_bayar' => $tanggalBayar,
                'invoice_number' => $invoiceId
            ];
            
            if ($printInvoice === 'yes') {
                header("Location: print_invoice.php");
            } else {
                header("Location: data_tagihan.php");
            }
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
}

// Get invoice data for display
$invoice = $paymentHandler->getInvoiceDetail($invoiceId);
if (!$invoice) {
    $_SESSION['error_message'] = 'Tagihan tidak ditemukan';
    header("Location: data_tagihan.php");
    exit;
}

// Check if already paid
if ($invoice['status_tagihan'] === 'sudah_bayar') {
    $_SESSION['error_message'] = 'Tagihan sudah dibayar sebelumnya';
    header("Location: data_tagihan.php");
    exit;
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
.card-header h4 { font-size: 18px; font-weight: 500; color: var(--dark); margin: 0; }
.card-header.bg-primary { background-color: var(--primary) !important; }
.card-header.bg-primary h4 { color: white; }
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
.btn-lg { padding: 12px 24px; font-size: 16px; }
.form-label { font-size: 13px; font-weight: 500; color: var(--dark); margin-bottom: 5px; }
.form-control, .form-select { border: 1px solid #ddd; border-radius: 4px; padding: 8px 12px; font-size: 14px; transition: border-color 0.15s ease-in-out; }
.form-control:focus, .form-select:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 0.1rem rgba(26, 187, 156, 0.25); }
.form-control.bg-light { background-color: #f8f9fa; }
.is-invalid { border-color: var(--danger) !important; }
.invalid-feedback { color: var(--danger); display: none; width: 100%; margin-top: 0.25rem; font-size: 0.875em; }
.table { width: 100%; margin-bottom: 0; border-collapse: collapse; }
.table th, .table td { padding: 8px; vertical-align: middle; border-top: 1px solid #dee2e6; font-size: 14px; }
.table thead th { background-color: #f8f9fa; border-bottom: 2px solid #dee2e6; font-weight: 600; color: var(--dark); font-size: 13px; }
.table-sm th, .table-sm td { padding: 6px; font-size: 13px; }
.table-warning { background-color: rgba(248, 172, 89, 0.1); }
.invoice-info { background-color: #f8f9fa; border-radius: 6px; padding: 20px; border: 1px solid #dee2e6; }
.input-group { display: flex; }
.input-group-text { background-color: #f8f9fa; border: 1px solid #ddd; border-right: 0; padding: 8px 12px; border-radius: 4px 0 0 4px; font-size: 14px; }
.input-group .form-control { border-radius: 0 4px 4px 0; }
.form-check { margin-bottom: 1rem; }
.form-check-input { margin-right: 0.5rem; }
.form-check-label { font-size: 14px; }
.row { display: flex; flex-wrap: wrap; margin-left: -15px; margin-right: -15px; }
.col-md-6 { flex: 0 0 50%; max-width: 50%; padding-left: 15px; padding-right: 15px; }
.mb-3 { margin-bottom: 1rem; }
.d-flex { display: flex; }
.justify-content-between { justify-content: space-between; }
.me-1 { margin-right: 0.25rem; }
.me-2 { margin-right: 0.5rem; }
.text-danger { color: var(--danger); }
.text-muted { color: #6c757d; }
.text-white { color: white; }
.fw-bold { font-weight: 600; }
.container-fluid { width: 100%; padding-right: 0; padding-left: 0; }
.mt-4 { margin-top: 1.5rem; }
.close, .btn-close { background: none; border: none; font-size: 20px; cursor: pointer; color: #aaa; }
.close:hover, .btn-close:hover { color: #000; }
.alert-dismissible { position: relative; padding-right: 3rem; }
.fade.show { opacity: 1; }
@media (max-width: 768px) { 
    .col-md-6 { flex: 0 0 100%; max-width: 100%; } 
    .d-flex { flex-direction: column; gap: 1rem; }
    .table th, .table td { font-size: 12px; padding: 6px; }
}
</style>

<main class="main-content">
    <div class="page-title">
        <h1>Konfirmasi Pembayaran Tagihan</h1>
        <div class="page-subtitle">Form konfirmasi pembayaran tagihan pelanggan</div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
        <i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
    </div>
    <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <div class="card">
        <div class="card-header bg-primary">
            <h4><i class="fa fa-money-bill me-2"></i>Konfirmasi Pembayaran Tagihan</h4>
        </div>
        <div class="card-body">
            <form id="paymentForm" method="POST">
                <div class="row">
                    <div class="col-md-6">
                        <div class="invoice-info">
                            <h5 class="mb-3"><i class="fa fa-file-invoice me-2"></i>Detail Tagihan</h5>
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">ID Tagihan</th>
                                    <td><?= htmlspecialchars($invoice['id_tagihan']) ?></td>
                                </tr>
                                <tr>
                                    <th>Nama Pelanggan</th>
                                    <td><strong><?= htmlspecialchars($invoice['nama_pelanggan']) ?></strong></td>
                                </tr>
                                <tr>
                                    <th>No. Telp</th>
                                    <td><?= htmlspecialchars($invoice['no_telp']) ?></td>
                                </tr>
                                <tr>
                                    <th>Alamat</th>
                                    <td><?= htmlspecialchars($invoice['alamat']) ?></td>
                                </tr>
                                <tr>
                                    <th>Paket Internet</th>
                                    <td><?= htmlspecialchars($invoice['nama_paket']) ?></td>
                                </tr>
                                <tr>
                                    <th>Periode Tagihan</th>
                                    <td><?= date('F Y', strtotime($invoice['tahun_tagihan'] . '-' . $invoice['bulan_tagihan'] . '-01')) ?></td>
                                </tr>
                                <tr>
                                    <th>Jatuh Tempo</th>
                                    <td><?= date('d/m/Y', strtotime($invoice['tgl_jatuh_tempo'])) ?></td>
                                </tr>
                                <tr class="table-warning">
                                    <th>Jumlah Tagihan</th>
                                    <td><strong>Rp <span id="original_amount"><?= number_format($invoice['jumlah_tagihan'], 0, ',', '.') ?></span></strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h5 class="mb-3"><i class="fa fa-credit-card me-2"></i>Detail Pembayaran</h5>
                        
                        <div class="mb-3">
                            <label class="form-label">Tanggal Pembayaran <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="tanggal_bayar" value="<?= date('Y-m-d') ?>" required max="<?= date('Y-m-d') ?>">
                            <div class="invalid-feedback">Harap isi tanggal pembayaran</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Diskon (Rp)</label>
                            <input type="number" class="form-control" name="diskon" id="diskon" value="0" min="0" max="<?= $invoice['jumlah_tagihan'] ?>">
                            <div class="invalid-feedback">Diskon tidak valid</div>
                            <small class="text-muted">Maksimal: Rp <?= number_format($invoice['jumlah_tagihan'], 0, ',', '.') ?></small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Metode Pembayaran <span class="text-danger">*</span></label>
                            <select class="form-select" name="metode_pembayaran" required>
                                <option value="">-- Pilih Metode --</option>
                                <option value="CASH">CASH</option>
                                <option value="TRANSFER">TRANSFER</option>
                                <option value="E-WALLET">E-WALLET</option>
                                <option value="QRIS">QRIS</option>
                            </select>
                            <div class="invalid-feedback">Harap pilih metode pembayaran</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Total yang Harus Dibayar</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="text" class="form-control bg-light" id="total_amount" value="<?= number_format($invoice['jumlah_tagihan'], 0, ',', '.') ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="print_invoice" value="yes" id="printCheck" checked>
                                <label class="form-check-label" for="printCheck">
                                    Cetak invoice setelah pembayaran
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <hr>
                
<div class="d-flex justify-content-between">
    <a href="data_tagihan.php" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.875rem;">
        <i class="fa fa-arrow-left me-1"></i> Kembali
    </a>
    <button type="submit" class="btn btn-success" style="padding: 0.25rem 0.5rem; font-size: 0.875rem;">
        <i class="fa fa-check me-1"></i> Konfirmasi Pembayaran
    </button>
</div>
            </form>
        </div>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    const originalAmount = <?= $invoice['jumlah_tagihan'] ?>;
    
    function updateTotal() {
        const diskon = parseInt($('#diskon').val()) || 0;
        const total = originalAmount - diskon;
        $('#total_amount').val(total.toLocaleString('id-ID'));
    }
    
    $('#diskon').on('input', function() {
        const diskon = parseInt($(this).val()) || 0;
        const maxDiskon = originalAmount;
        
        if (diskon < 0 || diskon > maxDiskon) {
            $(this).addClass('is-invalid');
            $(this).next('.invalid-feedback').text('Diskon harus antara 0 sampai ' + maxDiskon.toLocaleString('id-ID')).show();
        } else {
            $(this).removeClass('is-invalid');
            $(this).next('.invalid-feedback').hide();
            updateTotal();
        }
    });
    
    $('#paymentForm').on('submit', function(e) {
        let isValid = true;
        
        // Reset all validation
        $('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').hide();
        
        // Validate required fields
        const metodePembayaran = $('[name="metode_pembayaran"]').val();
        const tanggalBayar = $('[name="tanggal_bayar"]').val();
        const diskon = parseInt($('#diskon').val()) || 0;
        
        if (!metodePembayaran) {
            $('[name="metode_pembayaran"]').addClass('is-invalid');
            $('[name="metode_pembayaran"]').next('.invalid-feedback').show();
            isValid = false;
        }
        
        if (!tanggalBayar) {
            $('[name="tanggal_bayar"]').addClass('is-invalid');
            $('[name="tanggal_bayar"]').next('.invalid-feedback').show();
            isValid = false;
        }
        
        if (diskon < 0 || diskon > originalAmount) {
            $('#diskon').addClass('is-invalid');
            $('#diskon').next('.invalid-feedback').show();
            isValid = false;
        }
        
        if (!isValid) {
            e.preventDefault();
            return false;
        }
        
        const totalAmount = originalAmount - diskon;
        const confirmMessage = `Konfirmasi pembayaran sebesar Rp ${totalAmount.toLocaleString('id-ID')} untuk tagihan ${$('#paymentForm input[name="invoice_id"]').val() || '<?= $invoice['id_tagihan'] ?>'}?\n\nPembayaran akan dicatat di mutasi keuangan.`;
        
        if (!confirm(confirmMessage)) {
            e.preventDefault();
            return false;
        }
    });
});
</script>

<?php 
// Include footer
require_once __DIR__ . '/../templates/footer.php';

// End output buffering and flush
ob_end_flush();
?>