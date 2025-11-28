<?php
require_once __DIR__ . '/../config/config_database.php';
// Database connection already available from config_database.php as $mysqli

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<script>alert('ID ODC tidak ditemukan'); window.location.href='data_odc.php';</script>";
    exit;
}

$id = (int)$_GET['id'];

// Get ODC data
$stmt = $mysqli->prepare("SELECT * FROM ftth_odc WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$odc_data = $result->fetch_assoc();

if (!$odc_data) {
    echo "<script>alert('Data ODC tidak ditemukan'); window.location.href='data_odc.php';</script>";
    exit;
}

// Get all PON ports for dropdown (including current one)
$pon_query = "
    SELECT p.*, o.nama_olt, pop.nama_pop 
    FROM ftth_pon p 
    LEFT JOIN ftth_olt o ON p.olt_id = o.id 
    LEFT JOIN ftth_pop pop ON o.pop_id = pop.id 
    WHERE p.status = 'available' OR p.id = ?
    ORDER BY pop.nama_pop, o.nama_olt, p.port_name
";
$pon_stmt = $mysqli->prepare($pon_query);
$pon_stmt->bind_param("i", $odc_data['pon_port_id']);
$pon_stmt->execute();
$pon_result = $pon_stmt->get_result();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pon_port_id = $_POST['pon_port_id'];
    $nama_odc = $_POST['nama_odc'];
    $lokasi = $_POST['lokasi'];
    $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
    $jumlah_port = $_POST['jumlah_port'];
    $kapasitas_fiber = $_POST['kapasitas_fiber'];
    $status = $_POST['status'];
    $area_coverage = $_POST['area_coverage'];
    
    $mysqli->begin_transaction();
    try {
        // Update ODC
        $stmt = $mysqli->prepare("UPDATE ftth_odc SET nama_odc=?, pon_port_id=?, lokasi=?, latitude=?, longitude=?, jumlah_port=?, kapasitas_fiber=?, status=?, area_coverage=? WHERE id=?");
        $stmt->bind_param("sisddiiisi", $nama_odc, $pon_port_id, $lokasi, $latitude, $longitude, $jumlah_port, $kapasitas_fiber, $status, $area_coverage, $id);
        $stmt->execute();
        
        // Update old PON port status if changed
        if ($pon_port_id != $odc_data['pon_port_id']) {
            $update_old_pon_stmt = $mysqli->prepare("UPDATE ftth_pon SET status = 'available', connected_odc_id = NULL WHERE id = ?");
            $update_old_pon_stmt->bind_param("i", $odc_data['pon_port_id']);
            $update_old_pon_stmt->execute();
            
            // Update new PON port status
            $update_new_pon_stmt = $mysqli->prepare("UPDATE ftth_pon SET status = 'connected', connected_odc_id = ? WHERE id = ?");
            $update_new_pon_stmt->bind_param("ii", $id, $pon_port_id);
            $update_new_pon_stmt->execute();
        }
        
        $mysqli->commit();
        
        echo "<script>alert('ODC berhasil diupdate'); window.location.href='data_odc.php';</script>";
        exit;
    } catch (Exception $e) {
        $mysqli->rollback();
        $error_message = "Error: " . $e->getMessage();
    }
}
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
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: 500; color: var(--dark); font-size: 14px; }
.form-control, .form-select { border: 1px solid #ddd; font-size: 14px; padding: 10px 12px; height: auto; width: 100%; transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out; }
.form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 0.2rem rgba(26, 187, 156, 0.25); outline: none; }
.btn { font-size: 14px; padding: 8px 16px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s ease-in-out; text-decoration: none; display: inline-block; text-align: center; }
.btn-primary { background-color: var(--primary); color: white; }
.btn-primary:hover { background-color: #169F85; color: white; }
.btn-secondary { background-color: var(--secondary); color: white; }
.btn-secondary:hover { background-color: #5a6c7d; color: white; }
.close { background: none; border: none; font-size: 20px; cursor: pointer; color: #aaa; }
.close:hover { color: #000; }
.row { display: flex; flex-wrap: wrap; margin-left: -15px; margin-right: -15px; }
.col-md-6 { flex: 0 0 50%; max-width: 50%; padding-left: 15px; padding-right: 15px; }
.col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; padding-left: 15px; padding-right: 15px; }
@media (max-width: 768px) { .col-md-6, .col-md-4 { flex: 0 0 100%; max-width: 100%; } }
</style>

<main class="main-content" id="mainContent">
    <div class="page-title">
        <h1>Edit ODC</h1>
        <div class="page-subtitle">Edit Optical Distribution Cabinet untuk infrastruktur FTTH</div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="fa fa-exclamation-triangle"></i> <?php echo $error_message; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h5>Form Edit ODC: <?php echo htmlspecialchars($odc_data['nama_odc']); ?></h5>
            <a href="data_odc.php" class="btn btn-secondary">
                <i class="fa fa-arrow-left"></i> Kembali
            </a>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>PON Port *</label>
                            <select name="pon_port_id" class="form-control" required>
                                <option value="">Pilih PON Port</option>
                                <?php while ($pon_row = $pon_result->fetch_assoc()): ?>
                                <option value="<?php echo $pon_row['id']; ?>" <?php echo ($pon_row['id'] == $odc_data['pon_port_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pon_row['nama_pop'] . ' - ' . $pon_row['nama_olt'] . ' - ' . $pon_row['port_name']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nama ODC *</label>
                            <input type="text" name="nama_odc" class="form-control" required placeholder="Contoh: ODC-PUSAT-001" 
                                   value="<?php echo htmlspecialchars($odc_data['nama_odc']); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Lokasi *</label>
                    <input type="text" name="lokasi" class="form-control" required placeholder="Alamat lengkap lokasi ODC" 
                           value="<?php echo htmlspecialchars($odc_data['lokasi']); ?>">
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Latitude</label>
                            <input type="number" name="latitude" class="form-control" step="any" placeholder="Contoh: -6.2088" 
                                   value="<?php echo $odc_data['latitude']; ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Longitude</label>
                            <input type="number" name="longitude" class="form-control" step="any" placeholder="Contoh: 106.8456" 
                                   value="<?php echo $odc_data['longitude']; ?>">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Jumlah Port *</label>
                            <input type="number" name="jumlah_port" class="form-control" required min="1" max="16" 
                                   value="<?php echo $odc_data['jumlah_port']; ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Kapasitas Fiber</label>
                            <input type="number" name="kapasitas_fiber" class="form-control" min="1" max="288" 
                                   value="<?php echo $odc_data['kapasitas_fiber']; ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Status *</label>
                            <select name="status" class="form-control" required>
                                <option value="active" <?php echo ($odc_data['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($odc_data['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="maintenance" <?php echo ($odc_data['status'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Area Coverage</label>
                    <input type="text" name="area_coverage" class="form-control" placeholder="Area yang dilayani ODC ini" 
                           value="<?php echo htmlspecialchars($odc_data['area_coverage']); ?>">
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Update
                    </button>
                    <a href="data_odc.php" class="btn btn-secondary">
                        <i class="fa fa-times"></i> Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>