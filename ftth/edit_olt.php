<?php
// /ftth/edit_olt.php		<-- jangan di Hapus

require_once __DIR__ . '/../config/config_database.php';
// Database connection already available from config_database.php as $mysqli

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<script>alert('ID OLT tidak ditemukan'); window.location.href='data_olt.php';</script>";
    exit;
}

$id = (int)$_GET['id'];
$pop_id = isset($_GET['pop_id']) ? (int)$_GET['pop_id'] : 0;

// Get OLT data
$stmt = $mysqli->prepare("SELECT * FROM ftth_olt WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$olt_data = $result->fetch_assoc();

if (!$olt_data) {
    echo "<script>alert('Data OLT tidak ditemukan'); window.location.href='data_olt.php';</script>";
    exit;
}

// Get POP information for display
$pop_info = null;
if ($olt_data['pop_id'] > 0) {
    $pop_stmt = $mysqli->prepare("SELECT * FROM ftth_pop WHERE id = ?");
    $pop_stmt->bind_param("i", $olt_data['pop_id']);
    $pop_stmt->execute();
    $pop_result = $pop_stmt->get_result();
    $pop_info = $pop_result->fetch_assoc();
}

// Get all POPs for dropdown
$pop_query = "SELECT id, nama_pop, lokasi FROM ftth_pop WHERE status = 'active' ORDER BY nama_pop";
$pop_result = $mysqli->query($pop_query);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pop_id_form = $_POST['pop_id'];
    $nama_olt = $_POST['nama_olt'];
    $ip_address = $_POST['ip_address'];
    $lokasi = $_POST['lokasi'];
    $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
    $merk = $_POST['merk'];
    $model = $_POST['model'];
    $jumlah_port_pon = $_POST['jumlah_port_pon'];
    $status = $_POST['status'];
    
    // Validate IP address
    if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
        $error_message = "IP Address tidak valid";
    } else {
        // Check if IP already exists (except current OLT)
        $check_ip_stmt = $mysqli->prepare("SELECT id FROM ftth_olt WHERE ip_address = ? AND id != ?");
        $check_ip_stmt->bind_param("si", $ip_address, $id);
        $check_ip_stmt->execute();
        $check_ip_result = $check_ip_stmt->get_result();
        
        if ($check_ip_result->num_rows > 0) {
            $error_message = "IP Address sudah digunakan oleh OLT lain";
        } else {
            // Check POP capacity if changing POP
            if ($pop_id_form > 0 && $pop_id_form != $olt_data['pop_id']) {
                $capacity_stmt = $mysqli->prepare("SELECT kapasitas_olt, jumlah_olt FROM ftth_pop WHERE id = ?");
                $capacity_stmt->bind_param("i", $pop_id_form);
                $capacity_stmt->execute();
                $capacity_result = $capacity_stmt->get_result();
                $capacity_data = $capacity_result->fetch_assoc();
                
                if ($capacity_data && $capacity_data['jumlah_olt'] >= $capacity_data['kapasitas_olt']) {
                    $error_message = "Kapasitas OLT di POP tujuan sudah penuh";
                } else {
                    // Update OLT
                    $mysqli->begin_transaction();
                    try {
                        // Update OLT
                        $stmt = $mysqli->prepare("UPDATE ftth_olt SET pop_id=?, nama_olt=?, ip_address=?, lokasi=?, latitude=?, longitude=?, merk=?, model=?, jumlah_port_pon=?, status=? WHERE id=?");
                        $stmt->bind_param("isssddssssi", $pop_id_form, $nama_olt, $ip_address, $lokasi, $latitude, $longitude, $merk, $model, $jumlah_port_pon, $status, $id);
                        $stmt->execute();
                        
                        // Update POP jumlah_olt for old POP
                        if ($olt_data['pop_id'] > 0) {
                            $update_old_pop_stmt = $mysqli->prepare("UPDATE ftth_pop SET jumlah_olt = (SELECT COUNT(*) FROM ftth_olt WHERE pop_id = ?) WHERE id = ?");
                            $update_old_pop_stmt->bind_param("ii", $olt_data['pop_id'], $olt_data['pop_id']);
                            $update_old_pop_stmt->execute();
                        }
                        
                        // Update POP jumlah_olt for new POP
                        if ($pop_id_form > 0) {
                            $update_new_pop_stmt = $mysqli->prepare("UPDATE ftth_pop SET jumlah_olt = (SELECT COUNT(*) FROM ftth_olt WHERE pop_id = ?) WHERE id = ?");
                            $update_new_pop_stmt->bind_param("ii", $pop_id_form, $pop_id_form);
                            $update_new_pop_stmt->execute();
                        }
                        
                        $mysqli->commit();
                        
                        $redirect_url = "data_olt.php";
                        if ($pop_id > 0) {
                            $redirect_url .= "?pop_id=" . $pop_id;
                        }
                        
                        echo "<script>alert('OLT berhasil diupdate'); window.location.href='$redirect_url';</script>";
                        exit;
                    } catch (Exception $e) {
                        $mysqli->rollback();
                        $error_message = "Error: " . $e->getMessage();
                    }
                }
            } else {
                // Update without changing POP
                $mysqli->begin_transaction();
                try {
                    // Update OLT
                    $stmt = $mysqli->prepare("UPDATE ftth_olt SET pop_id=?, nama_olt=?, ip_address=?, lokasi=?, latitude=?, longitude=?, merk=?, model=?, jumlah_port_pon=?, status=? WHERE id=?");
                    $stmt->bind_param("isssddssssi", $pop_id_form, $nama_olt, $ip_address, $lokasi, $latitude, $longitude, $merk, $model, $jumlah_port_pon, $status, $id);
                    $stmt->execute();
                    
                    $mysqli->commit();
                    
                    $redirect_url = "data_olt.php";
                    if ($pop_id > 0) {
                        $redirect_url .= "?pop_id=" . $pop_id;
                    }
                    
                    echo "<script>alert('OLT berhasil diupdate'); window.location.href='$redirect_url';</script>";
                    exit;
                } catch (Exception $e) {
                    $mysqli->rollback();
                    $error_message = "Error: " . $e->getMessage();
                }
            }
        }
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
.alert-success { background-color: rgba(38, 185, 154, 0.1); color: var(--success); border-left: 4px solid var(--success); }
.alert-warning { background-color: rgba(248, 172, 89, 0.1); color: var(--warning); border-left: 4px solid var(--warning); }
.alert-info { background-color: rgba(35, 198, 200, 0.1); color: var(--info); border-left: 4px solid var(--info); }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: 500; color: var(--dark); font-size: 14px; }
.form-control, .form-select { border: 1px solid #ddd; font-size: 14px; padding: 10px 12px; height: auto; width: 100%; transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out; }
.form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 0.2rem rgba(26, 187, 156, 0.25); outline: none; }
.btn { font-size: 14px; padding: 8px 16px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s ease-in-out; text-decoration: none; display: inline-block; text-align: center; }
.btn-primary { background-color: var(--primary); color: white; }
.btn-primary:hover { background-color: #169F85; color: white; }
.btn-secondary { background-color: var(--secondary); color: white; }
.btn-secondary:hover { background-color: #5a6c7d; color: white; }
.badge-primary { background-color: var(--primary); color: white; padding: 3px 8px; font-size: 11px; border-radius: 12px; }
.close { background: none; border: none; font-size: 20px; cursor: pointer; color: #aaa; }
.close:hover { color: #000; }
.row { display: flex; flex-wrap: wrap; margin-left: -15px; margin-right: -15px; }
.col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; padding-left: 15px; padding-right: 15px; }
.col-md-6 { flex: 0 0 50%; max-width: 50%; padding-left: 15px; padding-right: 15px; }
@media (max-width: 768px) { .col-md-4, .col-md-6 { flex: 0 0 100%; max-width: 100%; } }
</style>

<main class="main-content" id="mainContent">
    <div class="page-title">
        <h1>
            Edit OLT
            <?php if ($pop_info): ?>
                <span class="badge-primary">POP: <?php echo htmlspecialchars($pop_info['nama_pop']); ?></span>
            <?php endif; ?>
        </h1>
        <div class="page-subtitle">Edit Optical Line Terminal untuk infrastruktur FTTH</div>
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
            <h5>Form Edit OLT: <?php echo htmlspecialchars($olt_data['nama_olt']); ?></h5>
            <a href="data_olt.php<?php echo $pop_id > 0 ? '?pop_id=' . $pop_id : ''; ?>" class="btn btn-secondary">
                <i class="fa fa-arrow-left"></i> Kembali
            </a>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>POP Tujuan *</label>
                            <select name="pop_id" class="form-control" required>
                                <option value="">Pilih POP</option>
                                <?php 
                                while ($pop_row = $pop_result->fetch_assoc()): 
                                ?>
                                <option value="<?php echo $pop_row['id']; ?>" <?php echo ($pop_row['id'] == $olt_data['pop_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pop_row['nama_pop'] . ' - ' . $pop_row['lokasi']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nama OLT *</label>
                            <input type="text" name="nama_olt" class="form-control" required placeholder="Contoh: OLT-PUSAT-001" 
                                   value="<?php echo htmlspecialchars($olt_data['nama_olt']); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>IP Address *</label>
                            <input type="text" name="ip_address" class="form-control" required placeholder="192.168.1.10" 
                                   value="<?php echo htmlspecialchars($olt_data['ip_address']); ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Status *</label>
                            <select name="status" class="form-control" required>
                                <option value="active" <?php echo ($olt_data['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($olt_data['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="maintenance" <?php echo ($olt_data['status'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Lokasi *</label>
                    <input type="text" name="lokasi" class="form-control" required placeholder="Gedung/Alamat tempat OLT berada" 
                           value="<?php echo htmlspecialchars($olt_data['lokasi']); ?>">
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Latitude</label>
                            <input type="number" name="latitude" class="form-control" step="any" placeholder="Contoh: -6.2088" 
                                   value="<?php echo $olt_data['latitude']; ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Longitude</label>
                            <input type="number" name="longitude" class="form-control" step="any" placeholder="Contoh: 106.8456" 
                                   value="<?php echo $olt_data['longitude']; ?>">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Merk *</label>
                            <select name="merk" class="form-control" required>
                                <option value="">Pilih Merk</option>
                                <option value="Huawei" <?php echo ($olt_data['merk'] == 'Huawei') ? 'selected' : ''; ?>>Huawei</option>
                                <option value="ZTE" <?php echo ($olt_data['merk'] == 'ZTE') ? 'selected' : ''; ?>>ZTE</option>
                                <option value="Fiberhome" <?php echo ($olt_data['merk'] == 'Fiberhome') ? 'selected' : ''; ?>>HIOSO</option>
                                <option value="Nokia" <?php echo ($olt_data['merk'] == 'Nokia') ? 'selected' : ''; ?>>HSGQ</option>
                                <option value="Alcatel" <?php echo ($olt_data['merk'] == 'Alcatel') ? 'selected' : ''; ?>>VSOL</option>
                                <option value="Other" <?php echo ($olt_data['merk'] == 'Other') ? 'selected' : ''; ?>>Lainnya</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Model *</label>
                            <input type="text" name="model" class="form-control" required placeholder="Contoh: MA5608T" 
                                   value="<?php echo htmlspecialchars($olt_data['model']); ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Jumlah Port PON *</label>
                            <input type="number" name="jumlah_port_pon" class="form-control" required min="1" max="32" 
                                   value="<?php echo $olt_data['jumlah_port_pon']; ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Update
                    </button>
                    <a href="data_olt.php<?php echo $pop_id > 0 ? '?pop_id=' . $pop_id : ''; ?>" class="btn btn-secondary">
                        <i class="fa fa-times"></i> Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>