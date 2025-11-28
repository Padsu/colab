<?php
// mutasi_keuangan.php - FIXED VERSION with detailed payment information

ob_start();
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

// Handle form submission for adding new transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_transaction') {
    try {
        $tanggal = trim($_POST['tanggal'] ?? '');
        $jenis = trim($_POST['jenis'] ?? '');
        $kategori = trim($_POST['kategori'] ?? '');
        $keterangan = trim($_POST['keterangan'] ?? '');
        $jumlah = (float)($_POST['jumlah'] ?? 0);

        // Validation
        $errors = [];
        if (empty($tanggal)) $errors[] = 'Tanggal wajib diisi';
        if (empty($jenis)) $errors[] = 'Jenis transaksi wajib dipilih';
        if (empty($kategori)) $errors[] = 'Kategori wajib diisi';
        if ($jumlah <= 0) $errors[] = 'Jumlah harus lebih dari 0';

        if (empty($errors)) {
            $stmt = $mysqli->prepare("INSERT INTO transaksi_lain (tanggal, jenis, kategori, keterangan, jumlah, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $user_id = $_SESSION['user_id'] ?? null;
            $stmt->bind_param('ssssdi', $tanggal, $jenis, $kategori, $keterangan, $jumlah, $user_id);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Transaksi berhasil ditambahkan!';
            } else {
                $_SESSION['error_message'] = 'Gagal menambahkan transaksi!';
            }
            $stmt->close();
        } else {
            $_SESSION['error_message'] = implode('<br>', $errors);
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    }
}

// Handle delete transaction
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $mysqli->prepare("DELETE FROM transaksi_lain WHERE id = ?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = 'Transaksi berhasil dihapus!';
        } else {
            $_SESSION['error_message'] = 'Gagal menghapus transaksi!';
        }
        $stmt->close();
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    }
    header("Location: mutasi_keuangan.php");
    exit;
}

// Filter parameters
$jenis_filter = $_GET['jenis'] ?? '';
$kategori_filter = $_GET['kategori'] ?? '';
$tanggal_dari = $_GET['tanggal_dari'] ?? date('Y-m-01'); // First day of current month
$tanggal_sampai = $_GET['tanggal_sampai'] ?? date('Y-m-t'); // Last day of current month

// Pagination
$limit = 50;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Build query conditions
$where_conditions = [];
$params = [];
$types = '';

if (!empty($jenis_filter)) {
    $where_conditions[] = "jenis = ?";
    $params[] = $jenis_filter;
    $types .= 's';
}

if (!empty($kategori_filter)) {
    $where_conditions[] = "kategori = ?";
    $params[] = $kategori_filter;
    $types .= 's';
}

if (!empty($tanggal_dari)) {
    $where_conditions[] = "tanggal >= ?";
    $params[] = $tanggal_dari;
    $types .= 's';
}

