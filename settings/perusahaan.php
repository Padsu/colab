<?php
// pengaturan_perusahaan.php

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

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

// Database connection already available from config_database.php as $mysqli

// Function to convert image to base64
function getImageAsBase64($imagePath) {
    if (file_exists($imagePath) && is_readable($imagePath)) {
        clearstatcache(true, $imagePath);
        $image_data = file_get_contents($imagePath);
        if ($image_data !== false) {
            $image_info = getimagesize($imagePath);
            $mime_type = $image_info['mime'] ?? 'image/png';
            return 'data:' . $mime_type . ';base64,' . base64_encode($image_data);
        }
    }
    return null;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nama_perusahaan = trim($_POST['nama_perusahaan'] ?? '');
        $alamat_perusahaan = trim($_POST['alamat_perusahaan'] ?? '');
        $telepon_perusahaan = trim($_POST['telepon_perusahaan'] ?? '');
        $email_perusahaan = trim($_POST['email_perusahaan'] ?? '');
        $bank_nama = trim($_POST['bank_nama'] ?? '');
        $bank_atas_nama = trim($_POST['bank_atas_nama'] ?? '');
        $bank_no_rekening = trim($_POST['bank_no_rekening'] ?? '');
        $logo_perusahaan = '';

        // Validation
        $errors = [];
        if (empty($nama_perusahaan)) $errors[] = 'Nama perusahaan wajib diisi';
        if (empty($alamat_perusahaan)) $errors[] = 'Alamat perusahaan wajib diisi';

        // Handle logo upload
        if (isset($_FILES['logo_perusahaan']) && $_FILES['logo_perusahaan']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $max_size = 5 * 1024 * 1024; // 5MB

            if (!in_array($_FILES['logo_perusahaan']['type'], $allowed_types)) {
                $errors[] = 'Format logo harus JPEG, PNG, atau GIF';
            } elseif ($_FILES['logo_perusahaan']['size'] > $max_size) {
                $errors[] = 'Ukuran logo maksimal 5MB';
            } else {
                $upload_dir = __DIR__ . '/../assets/images/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $file_extension = pathinfo($_FILES['logo_perusahaan']['name'], PATHINFO_EXTENSION);
                $new_filename = 'logo_perusahaan_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;

                if (move_uploaded_file($_FILES['logo_perusahaan']['tmp_name'], $upload_path)) {
                    $logo_perusahaan = '../assets/images/' . $new_filename;
                    
                    // Delete old logo if exists
                    $old_logo_query = "SELECT logo_perusahaan FROM pengaturan_perusahaan ORDER BY id_pengaturan DESC LIMIT 1";
                    $old_logo_result = $mysqli->query($old_logo_query);
                    if ($old_logo_result && $old_logo = $old_logo_result->fetch_assoc()) {
                        if (!empty($old_logo['logo_perusahaan']) && file_exists(__DIR__ . '/' . $old_logo['logo_perusahaan'])) {
                            unlink(__DIR__ . '/' . $old_logo['logo_perusahaan']);
                        }
                    }
                } else {
                    $errors[] = 'Gagal mengupload logo';
                }
            }
        }

        if (empty($errors)) {
            // Check if company settings exist
            $check_query = "SELECT id_pengaturan FROM pengaturan_perusahaan ORDER BY id_pengaturan DESC LIMIT 1";
            $check_result = $mysqli->query($check_query);

            if ($check_result && $check_result->num_rows > 0) {
                // Update existing record
                $existing = $check_result->fetch_assoc();
                $update_query = "UPDATE pengaturan_perusahaan SET 
                                nama_perusahaan = ?, 
                                alamat_perusahaan = ?, 
                                telepon_perusahaan = ?, 
                                email_perusahaan = ?,
                                bank_nama = ?,
                                bank_atas_nama = ?,
                                bank_no_rekening = ?";
                
                $params = [$nama_perusahaan, $alamat_perusahaan, $telepon_perusahaan, $email_perusahaan, $bank_nama, $bank_atas_nama, $bank_no_rekening];
                $types = 'sssssss';

                if (!empty($logo_perusahaan)) {
                    $update_query .= ", logo_perusahaan = ?";
                    $params[] = $logo_perusahaan;
                    $types .= 's';
                }

                $update_query .= " WHERE id_pengaturan = ?";
                $params[] = $existing['id_pengaturan'];
                $types .= 'i';

                $stmt = $mysqli->prepare($update_query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
            } else {
                // Insert new record
                $insert_query = "INSERT INTO pengaturan_perusahaan 
                                (nama_perusahaan, alamat_perusahaan, telepon_perusahaan, email_perusahaan, bank_nama, bank_atas_nama, bank_no_rekening, logo_perusahaan) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $mysqli->prepare($insert_query);
                $stmt->bind_param('ssssssss', $nama_perusahaan, $alamat_perusahaan, $telepon_perusahaan, $email_perusahaan, $bank_nama, $bank_atas_nama, $bank_no_rekening, $logo_perusahaan);
                $stmt->execute();
            }

            $_SESSION['success_message'] = 'Pengaturan perusahaan berhasil disimpan!';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $_SESSION['error_message'] = implode('<br>', $errors);
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    }
}

// Get current company data
$company_query = "SELECT * FROM pengaturan_perusahaan ORDER BY id_pengaturan DESC LIMIT 1";
$company_result = $mysqli->query($company_query);
$company_data = $company_result->fetch_assoc();

// Default values if no data found
if (!$company_data) {
    $company_data = [
        'nama_perusahaan' => '',
        'alamat_perusahaan' => '',
        'telepon_perusahaan' => '',
        'email_perusahaan' => '',
        'bank_nama' => '',
        'bank_atas_nama' => '',
        'bank_no_rekening' => '',
        'logo_perusahaan' => ''
    ];
}

// Convert logo to base64 untuk bypass .htaccess
$current_logo_base64 = null;
$preview_logo_base64 = null;
$debug_logo_info = []; // Tambah debug

if (!empty($company_data['logo_perusahaan'])) {
    $logo_path = __DIR__ . '/' . $company_data['logo_perusahaan'];
    $debug_logo_info[] = "DB Logo Path: " . $company_data['logo_perusahaan'];
    $debug_logo_info[] = "Full Logo Path: " . $logo_path;
    $debug_logo_info[] = "File Exists: " . (file_exists($logo_path) ? 'YES' : 'NO');
    $debug_logo_info[] = "Is Readable: " . (is_readable($logo_path) ? 'YES' : 'NO');
    
    $current_logo_base64 = getImageAsBase64($logo_path);
    $preview_logo_base64 = $current_logo_base64; // Same untuk preview invoice
    
    $debug_logo_info[] = "Base64 Generated: " . ($current_logo_base64 ? 'YES' : 'NO');
    
    // Coba alternatif path jika gagal
    if (!$current_logo_base64) {
        $alternative_paths = [
            __DIR__ . '/../' . $company_data['logo_perusahaan'],
            __DIR__ . '/../../' . $company_data['logo_perusahaan'],
            $company_data['logo_perusahaan'] // Path relatif
        ];
        
        foreach ($alternative_paths as $alt_path) {
            $debug_logo_info[] = "Trying: " . $alt_path . " -> " . (file_exists($alt_path) ? 'EXISTS' : 'NOT FOUND');
            if (file_exists($alt_path)) {
                $current_logo_base64 = getImageAsBase64($alt_path);
                if ($current_logo_base64) {
                    $preview_logo_base64 = $current_logo_base64;
                    $debug_logo_info[] = "SUCCESS with: " . $alt_path;
                    break;
                }
            }
        }
    }
} else {
    // Jika database kosong, cari logo di lokasi default
    $debug_logo_info[] = "Database empty, checking default locations...";
    $default_logo_paths = [
        __DIR__ . '/../img/logo.png',
        __DIR__ . '/../../img/logo.png',
        __DIR__ . '/../assets/images/logo.png',
        __DIR__ . '/../../assets/images/logo.png',
        __DIR__ . '/../login.png',
        __DIR__ . '/../../login.png'
    ];
    
    foreach ($default_logo_paths as $path) {
        $debug_logo_info[] = "Checking default: " . $path . " -> " . (file_exists($path) ? 'EXISTS' : 'NOT FOUND');
        if (file_exists($path)) {
            $current_logo_base64 = getImageAsBase64($path);
            if ($current_logo_base64) {
                $preview_logo_base64 = $current_logo_base64;
                $debug_logo_info[] = "SUCCESS with default: " . $path;
                // Update database dengan logo yang ditemukan
                $relative_path = str_replace(__DIR__ . '/', '', $path);
                $debug_logo_info[] = "Auto-updating database with: " . $relative_path;
                
                // Update database
                try {
                    if ($company_data && isset($company_data['nama_perusahaan'])) {
                        $update_query = "UPDATE pengaturan_perusahaan SET logo_perusahaan = ? ORDER BY id_pengaturan DESC LIMIT 1";
                        $stmt = $mysqli->prepare($update_query);
                        $stmt->bind_param('s', $relative_path);
                        $stmt->execute();
                    } else {
                        $insert_query = "INSERT INTO pengaturan_perusahaan (logo_perusahaan) VALUES (?)";
                        $stmt = $mysqli->prepare($insert_query);
                        $stmt->bind_param('s', $relative_path);
                        $stmt->execute();
                    }
                    $debug_logo_info[] = "Database updated successfully!";
                } catch (Exception $e) {
                    $debug_logo_info[] = "Database update failed: " . $e->getMessage();
                }
                break;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Perusahaan</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
      font-family: "Segoe UI", "Helvetica Neue", Roboto, Arial, sans-serif;
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

    .settings-section {
      background: white;
      padding: 15px;
      margin-bottom: 20px;
      border-radius: 5px;
      border: 1px solid #e5e5e5;
    }

    .settings-section h6 {
      font-size: 14px;
      font-weight: 600;
      color: var(--dark);
      margin-bottom: 15px;
      padding-bottom: 10px;
      border-bottom: 1px solid #eee;
    }

    .form-label {
      font-weight: 500;
      font-size: 13px;
      color: #555;
      margin-bottom: 5px;
    }

    .form-control, .form-select {
      border-radius: 3px;
      border: 1px solid #D5D5D5;
      font-size: 13px;
      padding: 8px 12px;
      height: auto;
    }

    .form-control:focus, .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 0.2rem rgba(26, 187, 156, 0.25);
    }

    .required {
      color: var(--danger);
      font-weight: bold;
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

    .btn-success {
      background-color: var(--success);
      border-color: var(--success);
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

    .alert-success {
      background-color: rgba(38, 185, 154, 0.1);
      color: var(--success);
    }

    .alert-info {
      background-color: rgba(35, 198, 200, 0.1);
      color: var(--info);
    }

    /* Custom styles for this page */
    .logo-preview {
        max-width: 200px;
        max-height: 100px;
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        padding: 10px;
        display: inline-block;
        background-color: #f8f9fa;
    }
    
    .logo-preview img {
        max-width: 100%;
        max-height: 80px;
        object-fit: contain;
    }
    
    .upload-area {
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        padding: 20px;
        text-align: center;
        background-color: #f8f9fa;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .upload-area:hover {
        border-color: var(--primary);
        background-color: #e3f2fd;
    }
    
    .upload-area.dragover {
        border-color: var(--success);
        background-color: #d4edda;
    }
    
    .invoice-preview {
        background: linear-gradient(135deg, var(--primary), #169F85);
        color: white;
        border-radius: 5px;
        padding: 15px;
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
            <h1><i class="fas fa-building"></i> Pengaturan Perusahaan</h1>
            <p class="page-subtitle">Kelola informasi dan identitas perusahaan Anda</p>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Form Pengaturan</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['success_message'])): ?>
                            <div class="alert alert-success d-flex align-items-center">
                                <i class="fas fa-check-circle me-2"></i>
                                <?= htmlspecialchars($_SESSION['success_message']) ?>
                            </div>
                            <?php unset($_SESSION['success_message']); ?>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['error_message'])): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?= $_SESSION['error_message'] ?>
                            </div>
                            <?php unset($_SESSION['error_message']); ?>
                        <?php endif; ?>
                        
                        <form method="POST" enctype="multipart/form-data" id="companyForm">
                            <div class="row">
                                <!-- Company Info -->
                                <div class="col-md-8">
                                    <div class="settings-section">
                                        <h6><i class="fas fa-info-circle me-2"></i>Informasi Perusahaan</h6>
                                        
                                        <div class="mb-3">
                                            <label for="nama_perusahaan" class="form-label">Nama Perusahaan <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="nama_perusahaan" name="nama_perusahaan" 
                                                   value="<?= htmlspecialchars($company_data['nama_perusahaan']) ?>" required>
                                            <div class="form-text">Nama lengkap perusahaan yang akan ditampilkan di invoice</div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="telepon_perusahaan" class="form-label"><i class="fas fa-phone me-1"></i> Nomor Telepon</label>
                                                    <input type="text" class="form-control" id="telepon_perusahaan" name="telepon_perusahaan" 
                                                           value="<?= htmlspecialchars($company_data['telepon_perusahaan']) ?>"
                                                           placeholder="+62812XXXXXXXX">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="email_perusahaan" class="form-label"><i class="fas fa-envelope me-1"></i> Email Perusahaan</label>
                                                    <input type="email" class="form-control" id="email_perusahaan" name="email_perusahaan" 
                                                           value="<?= htmlspecialchars($company_data['email_perusahaan']) ?>"
                                                           placeholder="info@company.com">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="alamat_perusahaan" class="form-label">Alamat Perusahaan <span class="required">*</span></label>
                                            <textarea class="form-control" id="alamat_perusahaan" name="alamat_perusahaan" 
                                                      rows="3" required><?= htmlspecialchars($company_data['alamat_perusahaan']) ?></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="settings-section">
                                        <h6><i class="fas fa-university me-2"></i>Informasi Bank</h6>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="bank_nama" class="form-label">Nama Bank</label>
                                                    <input type="text" class="form-control" id="bank_nama" name="bank_nama" 
                                                           value="<?= htmlspecialchars($company_data['bank_nama'] ?? '') ?>"
                                                           placeholder="Contoh: BRI, BCA, Mandiri">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="bank_no_rekening" class="form-label">No. Rekening</label>
                                                    <input type="text" class="form-control" id="bank_no_rekening" name="bank_no_rekening" 
                                                           value="<?= htmlspecialchars($company_data['bank_no_rekening'] ?? '') ?>"
                                                           placeholder="1234567890">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="bank_atas_nama" class="form-label">Atas Nama</label>
                                            <input type="text" class="form-control" id="bank_atas_nama" name="bank_atas_nama" 
                                                   value="<?= htmlspecialchars($company_data['bank_atas_nama'] ?? '') ?>"
                                                   placeholder="Nama pemilik rekening">
                                        </div>
                                    </div>
                                </div>

                                <!-- Logo Section -->
                                <div class="col-md-4">
                                    <div class="settings-section">
                                        <h6><i class="fas fa-image me-2"></i>Logo Perusahaan</h6>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Logo Saat Ini:</label>
                                            <div class="logo-preview">
                                                <?php if ($current_logo_base64): ?>
                                                    <img src="<?= $current_logo_base64 ?>" alt="Logo Perusahaan" id="currentLogo">
                                                <?php else: ?>
                                                    <div class="text-muted text-center">
                                                        <i class="fas fa-image fs-3"></i><br>
                                                        <small>Belum ada logo</small>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="logo_perusahaan" class="form-label">Upload Logo Baru:</label>
                                            <div class="upload-area" onclick="document.getElementById('logo_perusahaan').click()">
                                                <i class="fas fa-cloud-upload-alt fs-2 text-primary"></i>
                                                <p class="mb-0">Klik untuk pilih file atau drag & drop</p>
                                                <small class="text-muted">Format: JPG, PNG, GIF (Max: 5MB)</small>
                                            </div>
                                            <input type="file" class="d-none" id="logo_perusahaan" name="logo_perusahaan" 
                                                   accept="image/jpeg,image/png,image/gif">
                                        </div>

                                        <div id="newLogoPreview" class="mb-3" style="display: none;">
                                            <label class="form-label">Preview Logo Baru:</label>
                                            <div class="logo-preview">
                                                <img id="previewImage" src="" alt="Preview">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between mt-4">
                                <a href="../dashboard/" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-1"></i> Kembali
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Simpan Pengaturan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Preview Invoice Card -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-file-invoice me-2"></i>Preview Invoice</h5>
                    </div>
                    <div class="card-body">
                        <div class="invoice-preview">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <?php if ($preview_logo_base64): ?>
                                        <img src="<?= $preview_logo_base64 ?>" alt="Logo" style="max-height: 50px; margin-bottom: 10px;">
                                    <?php endif; ?>
                                    <h6 class="mb-1"><?= !empty($company_data['nama_perusahaan']) ? htmlspecialchars($company_data['nama_perusahaan']) : 'Nama Perusahaan' ?></h6>
                                    <small>Internet Service Provider</small>
                                </div>
                                <div class="text-end">
                                    <h6>INVOICE</h6>
                                    <span class="badge bg-success">LUNAS</span>
                                </div>
                            </div>
                            <hr style="border-color: rgba(255,255,255,0.3);">
                            <div class="text-center">
                                <small>
                                    <i class="fas fa-map-marker-alt"></i> 
                                    <?= !empty($company_data['alamat_perusahaan']) ? htmlspecialchars($company_data['alamat_perusahaan']) : 'Alamat Perusahaan' ?>
                                </small><br>
                                <small>
                                    <?php if (!empty($company_data['telepon_perusahaan'])): ?>
                                        <i class="fas fa-phone"></i> <?= htmlspecialchars($company_data['telepon_perusahaan']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($company_data['email_perusahaan'])): ?>
                                        <?= !empty($company_data['telepon_perusahaan']) ? ' | ' : '' ?>
                                        <i class="fas fa-envelope"></i> <?= htmlspecialchars($company_data['email_perusahaan']) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            
                            <?php if (!empty($company_data['bank_nama']) || !empty($company_data['bank_no_rekening']) || !empty($company_data['bank_atas_nama'])): ?>
                            <hr style="border-color: rgba(255,255,255,0.3); margin: 15px 0 10px 0;">
                            <div class="text-center">
                                <small style="font-size: 0.8em;">
                                    <strong>Transfer Bank:</strong><br>
                                    <?= !empty($company_data['bank_nama']) ? htmlspecialchars($company_data['bank_nama']) : 'Bank' ?> - 
                                    <?= !empty($company_data['bank_no_rekening']) ? htmlspecialchars($company_data['bank_no_rekening']) : 'No. Rekening' ?><br>
                                    A.n: <?= !empty($company_data['bank_atas_nama']) ? htmlspecialchars($company_data['bank_atas_nama']) : 'Atas Nama' ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted mt-2 mb-0">
                            <small><i class="fas fa-info-circle"></i> Ini adalah preview bagaimana informasi perusahaan akan ditampilkan di invoice</small>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Bootstrap 5 JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('logo_perusahaan');
    const uploadArea = document.querySelector('.upload-area');
    const newLogoPreview = document.getElementById('newLogoPreview');
    const previewImage = document.getElementById('previewImage');

    // File input change event
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            previewFile(file);
        }
    });

    // Drag and drop functionality
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            const file = files[0];
            if (file.type.startsWith('image/')) {
                fileInput.files = files;
                previewFile(file);
            } else {
                alert('Hanya file gambar yang diperbolehkan!');
            }
        }
    });

    function previewFile(file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            newLogoPreview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    }

    // Form validation
    document.getElementById('companyForm').addEventListener('submit', function(e) {
        const namaPerusahaan = document.getElementById('nama_perusahaan').value.trim();
        const alamatPerusahaan = document.getElementById('alamat_perusahaan').value.trim();

        if (!namaPerusahaan || !alamatPerusahaan) {
            e.preventDefault();
            alert('Nama perusahaan dan alamat wajib diisi!');
            return false;
        }

        return confirm('Apakah Anda yakin ingin menyimpan pengaturan perusahaan?');
    });
});
</script>

<?php 
require_once __DIR__ . '/../templates/footer.php';
ob_end_flush();
?>