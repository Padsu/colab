<?php
// /ftth/edit_pop.php		<-- jangan di Hapus


require_once __DIR__ . '/../config/config_database.php';
// Database connection already available from config_database.php as $mysqli

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<script>alert('ID POP tidak ditemukan'); window.location.href='data_pop.php';</script>";
    exit;
}

$id = (int)$_GET['id'];

// Get POP data
$stmt = $mysqli->prepare("SELECT * FROM ftth_pop WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$pop_data = $result->fetch_assoc();

if (!$pop_data) {
    echo "<script>alert('Data POP tidak ditemukan'); window.location.href='data_pop.php';</script>";
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_pop = $_POST['nama_pop'];
    $lokasi = $_POST['lokasi'];
    $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
    $alamat_lengkap = $_POST['alamat_lengkap'];
    $kapasitas_olt = $_POST['kapasitas_olt'];
    $status = $_POST['status'];
    $pic_nama = $_POST['pic_nama'];
    $pic_telepon = $_POST['pic_telepon'];
    $keterangan = $_POST['keterangan'];
    
    $stmt = $mysqli->prepare("UPDATE ftth_pop SET nama_pop=?, lokasi=?, latitude=?, longitude=?, alamat_lengkap=?, kapasitas_olt=?, status=?, pic_nama=?, pic_telepon=?, keterangan=? WHERE id=?");
    $stmt->bind_param("ssddsissssi", $nama_pop, $lokasi, $latitude, $longitude, $alamat_lengkap, $kapasitas_olt, $status, $pic_nama, $pic_telepon, $keterangan, $id);
    
    if ($stmt->execute()) {
        echo "<script>alert('POP berhasil diupdate'); window.location.href='data_pop.php';</script>";
        exit;
    } else {
        $error_message = "Error: " . $stmt->error;
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
.close { background: none; border: none; font-size: 20px; cursor: pointer; color: #aaa; }
.close:hover { color: #000; }
.row { display: flex; flex-wrap: wrap; margin-left: -15px; margin-right: -15px; }
.col-md-6 { flex: 0 0 50%; max-width: 50%; padding-left: 15px; padding-right: 15px; }
@media (max-width: 768px) { .col-md-6 { flex: 0 0 100%; max-width: 100%; } }
</style>

<main class="main-content" id="mainContent">
    <div class="page-title">
        <h1>Edit POP</h1>
        <div class="page-subtitle">Edit Point of Presence untuk infrastruktur FTTH</div>
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
            <h5>Form Edit POP: <?php echo htmlspecialchars($pop_data['nama_pop']); ?></h5>
            <a href="data_pop.php" class="btn btn-secondary">
                <i class="fa fa-arrow-left"></i> Kembali
            </a>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nama POP *</label>
                            <input type="text" name="nama_pop" class="form-control" required 
                                   value="<?php echo htmlspecialchars($pop_data['nama_pop']); ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Lokasi *</label>
                            <input type="text" name="lokasi" class="form-control" required 
                                   value="<?php echo htmlspecialchars($pop_data['lokasi']); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Latitude</label>
                            <input type="number" name="latitude" class="form-control" step="any" 
                                   placeholder="Contoh: -6.2088"
                                   value="<?php echo $pop_data['latitude']; ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Longitude</label>
                            <input type="number" name="longitude" class="form-control" step="any" 
                                   placeholder="Contoh: 106.8456"
                                   value="<?php echo $pop_data['longitude']; ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Alamat Lengkap</label>
                    <textarea name="alamat_lengkap" class="form-control" rows="3"><?php echo htmlspecialchars($pop_data['alamat_lengkap']); ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Kapasitas OLT *</label>
                            <input type="number" name="kapasitas_olt" class="form-control" required min="1" 
                                   value="<?php echo $pop_data['kapasitas_olt']; ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Status *</label>
                            <select name="status" class="form-control" required>
                                <option value="active" <?php echo ($pop_data['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($pop_data['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="maintenance" <?php echo ($pop_data['status'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nama PIC</label>
                            <input type="text" name="pic_nama" class="form-control" 
                                   value="<?php echo htmlspecialchars($pop_data['pic_nama']); ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Telepon PIC</label>
                            <input type="text" name="pic_telepon" class="form-control" 
                                   value="<?php echo htmlspecialchars($pop_data['pic_telepon']); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Keterangan</label>
                    <textarea name="keterangan" class="form-control" rows="2"><?php echo htmlspecialchars($pop_data['keterangan']); ?></textarea>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Update POP
                    </button>
                    <a href="data_pop.php" class="btn btn-secondary">
                        <i class="fa fa-times"></i> Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>