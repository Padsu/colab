<?php
ob_start(); // Start output buffering at the VERY TOP

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: ../login.php");
    exit();
}

// Load configurations
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Pagination settings
$per_page = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Filter parameters
$tanggal_dari = isset($_GET['tanggal_dari']) ? $_GET['tanggal_dari'] : '';
$tanggal_sampai = isset($_GET['tanggal_sampai']) ? $_GET['tanggal_sampai'] : '';
$profile_filter = isset($_GET['profile']) ? $_GET['profile'] : '';
$penjual_filter = isset($_GET['penjual']) ? $_GET['penjual'] : '';

// Initialize variables
$sales = [];
$total_records = 0;
$total_pages = 1;
$summary = [
    'total_sales' => 0,
    'total_amount' => 0,
    'avg_amount' => 0,
    'today_sales' => 0,
    'today_amount' => 0,
    'month_sales' => 0,
    'month_amount' => 0
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_sale':
                try {
                    $id_user_hotspot = (int)$_POST['id_user_hotspot'];
                    $tanggal_jual = $_POST['tanggal_jual'];
                    $harga_jual = (float)$_POST['harga_jual'];
                    $nama_pembeli = $_POST['nama_pembeli'] ?? '';
                    $telepon_pembeli = $_POST['telepon_pembeli'] ?? '';
                    $keterangan = $_POST['keterangan'] ?? '';
                    $id_user_penjual = $_SESSION['user_id'] ?? 1;

                    // Validate input
                    if ($id_user_hotspot <= 0 || empty($tanggal_jual) || $harga_jual <= 0) {
                        throw new Exception("Data tidak lengkap atau tidak valid!");
                    }

                    // Check if voucher exists and not sold yet
                    $check_query = "SELECT status FROM hotspot_users WHERE id_user_hotspot = ?";
                    $check_stmt = $mysqli->prepare($check_query);
                    $check_stmt->bind_param('i', $id_user_hotspot);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows == 0) {
                        throw new Exception("Voucher tidak ditemukan!");
                    }
                    
                    $voucher = $result->fetch_assoc();
                    if ($voucher['status'] === 'used') {
                        throw new Exception("Voucher sudah dijual sebelumnya!");
                    }

                    // Insert sale record
                    $insert_query = "INSERT INTO hotspot_sales (id_user_hotspot, tanggal_jual, harga_jual, nama_pembeli, telepon_pembeli, keterangan, id_user_penjual, created_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $stmt = $mysqli->prepare($insert_query);
                    $stmt->bind_param('isdssssi', $id_user_hotspot, $tanggal_jual, $harga_jual, $nama_pembeli, $telepon_pembeli, $keterangan, $id_user_penjual);
                    
                    if ($stmt->execute()) {
                        // Update voucher status
                        $update_query = "UPDATE hotspot_users SET status = 'used', updated_at = NOW() WHERE id_user_hotspot = ?";
                        $update_stmt = $mysqli->prepare($update_query);
                        $update_stmt->bind_param('i', $id_user_hotspot);
                        $update_stmt->execute();
                        
                        $_SESSION['success_message'] = "Penjualan voucher berhasil dicatat!";
                    } else {
                        throw new Exception("Gagal menyimpan data penjualan!");
                    }
                    
                } catch (Exception $e) {
                    $_SESSION['error_message'] = $e->getMessage();
                }
                break;

            case 'edit_sale':
                try {
                    $id_sale = (int)$_POST['id_sale'];
                    $harga_jual = (float)$_POST['harga_jual'];
                    $nama_pembeli = $_POST['nama_pembeli'] ?? '';
                    $telepon_pembeli = $_POST['telepon_pembeli'] ?? '';
                    $keterangan = $_POST['keterangan'] ?? '';

                    $update_query = "UPDATE hotspot_sales SET harga_jual = ?, nama_pembeli = ?, telepon_pembeli = ?, keterangan = ?, updated_at = NOW() WHERE id_sale = ?";
                    $stmt = $mysqli->prepare($update_query);
                    $stmt->bind_param('dsssi', $harga_jual, $nama_pembeli, $telepon_pembeli, $keterangan, $id_sale);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = "Data penjualan berhasil diperbarui!";
                    } else {
                        throw new Exception("Gagal memperbarui data penjualan!");
                    }
                    
                } catch (Exception $e) {
                    $_SESSION['error_message'] = $e->getMessage();
                }
                break;
        }
        
        header("Location: voucher_sales.php");
        exit;
    }
}

