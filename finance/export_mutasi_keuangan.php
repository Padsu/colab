<?php
// export_mutasi_keuangan.php

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

// Database connection already available from config_database.php as $mysqli

$export_format = $_GET['export'] ?? '';
if (!in_array($export_format, ['excel', 'pdf'])) {
    die('Format export tidak valid');
}

// Filter parameters (same as main page)
$jenis_filter = $_GET['jenis'] ?? '';
$kategori_filter = $_GET['kategori'] ?? '';
$tanggal_dari = $_GET['tanggal_dari'] ?? date('Y-m-01');
$tanggal_sampai = $_GET['tanggal_sampai'] ?? date('Y-m-t');

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

// Get all transactions for export
$main_query = "SELECT tl.*, u.nama_lengkap as created_by_name 
               FROM transaksi_lain tl 
               LEFT JOIN users u ON tl.created_by = u.id_user 
               $where_clause 
               ORDER BY tl.tanggal DESC, tl.created_at DESC";

$stmt = $mysqli->prepare($main_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get summary
$summary_query = "SELECT 
    SUM(CASE WHEN jenis = 'pemasukan' THEN jumlah ELSE 0 END) as total_pemasukan,
    SUM(CASE WHEN jenis = 'pengeluaran' THEN jumlah ELSE 0 END) as total_pengeluaran,
    COUNT(CASE WHEN jenis = 'pemasukan' THEN 1 END) as count_pemasukan,
    COUNT(CASE WHEN jenis = 'pengeluaran' THEN 1 END) as count_pengeluaran
    FROM transaksi_lain $where_clause";

$summary_stmt = $mysqli->prepare($summary_query);
if (!empty($params)) {
    $summary_stmt->bind_param($types, ...$params);
}
$summary_stmt->execute();
$summary = $summary_stmt->get_result()->fetch_assoc();
$summary_stmt->close();

// Get payment data
$payment_query = "SELECT 
    SUM(jumlah_bayar) as total_pembayaran_pelanggan,
    COUNT(*) as count_pembayaran_pelanggan
    FROM pembayaran 
    WHERE tanggal_bayar >= ? AND tanggal_bayar <= ?";

$payment_stmt = $mysqli->prepare($payment_query);
$payment_stmt->bind_param('ss', $tanggal_dari, $tanggal_sampai);
$payment_stmt->execute();
$payment_summary = $payment_stmt->get_result()->fetch_assoc();
$payment_stmt->close();

$total_pemasukan = ($summary['total_pemasukan'] ?? 0) + ($payment_summary['total_pembayaran_pelanggan'] ?? 0);
$total_pengeluaran = $summary['total_pengeluaran'] ?? 0;
$saldo = $total_pemasukan - $total_pengeluaran;

if ($export_format === 'excel') {
    // Excel Export
    $filename = 'Mutasi_Keuangan_' . date('Y-m-d_H-i-s') . '.xls';
    
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Cache-Control: max-age=0");
    
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    echo '<Worksheet ss:Name="Mutasi Keuangan">' . "\n";
    echo '<Table>' . "\n";
    
    // Header
    echo '<Row>';
    echo '<Cell><Data ss:Type="String">LAPORAN MUTASI KEUANGAN</Data></Cell>';
    echo '</Row>';
    
    echo '<Row>';
    echo '<Cell><Data ss:Type="String">Periode: ' . date('d/m/Y', strtotime($tanggal_dari)) . ' - ' . date('d/m/Y', strtotime($tanggal_sampai)) . '</Data></Cell>';
    echo '</Row>';
    
    echo '<Row>';
    echo '<Cell><Data ss:Type="String">Dicetak: ' . date('d/m/Y H:i:s') . '</Data></Cell>';
    echo '</Row>';
    
    echo '<Row></Row>'; // Empty row
    
    // Summary
    echo '<Row>';
    echo '<Cell><Data ss:Type="String">RINGKASAN</Data></Cell>';
    echo '</Row>';
    
    echo '<Row>';
    echo '<Cell><Data ss:Type="String">Total Pemasukan</Data></Cell>';
    echo '<Cell><Data ss:Type="Number">' . $total_pemasukan . '</Data></Cell>';
    echo '</Row>';
    
    echo '<Row>';
    echo '<Cell><Data ss:Type="String">Total Pengeluaran</Data></Cell>';
    echo '<Cell><Data ss:Type="Number">' . $total_pengeluaran . '</Data></Cell>';
    echo '</Row>';
    
    echo '<Row>';
    echo '<Cell><Data ss:Type="String">Saldo Bersih</Data></Cell>';
    echo '<Cell><Data ss:Type="Number">' . $saldo . '</Data></Cell>';
    echo '</Row>';
    
    echo '<Row></Row>'; // Empty row
    
    // Table headers
    echo '<Row>';
    echo '<Cell><Data ss:Type="String">No</Data></Cell>';
    echo '<Cell><Data ss:Type="String">Tanggal</Data></Cell>';
    echo '<Cell><Data ss:Type="String">Jenis</Data></Cell>';
    echo '<Cell><Data ss:Type="String">Kategori</Data></Cell>';
    echo '<Cell><Data ss:Type="String">Keterangan</Data></Cell>';
    echo '<Cell><Data ss:Type="String">Jumlah</Data></Cell>';
    echo '<Cell><Data ss:Type="String">Dibuat Oleh</Data></Cell>';
    echo '</Row>';
    
    // Data rows
    $no = 1;
    foreach ($transactions as $transaction) {
        echo '<Row>';
        echo '<Cell><Data ss:Type="Number">' . $no++ . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . date('d/m/Y', strtotime($transaction['tanggal'])) . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . ucfirst($transaction['jenis']) . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($transaction['kategori']) . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($transaction['keterangan']) . '</Data></Cell>';
        echo '<Cell><Data ss:Type="Number">' . $transaction['jumlah'] . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($transaction['created_by_name'] ?? 'System') . '</Data></Cell>';
        echo '</Row>';
    }
    
    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>' . "\n";
    
} elseif ($export_format === 'pdf') {
    // PDF Export using HTML to PDF
    ob_clean();
    
    $filename = 'Mutasi_Keuangan_' . date('Y-m-d_H-i-s') . '.pdf';
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Laporan Mutasi Keuangan</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .summary { margin-bottom: 30px; }
            .summary table { width: 50%; border-collapse: collapse; }
            .summary td { padding: 8px; border: 1px solid #ddd; }
            .summary .label { font-weight: bold; background-color: #f5f5f5; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { padding: 8px; border: 1px solid #ddd; text-align: left; font-size: 11px; }
            th { background-color: #f0f0f0; font-weight: bold; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .pemasukan { color: #28a745; }
            .pengeluaran { color: #dc3545; }
            @media print {
                body { margin: 0; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h2>LAPORAN MUTASI KEUANGAN</h2>
            <p>Periode: <?= date('d/m/Y', strtotime($tanggal_dari)) ?> - <?= date('d/m/Y', strtotime($tanggal_sampai)) ?></p>
            <p>Dicetak: <?= date('d/m/Y H:i:s') ?></p>
        </div>

        <div class="summary">
            <h3>RINGKASAN KEUANGAN</h3>
            <table>
                <tr>
                    <td class="label">Total Pemasukan</td>
                    <td class="text-right pemasukan">Rp <?= number_format($total_pemasukan, 0, ',', '.') ?></td>
                </tr>
                <tr>
                    <td class="label">Total Pengeluaran</td>
                    <td class="text-right pengeluaran">Rp <?= number_format($total_pengeluaran, 0, ',', '.') ?></td>
                </tr>
                <tr>
                    <td class="label">Saldo Bersih</td>
                    <td class="text-right <?= $saldo >= 0 ? 'pemasukan' : 'pengeluaran' ?>">
                        Rp <?= number_format(abs($saldo), 0, ',', '.') ?> <?= $saldo >= 0 ? '(Surplus)' : '(Defisit)' ?>
                    </td>
                </tr>
                <tr>
                    <td class="label">Total Transaksi</td>
                    <td class="text-right"><?= count($transactions) ?> transaksi</td>
                </tr>
            </table>
        </div>

        <h3>DETAIL TRANSAKSI</h3>
        <table>
            <thead>
                <tr>
                    <th width="5%">No</th>
                    <th width="12%">Tanggal</th>
                    <th width="10%">Jenis</th>
                    <th width="15%">Kategori</th>
                    <th width="30%">Keterangan</th>
                    <th width="15%">Jumlah</th>
                    <th width="13%">Dibuat Oleh</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="7" class="text-center">Tidak ada transaksi ditemukan</td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; ?>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td><?= date('d/m/Y', strtotime($transaction['tanggal'])) ?></td>
                            <td class="<?= $transaction['jenis'] ?>"><?= ucfirst($transaction['jenis']) ?></td>
                            <td><?= htmlspecialchars($transaction['kategori']) ?></td>
                            <td><?= htmlspecialchars($transaction['keterangan']) ?></td>
                            <td class="text-right <?= $transaction['jenis'] ?>">
                                Rp <?= number_format($transaction['jumlah'], 0, ',', '.') ?>
                            </td>
                            <td><?= htmlspecialchars($transaction['created_by_name'] ?? 'System') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top: 50px;">
            <p style="text-align: right;">
                <br><br><br>
                ____________________<br>
                Finance Manager
            </p>
        </div>
    </body>
    </html>

    <script>
        window.onload = function() {
            window.print();
        };
    </script>
    <?php
}

ob_end_flush();
?>