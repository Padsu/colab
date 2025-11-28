<?php
ob_start();
session_start();

// Load konfigurasi
require_once __DIR__ . '/../config/config_mikrotik.php';
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Mengambil data paket dari database
$query = "SELECT * FROM paket_internet ORDER BY nama_paket ASC";
$result = $mysqli->query($query);

$paket_list = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $paket_list[] = $row;
    }
    $result->free();
} else {
    echo "<div class='alert alert-danger'>Error fetching data: " . htmlspecialchars($mysqli->error) . "</div>";
}

// Proses hapus paket
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $query = "DELETE FROM paket_internet WHERE id_paket = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Paket berhasil dihapus!';
        header("Location: list_paket_pppoe.php");
        exit();
    } else {
        $_SESSION['error_message'] = 'Gagal menghapus paket: ' . $stmt->error;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Paket PPPoE | Admin</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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

    .alert-success {
      background-color: rgba(38, 185, 154, 0.1);
      color: var(--success);
    }

    .alert-danger {
      background-color: rgba(237, 85, 101, 0.1);
      color: var(--danger);
    }

    .badge {
      font-weight: 500;
      font-size: 12px;
      padding: 5px 8px;
    }

    .badge-success {
      background-color: var(--success);
    }

    .badge-warning {
      background-color: var(--warning);
    }

    .table-responsive {
      overflow-x: auto;
    }

    table.dataTable {
      margin-top: 10px !important;
      margin-bottom: 15px !important;
      border-collapse: collapse !important;
    }

    table.dataTable thead th {
      border-bottom: 1px solid #ddd;
      font-weight: 500;
      font-size: 13px;
      color: var(--dark);
      background-color: white;
    }

    table.dataTable tbody td {
      font-size: 13px;
      vertical-align: middle;
      padding: 12px 15px;
      border-top: 1px solid #f1f1f1;
    }

    table.dataTable tbody tr:hover {
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

    .btn-success {
      background-color: var(--success);
    }

    .btn-warning {
      background-color: var(--warning);
    }

    .btn-danger {
      background-color: var(--danger);
    }

    .btn-info {
      background-color: var(--info);
    }

    .btn-sm {
      padding: 5px 10px;
      font-size: 12px;
    }

    .btn-group .btn {
      margin-right: 5px;
    }

    .btn-group .btn:last-child {
      margin-right: 0;
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
            <h1><i class="fas fa-network-wired me-2"></i>Daftar Paket PPPoE</h1>
            <p class="page-subtitle">Kelola paket internet PPPoE yang tersedia</p>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Data Paket PPPoE</h5>
                        <div class="d-flex gap-2">
                            <a href="../paket/tambah_paket_pppoe.php" class="btn btn-warning btn-sm">
                                <i class="fas fa-plus me-1"></i> Tambah Paket
                            </a>
                            <a href="/rtrwnet/paket/" class="btn btn-secondary btn-sm">
                                <i class="fas fa-arrow-left me-1"></i> Kembali
                            </a>
                        </div>
                    </div>
                    
                    <div class="card-body">
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
                        
                        <div class="table-responsive">
                            <table id="paketTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nama Paket</th>
                                        <th>Profile Mikrotik</th>
                                        <th>Harga</th>
                                        <th>Kecepatan (DL/UL)</th>
                                        <th>IP Pool</th>
                                        <th>Status Sync</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($paket_list) > 0): ?>
                                        <?php foreach ($paket_list as $paket): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($paket['id_paket']) ?></td>
                                                <td><?= htmlspecialchars($paket['nama_paket']) ?></td>
                                                <td><?= htmlspecialchars($paket['profile_name']) ?></td>
                                                <td>Rp <?= number_format($paket['harga'], 0, ',', '.') ?></td>
                                                <td><?= htmlspecialchars($paket['rate_limit_rx'] . ' / ' . $paket['rate_limit_tx']) ?></td>
                                                <td><?= htmlspecialchars($paket['remote_address']) ?></td>
                                                <td>
                                                    <span class="badge <?= ($paket['sync_mikrotik'] == 'yes') ? 'badge-success' : 'badge-warning' ?>">
                                                        <?= htmlspecialchars(ucfirst($paket['sync_mikrotik'])) ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group">
                                                        <a href="detail_paket_pppoe.php?id=<?= $paket['id_paket'] ?>" class="btn btn-info btn-sm" title="Detail">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="edit_paket_pppoe.php?id=<?= $paket['id_paket'] ?>" class="btn btn-warning btn-sm" title="Edit">
                                                            <i class="fas fa-pencil-alt"></i>
                                                        </a>
                                                        <a href="?action=delete&id=<?= $paket['id_paket'] ?>" class="btn btn-danger btn-sm" title="Hapus" onclick="return confirm('Yakin ingin menghapus paket ini?')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">Tidak ada data paket yang tersedia</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#paketTable').DataTable({
            "language": {
                "lengthMenu": "Tampilkan _MENU_ paket per halaman",
                "zeroRecords": "Tidak ada paket ditemukan",
                "info": "Menampilkan halaman _PAGE_ dari _PAGES_",
                "infoEmpty": "Tidak ada data tersedia",
                "infoFiltered": "(difilter dari _MAX_ total paket)",
                "search": "Cari:",
                "paginate": {
                    "first": "Pertama",
                    "last": "Terakhir",
                    "next": "Selanjutnya",
                    "previous": "Sebelumnya"
                }
            },
            "columnDefs": [
                { "orderable": false, "targets": [7] } // Disable sorting for action column
            ]
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            $('.alert').fadeOut('slow');
        }, 5000);
    });
    </script>
</body>
</html>

<?php 
$mysqli->close();
ob_end_flush();
require_once __DIR__ . '/../templates/footer.php';

?>