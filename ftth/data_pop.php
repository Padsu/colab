<?php
// /ftth/data_pop.php		<-- jangan di Hapus

require_once __DIR__ . '/../config/config_database.php';
// Database connection already available from config_database.php as $mysqli

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Handle form actions - hanya untuk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete') {
        $id = $_POST['id'];
        
        // Check if POP has OLT
        $check_stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM ftth_olt WHERE pop_id = ?");
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_data = $check_result->fetch_assoc();
        
        if ($check_data['count'] > 0) {
            $error_message = "POP tidak dapat dihapus karena masih memiliki OLT terhubung";
        } else {
            $stmt = $mysqli->prepare("DELETE FROM ftth_pop WHERE id=?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $success_message = "POP berhasil dihapus";
            } else {
                $error_message = "Error: " . $stmt->error;
            }
        }
    }
}

// Get all POP data with OLT count
$query = "SELECT p.*, COUNT(o.id) as jumlah_olt_aktual FROM ftth_pop p LEFT JOIN ftth_olt o ON p.id = o.pop_id GROUP BY p.id ORDER BY p.nama_pop";
$result = $mysqli->query($query);
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
.btn-group { display: flex; gap: 4px; }
.action-buttons { display: flex; gap: 4px; align-items: center; }
.action-buttons .btn { margin: 0; }
.close { background: none; border: none; font-size: 20px; cursor: pointer; color: #aaa; }
.close:hover { color: #000; }
.float-right { float: right; }
.row { display: flex; flex-wrap: wrap; margin-left: -15px; margin-right: -15px; }
.col-md-6 { flex: 0 0 50%; max-width: 50%; padding-left: 15px; padding-right: 15px; }
@media (max-width: 768px) { .col-md-6 { flex: 0 0 100%; max-width: 100%; } .action-buttons { flex-direction: column; gap: 2px; } .btn-sm { padding: 4px 8px; font-size: 11px; } }
</style>

<main class="main-content" id="mainContent">
    <div class="page-title">
        <h1>Data POP (Point of Presence)</h1>
        <div class="page-subtitle">Daftar Point of Presence untuk infrastruktur FTTH</div>
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

    <div class="card">
        <div class="card-header">
            <h5>Daftar POP</h5>
            <a href="add_pop.php" class="btn btn-primary">
                <i class="fa fa-plus"></i> Tambah POP Baru
            </a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" id="popTable">
                    <thead>
                        <tr>
                            <th width="5%">No</th>
                            <th width="12%">Nama POP</th>
                            <th width="12%">Lokasi</th>
                            <th width="18%">Alamat Lengkap</th>
                            <th width="13%">Koordinat</th>
                            <th width="5%">OLT</th>
                            <th width="5%">Used OLT</th>
                            <th width="8%">Status</th>
                            <th width="12%">PIC</th>
                            <th width="8%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        while ($row = $result->fetch_assoc()): 
                        ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><strong><?php echo htmlspecialchars($row['nama_pop']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['lokasi']); ?></td>
                            <td><?php echo htmlspecialchars($row['alamat_lengkap']); ?></td>
                            <td>
                                <?php if($row['latitude'] && $row['longitude']): ?>
                                    <span class="badge badge-info" title="Koordinat Latitude, Longitude">
                                        <?php echo number_format($row['latitude'], 6); ?>, <?php echo number_format($row['longitude'], 6); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $row['kapasitas_olt']; ?></td>
                            <td>
                                <span class="badge <?php echo $row['jumlah_olt_aktual'] >= $row['kapasitas_olt'] ? 'badge-danger' : 'badge-success'; ?>">
                                    <?php echo $row['jumlah_olt_aktual']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo $row['status'] == 'active' ? 'badge-success' : ($row['status'] == 'maintenance' ? 'badge-warning' : 'badge-danger'); ?>">
                                    <?php echo ucfirst($row['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if($row['pic_nama']): ?>
                                    <?php echo htmlspecialchars($row['pic_nama']); ?>
                                    <?php if ($row['pic_telepon']): ?>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars($row['pic_telepon']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
<a href="edit_pop.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
    <i class="fa fa-pencil"></i>
</a>
                                    <a href="data_olt.php?pop_id=<?php echo $row['id']; ?>" class="btn btn-success btn-sm" title="Lihat OLT">
                                        <i class="fa fa-server"></i>
                                    </a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus POP ini?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
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
    $('#popTable').DataTable({
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