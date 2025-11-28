<?php
// /ftth/data_olt.php		<-- jangan di Hapus

require_once __DIR__ . '/../config/config_database.php';
// Database connection already available from config_database.php as $mysqli

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Get POP ID from URL parameter
$pop_id = isset($_GET['pop_id']) ? (int)$_GET['pop_id'] : 0;

// Get POP information
$pop_info = null;
if ($pop_id > 0) {
    $pop_stmt = $mysqli->prepare("SELECT * FROM ftth_pop WHERE id = ?");
    $pop_stmt->bind_param("i", $pop_id);
    $pop_stmt->execute();
    $pop_result = $pop_stmt->get_result();
    $pop_info = $pop_result->fetch_assoc();
}

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $olt_id = $_POST['olt_id'];
    
    // Check if OLT has PON ports with connected ODC
    $check_stmt = $mysqli->prepare("
        SELECT COUNT(*) as count 
        FROM ftth_pon p 
        JOIN ftth_odc o ON p.id = o.pon_port_id 
        WHERE p.olt_id = ?
    ");
    $check_stmt->bind_param("i", $olt_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_data = $check_result->fetch_assoc();
    
    if ($check_data['count'] > 0) {
        $error_message = "OLT tidak dapat dihapus karena masih memiliki ODC terhubung";
    } else {
        $mysqli->begin_transaction();
        try {
            // Delete PON ports first
            $delete_pon_stmt = $mysqli->prepare("DELETE FROM ftth_pon WHERE olt_id = ?");
            $delete_pon_stmt->bind_param("i", $olt_id);
            $delete_pon_stmt->execute();
            
            // Delete OLT
            $delete_olt_stmt = $mysqli->prepare("DELETE FROM ftth_olt WHERE id = ?");
            $delete_olt_stmt->bind_param("i", $olt_id);
            $delete_olt_stmt->execute();
            
            // Update POP jumlah_olt
            if ($pop_id > 0) {
                $update_pop_stmt = $mysqli->prepare("UPDATE ftth_pop SET jumlah_olt = (SELECT COUNT(*) FROM ftth_olt WHERE pop_id = ?) WHERE id = ?");
                $update_pop_stmt->bind_param("ii", $pop_id, $pop_id);
                $update_pop_stmt->execute();
            }
            
            $mysqli->commit();
            $success_message = "OLT berhasil dihapus";
        } catch (Exception $e) {
            $mysqli->rollback();
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Build query
$where_clause = "";
$params = [];
$param_types = "";

if ($pop_id > 0) {
    $where_clause = "WHERE o.pop_id = ?";
    $params[] = $pop_id;
    $param_types .= "i";
}

// Get OLT data with PON port count
$query = "
    SELECT o.*, p.nama_pop, p.lokasi as pop_lokasi,
           COUNT(pon.id) as jumlah_pon_ports,
           SUM(CASE WHEN pon.status = 'available' THEN 1 ELSE 0 END) as pon_available,
           SUM(CASE WHEN pon.status = 'connected' THEN 1 ELSE 0 END) as pon_connected
    FROM ftth_olt o 
    LEFT JOIN ftth_pop p ON o.pop_id = p.id 
    LEFT JOIN ftth_pon pon ON o.id = pon.olt_id 
    $where_clause
    GROUP BY o.id 
    ORDER BY p.nama_pop, o.nama_olt
";

$stmt = $mysqli->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
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
.card { border: none; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); margin-bottom: 20px; background: white; }
.card-header { background-color: white; border-bottom: 1px solid #e9ecef; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
.card-header h5 { font-size: 16px; font-weight: 500; color: var(--dark); margin: 0; }
.card-body { padding: 20px; }
.alert { padding: 12px 20px; font-size: 14px; border: none; margin-bottom: 20px; }
.alert-danger { background-color: rgba(237, 85, 101, 0.1); color: var(--danger); border-left: 4px solid var(--danger); }
.alert-success { background-color: rgba(38, 185, 154, 0.1); color: var(--success); border-left: 4px solid var(--success); }
.alert-warning { background-color: rgba(248, 172, 89, 0.1); color: var(--warning); border-left: 4px solid var(--warning); }
.alert-info { background-color: rgba(35, 198, 200, 0.1); color: var(--info); border-left: 4px solid var(--info); }
.btn { font-size: 14px; padding: 8px 16px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s ease-in-out; text-decoration: none; display: inline-block; text-align: center; }
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
.btn-sm { padding: 6px 12px; font-size: 12px; margin: 0 2px; }
.table { width: 100%; margin-bottom: 0; border-collapse: collapse; }
.table th, .table td { padding: 8px; vertical-align: middle; border-top: 1px solid #dee2e6; font-size: 12px; }
.table thead th { background-color: #f8f9fa; border-bottom: 2px solid #dee2e6; font-weight: 600; color: var(--dark); font-size: 11px; }
.table-striped tbody tr:nth-of-type(odd) { background-color: rgba(0, 0, 0, 0.02); }
.table-responsive { overflow-x: auto; }
.badge { display: inline-block; padding: 3px 6px; font-size: 10px; font-weight: 500; line-height: 1; text-align: center; white-space: nowrap; vertical-align: baseline; }
.badge-success { background-color: var(--success); color: white; }
.badge-danger { background-color: var(--danger); color: white; }
.badge-warning { background-color: var(--warning); color: white; }
.badge-info { background-color: var(--info); color: white; }
.badge-primary { background-color: var(--primary); color: white; }
.btn-group { display: flex; gap: 4px; }
.action-buttons { display: flex; gap: 4px; align-items: center; }
.action-buttons .btn { margin: 0; }
.close { background: none; border: none; font-size: 20px; cursor: pointer; color: #aaa; }
.close:hover { color: #000; }
.float-right { float: right; }
.row { display: flex; flex-wrap: wrap; margin-left: -15px; margin-right: -15px; }
.col-md-3 { flex: 0 0 25%; max-width: 25%; padding-left: 15px; padding-right: 15px; }
.col-md-6 { flex: 0 0 50%; max-width: 50%; padding-left: 15px; padding-right: 15px; }
.progress { height: 6px; background-color: #e9ecef; border-radius: 3px; overflow: hidden; margin: 5px 0; }
.progress-bar { background-color: var(--primary); height: 100%; border-radius: 3px; transition: width 0.3s ease; }
.port-info { font-size: 11px; }
.port-info span { margin-right: 8px; }
@media (max-width: 768px) { .col-md-3 { flex: 0 0 50%; max-width: 50%; } .col-md-6 { flex: 0 0 100%; max-width: 100%; } .action-buttons { flex-direction: column; gap: 2px; } .btn-sm { padding: 4px 8px; font-size: 11px; } }
</style>

<main class="main-content" id="mainContent">
    <div class="page-title">
        <h1>
            Daftar OLT
            <?php if ($pop_info): ?>
                <span class="badge badge-primary">POP: <?php echo htmlspecialchars($pop_info['nama_pop']); ?></span>
            <?php endif; ?>
        </h1>
        <div class="page-subtitle">Kelola Optical Line Terminal untuk infrastruktur FTTH</div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="fa fa-check"></i> <?php echo $success_message; ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="fa fa-exclamation-triangle"></i> <?php echo $error_message; ?>
    </div>
    <?php endif; ?>

    <?php if ($pop_info): ?>
    <div class="card">
        <div class="card-header">
            <h5>Informasi POP</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <strong>Nama POP:</strong><br>
                    <?php echo htmlspecialchars($pop_info['nama_pop']); ?>
                </div>
                <div class="col-md-3">
                    <strong>Lokasi:</strong><br>
                    <?php echo htmlspecialchars($pop_info['lokasi']); ?>
                </div>
                <div class="col-md-3">
                    <strong>Kapasitas OLT:</strong><br>
                    <?php echo $pop_info['kapasitas_olt']; ?>
                </div>
                <div class="col-md-3">
                    <strong>Status:</strong><br>
                    <span class="badge <?php echo $pop_info['status'] == 'active' ? 'badge-success' : ($pop_info['status'] == 'maintenance' ? 'badge-warning' : 'badge-danger'); ?>">
                        <?php echo ucfirst($pop_info['status']); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h5>Daftar OLT</h5>
            <div class="float-right">
                <?php if ($pop_id > 0): ?>
                    <a href="add_olt.php?pop_id=<?php echo $pop_id; ?>" class="btn btn-primary btn-sm">
                        <i class="fa fa-plus"></i> Tambah OLT
                    </a>
                <?php else: ?>
                    <a href="add_olt.php" class="btn btn-primary btn-sm">
                        <i class="fa fa-plus"></i> Tambah OLT
                    </a>
                <?php endif; ?>
                <a href="data_pop.php" class="btn btn-secondary btn-sm">
                    <i class="fa fa-arrow-left"></i> Kembali ke POP
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" id="oltTable">
                    <thead>
                        <tr>
                            <th width="5%">No</th>
                            <th width="16%">Nama OLT</th>
                            <th width="10%">IP Address</th>
                            <th width="12%">Nama POP</th>
                            <th width="10%">Lokasi</th>
                            <th width="12%">Merk/Model</th>
                            <th width="9%">Jumlah PON</th>
                            <th width="20%">Port PON</th>
                            <th width="8%">Status</th>
                            <th width="11%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        while ($row = $result->fetch_assoc()): 
                        ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><strong><?php echo htmlspecialchars($row['nama_olt']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['ip_address']); ?></td>
                            <td><?php echo htmlspecialchars($row['nama_pop'] ?? 'Tidak terhubung'); ?></td>
                            <td><?php echo htmlspecialchars($row['lokasi']); ?></td>
                            <td><?php echo htmlspecialchars($row['merk'] . ' ' . $row['model']); ?></td>
							<td>
                                <div>
                                    <span class="badge badge-info">
                                        <?php echo $row['jumlah_pon_ports']; ?>/<?php echo $row['jumlah_port_pon']; ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <div class="port-info">
                                        <span class="badge badge-success">Available: <?php echo $row['pon_available']; ?></span><br>
                                        <span class="badge badge-danger">Connected: <?php echo $row['pon_connected']; ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?php echo $row['status'] == 'active' ? 'badge-success' : ($row['status'] == 'maintenance' ? 'badge-warning' : 'badge-danger'); ?>">
                                    <?php echo ucfirst($row['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view_olt.php?id=<?php echo $row['id']; ?>" class="btn btn-info btn-sm" title="Lihat Detail">
                                        <i class="fa fa-eye"></i>
                                    </a>
                                    <a href="edit_olt.php?id=<?php echo $row['id']; ?><?php echo $pop_id > 0 ? '&pop_id=' . $pop_id : ''; ?>" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fa fa-edit"></i>
                                    </a>
                                    <a href="list_pon.php?olt_id=<?php echo $row['id']; ?>" class="btn btn-success btn-sm" title="Lihat PON">
                                        <i class="fa fa-sitemap"></i>
                                    </a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus OLT ini? Semua PON port akan ikut terhapus.')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="olt_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Hapus">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
$(document).ready(function() {
    $('#oltTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/Indonesian.json"
        },
        "pageLength": 10,
        "responsive": true,
        "order": [[ 1, "asc" ]]
    });
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>