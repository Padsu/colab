<?php
// Start output buffering
ob_start();

// Error reporting for debugging
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

// Month names in Indonesian
$bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Default date range (current month)
$current_month = (int)date('m');
$current_year = (int)date('Y');

// Get filter parameters with proper validation
$month = isset($_GET['month']) ? (int)$_GET['month'] : $current_month;
$year = isset($_GET['year']) ? (int)$_GET['year'] : $current_year;
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'monthly';

// Validate month and year
if ($month < 1 || $month > 12) {
    $month = $current_month;
}
if ($year < 2020 || $year > 2100) {
    $year = $current_year;
}

// Ensure month is valid for array access
if (!isset($bulan[$month])) {
    $month = $current_month;
}

// Initialize variables
$total_income = 0;
$total_expense = 0;
$net_profit = 0;
$payment_count = 0;
$expense_count = 0;
$yearly_income = [];
$yearly_expense = [];
$error = '';

// Format rupiah function
function format_rupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Get financial data based on report type
try {
    // VARIABEL UNTUK MENYIMPAN DATA BULANAN
    $pembayaran_income = 0;
    $pembayaran_count = 0;
    $hotspot_income = 0;
    $hotspot_count = 0;
    $transaksi_income = 0;
    $transaksi_income_count = 0;
    $pengeluaran_expense = 0;
    $pengeluaran_count = 0;
    $transaksi_expense = 0;
    $transaksi_expense_count = 0;

    // ===== PENDAPATAN BULANAN =====
    
    // 1. Pendapatan dari pembayaran client
    $pembayaran_query = "SELECT 
                        COALESCE(SUM(jumlah_bayar), 0) as total_pembayaran,
                        COUNT(*) as payment_count
                        FROM pembayaran 
                        WHERE YEAR(tanggal_bayar) = ? AND MONTH(tanggal_bayar) = ?
                        AND jumlah_bayar > 0";
    
    $stmt = $mysqli->prepare($pembayaran_query);
    if ($stmt) {
        $stmt->bind_param("ii", $year, $month);
        $stmt->execute();
        $result = $stmt->get_result();
        $pembayaran_data = $result->fetch_assoc();
        if ($pembayaran_data && $pembayaran_data['total_pembayaran'] !== null) {
            $pembayaran_income = (float)$pembayaran_data['total_pembayaran'];
            $pembayaran_count = (int)$pembayaran_data['payment_count'];
        }
        $stmt->close();
    }

    // 2. Pendapatan dari penjualan hotspot (jika ada)
    $hotspot_query = "SELECT 
                     COALESCE(SUM(harga_jual), 0) as total_hotspot,
                     COUNT(*) as hotspot_count
                     FROM hotspot_sales 
                     WHERE YEAR(tanggal_jual) = ? AND MONTH(tanggal_jual) = ?
                     AND harga_jual > 0";
    
    $stmt = $mysqli->prepare($hotspot_query);
    if ($stmt) {
        $stmt->bind_param("ii", $year, $month);
        $stmt->execute();
        $result = $stmt->get_result();
        $hotspot_data = $result->fetch_assoc();
        if ($hotspot_data && $hotspot_data['total_hotspot'] !== null) {
            $hotspot_income = (float)$hotspot_data['total_hotspot'];
            $hotspot_count = (int)$hotspot_data['hotspot_count'];
        }
        $stmt->close();
    }

    // 3. Pendapatan dari transaksi lain (pemasukan)
    $transaksi_income_query = "SELECT 
                              COALESCE(SUM(jumlah), 0) as total_transaksi_masuk,
                              COUNT(*) as transaksi_count
                              FROM transaksi_lain 
                              WHERE YEAR(tanggal) = ? AND MONTH(tanggal) = ? 
                              AND jenis = 'pemasukan' AND jumlah > 0";
    
    $stmt = $mysqli->prepare($transaksi_income_query);
    if ($stmt) {
        $stmt->bind_param("ii", $year, $month);
        $stmt->execute();
        $result = $stmt->get_result();
        $transaksi_income_data = $result->fetch_assoc();
        if ($transaksi_income_data && $transaksi_income_data['total_transaksi_masuk'] !== null) {
            $transaksi_income = (float)$transaksi_income_data['total_transaksi_masuk'];
            $transaksi_income_count = (int)$transaksi_income_data['transaksi_count'];
        }
        $stmt->close();
    }

    // ===== PENGELUARAN BULANAN =====
    
    // 1. Pengeluaran dari tabel pengeluaran
    $pengeluaran_query = "SELECT 
                         COALESCE(SUM(jumlah), 0) as total_pengeluaran,
                         COUNT(*) as expense_count
                         FROM pengeluaran 
                         WHERE YEAR(tanggal) = ? AND MONTH(tanggal) = ?
                         AND jumlah > 0";
    
    $stmt = $mysqli->prepare($pengeluaran_query);
    if ($stmt) {
        $stmt->bind_param("ii", $year, $month);
        $stmt->execute();
        $result = $stmt->get_result();
        $pengeluaran_data = $result->fetch_assoc();
        if ($pengeluaran_data && $pengeluaran_data['total_pengeluaran'] !== null) {
            $pengeluaran_expense = (float)$pengeluaran_data['total_pengeluaran'];
            $pengeluaran_count = (int)$pengeluaran_data['expense_count'];
        }
        $stmt->close();
    }

    // 2. Pengeluaran dari transaksi lain
    $transaksi_expense_query = "SELECT 
                               COALESCE(SUM(jumlah), 0) as total_transaksi_keluar,
                               COUNT(*) as transaksi_count
                               FROM transaksi_lain 
                               WHERE YEAR(tanggal) = ? AND MONTH(tanggal) = ? 
                               AND jenis = 'pengeluaran' AND jumlah > 0";
    
    $stmt = $mysqli->prepare($transaksi_expense_query);
    if ($stmt) {
        $stmt->bind_param("ii", $year, $month);
        $stmt->execute();
        $result = $stmt->get_result();
        $transaksi_expense_data = $result->fetch_assoc();
        if ($transaksi_expense_data && $transaksi_expense_data['total_transaksi_keluar'] !== null) {
            $transaksi_expense = (float)$transaksi_expense_data['total_transaksi_keluar'];
            $transaksi_expense_count = (int)$transaksi_expense_data['transaksi_count'];
        }
        $stmt->close();
    }

    // ===== KALKULASI TOTAL BULANAN =====
    $total_income = $pembayaran_income + $hotspot_income + $transaksi_income;
    $payment_count = $pembayaran_count + $hotspot_count + $transaksi_income_count;
    $total_expense = $pengeluaran_expense + $transaksi_expense;
    $expense_count = $pengeluaran_count + $transaksi_expense_count;

    // ===== DATA TAHUNAN UNTUK GRAFIK (PENDEKATAN BARU) =====
    
    // Inisialisasi array untuk 12 bulan
    $monthly_income_data = array_fill(1, 12, 0);
    $monthly_expense_data = array_fill(1, 12, 0);

    // PENDAPATAN TAHUNAN - Query terpisah untuk setiap sumber per bulan
    for ($m = 1; $m <= 12; $m++) {
        $monthly_total_income = 0;
        
        // Pembayaran per bulan
        $monthly_pembayaran_query = "SELECT COALESCE(SUM(jumlah_bayar), 0) as income 
                                    FROM pembayaran 
                                    WHERE YEAR(tanggal_bayar) = ? AND MONTH(tanggal_bayar) = ?
                                    AND jumlah_bayar > 0";
        $stmt = $mysqli->prepare($monthly_pembayaran_query);
        if ($stmt) {
            $stmt->bind_param("ii", $year, $m);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ($row) {
                $monthly_total_income += (float)$row['income'];
            }
            $stmt->close();
        }
        
        // Hotspot per bulan
        $monthly_hotspot_query = "SELECT COALESCE(SUM(harga_jual), 0) as income 
                                 FROM hotspot_sales 
                                 WHERE YEAR(tanggal_jual) = ? AND MONTH(tanggal_jual) = ?
                                 AND harga_jual > 0";
        $stmt = $mysqli->prepare($monthly_hotspot_query);
        if ($stmt) {
            $stmt->bind_param("ii", $year, $m);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ($row) {
                $monthly_total_income += (float)$row['income'];
            }
            $stmt->close();
        }
        
        // Transaksi lain (pemasukan) per bulan
        $monthly_transaksi_income_query = "SELECT COALESCE(SUM(jumlah), 0) as income 
                                          FROM transaksi_lain 
                                          WHERE YEAR(tanggal) = ? AND MONTH(tanggal) = ? 
                                          AND jenis = 'pemasukan' AND jumlah > 0";
        $stmt = $mysqli->prepare($monthly_transaksi_income_query);
        if ($stmt) {
            $stmt->bind_param("ii", $year, $m);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ($row) {
                $monthly_total_income += (float)$row['income'];
            }
            $stmt->close();
        }
        
        $monthly_income_data[$m] = $monthly_total_income;
    }

    // PENGELUARAN TAHUNAN - Query terpisah untuk setiap sumber per bulan
    for ($m = 1; $m <= 12; $m++) {
        $monthly_total_expense = 0;
        
        // Pengeluaran per bulan
        $monthly_pengeluaran_query = "SELECT COALESCE(SUM(jumlah), 0) as expense 
                                     FROM pengeluaran 
                                     WHERE YEAR(tanggal) = ? AND MONTH(tanggal) = ?
                                     AND jumlah > 0";
        $stmt = $mysqli->prepare($monthly_pengeluaran_query);
        if ($stmt) {
            $stmt->bind_param("ii", $year, $m);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ($row) {
                $monthly_total_expense += (float)$row['expense'];
            }
            $stmt->close();
        }
        
        // Transaksi lain (pengeluaran) per bulan
        $monthly_transaksi_expense_query = "SELECT COALESCE(SUM(jumlah), 0) as expense 
                                           FROM transaksi_lain 
                                           WHERE YEAR(tanggal) = ? AND MONTH(tanggal) = ? 
                                           AND jenis = 'pengeluaran' AND jumlah > 0";
        $stmt = $mysqli->prepare($monthly_transaksi_expense_query);
        if ($stmt) {
            $stmt->bind_param("ii", $year, $m);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ($row) {
                $monthly_total_expense += (float)$row['expense'];
            }
            $stmt->close();
        }
        
        $monthly_expense_data[$m] = $monthly_total_expense;
    }

    // Convert untuk format yang dibutuhkan chart
    $yearly_income = [];
    $yearly_expense = [];
    for ($m = 1; $m <= 12; $m++) {
        $yearly_income[] = ['month' => $m, 'income' => $monthly_income_data[$m]];
        $yearly_expense[] = ['month' => $m, 'expense' => $monthly_expense_data[$m]];
    }

} catch (Exception $e) {
    $error = "Gagal mengambil data laporan: " . $e->getMessage();
}