// Handle delete action
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $id_sale = (int)$_GET['delete'];
        
        // Get voucher ID before deleting
        $get_voucher_query = "SELECT id_user_hotspot FROM hotspot_sales WHERE id_sale = ?";
        $get_stmt = $mysqli->prepare($get_voucher_query);
        $get_stmt->bind_param('i', $id_sale);
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $sale_data = $result->fetch_assoc();
            $id_user_hotspot = $sale_data['id_user_hotspot'];
            
            // Delete sale record
            $delete_query = "DELETE FROM hotspot_sales WHERE id_sale = ?";
            $delete_stmt = $mysqli->prepare($delete_query);
            $delete_stmt->bind_param('i', $id_sale);
            
            if ($delete_stmt->execute()) {
                // Revert voucher status to active
                $revert_query = "UPDATE hotspot_users SET status = 'aktif', updated_at = NOW() WHERE id_user_hotspot = ?";
                $revert_stmt = $mysqli->prepare($revert_query);
                $revert_stmt->bind_param('i', $id_user_hotspot);
                $revert_stmt->execute();
                
                $_SESSION['success_message'] = "Data penjualan berhasil dihapus dan voucher dikembalikan ke status aktif!";
            } else {
                throw new Exception("Gagal menghapus data penjualan!");
            }
        }
        
        header("Location: voucher_sales.php");
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
}

