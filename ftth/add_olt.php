<?php
// /ftth/add_olt.php		<-- jangan di hapus

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
    $auto_create_pon = 1; // Default selalu buat PON port otomatis
    
    // Validate IP address
    if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
        $error_message = "IP Address tidak valid";
    } else {
        // Check if IP already exists
        $check_ip_stmt = $mysqli->prepare("SELECT id FROM ftth_olt WHERE ip_address = ?");
        $check_ip_stmt->bind_param("s", $ip_address);
        $check_ip_stmt->execute();
        $check_ip_result = $check_ip_stmt->get_result();
        
        if ($check_ip_result->num_rows > 0) {
            $error_message = "IP Address sudah digunakan oleh OLT lain";
        } else {
            // Check POP capacity
            if ($pop_id_form > 0) {
                $capacity_stmt = $mysqli->prepare("SELECT kapasitas_olt, jumlah_olt FROM ftth_pop WHERE id = ?");
                $capacity_stmt->bind_param("i", $pop_id_form);
                $capacity_stmt->execute();
                $capacity_result = $capacity_stmt->get_result();
                $capacity_data = $capacity_result->fetch_assoc();
                
                if ($capacity_data && $capacity_data['jumlah_olt'] >= $capacity_data['kapasitas_olt']) {
                    $error_message = "Kapasitas OLT di POP ini sudah penuh";
                } else {
                    // Insert OLT
                    $mysqli->begin_transaction();
                    try {
                        // Insert OLT
                        $stmt = $mysqli->prepare("INSERT INTO ftth_olt (pop_id, nama_olt, ip_address, lokasi, latitude, longitude, merk, model, jumlah_port_pon, port_tersedia, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isssddssiis", $pop_id_form, $nama_olt, $ip_address, $lokasi, $latitude, $longitude, $merk, $model, $jumlah_port_pon, $jumlah_port_pon, $status);
                        $stmt->execute();
                        
                        $olt_id = $mysqli->insert_id;
                        
                        // Auto create PON ports if requested
                        if ($auto_create_pon) {
                            $pon_stmt = $mysqli->prepare("INSERT INTO ftth_pon (olt_id, port_number, port_name, status, max_distance, splitter_ratio) VALUES (?, ?, ?, 'available', 20000, '1:32')");
                            
                            for ($i = 0; $i < $jumlah_port_pon; $i++) {
                                $port_number = "0/1/" . $i;
                                $port_name = "PON-" . strtoupper(substr($nama_olt, -3)) . "-" . ($i + 1);
                                
                                $pon_stmt->bind_param("iss", $olt_id, $port_number, $port_name);
                                $pon_stmt->execute();
                            }
                        }
                        
                        // Update POP jumlah_olt
                        if ($pop_id_form > 0) {
                            $update_pop_stmt = $mysqli->prepare("UPDATE ftth_pop SET jumlah_olt = (SELECT COUNT(*) FROM ftth_olt WHERE pop_id = ?) WHERE id = ?");
                            $update_pop_stmt->bind_param("ii", $pop_id_form, $pop_id_form);
                            $update_pop_stmt->execute();
                        }
                        
                        $mysqli->commit();
                        
                        $redirect_url = "data_olt.php";
                        if ($pop_id_form > 0) {
                            $redirect_url .= "?pop_id=" . $pop_id_form;
                        }
                        
                        echo "<script>alert('OLT berhasil ditambahkan'); window.location.href='$redirect_url';</script>";
                        exit;
                    } catch (Exception $e) {
                        $mysqli->rollback();
                        $error_message = "Error: " . $e->getMessage();
                    }
                }
            } else {
                // Insert without POP
                $mysqli->begin_transaction();
                try {
                    // Insert OLT
                    $stmt = $mysqli->prepare("INSERT INTO ftth_olt (pop_id, nama_olt, ip_address, lokasi, latitude, longitude, merk, model, jumlah_port_pon, port_tersedia, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isssddssiis", $pop_id_form, $nama_olt, $ip_address, $lokasi, $latitude, $longitude, $merk, $model, $jumlah_port_pon, $jumlah_port_pon, $status);
                    $stmt->execute();
                    
                    $olt_id = $mysqli->insert_id;
                    
                    // Auto create PON ports if requested
                    if ($auto_create_pon) {
                        $pon_stmt = $mysqli->prepare("INSERT INTO ftth_pon (olt_id, port_number, port_name, status, max_distance, splitter_ratio) VALUES (?, ?, ?, 'available', 20000, '1:32')");
                        
                        for ($i = 0; $i < $jumlah_port_pon; $i++) {
                            $port_number = "0/1/" . $i;
                            $port_name = "PON-" . strtoupper(substr($nama_olt, -3)) . "-" . ($i + 1);
                            
                            $pon_stmt->bind_param("iss", $olt_id, $port_number, $port_name);
                            $pon_stmt->execute();
                        }
                    }
                    
                    $mysqli->commit();
                    $success_message = "OLT berhasil ditambahkan";
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
.col-md-6 { flex: 0 0 50%; max-width: 50%; padding-left: 15px; padding-right: 15px; }
@media (max-width: 768px) { .col-md-6 { flex: 0 0 100%; max-width: 100%; } }
</style>

<main class="main-content" id="mainContent">
    <div class="page-title">
        <h1>
            Tambah OLT Baru
            <?php if ($pop_info): ?>
                <span class="badge-primary">POP: <?php echo htmlspecialchars($pop_info['nama_pop']); ?></span>
            <?php endif; ?>
        </h1>
        <div class="page-subtitle">Tambah Optical Line Terminal untuk infrastruktur FTTH</div>
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
            <h5>Form Tambah OLT</h5>
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
                                <option value="<?php echo $pop_row['id']; ?>" <?php echo ($pop_row['id'] == $pop_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pop_row['nama_pop'] . ' - ' . $pop_row['lokasi']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nama OLT *</label>
                            <input type="text" name="nama_olt" class="form-control" required placeholder="Contoh: OLT-PUSAT-001">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>IP Address *</label>
                            <input type="text" name="ip_address" class="form-control" required placeholder="192.168.1.10">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Status *</label>
                            <select name="status" class="form-control" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Lokasi *</label>
                    <input type="text" name="lokasi" class="form-control" required placeholder="Gedung/Alamat tempat OLT berada">
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Latitude</label>
                            <input type="number" name="latitude" class="form-control" step="any" placeholder="Contoh: -6.2088">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Longitude</label>
                            <input type="number" name="longitude" class="form-control" step="any" placeholder="Contoh: 106.8456">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Merk *</label>
                            <select name="merk" class="form-control" required>
                                <option value="">Pilih Merk</option>
                                <option value="Huawei">Huawei</option>
                                <option value="ZTE">ZTE</option>
                                <option value="Fiberhome">HIOSO</option>
                                <option value="Nokia">HSGQ</option>
                                <option value="Alcatel">VSOL</option>
                                <option value="Other">Lainnya</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Model *</label>
                            <input type="text" name="model" class="form-control" required placeholder="Contoh: MA5608T">
                        </div>
                    </div>
					<div class="col-md-4">
                    <div class="form-group">
                    <label>Jumlah Port PON *</label>
                    <input type="number" name="jumlah_port_pon" class="form-control" required min="1" max="32" value="16">
                </div>
                    </div>
                </div>
                

                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Simpan
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