// Hitung laba bersih
$net_profit = $total_income - $total_expense;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Keuangan | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
    
    .card-body {
      padding: 20px;
    }
    
    .form-label {
      font-weight: 500;
      font-size: 13px;
      color: var(--secondary);
      margin-bottom: 5px;
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
    
    .btn-info {
      background-color: var(--info);
      border-color: var(--info);
    }
    
    .btn-success {
      background-color: var(--success);
      border-color: var(--success);
    }
    
    .btn-danger {
      background-color: var(--danger);
      border-color: var(--danger);
    }
    
    .btn-warning {
      background-color: var(--warning);
      border-color: var(--warning);
      color: #333;
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
    
    .table {
      font-size: 13px;
      border-collapse: separate;
      border-spacing: 0;
    }
    
    .table thead th {
      background-color: var(--dark);
      color: white;
      font-weight: 500;
      padding: 12px 15px;
      border: none;
    }
    
    .table tbody td {
      padding: 12px 15px;
      vertical-align: middle;
      border-top: 1px solid #f1f1f1;
    }
    
    .table tbody tr:hover {
      background-color: rgba(26, 187, 156, 0.05);
    }
    
    .badge {
      font-size: 0.75em;
      font-weight: 500;
      padding: 5px 8px;
      border-radius: 50px;
    }
    
    .badge-success {
      background-color: var(--success);
    }
    
    .badge-warning {
      background-color: var(--warning);
      color: #333;
    }
    
    .badge-danger {
      background-color: var(--danger);
    }
    
    /* Summary cards */
    .summary-card {
      transition: transform 0.2s ease-in-out;
      border-left: 4px solid;
      height: 100%;
    }
    
    .summary-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .summary-card .card-body {
      padding: 15px;
    }
    
    /* Chart container */
    .chart-container {
      position: relative;
      height: 300px;
      width: 100%;
    }
    
    /* Status colors */
    .text-income {
      color: var(--success);
    }
    
    .text-expense {
      color: var(--danger);
    }
    
    .text-profit {
      color: var(--primary);
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
      .chart-container {
        height: 250px;
      }
    }
    
    @media print {
      .btn, .card-header .btn {
        display: none !important;
      }
      
      body {
        background-color: white !important;
      }
      
      .card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
      }
    }
    </style>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="container-fluid">
            <!-- Error Display -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="page-title">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1><i class="fas fa-chart-line me-2"></i>Laporan Keuangan</h1>
                        <p class="page-subtitle">Analisis pendapatan dan pengeluaran</p>
                    </div>
                    <div>
                        <a href="mutasi_keuangan.php" class="btn btn-primary">
                            <i class="fas fa-exchange-alt me-1"></i> Mutasi Keuangan
                        </a>
                        <button onclick="printReport()" class="btn btn-secondary">
                            <i class="fas fa-print me-1"></i> Cetak
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filter Form -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="report_type" class="form-label">Jenis Laporan</label>
                            <select name="report_type" id="report_type" class="form-select">
                                <option value="monthly" <?= $report_type == 'monthly' ? 'selected' : '' ?>>Bulanan</option>
                                <option value="yearly" <?= $report_type == 'yearly' ? 'selected' : '' ?>>Tahunan</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="month" class="form-label">Bulan</label>
                            <select name="month" id="month" class="form-select" <?= $report_type == 'yearly' ? 'disabled' : '' ?>>
                                <?php for($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>" <?= $month == $i ? 'selected' : '' ?>>
                                        <?= $bulan[$i] ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="year" class="form-label">Tahun</label>
                            <select name="year" id="year" class="form-select">
                                <?php for($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>>
                                        <?= $y ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-1"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Financial Summary -->
            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="card summary-card border-success">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h5 class="card-title text-success">Pendapatan</h5>
                                    <h3 class="text-success"><?= format_rupiah($total_income) ?></h3>
                                    <p class="card-text">
                                        <?= $payment_count ?> transaksi di <?= $bulan[$month] ?> <?= $year ?>
                                    </p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-money-bill-wave fa-3x text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 mb-3">
                    <div class="card summary-card border-danger">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h5 class="card-title text-danger">Pengeluaran</h5>
                                    <h3 class="text-danger"><?= format_rupiah($total_expense) ?></h3>
                                    <p class="card-text">
                                        <?= $expense_count ?> transaksi di <?= $bulan[$month] ?> <?= $year ?>
                                    </p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-receipt fa-3x text-danger"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 mb-3">
                    <div class="card summary-card border-primary">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h5 class="card-title text-primary">Laba Bersih</h5>
                                    <h3 class="<?= $net_profit >= 0 ? 'text-success' : 'text-danger' ?>">
                                        <?= format_rupiah($net_profit) ?>
                                    </h3>
                                    <p class="card-text">
                                        <?= $bulan[$month] ?> <?= $year ?>
                                    </p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-chart-bar fa-3x text-primary"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row mb-4">
                <div class="col-lg-12 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Grafik Pendapatan vs Pengeluaran Tahunan <?= $year ?></h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="incomeExpenseChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detail Breakdown (Debug Mode) -->
            <?php if (isset($_GET['debug']) && $_GET['debug'] == '1'): ?>
            <div class="row mb-4">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Detail Breakdown (Debug Mode)</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Pendapatan Detail (<?= $bulan[$month] ?> <?= $year ?>):</h6>
                                    <ul class="list-unstyled">
                                        <li><strong>Pembayaran Client:</strong> <?= format_rupiah($pembayaran_income) ?> <small class="text-muted">(<?= $pembayaran_count ?> transaksi)</small></li>
                                        <li><strong>Penjualan Hotspot:</strong> <?= format_rupiah($hotspot_income) ?> <small class="text-muted">(<?= $hotspot_count ?> transaksi)</small></li>
                                        <li><strong>Transaksi Lain (Masuk):</strong> <?= format_rupiah($transaksi_income) ?> <small class="text-muted">(<?= $transaksi_income_count ?> transaksi)</small></li>
                                        <li class="border-top pt-2 mt-2"><strong>Total Pendapatan:</strong> <?= format_rupiah($total_income) ?> <small class="text-muted">(<?= $payment_count ?> transaksi)</small></li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6>Pengeluaran Detail (<?= $bulan[$month] ?> <?= $year ?>):</h6>
                                    <ul class="list-unstyled">
                                        <li><strong>Pengeluaran Operasional:</strong> <?= format_rupiah($pengeluaran_expense) ?> <small class="text-muted">(<?= $pengeluaran_count ?> transaksi)</small></li>
                                        <li><strong>Transaksi Lain (Keluar):</strong> <?= format_rupiah($transaksi_expense) ?> <small class="text-muted">(<?= $transaksi_expense_count ?> transaksi)</small></li>
                                        <li class="border-top pt-2 mt-2"><strong>Total Pengeluaran:</strong> <?= format_rupiah($total_expense) ?> <small class="text-muted">(<?= $expense_count ?> transaksi)</small></li>
                                    </ul>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <strong>Laba Bersih:</strong> <?= format_rupiah($net_profit) ?> 
                                        <small class="text-muted">(Pendapatan - Pengeluaran = <?= format_rupiah($total_income) ?> - <?= format_rupiah($total_expense) ?>)</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Access to Debug Mode -->
            <?php if (!isset($_GET['debug'])): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="alert alert-light">
                        <i class="fas fa-info-circle me-2"></i>
                        Untuk melihat detail breakdown data, <a href="?<?= http_build_query(array_merge($_GET, ['debug' => '1'])) ?>">aktifkan debug mode</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
    // Initialize charts when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Income vs Expense Chart (Bar)
        const incomeExpenseCtx = document.getElementById('incomeExpenseChart').getContext('2d');
        
        // Prepare monthly data arrays (12 months)
        const monthlyIncomeData = new Array(12).fill(0);
        const monthlyExpenseData = new Array(12).fill(0);
        
        // Fill with actual income data
        <?php if (!empty($yearly_income)): ?>
            <?php foreach ($yearly_income as $data): ?>
                monthlyIncomeData[<?= $data['month'] - 1 ?>] = <?= $data['income'] ?>;
            <?php endforeach; ?>
        <?php endif; ?>
        
        // Fill with actual expense data
        <?php if (!empty($yearly_expense)): ?>
            <?php foreach ($yearly_expense as $data): ?>
                monthlyExpenseData[<?= $data['month'] - 1 ?>] = <?= $data['expense'] ?>;
            <?php endforeach; ?>
        <?php endif; ?>
        
        const incomeExpenseChart = new Chart(incomeExpenseCtx, {
            type: 'bar',
            data: {
                labels: [
                    'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 
                    'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'
                ],
                datasets: [{
                    label: 'Pendapatan (Rp)',
                    data: monthlyIncomeData,
                    backgroundColor: 'rgba(38, 185, 154, 0.7)',
                    borderColor: 'rgba(38, 185, 154, 1)',
                    borderWidth: 1
                }, {
                    label: 'Pengeluaran (Rp)',
                    data: monthlyExpenseData,
                    backgroundColor: 'rgba(237, 85, 101, 0.7)',
                    borderColor: 'rgba(237, 85, 101, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rp ' + value.toLocaleString('id-ID');
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': Rp ' + context.raw.toLocaleString('id-ID');
                            }
                        }
                    },
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });

        // Toggle month select based on report type
        document.getElementById('report_type').addEventListener('change', function() {
            const monthSelect = document.getElementById('month');
            monthSelect.disabled = this.value === 'yearly';
        });
    });

    // Print function
    function printReport() {
        window.print();
    }
    </script>

    <?php 
    // Include footer
    require_once __DIR__ . '/../templates/footer.php';
    
    // End output buffering and flush
    ob_end_flush();
    ?>
</body>
</html>