if (!empty($tanggal_sampai)) {
    $where_conditions[] = "tanggal <= ?";
    $params[] = $tanggal_sampai;
    $types .= 's';
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get transactions data - FIXED: Only from transaksi_lain table (pembayaran already included via payment.php)
$main_query = "SELECT tl.*, u.nama_lengkap as created_by_name 
               FROM transaksi_lain tl 
               LEFT JOIN users u ON tl.created_by = u.id_user 
               $where_clause 
               ORDER BY tl.tanggal DESC, tl.created_at DESC 
               LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $mysqli->prepare($main_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM transaksi_lain $where_clause";
$count_params = array_slice($params, 0, -2); // Remove limit and offset
$count_types = substr($types, 0, -2);

$count_stmt = $mysqli->prepare($count_query);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);
$count_stmt->close();

// Get summary statistics - FIXED: Only from transaksi_lain (no double counting)
$summary_query = "SELECT 
    SUM(CASE WHEN jenis = 'pemasukan' THEN jumlah ELSE 0 END) as total_pemasukan,
    SUM(CASE WHEN jenis = 'pengeluaran' THEN jumlah ELSE 0 END) as total_pengeluaran,
    COUNT(CASE WHEN jenis = 'pemasukan' THEN 1 END) as count_pemasukan,
    COUNT(CASE WHEN jenis = 'pengeluaran' THEN 1 END) as count_pengeluaran,
    SUM(CASE WHEN jenis = 'pemasukan' AND kategori = 'Pembayaran Pelanggan' THEN jumlah ELSE 0 END) as total_pembayaran_pelanggan,
    COUNT(CASE WHEN jenis = 'pemasukan' AND kategori = 'Pembayaran Pelanggan' THEN 1 END) as count_pembayaran_pelanggan
    FROM transaksi_lain $where_clause";

$summary_stmt = $mysqli->prepare($summary_query);
if (!empty($count_params)) {
    $summary_stmt->bind_param($count_types, ...$count_params);
}
$summary_stmt->execute();
$summary = $summary_stmt->get_result()->fetch_assoc();
$summary_stmt->close();

// Calculate totals
$total_pemasukan = $summary['total_pemasukan'] ?? 0;
$total_pengeluaran = $summary['total_pengeluaran'] ?? 0;
$saldo = $total_pemasukan - $total_pengeluaran;

// Get unique categories for filter
$categories_query = "SELECT DISTINCT kategori FROM transaksi_lain ORDER BY kategori";
$categories_result = $mysqli->query($categories_query);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mutasi Keuangan</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .summary-card {
            transition: transform 0.2s ease-in-out;
            border-left: 4px solid;
        }
        .summary-card:hover {
            transform: translateY(-2px);
        }
        .summary-card.pemasukan {
            border-left-color: #28a745;
        }
        .summary-card.pengeluaran {
            border-left-color: #dc3545;
        }
        .summary-card.saldo {
            border-left-color: #007bff;
        }
        .transaction-row.pemasukan {
            border-left: 3px solid #28a745;
        }
        .transaction-row.pengeluaran {
            border-left: 3px solid #dc3545;
        }
        .btn-group-actions {
            gap: 5px;
        }
        .form-floating-custom {
            position: relative;
        }
        .table-responsive {
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .payment-detail {
            font-size: 0.9em;
            color: #666;
            font-style: italic;
        }
        .transaction-customer {
            background-color: rgba(40, 167, 69, 0.1);
        }
    </style>
</head>
<body>

<div class="main-content">
    <main class="container-fluid mt-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3><i class="bi bi-cash-stack"></i> Mutasi Keuangan</h3>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                <i class="bi bi-plus-circle"></i> Tambah Transaksi
            </button>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= $_SESSION['error_message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card summary-card pemasukan h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title text-success">Total Pemasukan</h6>
                                <h4 class="text-success">Rp <?= number_format($total_pemasukan, 0, ',', '.') ?></h4>
                                <small class="text-muted">
                                    <?= $summary['count_pemasukan'] ?? 0 ?> transaksi
                                </small>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-arrow-up-circle fs-1 text-success"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card summary-card pengeluaran h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title text-danger">Total Pengeluaran</h6>
                                <h4 class="text-danger">Rp <?= number_format($total_pengeluaran, 0, ',', '.') ?></h4>
                                <small class="text-muted"><?= $summary['count_pengeluaran'] ?? 0 ?> transaksi</small>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-arrow-down-circle fs-1 text-danger"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card summary-card saldo h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title <?= $saldo >= 0 ? 'text-primary' : 'text-warning' ?>">Saldo Bersih</h6>
                                <h4 class="<?= $saldo >= 0 ? 'text-primary' : 'text-warning' ?>">
                                    Rp <?= number_format(abs($saldo), 0, ',', '.') ?>
                                </h4>
                                <small class="text-muted"><?= $saldo >= 0 ? 'Surplus' : 'Defisit' ?></small>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-wallet2 fs-1 <?= $saldo >= 0 ? 'text-primary' : 'text-warning' ?>"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card summary-card h-100" style="border-left-color: #6f42c1;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title" style="color: #6f42c1;">Pembayaran Pelanggan</h6>
                                <h4 style="color: #6f42c1;">Rp <?= number_format($summary['total_pembayaran_pelanggan'] ?? 0, 0, ',', '.') ?></h4>
                                <small class="text-muted"><?= $summary['count_pembayaran_pelanggan'] ?? 0 ?> pembayaran</small>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-people fs-1" style="color: #6f42c1;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-funnel"></i> Filter Transaksi</h6>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Jenis Transaksi</label>
                        <select name="jenis" class="form-select">
                            <option value="">Semua Jenis</option>
                            <option value="pemasukan" <?= $jenis_filter == 'pemasukan' ? 'selected' : '' ?>>Pemasukan</option>
                            <option value="pengeluaran" <?= $jenis_filter == 'pengeluaran' ? 'selected' : '' ?>>Pengeluaran</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Kategori</label>
                        <select name="kategori" class="form-select">
                            <option value="">Semua Kategori</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['kategori']) ?>" <?= $kategori_filter == $cat['kategori'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tanggal Dari</label>
                        <input type="date" name="tanggal_dari" class="form-control" value="<?= htmlspecialchars($tanggal_dari) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tanggal Sampai</label>
                        <input type="date" name="tanggal_sampai" class="form-control" value="<?= htmlspecialchars($tanggal_sampai) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-info w-100">
                            <i class="bi bi-search"></i> Filter
                        </button>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <a href="mutasi_keuangan.php" class="btn btn-secondary w-100">
                            <i class="bi bi-arrow-clockwise"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-list-ul"></i> Daftar Transaksi</h6>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="exportData('excel')">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="exportData('pdf')">
                        <i class="bi bi-file-earmark-pdf"></i> Export PDF
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th width="3%">No</th>
                                <th width="10%">Tanggal</th>
                                <th width="8%">Jenis</th>
                                <th width="15%">Kategori</th>
                                <th width="30%">Keterangan</th>
                                <th width="12%">Jumlah</th>
                                <th width="12%">Dibuat Oleh</th>
                                <th width="8%">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="bi bi-inbox fs-1 text-muted"></i><br>
                                        <span class="text-muted">Tidak ada transaksi ditemukan</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr class="transaction-row <?= $transaction['jenis'] ?> <?= $transaction['kategori'] == 'Pembayaran Pelanggan' ? 'transaction-customer' : '' ?>">
                                        <td><?= $no++ ?></td>
                                        <td><?= date('d/m/Y', strtotime($transaction['tanggal'])) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $transaction['jenis'] == 'pemasukan' ? 'success' : 'danger' ?>">
                                                <i class="bi bi-arrow-<?= $transaction['jenis'] == 'pemasukan' ? 'up' : 'down' ?>-circle"></i>
                                                <?= ucfirst($transaction['jenis']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $transaction['kategori'] == 'Pembayaran Pelanggan' ? 'primary' : 'secondary' ?>">
                                                <?php if ($transaction['kategori'] == 'Pembayaran Pelanggan'): ?>
                                                    <i class="bi bi-people"></i>
                                                <?php endif; ?>
                                                <?= htmlspecialchars($transaction['kategori']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?= htmlspecialchars($transaction['keterangan']) ?></div>
                                            <?php if ($transaction['kategori'] == 'Pembayaran Pelanggan'): ?>
                                                <small class="payment-detail">
                                                    <i class="bi bi-credit-card"></i> Pembayaran dari pelanggan
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-<?= $transaction['jenis'] == 'pemasukan' ? 'success' : 'danger' ?> fw-bold">
                                            Rp <?= number_format($transaction['jumlah'], 0, ',', '.') ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($transaction['created_by_name'] ?? 'System') ?><br>
                                                <span style="font-size: 0.75em;"><?= date('d/m/Y H:i', strtotime($transaction['created_at'])) ?></span>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group-actions d-flex">
                                                <?php if ($transaction['kategori'] != 'Pembayaran Pelanggan'): ?>
                                                    <button type="button" class="btn btn-outline-primary btn-sm" 
                                                            onclick="editTransaction(<?= $transaction['id'] ?>)" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <a href="?delete=<?= $transaction['id'] ?>" 
                                                       class="btn btn-outline-danger btn-sm" 
                                                       onclick="return confirm('Apakah Anda yakin ingin menghapus transaksi ini?')"
                                                       title="Hapus">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="badge bg-info" title="Transaksi dari pembayaran pelanggan tidak dapat diedit">
                                                        <i class="bi bi-lock"></i> Auto
                                                    </span>
                                                <?php endif; ?>
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

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav aria-label="Pagination" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                            <i class="bi bi-chevron-left"></i> Previous
                        </a>
                    </li>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                            Next <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>

        <div class="text-center text-muted mt-3">
            Menampilkan <?= count($transactions) ?> dari <?= $total_records ?> total transaksi
        </div>
    </main>
</div>

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTransactionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Tambah Transaksi Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addTransactionForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_transaction">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="tanggal" class="form-label">Tanggal Transaksi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="tanggal" name="tanggal" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="jenis" class="form-label">Jenis Transaksi <span class="text-danger">*</span></label>
                                <select class="form-select" id="jenis" name="jenis" required>
                                    <option value="">-- Pilih Jenis --</option>
                                    <option value="pemasukan">Pemasukan</option>
                                    <option value="pengeluaran">Pengeluaran</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="kategori" class="form-label">Kategori <span class="text-danger">*</span></label>
                                <select class="form-select" id="kategori" name="kategori" required>
                                    <option value="">-- Pilih Kategori --</option>
                                </select>
                                <div class="form-text">
                                    <input type="text" class="form-control form-control-sm mt-2" id="kategori_baru" placeholder="Atau ketik kategori baru...">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="jumlah" class="form-label">Jumlah (Rp) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="jumlah" name="jumlah" min="1" step="1" required>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="keterangan" class="form-label">Keterangan</label>
                        <textarea class="form-control" id="keterangan" name="keterangan" rows="3" placeholder="Deskripsi detail transaksi..."></textarea>
                    </div>

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Info:</strong> Pembayaran pelanggan akan otomatis tercatat saat ada pembayaran tagihan. 
                        Form ini untuk transaksi lain seperti pengeluaran operasional atau pemasukan non-pelanggan.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save"></i> Simpan Transaksi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Predefined categories based on transaction type
const categories = {
    pemasukan: [
        'Biaya Pasang Baru (PSB)',
        'Pendapatan Instalasi',
        'Pendapatan Maintenance',
        'Bunga Bank',
        'Investasi',
        'Hibah/Donasi',
        'Penjualan Aset',
        'Pendapatan Lain-lain'
    ],
    pengeluaran: [
        'Gaji Karyawan',
        'Biaya Internet/Bandwidth',
        'Listrik',
        'Maintenance Peralatan',
        'Pembelian Peralatan',
        'Sewa Tempat',
        'Transportasi',
        'Komunikasi',
        'Pajak',
        'Asuransi',
        'ATK & Supplies',
        'Marketing',
        'Operasional Lain-lain'
    ]
};

document.addEventListener('DOMContentLoaded', function() {
    const jenisSelect = document.getElementById('jenis');
    const kategoriSelect = document.getElementById('kategori');
    const kategoriBaru = document.getElementById('kategori_baru');

    // Update categories when transaction type changes
    jenisSelect.addEventListener('change', function() {
        const jenis = this.value;
        kategoriSelect.innerHTML = '<option value="">-- Pilih Kategori --</option>';
        
        if (jenis && categories[jenis]) {
            categories[jenis].forEach(function(cat) {
                const option = document.createElement('option');
                option.value = cat;
                option.textContent = cat;
                kategoriSelect.appendChild(option);
            });
        }
        
        kategoriSelect.disabled = !jenis;
    });

    // Handle new category input
    kategoriBaru.addEventListener('input', function() {
        if (this.value.trim()) {
            kategoriSelect.value = this.value.trim();
            // Add new option if it doesn't exist
            if (!Array.from(kategoriSelect.options).some(option => option.value === this.value.trim())) {
                const option = document.createElement('option');
                option.value = this.value.trim();
                option.textContent = this.value.trim();
                option.selected = true;
                kategoriSelect.appendChild(option);
            }
        }
    });

    kategoriSelect.addEventListener('change', function() {
        if (this.value) {
            kategoriBaru.value = '';
        }
    });

    // Form validation
    document.getElementById('addTransactionForm').addEventListener('submit', function(e) {
        const jenis = document.getElementById('jenis').value;
        const kategori = document.getElementById('kategori').value;
        const jumlah = document.getElementById('jumlah').value;

        if (!jenis || !kategori || !jumlah || jumlah <= 0) {
            e.preventDefault();
            alert('Mohon lengkapi semua field yang wajib diisi!');
            return false;
        }

        return confirm(`Apakah Anda yakin ingin menambah transaksi ${jenis} sebesar Rp ${parseInt(jumlah).toLocaleString('id-ID')}?`);
    });
});

function editTransaction(id) {
    // TODO: Implement edit functionality
    alert('Fitur edit akan segera tersedia!');
}

function exportData(format) {
    const params = new URLSearchParams(window.location.search);
    params.append('export', format);
    window.open('export_mutasi_keuangan.php?' + params.toString(), '_blank');
}
</script>

<?php 
require_once __DIR__ . '/../templates/footer.php';
ob_end_flush();
?>