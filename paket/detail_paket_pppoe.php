<?php
ob_start();
session_start();

require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

$paket = null;
$id_paket = $_GET['id'] ?? null;

if (!$id_paket || !is_numeric($id_paket)) {
    $_SESSION['error_message'] = 'ID Paket tidak valid';
    header('Location: list_paket_pppoe.php');
    exit();
}

$stmt = $mysqli->prepare("SELECT * FROM `paket_internet` WHERE `id_paket` = ?");
if ($stmt) {
    $stmt->bind_param("i", $id_paket);
    $stmt->execute();
    $result = $stmt->get_result();
    $paket = $result->fetch_assoc();
    $stmt->close();
} else {
    $_SESSION['error_message'] = 'Error preparing statement: ' . $mysqli->error;
    header('Location: list_paket_pppoe.php');
    exit();
}

$mysqli->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Paket PPPoE | Admin</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

    .badge-danger {
      background-color: var(--danger);
    }

    .badge-warning {
      background-color: var(--warning);
    }

    .panel {
      background-color: white;
      border-radius: 5px;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
      margin-bottom: 20px;
    }

    .panel-header {
      padding: 15px 20px;
      border-bottom: 1px solid #eee;
    }

    .panel-header h2 {
      font-size: 16px;
      font-weight: 500;
      color: var(--dark);
      margin: 0;
    }

    .panel-body {
      padding: 20px;
    }

    .table-detail {
      width: 100%;
      border-collapse: collapse;
    }

    .table-detail th {
      width: 40%;
      text-align: left;
      padding: 12px 15px;
      background-color: #f8f9fa;
      border: 1px solid #eee;
      font-weight: 500;
      color: var(--dark);
    }

    .table-detail td {
      padding: 12px 15px;
      border: 1px solid #eee;
    }

    pre {
      background-color: #f8f9fa;
      padding: 15px;
      border-radius: 3px;
      border: 1px solid #eee;
      font-family: Consolas, Monaco, 'Andale Mono', monospace;
      font-size: 13px;
      line-height: 1.5;
      color: #333;
      overflow-x: auto;
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

    .btn-warning {
      background-color: var(--warning);
    }

    .btn-success {
      background-color: var(--success);
    }

    .btn-sm {
      padding: 5px 10px;
      font-size: 12px;
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
            <h1><i class="fas fa-network-wired me-2"></i>Detail Paket PPPoE</h1>
            <p class="page-subtitle">Informasi lengkap paket internet PPPoE</p>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Informasi Paket</h5>
                        <div>
                            <a href="edit_paket_pppoe.php?id=<?php echo $id_paket; ?>" class="btn btn-warning btn-sm">
                                <i class="fas fa-edit me-1"></i> Edit Paket
                            </a>
                            <a href="../paket/list_paket_pppoe.php" class="btn btn-success btn-sm ms-2">
                                <i class="fas fa-arrow-left me-1"></i> Kembali
                            </a>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if ($paket): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="panel">
                                        <div class="panel-header">
                                            <h2><i class="fas fa-info-circle me-2"></i>Informasi Dasar</h2>
                                        </div>
                                        <div class="panel-body">
                                            <table class="table-detail">
                                                <tbody>
                                                    <tr>
                                                        <th>ID Paket</th>
                                                        <td><?php echo htmlspecialchars($paket['id_paket']); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Nama Paket</th>
                                                        <td><?php echo htmlspecialchars($paket['nama_paket']); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Profile Mikrotik</th>
                                                        <td><?php echo htmlspecialchars($paket['profile_name']); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Harga</th>
                                                        <td>Rp <?php echo number_format($paket['harga'], 0, ',', '.'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Status</th>
                                                        <td>
                                                            <span class="badge <?php echo ($paket['status_paket'] == 'aktif') ? 'badge-success' : 'badge-danger'; ?>">
                                                                <?php echo ucfirst($paket['status_paket']); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="panel">
                                        <div class="panel-header">
                                            <h2><i class="fas fa-tachometer-alt me-2"></i>Kecepatan</h2>
                                        </div>
                                        <div class="panel-body">
                                            <table class="table-detail">
                                                <tbody>
                                                    <tr>
                                                        <th>Download (Rx)</th>
                                                        <td><?php echo htmlspecialchars($paket['rate_limit_rx']); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Upload (Tx)</th>
                                                        <td><?php echo htmlspecialchars($paket['rate_limit_tx']); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Burst Limit</th>
                                                        <td>
                                                            <?php echo htmlspecialchars($paket['burst_limit_rx']); ?> / 
                                                            <?php echo htmlspecialchars($paket['burst_limit_tx']); ?>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="panel">
                                        <div class="panel-header">
                                            <h2><i class="fas fa-cog me-2"></i>Pengaturan PPPoE</h2>
                                        </div>
                                        <div class="panel-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <table class="table-detail">
                                                        <tbody>
                                                            <tr>
                                                                <th>Local Address</th>
                                                                <td><?php echo htmlspecialchars($paket['local_address'] ?: '-'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Remote Address</th>
                                                                <td><?php echo htmlspecialchars($paket['remote_address'] ?: '-'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>DNS Server</th>
                                                                <td><?php echo htmlspecialchars($paket['dns_server'] ?: '-'); ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <div class="col-md-6">
                                                    <table class="table-detail">
                                                        <tbody>
                                                            <tr>
                                                                <th>Idle Timeout</th>
                                                                <td><?php echo htmlspecialchars($paket['idle_timeout'] ?: '-'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Session Timeout</th>
                                                                <td><?php echo htmlspecialchars($paket['session_timeout'] ?: '-'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Shared Users</th>
                                                                <td><?php echo htmlspecialchars($paket['shared_users'] ?: '-'); ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="panel">
                                        <div class="panel-header">
                                            <h2><i class="fas fa-code me-2"></i>Scripts</h2>
                                        </div>
                                        <div class="panel-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <h4><i class="fas fa-arrow-up me-2"></i>On Up Script</h4>
                                                    <pre><?php echo htmlspecialchars($paket['on_up'] ?: 'Tidak ada script'); ?></pre>
                                                </div>
                                                <div class="col-md-6">
                                                    <h4><i class="fas fa-arrow-down me-2"></i>On Down Script</h4>
                                                    <pre><?php echo htmlspecialchars($paket['on_down'] ?: 'Tidak ada script'); ?></pre>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        <?php else: ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <div>
                                    <strong>Error!</strong> Paket dengan ID tersebut tidak ditemukan.
                                </div>
                            </div>
                            <div class="text-center">
                                <a href="list_paket_pppoe.php" class="btn btn-primary">
                                    <i class="fas fa-arrow-left me-1"></i> Kembali ke Daftar
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <?php require_once __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>

<?php ob_end_flush(); ?>