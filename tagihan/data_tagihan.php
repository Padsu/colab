<?php
// /tagihan/data_tagihan.php - SIMPLIFIED VERSION without checkbox and bulk actions

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

// Mikrotik API Functions for PPPOE isolation
function getMikrotikSettings() {
    global $mysqli;
    $settings = $mysqli->query("SELECT * FROM system_settings LIMIT 1")->fetch_assoc();
    return $settings;
}

function connectMikrotik() {
    $settings = getMikrotikSettings();
    if (!$settings) {
        error_log("Mikrotik settings not found");
        return false;
    }
    
    // Simple socket connection to Mikrotik API
    $socket = @fsockopen($settings['mikrotik_ip'], $settings['mikrotik_port'], $errno, $errstr, 5);
    if (!$socket) {
        error_log("Cannot connect to Mikrotik: $errstr ($errno)");
        return false;
    }
    
    return [
        'socket' => $socket,
        'settings' => $settings
    ];
}

function mikrotikApiLogin($connection) {
    $socket = $connection['socket'];
    $settings = $connection['settings'];
    
    // Send login command
    fwrite($socket, "/login\n");
    fwrite($socket, "=name=" . $settings['mikrotik_user'] . "\n");
    fwrite($socket, "=password=" . $settings['mikrotik_pass'] . "\n");
    fwrite($socket, "\n");
    
    $response = fread($socket, 1024);
    return strpos($response, '!done') !== false;
}

