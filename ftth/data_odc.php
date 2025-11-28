<?php
require_once __DIR__ . '/../config/config_database.php';
// Database connection already available from config_database.php as $mysqli

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Handle form actions - hanya untuk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete') {
        $id = $_POST['id'];
        
        // Check if ODC has ODP
        $check_stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM ftth_odp WHERE odc_port_id IN (SELECT id FROM ftth_odc_ports WHERE odc_id = ?)");
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_data = $check_result->fetch_assoc();
        
        if ($check_data['count'] > 0) {
            $error_message = "ODC tidak dapat dihapus karena masih memiliki ODP terhubung";
        } else {
            $mysqli->begin_transaction();
            try {
                // Delete ODC ports first
                $delete_ports_stmt = $mysqli->prepare("DELETE FROM ftth_odc_ports WHERE odc_id = ?");
                $delete_ports_stmt->bind_param("i", $id);
                $delete_ports_stmt->execute();
                
                // Delete ODC
                $delete_odc_stmt = $mysqli->prepare("DELETE FROM ftth_odc WHERE id = ?");
                $delete_odc_stmt->bind_param("i", $id);
                $delete_odc_stmt->execute();
                
                $mysqli->commit();
                $success_message = "ODC berhasil dihapus";
            } catch (Exception $e) {
                $mysqli->rollback();
                $error_message = "Error: " . $e->getMessage();
            }
        }
    }
}

// Get all ODC data with PON port info
$query = "
    SELECT o.*, 
           p.port_name as pon_port_name, 
           olt.nama_olt, 
           pop.nama_pop,
           COUNT(odc_ports.id) as jumlah_port_aktual,
           SUM(CASE WHEN odc_ports.status = 'available' THEN 1 ELSE 0 END) as port_available,
           SUM(CASE WHEN odc_ports.status = 'connected' THEN 1 ELSE 0 END) as port_connected
    FROM ftth_odc o 
    LEFT JOIN ftth_pon p ON o.pon_port_id = p.id 
    LEFT JOIN ftth_olt olt ON p.olt_id = olt.id 
    LEFT JOIN ftth_pop pop ON olt.pop_id = pop.id 
    LEFT JOIN ftth_odc_ports odc_ports ON o.id = odc_ports.odc_id 
    GROUP BY o.id 
    ORDER BY o.nama_odc
";
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
.progress { height: 6px; background-color: #e9ecef; border-radius: 3px; overflow: hidden; margin: 5px 0; }
.progress-bar { background-color: var(--primary); height: 100%; border-radius: 3px; transition: width 0.3s ease; }
.port-info { font-size: 11px; }
.port-info span { margin-right: 8px; }
@media (max-width: 768px) { .action-buttons { flex-direction: column; gap: 2px; } .btn-sm { padding: 4px 8px; font-size: 11px; } }
</style>

<main class="main-content" id="mainContent">
    <div class="page-title">
        <h1>Data ODC (Optical Distribution Cabinet)</h1>
        <div class="page-subtitle">Daftar ODC untuk infrastruktur FTTH</div>
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
            <h5>Daftar ODC</h5>
            <a href="add_odc.php" class="btn btn-primary">
                <i class="fa fa-plus"></i> Tambah ODC Baru
            </a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" id="odcTable">
                    <thead>
                        <tr>
                            <th width="5%">No</th>
                            <th width="12%">Nama ODC</th>
                            <th width="10%">PON Port</th>
                            <th width="10%">OLT</th>
                            <th width="10%">POP</th>
                            <th width="15%">Lokasi</th>
                            <th width="10%">Koordinat</th>
                            <th width="12%">Port ODC</th>
                            <th width="8%">Status</th>
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
                            <td><strong><?php echo htmlspecialchars($row['nama_odc']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['pon_port_name'] ?? 'Tidak terhubung'); ?></td>
                            <td><?php echo htmlspecialchars($row['nama_olt'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['nama_pop'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['lokasi']); ?></td>
                            <td>
                                <?php if($row['latitude'] && $row['longitude']): ?>
                                    <span class="badge badge-info" title="Koordinat Latitude, Longitude">
                                        <?php echo number_format($row['latitude'], 6); ?>, <?php echo number_format($row['longitude'], 6); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div>
                                    <span class="badge badge-info">
                                        <?php echo $row['jumlah_port_aktual']; ?>/<?php echo $row['jumlah_port']; ?>
                                    </span>
                                    <div class="progress">
                                        <div class="progress-bar" style="width: <?php echo ($row['jumlah_port'] > 0) ? ($row['jumlah_port_aktual'] / $row['jumlah_port'] * 100) : 0; ?>%"></div>
                                    </div>
                                    <div class="port-info">
                                        <span class="badge badge-success">Available: <?php echo $row['port_available']; ?></span>
                                        <span class="badge badge-danger">Connected: <?php echo $row['port_connected']; ?></span>
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
                                    <a href="edit_odc.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fa fa-pencil"></i>
                                    </a>
                                    <a href="data_odp.php?odc_id=<?php echo $row['id']; ?>" class="btn btn-success btn-sm" title="Lihat ODP">
                                        <i class="fa fa-sitemap"></i>
                                    </a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus ODC ini?')">
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
    $('#odcTable').DataTable({
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