<?php
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
require_once __DIR__ . '/../config/config_mikrotik.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

$active_clients = [];
$error = '';
$mikrotik_connected = false;

try {
    // Connect to MikroTik
    $api = new RouterosAPI();
    if ($api->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass)) {
        $mikrotik_connected = true;
        
        // Get active PPPoE sessions
        $api->write('/ppp/active/print');
        $pppoe_active = $api->read();
        
        // Get active hotspot users if needed
        // $api->write('/ip/hotspot/active/print');
        // $hotspot_active = $api->read();
        
        $api->disconnect();
        
        // Process active clients
        if (is_array($pppoe_active)) {
            foreach ($pppoe_active as $session) {
                $active_clients[] = [
                    'username' => $session['name'],
                    'service' => 'PPPoE',
                    'remote_address' => $session['remote-address'] ?? '-',
                    'uptime' => $session['uptime'] ?? '-',
                    'bytes_in' => $session['bytes-in'] ?? 0,
                    'bytes_out' => $session['bytes-out'] ?? 0
                ];
            }
        }
        
        // Sort by username
        usort($active_clients, function($a, $b) {
            return strcmp($a['username'], $b['username']);
        });
    }
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pelanggan Aktif</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Gentelella Style -->
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

    .alert-warning {
      background-color: rgba(248, 172, 89, 0.1);
      color: var(--warning);
    }

    .badge {
      font-weight: 500;
      font-size: 12px;
      padding: 5px 8px;
    }

    .badge-online {
      background-color: var(--success);
    }

    .badge-offline {
      background-color: var(--secondary);
    }

    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
      font-size: 13px;
    }

    .dataTables_wrapper .dataTables_filter input {
      border: 1px solid #ddd;
      padding: 5px 10px;
      border-radius: 3px;
    }

    table.dataTable {
      margin-top: 10px !important;
      margin-bottom: 15px !important;
    }

    table.dataTable thead th {
      border-bottom: 1px solid #ddd;
      font-weight: 500;
      font-size: 13px;
      color: var(--dark);
    }

    table.dataTable tbody td {
      font-size: 13px;
      vertical-align: middle;
      padding: 12px 15px;
    }

    .btn {
      border-radius: 3px;
      font-size: 13px;
      padding: 8px 15px;
      font-weight: 500;
    }

    .btn-primary {
      background-color: var(--primary);
      border-color: var(--primary);
    }

    .btn-primary:hover {
      background-color: #169F85;
      border-color: #169F85;
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
            <h1>Pelanggan Aktif</h1>
            <p class="page-subtitle">Daftar pelanggan yang sedang terkoneksi</p>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Daftar Pelanggan Aktif</h5>
                        <div>
                            <span class="badge badge-online me-2">
                                <i class="fas fa-circle me-1"></i> <?= count($active_clients) ?> Online
                            </span>
                            <button id="refreshBtn" class="btn btn-sm btn-primary">
                                <i class="fas fa-sync-alt me-1"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$mikrotik_connected): ?>
                            <div class="alert alert-warning d-flex align-items-center">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Tidak dapat terhubung ke MikroTik. Silakan cek koneksi dan konfigurasi.
                            </div>
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table id="activeClientsTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Layanan</th>
                                        <th>Alamat IP</th>
                                        <th>Uptime</th>
                                        <th>Download</th>
                                        <th>Upload</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_clients as $client): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($client['username']) ?></td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?= htmlspecialchars($client['service']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($client['remote_address']) ?></td>
                                            <td><?= htmlspecialchars($client['uptime']) ?></td>
                                            <td><?= formatBytes($client['bytes_in']) ?></td>
                                            <td><?= formatBytes($client['bytes_out']) ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-danger" onclick="disconnectUser('<?= $client['username'] ?>', '<?= $client['service'] ?>')">
                                                    <i class="fas fa-plug"></i> Disconnect
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
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
    // Initialize DataTable
    $(document).ready(function() {
        $('#activeClientsTable').DataTable({
            "language": {
                "lengthMenu": "Tampilkan _MENU_ pelanggan per halaman",
                "zeroRecords": "Tidak ada pelanggan aktif ditemukan",
                "info": "Menampilkan halaman _PAGE_ dari _PAGES_",
                "infoEmpty": "Tidak ada data tersedia",
                "infoFiltered": "(difilter dari _MAX_ total pelanggan)",
                "search": "Cari:",
                "paginate": {
                    "first": "Pertama",
                    "last": "Terakhir",
                    "next": "Selanjutnya",
                    "previous": "Sebelumnya"
                }
            },
            "columnDefs": [
                { "orderable": false, "targets": [6] } // Disable sorting for action column
            ]
        });
        
        // Refresh button
        $('#refreshBtn').click(function() {
            window.location.reload();
        });
    });
    
    // Disconnect user function
    function disconnectUser(username, service) {
        Swal.fire({
            title: 'Disconnect User?',
            text: `Anda yakin ingin memutus koneksi ${username}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#1ABB9C',
            cancelButtonColor: '#ED5565',
            confirmButtonText: 'Ya, Disconnect!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // AJAX request to disconnect
                $.ajax({
                    url: 'disconnect_user.php',
                    method: 'POST',
                    data: { 
                        username: username,
                        service: service 
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire(
                                'Terputus!',
                                `User ${username} telah diputus.`,
                                'success'
                            ).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire(
                                'Error!',
                                response.message || 'Gagal memutus koneksi',
                                'error'
                            );
                        }
                    },
                    error: function() {
                        Swal.fire(
                            'Error!',
                            'Terjadi kesalahan saat memproses permintaan',
                            'error'
                        );
                    }
                });
            }
        });
    }
    
    // Auto refresh every 60 seconds
    setInterval(function() {
        if ($('#activeClientsTable').length) {
            window.location.reload();
        }
    }, 60000);
    </script>
</body>
</html>

<?php
// Function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

require_once __DIR__ . '/../templates/footer.php';
?>