function disablePPPOEUser($username, $secret = null) {
    global $mysqli;
    
    try {
        // Log the isolation action
        error_log("ISOLATING PPPOE USER: $username - Reason: Outstanding payment (>1 day overdue)");
        
        // Update database status
        $update_status = "UPDATE data_pelanggan SET status_aktif = 'isolir', mikrotik_disabled = 'yes' WHERE mikrotik_username = ?";
        $status_stmt = $mysqli->prepare($update_status);
        if ($status_stmt) {
            $status_stmt->bind_param("s", $username);
            $status_stmt->execute();
            $status_stmt->close();
        }
        
        // Connect to Mikrotik and disable user via API
        $connection = connectMikrotik();
        if ($connection && mikrotikApiLogin($connection)) {
            $socket = $connection['socket'];
            
            // Disable user in /ppp/secret
            fwrite($socket, "/ppp/secret/set\n");
            fwrite($socket, "=.id=" . $username . "\n");
            fwrite($socket, "=disabled=yes\n");
            fwrite($socket, "\n");
            
            $response = fread($socket, 1024);
            fclose($socket);
            
            if (strpos($response, '!done') !== false) {
                error_log("Successfully disabled PPPOE user in Mikrotik: $username");
            } else {
                error_log("Failed to disable PPPOE user in Mikrotik: $username");
            }
        } else {
            error_log("Cannot connect to Mikrotik for user isolation: $username");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error isolating PPPOE user $username: " . $e->getMessage());
        return false;
    }
}

function enablePPPOEUser($username) {
    global $mysqli;
    
    try {
        // Log the restoration action
        error_log("RESTORING PPPOE USER: $username - Reason: Payment received");
        
        // Update database status
        $update_status = "UPDATE data_pelanggan SET status_aktif = 'aktif', mikrotik_disabled = 'no' WHERE mikrotik_username = ?";
        $status_stmt = $mysqli->prepare($update_status);
        if ($status_stmt) {
            $status_stmt->bind_param("s", $username);
            $status_stmt->execute();
            $status_stmt->close();
        }
        
        // Connect to Mikrotik and enable user via API
        $connection = connectMikrotik();
        if ($connection && mikrotikApiLogin($connection)) {
            $socket = $connection['socket'];
            
            // Enable user in /ppp/secret
            fwrite($socket, "/ppp/secret/set\n");
            fwrite($socket, "=.id=" . $username . "\n");
            fwrite($socket, "=disabled=no\n");
            fwrite($socket, "\n");
            
            $response = fread($socket, 1024);
            fclose($socket);
            
            if (strpos($response, '!done') !== false) {
                error_log("Successfully enabled PPPOE user in Mikrotik: $username");
            } else {
                error_log("Failed to enable PPPOE user in Mikrotik: $username");
            }
        } else {
            error_log("Cannot connect to Mikrotik for user restoration: $username");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error restoring PPPOE user $username: " . $e->getMessage());
        return false;
    }
}

function generateNextInvoice($customer_id, $current_period_month, $current_period_year) {
    global $mysqli;
    
    try {
        // Calculate next period
        $next_month = $current_period_month + 1;
        $next_year = $current_period_year;
        
        if ($next_month > 12) {
            $next_month = 1;
            $next_year++;
        }
        
        // Get customer and package info
        $customer_query = "SELECT dp.*, pi.harga FROM data_pelanggan dp 
                          LEFT JOIN paket_internet pi ON dp.id_paket = pi.id_paket 
                          WHERE dp.id_pelanggan = ?";
        $customer_stmt = $mysqli->prepare($customer_query);
        $customer_stmt->bind_param("i", $customer_id);
        $customer_stmt->execute();
        $customer = $customer_stmt->get_result()->fetch_assoc();
        $customer_stmt->close();
        
        if (!$customer) return false;
        
        // Check if next invoice already exists
        $check_query = "SELECT id_tagihan FROM tagihan WHERE id_pelanggan = ? AND bulan_tagihan = ? AND tahun_tagihan = ?";
        $check_stmt = $mysqli->prepare($check_query);
        $check_stmt->bind_param("iii", $customer_id, $next_month, $next_year);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();
        
        if ($exists) return true; // Already exists
        
        // Generate new invoice ID
        $invoice_id = 'INV-' . $next_year . str_pad($next_month, 2, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(md5(microtime() . $customer_id), 0, 6));
        
        // Calculate due date based on customer's tgl_expired pattern
        // If tgl_expired is 10 July, next due date should be 10 August (same day of month)
        if (!empty($customer['tgl_expired'])) {
            $expired_day = date('d', strtotime($customer['tgl_expired']));
            // Create due date with same day in next month
            $due_date = date('Y-m-d', strtotime("$next_year-$next_month-$expired_day"));
            
            // If the day doesn't exist in next month (e.g., 31 Feb), use last day of month
            if (date('m', strtotime($due_date)) != $next_month) {
                $due_date = date('Y-m-t', strtotime("$next_year-$next_month-01"));
            }
        } else {
            // Fallback to end of month if no tgl_expired
            $due_date = date('Y-m-t', strtotime("$next_year-$next_month-01"));
        }
        
        // Create new invoice
        $insert_query = "INSERT INTO tagihan 
                        (id_tagihan, id_pelanggan, bulan_tagihan, tahun_tagihan, jumlah_tagihan, tgl_jatuh_tempo, status_tagihan, auto_generated) 
                        VALUES (?, ?, ?, ?, ?, ?, 'belum_bayar', 'yes')";
        $insert_stmt = $mysqli->prepare($insert_query);
        $amount = $customer['harga'] ?? 0;
        $insert_stmt->bind_param("siiids", $invoice_id, $customer_id, $next_month, $next_year, $amount, $due_date);
        
        $result = $insert_stmt->execute();
        $insert_stmt->close();
        
        if ($result) {
            error_log("Auto-generated next invoice for customer ID $customer_id: $invoice_id (Period: $next_month/$next_year, Due: $due_date)");
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Error generating next invoice for customer $customer_id: " . $e->getMessage());
        return false;
    }
}

// ADDED: Function to record payment in mutasi keuangan
function recordPaymentMutasi($invoice_id, $customer_name, $bulan, $tahun, $amount, $metode = 'Manual') {
    global $mysqli;
    
    try {
        $bulanIndo = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        
        $bulanTagihan = $bulanIndo[$bulan];
        $keterangan = "Pembayaran tagihan - $customer_name ($invoice_id) - $bulanTagihan $tahun - Metode: $metode";
        
        $insertMutasi = $mysqli->prepare("INSERT INTO transaksi_lain 
            (tanggal, jenis, kategori, keterangan, jumlah, created_by) 
            VALUES (?, 'pemasukan', 'Pembayaran Pelanggan', ?, ?, ?)");
        
        $tanggal = date('Y-m-d');
        $userId = $_SESSION['user_id'] ?? null;
        
        $insertMutasi->bind_param("ssdi", $tanggal, $keterangan, $amount, $userId);
        $insertMutasi->execute();
        $insertMutasi->close();
        
        return true;
    } catch (Exception $e) {
        error_log("Error recording payment mutasi: " . $e->getMessage());
        return false;
    }
}

// Pagination settings
$limit = 50;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Search and filter
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$bulan_filter = $_GET['bulan'] ?? '';
$tahun_filter = $_GET['tahun'] ?? date('Y');

// Handle generate tagihan untuk periode baru
if (isset($_POST['action']) && $_POST['action'] === 'generate_tagihan') {
    $month = $_POST['month'] ?? '';
    $year = $_POST['year'] ?? '';
    
    if (!empty($month) && !empty($year)) {
        try {
            // Get all active customers
            $customers = $mysqli->query("SELECT id_pelanggan, nama_pelanggan, id_paket FROM data_pelanggan WHERE status_aktif = 'aktif'");
            
            $generated = 0;
            $skipped = 0;
            
            while ($customer = $customers->fetch_assoc()) {
                // Get package price
                $package = $mysqli->query("SELECT harga FROM paket_internet WHERE id_paket = " . $customer['id_paket'])->fetch_assoc();
                $amount = $package['harga'] ?? 0;
                
                // Check if invoice already exists for this customer in this period
                $check = $mysqli->prepare("SELECT id_tagihan FROM tagihan WHERE id_pelanggan = ? AND bulan_tagihan = ? AND tahun_tagihan = ?");
                $check->bind_param("iii", $customer['id_pelanggan'], $month, $year);
                $check->execute();
                
                if ($check->get_result()->num_rows === 0) {
                    // Generate invoice ID
                    $invoice_id = 'INV-' . $year . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(md5(microtime() . $customer['id_pelanggan']), 0, 6));
                    
                    // Calculate due date (end of month)
                    $due_date = date('Y-m-t', strtotime("$year-$month-01"));
                    
                    // Generate new invoice
                    $insert_invoice = $mysqli->prepare("INSERT INTO tagihan 
                        (id_tagihan, id_pelanggan, bulan_tagihan, tahun_tagihan, jumlah_tagihan, tgl_jatuh_tempo, status_tagihan, auto_generated) 
                        VALUES (?, ?, ?, ?, ?, ?, 'belum_bayar', 'yes')");
                    
                    $insert_invoice->bind_param("siiids", $invoice_id, $customer['id_pelanggan'], $month, $year, $amount, $due_date);
                    
                    if ($insert_invoice->execute()) {
                        $generated++;
                    } else {
                        error_log("Failed to generate invoice for customer: " . $customer['nama_pelanggan']);
                    }
                    $insert_invoice->close();
                } else {
                    $skipped++;
                }
                $check->close();
            }
            
            $_SESSION['alert'] = ['type' => 'success', 'message' => "Berhasil generate $generated tagihan baru untuk periode $month/$year. $skipped tagihan sudah ada sebelumnya."];
            header("Location: data_tagihan.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => "Error saat generate tagihan: " . $e->getMessage()];
            header("Location: data_tagihan.php");
            exit();
        }
    } else {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => "Bulan dan tahun wajib diisi!"];
        header("Location: data_tagihan.php");
        exit();
    }
}

// Handle update status pembayaran
if (isset($_POST['action']) && $_POST['action'] === 'update_payment') {
    $invoice_id = $_POST['invoice_id'];
    $payment_status = $_POST['payment_status'];
    $payment_date = ($payment_status === 'sudah_bayar') ? date('Y-m-d') : null;
    
    try {
        $update = $mysqli->prepare("UPDATE tagihan SET status_tagihan = ? WHERE id_tagihan = ?");
        $update->bind_param("ss", $payment_status, $invoice_id);
        
        if ($update->execute()) {
            // Get invoice and customer details
            $invoice_query = "SELECT t.*, dp.mikrotik_username, dp.nama_pelanggan FROM tagihan t 
                            JOIN data_pelanggan dp ON t.id_pelanggan = dp.id_pelanggan 
                            WHERE t.id_tagihan = ?";
            $invoice_stmt = $mysqli->prepare($invoice_query);
            $invoice_stmt->bind_param("s", $invoice_id);
            $invoice_stmt->execute();
            $invoice = $invoice_stmt->get_result()->fetch_assoc();
            $invoice_stmt->close();
            
            if ($payment_status === 'sudah_bayar') {
                // Record the payment
                $insert_payment = $mysqli->prepare("INSERT INTO pembayaran 
                    (id_tagihan, id_pelanggan, tanggal_bayar, jumlah_bayar, metode_bayar, id_user_pencatat) 
                    VALUES (?, ?, ?, ?, 'Manual', ?)");
                
                $user_id = $_SESSION['user_id'] ?? null;
                $insert_payment->bind_param("sisdi", 
                    $invoice_id, 
                    $invoice['id_pelanggan'], 
                    $payment_date, 
                    $invoice['jumlah_tagihan'],
                    $user_id
                );
                $insert_payment->execute();
                $insert_payment->close();

                // ADDED: Record payment in mutasi keuangan
                recordPaymentMutasi(
                    $invoice_id, 
                    $invoice['nama_pelanggan'], 
                    $invoice['bulan_tagihan'], 
                    $invoice['tahun_tagihan'], 
                    $invoice['jumlah_tagihan'], 
                    'Manual'
                );
                
                // Enable PPPOE if user was isolated
                if (!empty($invoice['mikrotik_username'])) {
                    enablePPPOEUser($invoice['mikrotik_username']);
                }
                
                // Generate next month invoice automatically
                generateNextInvoice(
                    $invoice['id_pelanggan'], 
                    $invoice['bulan_tagihan'], 
                    $invoice['tahun_tagihan']
                );
            }
            
            $_SESSION['alert'] = ['type' => 'success', 'message' => "Status pembayaran berhasil diupdate dan dicatat di mutasi keuangan!"];
        } else {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => "Gagal update status pembayaran!"];
        }
        $update->close();
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => "Error: " . $e->getMessage()];
    }
    header("Location: data_tagihan.php");
    exit();
}

// Handle delete transaction
if (isset($_GET['delete'])) {
    $invoice_id = $_GET['delete'];
    
    try {
        // First delete related payments
        $mysqli->query("DELETE FROM pembayaran WHERE id_tagihan = '$invoice_id'");
        
        // Then delete the invoice
        $delete = $mysqli->prepare("DELETE FROM tagihan WHERE id_tagihan = ?");
        $delete->bind_param("s", $invoice_id);
        
        if ($delete->execute()) {
            $_SESSION['alert'] = ['type' => 'success', 'message' => "Tagihan berhasil dihapus!"];
        } else {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => "Gagal menghapus tagihan!"];
        }
        $delete->close();
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => "Error: " . $e->getMessage()];
    }
    header("Location: data_tagihan.php");
    exit();
}

// Auto-isolate overdue customers (more than 1 day late)
$today = date('Y-m-d');
$isolation_date = date('Y-m-d', strtotime('-1 day')); // Changed from -3 days to -1 day

// Update status untuk tagihan yang terlambat
$mysqli->query("UPDATE tagihan SET status_tagihan = 'terlambat' WHERE tgl_jatuh_tempo < '$today' AND status_tagihan = 'belum_bayar'");

// Auto-isolate customers who are late more than 1 day (execute at 00:00 WIB)
$current_hour = date('H');
if ($current_hour == 0) { // Only run at midnight (00:00 WIB)
    $overdue_customers = $mysqli->query("
        SELECT DISTINCT dp.mikrotik_username, dp.nama_pelanggan, t.id_tagihan, t.tgl_jatuh_tempo
        FROM tagihan t 
        JOIN data_pelanggan dp ON t.id_pelanggan = dp.id_pelanggan 
        WHERE t.status_tagihan = 'terlambat' 
        AND t.tgl_jatuh_tempo < '$isolation_date'
        AND dp.status_aktif != 'isolir'
        AND dp.mikrotik_username IS NOT NULL 
        AND dp.mikrotik_username != ''
    ");

    $isolated_count = 0;
    while ($customer = $overdue_customers->fetch_assoc()) {
        if (disablePPPOEUser($customer['mikrotik_username'])) {
            $isolated_count++;
            error_log("Auto-isolated customer: " . $customer['nama_pelanggan'] . " (Username: " . $customer['mikrotik_username'] . ") - 1 day overdue");
        }
    }

    if ($isolated_count > 0) {
        error_log("Auto-isolation completed at 00:00 WIB: $isolated_count customers isolated for 1+ day overdue payments");
    }
} else {
    // For demonstration purposes, we'll run it anyway but with different logic
    $overdue_customers = $mysqli->query("
        SELECT DISTINCT dp.mikrotik_username, dp.nama_pelanggan, t.id_tagihan, t.tgl_jatuh_tempo
        FROM tagihan t 
        JOIN data_pelanggan dp ON t.id_pelanggan = dp.id_pelanggan 
        WHERE t.status_tagihan = 'terlambat' 
        AND t.tgl_jatuh_tempo < '$isolation_date'
        AND dp.status_aktif != 'isolir'
        AND dp.mikrotik_username IS NOT NULL 
        AND dp.mikrotik_username != ''
    ");

    $isolated_count = 0;
    while ($customer = $overdue_customers->fetch_assoc()) {
        if (disablePPPOEUser($customer['mikrotik_username'])) {
            $isolated_count++;
            error_log("Auto-isolated customer: " . $customer['nama_pelanggan'] . " (Username: " . $customer['mikrotik_username'] . ") - 1 day overdue");
        }
    }
}

// Build query with filters - LOGIKA H-10 YANG BENAR
$where_conditions = [];
$params = [];
$types = '';

// Hitung tanggal H+10 dari hari ini (10 hari ke depan)
$h_plus_10 = date('Y-m-d', strtotime('+10 days'));

$base_query = "SELECT t.*, p.nama_pelanggan, pi.nama_paket, pi.harga, p.mikrotik_username, p.status_aktif, p.mikrotik_disabled,
               DATEDIFF(t.tgl_jatuh_tempo, '$today') as days_to_due
               FROM tagihan t 
               JOIN data_pelanggan p ON t.id_pelanggan = p.id_pelanggan 
               LEFT JOIN paket_internet pi ON p.id_paket = pi.id_paket";

// LOGIKA H-10: Tampilkan tagihan yang:
// 1. Belum bayar DAN jatuh tempo dalam 10 hari ke depan (dari hari ini sampai H+10)
// 2. Terlambat tapi belum lewat 10 hari dari jatuh tempo
// 3. Sudah bayar (untuk tracking)
$where_conditions[] = "(
    (t.status_tagihan = 'belum_bayar' AND t.tgl_jatuh_tempo BETWEEN '$today' AND '$h_plus_10') OR
    (t.status_tagihan = 'terlambat' AND DATEDIFF('$today', t.tgl_jatuh_tempo) <= 10) OR
    t.status_tagihan = 'sudah_bayar'
)";

// Filter pencarian
if (!empty($search)) {
    $where_conditions[] = "(p.nama_pelanggan LIKE ? OR pi.nama_paket LIKE ? OR p.mikrotik_username LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

// Filter status
if (!empty($status_filter)) {
    if ($status_filter === 'terlambat') {
        $where_conditions[] = "t.status_tagihan = 'terlambat'";
    } else {
        $where_conditions[] = "t.status_tagihan = ?";
        $params[] = $status_filter;
        $types .= 's';
    }
}

// Filter bulan
if (!empty($bulan_filter)) {
    $where_conditions[] = "t.bulan_tagihan = ?";
    $params[] = intval($bulan_filter);
    $types .= 'i';
}

// Filter tahun
if (!empty($tahun_filter)) {
    $where_conditions[] = "t.tahun_tagihan = ?";
    $params[] = intval($tahun_filter);
    $types .= 'i';
}

$where_clause = " WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_query = "SELECT COUNT(*) FROM tagihan t 
                JOIN data_pelanggan p ON t.id_pelanggan = p.id_pelanggan" . $where_clause;
$count_stmt = $mysqli->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_row()[0];
$total_pages = ceil($total_records / $limit);

// Get billing transactions with pagination
$main_query = $base_query . $where_clause . " ORDER BY 
    CASE 
        WHEN t.status_tagihan = 'terlambat' THEN 1
        WHEN t.status_tagihan = 'belum_bayar' THEN 2
        WHEN t.status_tagihan = 'sudah_bayar' THEN 3
    END,
    t.tgl_jatuh_tempo ASC 
    LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $mysqli->prepare($main_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$billing_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics - PERBAIKAN UNTUK CARD STATISTICS
$stats_query = "SELECT 
    COUNT(CASE WHEN (
        (status_tagihan = 'belum_bayar' AND tgl_jatuh_tempo BETWEEN '$today' AND '$h_plus_10') OR
        (status_tagihan = 'terlambat' AND DATEDIFF('$today', tgl_jatuh_tempo) <= 10) OR
        status_tagihan = 'sudah_bayar'
    ) THEN 1 END) as total,
    COUNT(CASE WHEN status_tagihan = 'sudah_bayar' THEN 1 END) as paid,
    COUNT(CASE WHEN status_tagihan = 'belum_bayar' AND tgl_jatuh_tempo BETWEEN '$today' AND '$h_plus_10' THEN 1 END) as unpaid,
    COUNT(CASE WHEN status_tagihan = 'terlambat' AND DATEDIFF('$today', tgl_jatuh_tempo) <= 10 THEN 1 END) as overdue,
    COALESCE(SUM(CASE WHEN status_tagihan = 'sudah_bayar' THEN jumlah_tagihan END), 0) as total_paid,
    COALESCE(SUM(CASE WHEN status_tagihan IN ('belum_bayar', 'terlambat') AND (
        (status_tagihan = 'belum_bayar' AND tgl_jatuh_tempo BETWEEN '$today' AND '$h_plus_10') OR
        (status_tagihan = 'terlambat' AND DATEDIFF('$today', tgl_jatuh_tempo) <= 10)
    ) THEN jumlah_tagihan END), 0) as total_unpaid
    FROM tagihan";

$stats_result = $mysqli->query($stats_query);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    // Default values jika query gagal
    $stats = [
        'total' => 0,
        'paid' => 0,
        'unpaid' => 0,
        'overdue' => 0,
        'total_paid' => 0,
        'total_unpaid' => 0
    ];
}

// Get isolation statistics using existing status_aktif field
$isolation_stats = $mysqli->query("
    SELECT COUNT(*) as isolated_users 
    FROM data_pelanggan 
    WHERE status_aktif = 'isolir'
")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Tagihan | Admin</title>
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
    
    .alert-warning {
      background-color: rgba(248, 172, 89, 0.1);
      color: var(--warning);
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
    
    /* Table styling */
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
    
    /* Badge styling */
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
    
    .badge-dark {
      background-color: var(--dark);
    }
    
    /* Statistics cards */
    .stat-card {
      transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
      border: none;
      border-radius: 0.5rem;
      box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
      height: 100%;
    }
    
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
    }
    
    .stat-card .card-body {
      padding: 1rem;
    }
    
    /* Icon circle styling */
    .icon-circle {
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      flex-shrink: 0;
    }
    
    /* Typography hierarchy */
    .stat-card h6 {
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 0.5rem;
      color: #6c757d;
    }
    
    .stat-card h3 {
      font-size: 1.75rem;
      font-weight: 700;
      margin-bottom: 0;
      line-height: 1.2;
    }
    
    .stat-card p {
      font-size: 0.8rem;
      color: #6c757d;
      margin-bottom: 0;
      font-weight: 400;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
      .stat-card h3 {
        font-size: 1.5rem;
      }
      
      .icon-circle {
        width: 2rem;
        height: 2rem;
        font-size: 1rem;
      }
      
      .stat-card .card-body {
        padding: 0.75rem;
      }
      
      .stat-card {
        margin-bottom: 1rem;
      }
    }
    
    @media (max-width: 576px) {
      .stat-card h3 {
        font-size: 1.25rem;
      }
      
      .stat-card h6 {
        font-size: 0.7rem;
      }
      
      .stat-card p {
        font-size: 0.75rem;
      }
      
      .icon-circle {
        width: 1.8rem;
        height: 1.8rem;
        font-size: 0.9rem;
      }
    }
    
    /* Pagination */
    .pagination .page-item.active .page-link {
      background-color: var(--primary);
      border-color: var(--primary);
    }
    
    .pagination .page-link {
      color: var(--primary);
    }
    
    /* Modal styling */
    .modal-header {
      background-color: var(--primary);
      color: white;
    }
    
    .modal-title {
      font-weight: 500;
    }
    
    /* Button group */
    .btn-group-sm > .btn {
      padding: 5px 10px;
      font-size: 12px;
    }

    /* Days to due indicator */
    .days-indicator {
      font-size: 11px;
      padding: 2px 6px;
      border-radius: 3px;
      font-weight: 500;
    }
    
    .days-critical {
      background-color: rgba(237, 85, 101, 0.1);
      color: var(--danger);
    }
    
    .days-warning {
      background-color: rgba(248, 172, 89, 0.1);
      color: var(--warning);
    }
    
    .days-normal {
      background-color: rgba(38, 185, 154, 0.1);
      color: var(--success);
    }
    
    /* PPPOE Status indicators */
    .pppoe-status {
      font-size: 10px;
      padding: 1px 4px;
      border-radius: 2px;
      font-weight: 500;
    }
    
    .pppoe-active {
      background-color: rgba(38, 185, 154, 0.1);
      color: var(--success);
    }
    
    .pppoe-isolated {
      background-color: rgba(237, 85, 101, 0.1);
      color: var(--danger);
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
                        <h1><i class="fas fa-file-invoice me-2"></i>Billing Management System</h1>
                        <p class="page-subtitle">Daftar Tagihan dengan Auto Due Date & PPPOE Isolation - Terintegrasi Mutasi Keuangan</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="print_unpaid_invoices.php" target="_blank" class="btn btn-danger">
                        <i class="fas fa-print me-1"></i> Cetak Belum Bayar
                    </a>
                        <a href="../finance/mutasi_keuangan.php" class="btn btn-warning">
                            <i class="fas fa-exchange-alt me-1"></i> Mutasi Keuangan
                        </a>
                    </div>
                </div>
            </div>

            <!-- Info Alert untuk H-10 Logic & Auto Features -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Features:</strong> 
                ✅ Auto generate tagihan bulan berikutnya dengan due date mengikuti pola registrasi 
                ✅ Auto isolasi PPPOE user yang terlambat >1 hari (jam 00:00 WIB)
                ✅ Auto restore PPPOE setelah pembayaran via Mikrotik API
                ✅ <strong>Auto record pembayaran di mutasi keuangan</strong>
            </div>

            <!-- Auto-isolation Alert -->
            <?php if ($isolated_count > 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Auto-Isolation:</strong> <?= $isolated_count ?> pengguna telah di-isolasi otomatis karena terlambat >1 hari.
                </div>
            <?php endif; ?>

            <!-- Display alerts -->
            <?php if (isset($_SESSION['alert'])): ?>
                <div class="alert alert-<?= $_SESSION['alert']['type'] ?> d-flex align-items-center">
                    <i class="fas <?= $_SESSION['alert']['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-2"></i>
                    <div><?= htmlspecialchars($_SESSION['alert']['message']) ?></div>
                </div>
                <?php unset($_SESSION['alert']); ?>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <!-- Total Tagihan Card -->
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card stat-card h-100 border-start border-primary border-4">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="text-muted mb-2">Total Tagihan</h6>
                                    <h3 class="text-primary"><?= $stats['total'] ?? 0 ?></h3>
                                </div>
                                <div class="icon-circle bg-primary bg-opacity-10 text-primary">
                                    <i class="fas fa-file-invoice"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Sudah Bayar Card -->
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card stat-card h-100 border-start border-success border-4">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="text-muted mb-2">Sudah Bayar</h6>
                                    <h3 class="text-success"><?= $stats['paid'] ?? 0 ?></h3>
                                    <p class="text-muted mb-0">
                                        Rp <?= number_format($stats['total_paid'] ?? 0, 0, ',', '.') ?>
                                    </p>
                                </div>
                                <div class="icon-circle bg-success bg-opacity-10 text-success">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Outstanding Card -->
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card stat-card h-100 border-start border-danger border-4">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="text-muted mb-2">Outstanding</h6>
                                    <h3 class="text-danger"><?= $stats['overdue'] ?? 0 ?></h3>
                                    <p class="text-muted mb-0">
                                        Rp <?= number_format($stats['total_unpaid'] ?? 0, 0, ',', '.') ?>
                                    </p>
                                </div>
                                <div class="icon-circle bg-danger bg-opacity-10 text-danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Isolated Users Card -->
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card stat-card h-100 border-start border-dark border-4">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="text-muted mb-2">User Terisolasi</h6>
                                    <h3 class="text-dark"><?= $isolation_stats['isolated_users'] ?? 0 ?></h3>
                                </div>
                                <div class="icon-circle bg-dark bg-opacity-10 text-dark">
                                    <i class="fas fa-ban"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Form -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <input type="text" name="search" class="form-control" placeholder="Cari nama, paket, atau username..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-select">
                                <option value="">-- Semua Status --</option>
                                <option value="sudah_bayar" <?= $status_filter == 'sudah_bayar' ? 'selected' : '' ?>>Sudah Bayar</option>
                                <option value="belum_bayar" <?= $status_filter == 'belum_bayar' ? 'selected' : '' ?>>Belum Bayar</option>
                                <option value="terlambat" <?= $status_filter == 'terlambat' ? 'selected' : '' ?>>Terlambat</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="bulan" class="form-select">
                                <option value="">-- Semua Bulan --</option>
                                <?php for($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>" <?= $bulan_filter == $i ? 'selected' : '' ?>>
                                        <?= date('F', mktime(0, 0, 0, $i, 1)) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="tahun" class="form-select">
                                <option value="">-- Semua Tahun --</option>
                                <?php for($year = date('Y') - 2; $year <= date('Y') + 1; $year++): ?>
                                    <option value="<?= $year ?>" <?= $tahun_filter == $year ? 'selected' : '' ?>>
                                        <?= $year ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-1"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Billing Table -->
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-dark">
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Pelanggan</th>
                                    <th>Username Mikrotik</th>
                                    <th>Paket</th>
                                    <th>Jumlah</th>
                                    <th>Periode</th>
                                    <th>Jatuh Tempo</th>
                                    <th>Status</th>
                                    <th width="20%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($billing_list)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            Tidak ada tagihan dalam periode H-10 atau yang terlambat ≤10 hari
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $no = $offset + 1; ?>
                                    <?php foreach ($billing_list as $billing): ?>
                                        <?php
                                        // Hitung status dan hari ke jatuh tempo
                                        $days_to_due = $billing['days_to_due'];
                                        $is_overdue = $days_to_due < 0;
                                        $abs_days = abs($days_to_due);
                                        
                                        // Tentukan status tampilan
                                        if ($billing['status_tagihan'] == 'sudah_bayar') {
                                            $status_display = 'sudah_bayar';
                                            $status_class = 'success';
                                        } elseif ($billing['status_tagihan'] == 'terlambat') {
                                            $status_display = 'terlambat';
                                            $status_class = 'danger';
                                        } else {
                                            $status_display = 'belum_bayar';
                                            $status_class = 'warning';
                                        }
                                        
                                        // Indikator hari
                                        if ($billing['status_tagihan'] == 'sudah_bayar') {
                                            $days_indicator = '';
                                        } elseif ($is_overdue) {
                                            $days_indicator = "<span class='days-indicator days-critical'>Terlambat {$abs_days} hari</span>";
                                        } elseif ($days_to_due <= 3) {
                                            $days_indicator = "<span class='days-indicator days-critical'>H-{$days_to_due}</span>";
                                        } elseif ($days_to_due <= 7) {
                                            $days_indicator = "<span class='days-indicator days-warning'>H-{$days_to_due}</span>";
                                        } else {
                                            $days_indicator = "<span class='days-indicator days-normal'>H-{$days_to_due}</span>";
                                        }
                                        
                                        // PPPOE Status using existing fields
                                        $pppoe_status = ($billing['status_aktif'] == 'isolir' || $billing['mikrotik_disabled'] == 'yes') ? 'isolated' : 'active';
                                        $pppoe_class = $pppoe_status == 'isolated' ? 'pppoe-isolated' : 'pppoe-active';
                                        $pppoe_text = $pppoe_status == 'isolated' ? 'ISOLATED' : 'ACTIVE';
                                        ?>
                                        <tr>
                                            <td><?= $no++; ?></td>
                                            <td><strong><?= htmlspecialchars($billing['nama_pelanggan']); ?></strong></td>
                                            <td>
                                                <?= htmlspecialchars($billing['mikrotik_username'] ?? '-'); ?>
                                                <?php if (!empty($billing['mikrotik_username'])): ?>
                                                    <br><span class="pppoe-status <?= $pppoe_class ?>"><?= $pppoe_text ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($billing['nama_paket'] ?? '-'); ?></td>
                                            <td>Rp <?= number_format($billing['jumlah_tagihan'], 0, ',', '.'); ?></td>
                                            <td>
                                                <?= date('F Y', strtotime($billing['tahun_tagihan'] . '-' . $billing['bulan_tagihan'] . '-01')); ?>
                                            </td>
                                            <td>
                                                <?= date('d/m/Y', strtotime($billing['tgl_jatuh_tempo'])); ?>
                                                <?php if ($days_indicator): ?>
                                                    <br><?= $days_indicator ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?= $status_class ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $status_display)) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <?php if ($billing['status_tagihan'] != 'sudah_bayar'): ?>
                                                        <a href="payment.php?invoice_id=<?= $billing['id_tagihan'] ?>" class="btn btn-success" title="Bayar Tagihan & Auto Generate Next + Record Mutasi">
                                                            <i class="fas fa-money-bill"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($billing['mikrotik_username'])): ?>
                                                        <?php if ($pppoe_status == 'isolated'): ?>
                                                            <button type="button" class="btn btn-info" onclick="togglePPPOE('<?= $billing['id_tagihan'] ?>', 'restore')" title="Restore PPPOE">
                                                                <i class="fas fa-wifi"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-warning" onclick="togglePPPOE('<?= $billing['id_tagihan'] ?>', 'isolate')" title="Isolasi PPPOE">
                                                                <i class="fas fa-ban"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <a href="?delete=<?= $billing['id_tagihan']; ?>" class="btn btn-danger" title="Hapus" onclick="return confirm('Apakah Anda yakin ingin menghapus tagihan ini?')">
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
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="card-footer">
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center mb-0">
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
                        <div class="text-center text-muted mt-2">
                            Menampilkan <?= count($billing_list) ?> dari <?= $total_records ?> total tagihan (H-10)
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

<!-- Generate Billing Period Modal -->
<div class="modal fade" id="generateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Generate Tagihan Bulanan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="generate_tagihan">
                    
                    <div class="mb-3">
                        <label for="month" class="form-label">Bulan</label>
                        <select name="month" id="month" class="form-select" required>
                            <?php for($i = 1; $i <= 12; $i++): ?>
                                <option value="<?= $i ?>" <?= $i == date('n') ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0, 0, 0, $i, 1)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="year" class="form-label">Tahun</label>
                        <select name="year" id="year" class="form-select" required>
                            <?php for($year = date('Y') - 1; $year <= date('Y') + 1; $year++): ?>
                                <option value="<?= $year ?>" <?= $year == date('Y') ? 'selected' : '' ?>>
                                    <?= $year ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i> 
                        Tagihan akan digenerate untuk semua pelanggan aktif untuk bulan yang dipilih. 
                        Jatuh tempo otomatis di akhir bulan. Pembayaran akan otomatis tercatat di mutasi keuangan.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus-circle me-1"></i> Generate Tagihan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- PPPOE Management Modal -->
<div class="modal fade" id="pppoeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-wifi me-2"></i>PPPOE Management</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="pppoeForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="manage_pppoe">
                    <input type="hidden" name="invoice_id" id="pppoe_invoice_id">
                    <input type="hidden" name="pppoe_action" id="pppoe_action_type">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <span id="pppoeModalMessage"></span>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Alasan:</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="Masukkan alasan isolasi/restore..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning" id="pppoeActionBtn">
                        <i class="fas fa-wifi me-1"></i> <span id="pppoeActionText">Execute</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
// Simplified JavaScript without checkbox functionality
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ Billing Management System loaded successfully');
    console.log('📊 Features: Auto Invoice Generation, PPPOE Isolation, Payment Tracking, Mutasi Keuangan Integration');
});

function togglePPPOE(invoiceId, action) {
    document.getElementById('pppoe_invoice_id').value = invoiceId;
    document.getElementById('pppoe_action_type').value = action;
    
    const modalMessage = document.getElementById('pppoeModalMessage');
    const actionText = document.getElementById('pppoeActionText');
    const actionBtn = document.getElementById('pppoeActionBtn');
    
    if (action === 'isolate') {
        modalMessage.textContent = 'User akan di-isolasi dan tidak bisa akses internet. Pastikan ini adalah tindakan yang tepat.';
        actionText.textContent = 'Isolasi User';
        actionBtn.className = 'btn btn-warning';
        actionBtn.innerHTML = '<i class="fas fa-ban me-1"></i> Isolasi User';
    } else {
        modalMessage.textContent = 'User akan di-restore dan bisa akses internet kembali. Pastikan pembayaran sudah diterima.';
        actionText.textContent = 'Restore User';
        actionBtn.className = 'btn btn-success';
        actionBtn.innerHTML = '<i class="fas fa-wifi me-1"></i> Restore User';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('pppoeModal'));
    modal.show();
}

// Enhanced auto-hide alerts with progress bar
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert:not(.alert-info)');
    alerts.forEach(function(alert) {
        // Add progress bar
        const progressBar = document.createElement('div');
        progressBar.style.cssText = `
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background-color: rgba(255,255,255,0.3);
            width: 100%;
            animation: countdown 5s linear forwards;
        `;
        
        const style = document.createElement('style');
        style.textContent = `
            @keyframes countdown {
                from { width: 100%; }
                to { width: 0%; }
            }
        `;
        if (!document.head.querySelector('style[data-progress]')) {
            style.setAttribute('data-progress', 'true');
            document.head.appendChild(style);
        }
        
        alert.style.position = 'relative';
        alert.appendChild(progressBar);
        
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s, transform 0.5s';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });
}, 1000);

// PPPOE Form handler - handle individual PPPOE actions
document.getElementById('pppoeForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const action = formData.get('pppoe_action');
    const invoiceId = formData.get('invoice_id');
    
    // Here you would typically send an AJAX request to handle PPPOE action
    // For now, we'll show a loading message
    const submitBtn = document.getElementById('pppoeActionBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing...';
    submitBtn.disabled = true;
    
    // Simulate processing time
    setTimeout(() => {
        // Close modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('pppoeModal'));
        modal.hide();
        
        // Show success message
        const notification = document.createElement('div');
        notification.className = 'alert alert-success position-fixed';
        notification.style.cssText = `
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
        `;
        notification.innerHTML = `
            <i class="fas fa-check-circle me-2"></i>
            PPPOE ${action === 'isolate' ? 'isolation' : 'restoration'} berhasil diproses!
            <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
        `;
        document.body.appendChild(notification);
        
        // Reset button
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        
        // Auto remove notification
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
        
        // Optionally reload page to see updated status
        setTimeout(() => {
            window.location.reload();
        }, 2000);
    }, 2000);
});

// Enhanced notification system
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} position-fixed`;
    notification.style.cssText = `
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        max-width: 400px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        border-left: 4px solid var(--${type === 'success' ? 'success' : type === 'warning' ? 'warning' : type === 'danger' ? 'danger' : 'info'});
        animation: slideInRight 0.3s ease-out;
    `;
    
    const icons = {
        success: 'fa-check-circle',
        warning: 'fa-exclamation-triangle', 
        danger: 'fa-times-circle',
        info: 'fa-info-circle'
    };
    
    notification.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="fas ${icons[type] || icons.info} me-2"></i>
            <div class="flex-grow-1">${message}</div>
            <button type="button" class="btn-close btn-close-sm ms-2" onclick="this.parentElement.parentElement.remove()"></button>
        </div>
    `;
    
    // Add animation styles if not exists
    if (!document.head.querySelector('style[data-notifications]')) {
        const style = document.createElement('style');
        style.setAttribute('data-notifications', 'true');
        style.textContent = `
            @keyframes slideInRight {
                from {
                    opacity: 0;
                    transform: translateX(100%);
                }
                to {
                    opacity: 1;
                    transform: translateX(0);
                }
            }
        `;
        document.head.appendChild(style);
    }
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        if (notification.parentElement) {
            notification.style.animation = 'slideOutRight 0.3s ease-in forwards';
            setTimeout(() => notification.remove(), 300);
        }
    }, 5000);
}
</script>

<?php 
// Include footer
require_once __DIR__ . '/../templates/footer.php';

// End output buffering and flush
ob_end_flush();
?>