try {
    // Get summary statistics
    $summary_query = "SELECT 
        COUNT(*) as total_sales,
        COALESCE(SUM(harga_jual), 0) as total_amount,
        COALESCE(AVG(harga_jual), 0) as avg_amount,
        COALESCE(SUM(CASE WHEN DATE(tanggal_jual) = CURDATE() THEN 1 ELSE 0 END), 0) as today_sales,
        COALESCE(SUM(CASE WHEN DATE(tanggal_jual) = CURDATE() THEN harga_jual ELSE 0 END), 0) as today_amount,
        COALESCE(SUM(CASE WHEN MONTH(tanggal_jual) = MONTH(CURDATE()) AND YEAR(tanggal_jual) = YEAR(CURDATE()) THEN 1 ELSE 0 END), 0) as month_sales,
        COALESCE(SUM(CASE WHEN MONTH(tanggal_jual) = MONTH(CURDATE()) AND YEAR(tanggal_jual) = YEAR(CURDATE()) THEN harga_jual ELSE 0 END), 0) as month_amount
    FROM hotspot_sales";
    
    $summary_result = $mysqli->query($summary_query);
    if ($summary_result) {
        $summary = $summary_result->fetch_assoc();
    }

    // Build WHERE clause for filtering
    $where_conditions = [];
    $where_params = [];
    $param_types = '';

    if (!empty($tanggal_dari)) {
        $where_conditions[] = "hs.tanggal_jual >= ?";
        $where_params[] = $tanggal_dari;
        $param_types .= 's';
    }

    if (!empty($tanggal_sampai)) {
        $where_conditions[] = "hs.tanggal_jual <= ?";
        $where_params[] = $tanggal_sampai;
        $param_types .= 's';
    }

    if (!empty($profile_filter)) {
        $where_conditions[] = "hp.profile_name = ?";
        $where_params[] = $profile_filter;
        $param_types .= 's';
    }

    if (!empty($penjual_filter)) {
        $where_conditions[] = "hs.id_user_penjual = ?";
        $where_params[] = $penjual_filter;
        $param_types .= 'i';
    }

    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = " WHERE " . implode(" AND ", $where_conditions);
    }

    // Get total count for pagination
    $count_query = "SELECT COUNT(*) as total 
                   FROM hotspot_sales hs
                   LEFT JOIN hotspot_users hu ON hs.id_user_hotspot = hu.id_user_hotspot
                   LEFT JOIN hotspot_profiles hp ON hu.id_profile = hp.id_profile
                   LEFT JOIN users u ON hs.id_user_penjual = u.id_user
                   {$where_clause}";

    if (!empty($where_params)) {
        $count_stmt = $mysqli->prepare($count_query);
        $count_stmt->bind_param($param_types, ...$where_params);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $total_records = $count_result->fetch_assoc()['total'];
    } else {
        $count_result = $mysqli->query($count_query);
        $total_records = $count_result->fetch_assoc()['total'];
    }

    $total_pages = ceil($total_records / $per_page);

    // Get sales data with pagination
    $sales_query = "SELECT 
        hs.id_sale,
        hs.tanggal_jual,
        hs.harga_jual,
        hs.nama_pembeli,
        hs.telepon_pembeli,
        hs.keterangan as keterangan_penjualan,
        hs.created_at,
        hu.username as voucher_username,
        hu.nama_voucher,
        hp.nama_profile,
        hp.profile_name,
        hp.harga as harga_profile,
        u.nama_lengkap as nama_penjual
    FROM hotspot_sales hs
    LEFT JOIN hotspot_users hu ON hs.id_user_hotspot = hu.id_user_hotspot
    LEFT JOIN hotspot_profiles hp ON hu.id_profile = hp.id_profile
    LEFT JOIN users u ON hs.id_user_penjual = u.id_user
    {$where_clause}
    ORDER BY hs.tanggal_jual DESC, hs.created_at DESC
    LIMIT ? OFFSET ?";

    if (!empty($where_params)) {
        $all_params = array_merge($where_params, [$per_page, $offset]);
        $all_types = $param_types . 'ii';
        $sales_stmt = $mysqli->prepare($sales_query);
        $sales_stmt->bind_param($all_types, ...$all_params);
        $sales_stmt->execute();
        $sales_result = $sales_stmt->get_result();
    } else {
        $sales_stmt = $mysqli->prepare($sales_query);
        $sales_stmt->bind_param('ii', $per_page, $offset);
        $sales_stmt->execute();
        $sales_result = $sales_stmt->get_result();
    }

    $sales = $sales_result->fetch_all(MYSQLI_ASSOC);

} catch (Exception $e) {
    $_SESSION['error_message'] = "Gagal memuat data: " . $e->getMessage();
    $sales = [];
    $total_records = 0;
    $total_pages = 1;
}

