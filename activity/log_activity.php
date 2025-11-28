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
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Initialize variables
$activities = [];
$error = '';
$filter_username = $_GET['username'] ?? '';
$filter_action = $_GET['action'] ?? '';
$filter_date = $_GET['date'] ?? '';

// Build base query
$query = "SELECT * FROM activity_log";
$where = [];
$params = [];
$types = '';

// Apply filters
if (!empty($filter_username)) {
    $where[] = "username LIKE ?";
    $params[] = "%$filter_username%";
    $types .= 's';
}

if (!empty($filter_action)) {
    $where[] = "action LIKE ?";
    $params[] = "%$filter_action%";
    $types .= 's';
}

if (!empty($filter_date)) {
    $where[] = "DATE(timestamp) = ?";
    $params[] = $filter_date;
    $types .= 's';
}

// Combine where clauses
if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}

// Finalize query with sorting
$query .= " ORDER BY timestamp DESC";

try {
    // Prepare and execute query
    $stmt = $mysqli->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        $activities = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}

// Get unique usernames for filter dropdown
$usernames = [];
try {
    $result = $mysqli->query("SELECT DISTINCT username FROM activity_log ORDER BY username");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $usernames[] = $row['username'];
        }
    }
} catch (Exception $e) {
    // Silently fail, filter just won't have values
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Aktivitas</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Datepicker CSS -->
    <link href="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.css" rel="stylesheet">
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

    .filter-card {
      background-color: white;
      padding: 15px;
      margin-bottom: 20px;
      border-radius: 5px;
      border: 1px solid #e5e5e5;
    }

    .filter-card .form-group {
      margin-bottom: 15px;
    }

    .filter-card label {
      font-size: 13px;
      font-weight: 500;
      color: #555;
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

    .badge-primary {
      background-color: var(--primary);
    }

    .badge-secondary {
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

    .action-cell {
      max-width: 300px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
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

    .btn-outline-secondary {
      color: var(--secondary);
      border-color: var(--secondary);
    }

    .btn-outline-secondary:hover {
      background-color: var(--secondary);
      color: white;
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
      
      .filter-card .col-md-3 {
        margin-bottom: 15px;
      }
    }
    </style>
</head>
<body>
    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="page-title">
            <h1>Log Aktivitas Sistem</h1>
            <p class="page-subtitle">Catatan semua aktivitas yang dilakukan pengguna</p>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <!-- Filter Card -->
                <div class="filter-card">
                    <form method="get" action="">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <select class="form-select" id="username" name="username">
                                        <option value="">Semua User</option>
                                        <?php foreach ($usernames as $username): ?>
                                            <option value="<?= htmlspecialchars($username) ?>" <?= $filter_username === $username ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($username) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="action">Aksi</label>
                                    <input type="text" class="form-control" id="action" name="action" 
                                           placeholder="Cari aksi..." value="<?= htmlspecialchars($filter_action) ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="date">Tanggal</label>
                                    <input type="date" class="form-control" id="date" name="date" 
                                           value="<?= htmlspecialchars($filter_date) ?>">
                                </div>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="form-group w-100">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-filter me-1"></i> Filter
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Activity Log Card -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Daftar Aktivitas</h5>
                        <div>
                            <span class="badge badge-primary me-2">
                                <i class="fas fa-list me-1"></i> <?= count($activities) ?> Logs
                            </span>
                            <a href="log_activity.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-sync-alt me-1"></i> Reset
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table id="activityTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Waktu</th>
                                        <th>Username</th>
                                        <th>Aksi</th>
                                        <th>IP Address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activities as $activity): ?>
                                        <tr>
                                            <td>
                                                <?= date('d M Y H:i:s', strtotime($activity['timestamp'])) ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary">
                                                    <?= htmlspecialchars($activity['username']) ?>
                                                </span>
                                            </td>
                                            <td class="action-cell">
                                                <?= htmlspecialchars($activity['action']) ?>
                                            </td>
                                            <td>
                                                <?= !empty($activity['ip_address']) ? htmlspecialchars($activity['ip_address']) : '-' ?>
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
    <!-- Moment.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js"></script>
    <!-- Datepicker JS -->
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.min.js"></script>

    <script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#activityTable').DataTable({
            "language": {
                "lengthMenu": "Tampilkan _MENU_ log per halaman",
                "zeroRecords": "Tidak ada log aktivitas ditemukan",
                "info": "Menampilkan halaman _PAGE_ dari _PAGES_",
                "infoEmpty": "Tidak ada data tersedia",
                "infoFiltered": "(difilter dari _MAX_ total log)",
                "search": "Cari:",
                "paginate": {
                    "first": "Pertama",
                    "last": "Terakhir",
                    "next": "Selanjutnya",
                    "previous": "Sebelumnya"
                }
            },
            "order": [[0, "desc"]], // Sort by timestamp descending by default
            "columnDefs": [
                { "type": "date", "targets": 0 } // Proper sorting for date column
            ]
        });
        
        // Initialize datepicker
        $('#date').daterangepicker({
            singleDatePicker: true,
            showDropdowns: true,
            autoUpdateInput: false,
            locale: {
                format: 'YYYY-MM-DD',
                cancelLabel: 'Clear',
                applyLabel: 'Pilih',
                daysOfWeek: ['Mg', 'Sn', 'Sl', 'Rb', 'Km', 'Jm', 'Sb'],
                monthNames: ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
                firstDay: 1
            }
        });
        
        $('#date').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('YYYY-MM-DD'));
        });
        
        $('#date').on('cancel.daterangepicker', function(ev, picker) {
            $(this).val('');
        });
    });
    </script>
</body>
</html>

<?php
require_once __DIR__ . '/../templates/footer.php';
?>