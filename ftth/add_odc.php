<?php
require_once __DIR__ . '/../config/config_database.php';
// Database connection already available from config_database.php as $mysqli

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Get all available PON ports for dropdown
$pon_query = "
    SELECT p.*, o.nama_olt, pop.nama_pop 
    FROM ftth_pon p 
    LEFT JOIN ftth_olt o ON p.olt_id = o.id 
    LEFT JOIN ftth_pop pop ON o.pop_id = pop.id 
    WHERE p.status = 'available' 
    ORDER BY pop.nama_pop, o.nama_olt, p.port_name
";
$pon_result = $mysqli->query($pon_query);

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
    $auto_create_ports = 1; // Default selalu buat port otomatis
    
    $mysqli->begin_transaction();
    try {
        // Insert ODC
        $stmt = $mysqli->prepare("INSERT INTO ftth_odc (nama_odc, pon_port_id, lokasi, latitude, longitude, jumlah_port, port_tersedia, kapasitas_fiber, status, area_coverage) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sisddiiiss", $nama_odc, $pon_port_id, $lokasi, $latitude, $longitude, $jumlah_port, $jumlah_port, $kapasitas_fiber, $status, $area_coverage);
        $stmt->execute();
        
        $odc_id = $mysqli->insert_id;
        
        // Auto create ODC ports
        if ($auto_create_ports) {
            $port_stmt = $mysqli->prepare("INSERT INTO ftth_odc_ports (odc_id, port_number, port_name, status) VALUES (?, ?, ?, 'available')");
            
            for ($i = 1; $i <= $jumlah_port; $i++) {
                $port_name = "ODC-" . strtoupper(substr($nama_odc, -3)) . "-" . str_pad($i, 2, '0', STR_PAD_LEFT);
                
                $port_stmt->bind_param("iis", $odc_id, $i, $port_name);
                $port_stmt->execute();
            }
        }
        
        // Update PON port status to connected
        $update_pon_stmt = $mysqli->prepare("UPDATE ftth_pon SET status = 'connected', connected_odc_id = ? WHERE id = ?");
        $update_pon_stmt->bind_param("ii", $odc_id, $pon_port_id);
        $update_pon_stmt->execute();
        
        $mysqli->commit();
        
        echo "<script>alert('ODC berhasil ditambahkan'); window.location.href='data_odc.php';</script>";
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
.alert-success { background-color: rgba(38, 185, 154, 0.1); color: var(--success); border-left: 4px solid var(--success); }
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
        <h1>Tambah ODC Baru</h1>
        <div class="page-subtitle">Tambah Optical Distribution Cabinet untuk infrastruktur FTTH</div>
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
            <h5>Form Tambah ODC</h5>
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
                                <option value="<?php echo $pon_row['id']; ?>">
                                    <?php echo htmlspecialchars($pon_row['nama_pop'] . ' - ' . $pon_row['nama_olt'] . ' - ' . $pon_row['port_name']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nama ODC *</label>
                            <input type="text" name="nama_odc" class="form-control" required placeholder="Contoh: ODC-PUSAT-001">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Lokasi *</label>
                    <input type="text" name="lokasi" class="form-control" required placeholder="Alamat lengkap lokasi ODC">
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
                            <label>Jumlah Port *</label>
                            <input type="number" name="jumlah_port" class="form-control" required min="1" max="16" value="8">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Kapasitas Fiber</label>
                            <input type="number" name="kapasitas_fiber" class="form-control" min="1" max="288" value="24">
                        </div>
                    </div>
                    <div class="col-md-4">
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
                    <label>Area Coverage</label>
                    <input type="text" name="area_coverage" class="form-control" placeholder="Area yang dilayani ODC ini">
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Simpan
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