// Get available profiles for filter
$profiles = [];
try {
    $profile_query = "SELECT DISTINCT hp.profile_name, hp.nama_profile 
                     FROM hotspot_profiles hp 
                     INNER JOIN hotspot_users hu ON hp.id_profile = hu.id_profile
                     INNER JOIN hotspot_sales hs ON hu.id_user_hotspot = hs.id_user_hotspot
                     WHERE hp.status_profile = 'aktif'
                     ORDER BY hp.nama_profile";
    $profile_result = $mysqli->query($profile_query);
    if ($profile_result) {
        $profiles = $profile_result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $profiles = [];
}

// Get available users for filter
$users = [];
try {
    $user_query = "SELECT DISTINCT u.id_user, u.nama_lengkap 
                  FROM users u 
                  INNER JOIN hotspot_sales hs ON u.id_user = hs.id_user_penjual
                  WHERE u.status = 'aktif'
                  ORDER BY u.nama_lengkap";
    $user_result = $mysqli->query($user_query);
    if ($user_result) {
        $users = $user_result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $users = [];
}

// Get available vouchers for add sale form
$available_vouchers = [];
try {
    $voucher_query = "SELECT hu.id_user_hotspot, hu.username, hu.nama_voucher, hp.nama_profile, hp.harga
                     FROM hotspot_users hu
                     LEFT JOIN hotspot_profiles hp ON hu.id_profile = hp.id_profile
                     WHERE hu.status = 'aktif'
                     ORDER BY hu.created_at DESC";
    $voucher_result = $mysqli->query($voucher_query);
    if ($voucher_result) {
        $available_vouchers = $voucher_result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $available_vouchers = [];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management Penjualan Voucher | Admin</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Same style as generate_voucher.php -->
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
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
      color: #73879C;
      font-size: 13px;
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

    .alert-success {
      background-color: rgba(38, 185, 154, 0.1);
      color: var(--success);
    }

    .alert-warning {
      background-color: rgba(248, 172, 89, 0.1);
      color: var(--warning);
    }

    .alert-info {
      background-color: rgba(35, 198, 200, 0.1);
      color: var(--info);
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

    .badge-primary {
      background-color: var(--primary);
    }

    .badge-secondary {
      background-color: var(--secondary);
    }

    .badge-info {
      background-color: var(--info);
    }

    .badge-warning {
      background-color: var(--warning);
    }

    .form-control, .form-select {
      border-radius: 3px;
      border: 1px solid #D5D5D5;
      font-size: 13px;
      padding: 8px 12px;
      height: auto;
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    }

    .form-control:focus, .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 0.2rem rgba(26, 187, 156, 0.25);
    }

    .form-select {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
      background-size: 12px 12px;
      padding: 8px 30px 8px 12px;
    }

    .btn {
      border-radius: 3px;
      font-size: 13px;
      padding: 8px 15px;
      font-weight: 500;
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    }

    .btn-primary {
      background-color: var(--primary);
      border-color: var(--primary);
    }

    .btn-primary:hover {
      background-color: #169F85;
      border-color: #169F85;
    }

    .btn-success {
      background-color: var(--success);
      border-color: var(--success);
    }

    .btn-success:hover {
      background-color: #1e9e8a;
      border-color: #1e9e8a;
    }

    .btn-warning {
      background-color: var(--warning);
      border-color: var(--warning);
    }

    .btn-danger {
      background-color: var(--danger);
      border-color: var(--danger);
    }

    .btn-info {
      background-color: var(--info);
      border-color: var(--info);
    }

    .btn-sm {
      padding: 5px 10px;
      font-size: 12px;
    }

    .input-group-text {
      background-color: #f5f5f5;
      border: 1px solid #ddd;
      font-size: 13px;
    }

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

    .summary-card.total {
      border-left-color: var(--primary);
    }

    .summary-card.today {
      border-left-color: var(--success);
    }

    .summary-card.month {
      border-left-color: var(--info);
    }

    .summary-card.average {
      border-left-color: var(--warning);
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

    .pagination .page-item.active .page-link {
      background-color: var(--primary);
      border-color: var(--primary);
    }

    .pagination .page-link {
      color: var(--primary);
    }

    .form-label {
      font-weight: 500;
      font-size: 13px;
      color: var(--secondary);
      margin-bottom: 5px;
    }

    .modal-header {
      background-color: var(--primary);
      color: white;
    }

    .modal-title {
      font-weight: 500;
    }

    .table-responsive {
      max-height: 600px;
      overflow-y: auto;
    }

    .text-success {
      color: var(--success) !important;
    }

    .text-danger {
      color: var(--danger) !important;
    }

    .text-primary {
      color: var(--primary) !important;
    }

    .text-warning {
      color: var(--warning) !important;
    }

    .text-info {
      color: var(--info) !important;
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
        <div class="container-fluid">
            <!-- Header -->
            <div class="page-title">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1><i class="fas fa-ticket-alt me-2"></i>Management Penjualan Voucher</h1>
                        <p class="page-subtitle">Kelola data penjualan voucher hotspot</p>
                    </div>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addSaleModal">
                        <i class="fas fa-plus-circle me-1"></i> Tambah Penjualan
                    </button>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success d-flex align-items-center">
                    <i class="fas fa-check-circle me-2"></i>
                    <div><?= htmlspecialchars($_SESSION['success_message']) ?></div>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger d-flex align-items-center">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <div><?= htmlspecialchars($_SESSION['error_message']) ?></div>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <!-- Total Sales -->
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card summary-card total h-100">
                        <div class="card-body">
                            <div class="d-flex flex-column">
                                <h5 class="card-title text-primary mb-1" style="font-size:14px">Total Penjualan</h5>
                                <h3 class="text-primary mb-1" style="font-size:20px"><?= number_format($summary['total_sales'], 0, ',', '.') ?></h3>
                                <p class="card-text text-muted mb-2" style="font-size:12px">
                                    Rp <?= number_format($summary['total_amount'], 0, ',', '.') ?>
                                </p>
                                <div class="d-flex justify-content-between align-items-center mt-auto">
                                    <small class="text-muted" style="font-size:11px">
                                        <i class="fas fa-calendar fa-xs me-1"></i> Semua waktu
                                    </small>
                                    <i class="fas fa-chart-bar fa-lg text-primary"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today Sales -->
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card summary-card today h-100">
                        <div class="card-body">
                            <div class="d-flex flex-column">
                                <h5 class="card-title text-success mb-1" style="font-size:14px">Hari Ini</h5>
                                <h3 class="text-success mb-1" style="font-size:20px"><?= number_format($summary['today_sales'], 0, ',', '.') ?></h3>
                                <p class="card-text text-muted mb-2" style="font-size:12px">
                                    Rp <?= number_format($summary['today_amount'], 0, ',', '.') ?>
                                </p>
                                <div class="d-flex justify-content-between align-items-center mt-auto">
                                    <small class="text-muted" style="font-size:11px">
                                        <i class="fas fa-clock fa-xs me-1"></i> <?= date('d/m/Y') ?>
                                    </small>
                                    <i class="fas fa-calendar-day fa-lg text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Month Sales -->
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card summary-card month h-100">
                        <div class="card-body">
                            <div class="d-flex flex-column">
                                <h5 class="card-title text-info mb-1" style="font-size:14px">Bulan Ini</h5>
                                <h3 class="text-info mb-1" style="font-size:20px"><?= number_format($summary['month_sales'], 0, ',', '.') ?></h3>
                                <p class="card-text text-muted mb-2" style="font-size:12px">
                                    Rp <?= number_format($summary['month_amount'], 0, ',', '.') ?>
                                </p>
                                <div class="d-flex justify-content-between align-items-center mt-auto">
                                    <small class="text-muted" style="font-size:11px">
                                        <i class="fas fa-calendar-alt fa-xs me-1"></i> <?= date('F Y') ?>
                                    </small>
                                    <i class="fas fa-calendar-check fa-lg text-info"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Average Sales -->
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card summary-card average h-100">
                        <div class="card-body">
                            <div class="d-flex flex-column">
                                <h5 class="card-title text-warning mb-1" style="font-size:14px">Rata-rata</h5>
                                <h3 class="text-warning mb-1" style="font-size:20px">Rp <?= number_format($summary['avg_amount'], 0, ',', '.') ?></h3>
                                <p class="card-text text-muted mb-2" style="font-size:12px">
                                    Per voucher
                                </p>
                                <div class="d-flex justify-content-between align-items-center mt-auto">
                                    <small class="text-muted" style="font-size:11px">
                                        <i class="fas fa-calculator fa-xs me-1"></i> Harga rata-rata
                                    </small>
                                    <i class="fas fa-percentage fa-lg text-warning"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i> Filter Penjualan</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">Tanggal Dari</label>
                            <input type="date" name="tanggal_dari" class="form-control" value="<?= htmlspecialchars($tanggal_dari) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tanggal Sampai</label>
                            <input type="date" name="tanggal_sampai" class="form-control" value="<?= htmlspecialchars($tanggal_sampai) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Profile</label>
                            <select name="profile" class="form-select">
                                <option value="">Semua Profile</option>
                                <?php foreach ($profiles as $profile): ?>
                                    <option value="<?= htmlspecialchars($profile['profile_name']) ?>" <?= $profile_filter == $profile['profile_name'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($profile['nama_profile']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Penjual</label>
                            <select name="penjual" class="form-select">
                                <option value="">Semua Penjual</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id_user'] ?>" <?= $penjual_filter == $user['id_user'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($user['nama_lengkap']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-1"></i> Filter
                            </button>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <a href="voucher_sales.php" class="btn btn-secondary w-100">
                                <i class="fas fa-sync-alt me-1"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Sales Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list-ul me-2"></i> Daftar Penjualan Voucher</h5>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="exportData('excel')">
                            <i class="fas fa-file-excel me-1"></i> Excel
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="exportData('pdf')">
                            <i class="fas fa-file-pdf me-1"></i> PDF
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th width="3%">No</th>
                                    <th width="10%">Tanggal</th>
                                    <th width="12%">Voucher</th>
                                    <th width="12%">Profile</th>
                                    <th width="12%">Pembeli</th>
                                    <th width="10%">Harga</th>
                                    <th width="12%">Penjual</th>
                                    <th width="15%">Keterangan</th>
                                    <th width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sales)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            <i class="fas fa-inbox fa-2x text-muted"></i>
                                            <p class="text-muted mt-2">Tidak ada data penjualan ditemukan</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $no = $offset + 1; ?>
                                    <?php foreach ($sales as $sale): ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td><?= date('d/m/Y', strtotime($sale['tanggal_jual'])) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($sale['voucher_username']) ?></strong>
                                                <?php if ($sale['nama_voucher']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($sale['nama_voucher']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?= htmlspecialchars($sale['nama_profile']) ?></span>
                                                <br><small class="text-muted">@ Rp <?= number_format($sale['harga_profile'], 0, ',', '.') ?></small>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($sale['nama_pembeli'] ?: '-') ?></strong>
                                                <?php if ($sale['telepon_pembeli']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($sale['telepon_pembeli']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-success fw-bold">
                                                Rp <?= number_format($sale['harga_jual'], 0, ',', '.') ?>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($sale['nama_penjual'] ?: 'System') ?><br>
                                                    <span style="font-size: 0.75em;"><?= date('d/m/Y H:i', strtotime($sale['created_at'])) ?></span>
                                                </small>
                                            </td>
                                            <td>
                                                <small><?= htmlspecialchars($sale['keterangan_penjualan'] ?: '-') ?></small>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                                            onclick="editSale(<?= $sale['id_sale'] ?>, '<?= htmlspecialchars($sale['harga_jual']) ?>', '<?= htmlspecialchars($sale['nama_pembeli']) ?>', '<?= htmlspecialchars($sale['telepon_pembeli']) ?>', '<?= htmlspecialchars($sale['keterangan_penjualan']) ?>')" 
                                                            title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="?delete=<?= $sale['id_sale'] ?>" 
                                                       class="btn btn-sm btn-outline-danger" 
                                                       onclick="return confirm('Apakah Anda yakin ingin menghapus data penjualan ini?\n\nVoucher akan dikembalikan ke status aktif.')"
                                                       title="Hapus">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted">
                            Menampilkan <?= count($sales) ?> dari <?= $total_records ?> penjualan
                        </div>
                        
                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Sale Modal -->
    <div class="modal fade" id="addSaleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Tambah Penjualan Voucher</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addSaleForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_sale">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <small>Form ini untuk mencatat penjualan voucher yang sudah tersedia di sistem.</small>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="id_user_hotspot" class="form-label">Pilih Voucher <span class="text-danger">*</span></label>
                                    <select class="form-select" id="id_user_hotspot" name="id_user_hotspot" required>
                                        <option value="">-- Pilih Voucher --</option>
                                        <?php foreach ($available_vouchers as $voucher): ?>
                                            <option value="<?= $voucher['id_user_hotspot'] ?>" 
                                                    data-harga="<?= $voucher['harga'] ?>"
                                                    data-profile="<?= htmlspecialchars($voucher['nama_profile']) ?>">
                                                <?= htmlspecialchars($voucher['username']) ?> - <?= htmlspecialchars($voucher['nama_profile']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="tanggal_jual" class="form-label">Tanggal Jual <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="tanggal_jual" name="tanggal_jual" value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="harga_jual" class="form-label">Harga Jual (Rp) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="harga_jual" name="harga_jual" min="1" step="1" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="nama_pembeli" class="form-label">Nama Pembeli</label>
                                    <input type="text" class="form-control" id="nama_pembeli" name="nama_pembeli">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="telepon_pembeli" class="form-label">Telepon Pembeli</label>
                                    <input type="text" class="form-control" id="telepon_pembeli" name="telepon_pembeli">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Profile Info</label>
                                    <div class="form-control-plaintext bg-light p-2 rounded" id="profile_info">
                                        Pilih voucher untuk melihat info profile
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label for="keterangan" class="form-label">Keterangan</label>
                                    <textarea class="form-control" id="keterangan" name="keterangan" rows="2" placeholder="Keterangan tambahan penjualan..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-1"></i> Simpan Penjualan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Sale Modal -->
    <div class="modal fade" id="editSaleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Edit Penjualan Voucher</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editSaleForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_sale">
                        <input type="hidden" name="id_sale" id="edit_id_sale">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_harga_jual" class="form-label">Harga Jual (Rp) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="edit_harga_jual" name="harga_jual" min="1" step="1" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_nama_pembeli" class="form-label">Nama Pembeli</label>
                                    <input type="text" class="form-control" id="edit_nama_pembeli" name="nama_pembeli">
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label for="edit_telepon_pembeli" class="form-label">Telepon Pembeli</label>
                                    <input type="text" class="form-control" id="edit_telepon_pembeli" name="telepon_pembeli">
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label for="edit_keterangan" class="form-label">Keterangan</label>
                                    <textarea class="form-control" id="edit_keterangan" name="keterangan" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const voucherSelect = document.getElementById('id_user_hotspot');
        const hargaJualInput = document.getElementById('harga_jual');
        const profileInfoDiv = document.getElementById('profile_info');

        // Update harga dan info saat voucher dipilih
        voucherSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            if (this.value) {
                const harga = selectedOption.getAttribute('data-harga') || '0';
                const profile = selectedOption.getAttribute('data-profile') || '';
                
                hargaJualInput.value = harga;
                profileInfoDiv.innerHTML = `
                    <strong>Profile:</strong> ${profile}<br>
                    <strong>Harga Default:</strong> Rp ${parseInt(harga).toLocaleString('id-ID')}
                `;
            } else {
                hargaJualInput.value = '';
                profileInfoDiv.innerHTML = 'Pilih voucher untuk melihat info profile';
            }
        });

        // Form validation
        document.getElementById('addSaleForm').addEventListener('submit', function(e) {
            const voucher = document.getElementById('id_user_hotspot').value;
            const harga = document.getElementById('harga_jual').value;

            if (!voucher || !harga || harga <= 0) {
                e.preventDefault();
                alert('Mohon lengkapi semua field yang wajib diisi!');
                return false;
            }

            return confirm(`Apakah Anda yakin ingin mencatat penjualan voucher ini dengan harga Rp ${parseInt(harga).toLocaleString('id-ID')}?`);
        });
    });

    function editSale(id, harga, nama, telepon, keterangan) {
        document.getElementById('edit_id_sale').value = id;
        document.getElementById('edit_harga_jual').value = harga;
        document.getElementById('edit_nama_pembeli').value = nama;
        document.getElementById('edit_telepon_pembeli').value = telepon;
        document.getElementById('edit_keterangan').value = keterangan;
        
        const editModal = new bootstrap.Modal(document.getElementById('editSaleModal'));
        editModal.show();
    }

    function exportData(format) {
        const params = new URLSearchParams(window.location.search);
        params.append('export', format);
        window.open('export_voucher_sales.php?' + params.toString(), '_blank');
    }
    </script>

    <?php 
    require_once __DIR__ . '/../templates/footer.php';
    ob_end_flush();
    ?>
</body>
</html>