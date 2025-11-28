<?php
session_start();

// Jika pengguna sudah login, arahkan ke dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: admin/dashboard.php");
    exit();
}

// Muat konfigurasi database
require_once __DIR__ . '/config/config_database.php';

// Tangani pengiriman formulir login
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        try {
            // Periksa pengguna di database
            $stmt = $mysqli->prepare("SELECT id_user, username, password, nama_lengkap, level, status FROM users WHERE username = ? AND status = 'aktif'");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Verifikasi password (mendukung beberapa jenis hash)
                $password_valid = false;
                
                // Periksa apakah password adalah hash MD5 (32 karakter hex)
                if (strlen($user['password']) === 32 && ctype_xdigit($user['password'])) {
                    // Bandingkan dengan hash MD5
                    $password_valid = (md5($password) === $user['password']);
                } else {
                    // Coba password_verify untuk password yang di-hash modern
                    $password_valid = password_verify($password, $user['password']);
                    
                    // Jika itu gagal, coba perbandingan langsung untuk teks biasa (kurang aman, hindari jika memungkinkan)
                    if (!$password_valid) {
                        $password_valid = ($password === $user['password']);
                    }
                }
                
                if ($password_valid) {
                    // Login berhasil
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_id'] = $user['id_user'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                    $_SESSION['level'] = $user['level'];
                    
                    // Catat aktivitas login jika fungsi ada
                    if (function_exists('log_activity')) {
                        log_activity($username, "Login ke sistem", "users", $user['id_user']);
                    }
                    
                    header("Location: admin/dashboard.php");
                    exit();
                } else {
                    $error_message = "Username atau password salah!";
                }
            } else {
                $error_message = "Username atau password salah!";
            }
            $stmt->close();
        } catch (Exception $e) {
            $error_message = "Terjadi kesalahan sistem. Silakan coba lagi.";
            error_log("Login error: " . $e->getMessage());
        }
    } else {
        $error_message = "Username dan password harus diisi!";
    }
}

// Dapatkan pengaturan perusahaan untuk branding
$company_name = 'ANUNET Management System';
$has_logo = false;
$logo_url = '';

try {
    $result = $mysqli->query("SELECT nama_perusahaan FROM pengaturan_perusahaan ORDER BY id_pengaturan DESC LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $company = $result->fetch_assoc();
        $company_name = $company['nama_perusahaan'] . '<br>RTRWNet Billing Management System';    }
} catch (Exception $e) {
    // Gunakan default jika tabel tidak ada
}

// Cek logo dari folder root - bypass .htaccess dengan base64
$has_logo = false;
$logo_base64 = '';

// Coba baca file langsung dan convert ke base64
$logo_file_paths = [
    './login.png',
    'login.png',
    __DIR__ . '/login.png'
];

foreach ($logo_file_paths as $path) {
    if (file_exists($path) && is_readable($path)) {
        $image_data = file_get_contents($path);
        if ($image_data !== false) {
            $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
            $has_logo = true;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($company_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #F7F7F7;
            color: #73879C;
            font-family: "Helvetica Neue", Roboto, Arial, "Droid Sans", sans-serif;
            font-size: 13px;
            font-weight: 400;
            line-height: 1.471;
            min-height: 100vh;
            display: flex;
            align-items: center;
            background-image: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        
        .login-container {
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
            animation: fadeIn 0.5s ease-in-out;
        }
        
        .login-card {
            background: #fff;
            border: none;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            padding: 30px;
            transition: transform 0.3s ease;
        }
        
        .login-card:hover {
            transform: translateY(-5px);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .login-logo {
            margin: 0 auto 20px;
            text-align: center;
        }
        
        .login-logo img {
            max-height: 80px;
            max-width: 200px;
            transition: transform 0.3s ease;
        }
        
        .login-logo img:hover {
            transform: scale(1.05);
        }
        
        .login-title {
            color: #2A3F54;
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .login-subtitle {
            color: #73879C;
            font-size: 14px;
            margin-bottom: 25px;
        }
        
        .form-control {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-shadow: none;
            color: #555;
            font-size: 14px;
            height: 42px;
            padding: 10px 15px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #2A3F54;
            box-shadow: 0 0 0 0.2rem rgba(42, 63, 84, 0.25);
        }
        
        .btn-login {
            background-color: #2A3F54;
            border: none;
            border-radius: 5px;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            height: 45px;
            padding: 10px;
            transition: all 0.3s;
            width: 100%;
        }
        
        .btn-login:hover {
            background-color: #1E2E3B;
            transform: translateY(-2px);
        }
        
        .input-group-text {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-right: none;
            color: #555;
        }
        
        .alert {
            border-radius: 5px;
            padding: 12px 15px;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #73879C;
            z-index: 10;
        }
        
        .protected-footer {
            position: relative;
            margin-top: 20px;
            padding-top: 15px;
            text-align: center;
        }
        
        .protected-footer::before {
            content: "";
            position: absolute;
            top: 0;
            left: 25%;
            right: 25%;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(0,0,0,0.1), transparent);
        }
        
        .protected-footer small {
            font-size: 11px;
            color: #95a5a6;
            transition: color 0.3s;
        }
        
        .protected-footer:hover small {
            color: #7f8c8d;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 20px;
            color: #95a5a6;
            font-size: 12px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="login-container">
                    <div class="login-card">
                        <div class="login-header">
                            <?php if ($has_logo): ?>
                                <div class="login-logo">
                                    <img src="<?= $logo_base64 ?>" 
                                         alt="Logo" 
                                         class="img-fluid"
                                         onload="console.log('Base64 image loaded successfully');">
                                </div>
                            <?php else: ?>
                                <div class="login-logo">
                                    <i class="fas fa-building fa-4x text-primary"></i>
                                </div>
                            <?php endif; ?>
                            <p class="login-subtitle"><?= $company_name ?></p>
                        </div>
                        
                        <?php if (!empty($error_message)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?= htmlspecialchars($error_message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" autocomplete="off">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-user"></i>
                                    </span>
                                    <input type="text" class="form-control" id="username" name="username" 
                                            placeholder="Masukkan username" required 
                                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" class="form-control" id="password" name="password" 
                                            placeholder="Masukkan password" required>
                                    <i class="fas fa-eye password-toggle" onclick="togglePassword()"></i>
                                </div>
                            </div>
                            
                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-login">
                                    <i class="fas fa-sign-in-alt me-2"></i> Login
                                </button>
                            </div>
                            
                            <div class="protected-footer"></div>
                        </form>
                    </div>
                    
                    <div class="login-footer">
                        <p class="mb-0">&copy; <?= date('Y') ?> <?= $company_name ?></p>
                        <p class="mb-0"><small>v1.0.0</small></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password toggle
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.querySelector('.password-toggle');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
        
        // Dynamic copyright with protection
        document.addEventListener('DOMContentLoaded', function() {
            // Create copyright element
            const footer = document.querySelector('.protected-footer');
            if (footer) {
                const copyright = document.createElement('small');
                copyright.className = 'text-muted';
                copyright.innerHTML = '© ' + 
                    String.fromCharCode(68, 111, 110, 105, 101, 32, 84, 104, 97, 109, 98, 97, 115) + 
                    ' | ' + new Date().getFullYear();
                
                footer.appendChild(copyright);
                
                // Protection mechanism
                Object.defineProperty(footer, 'innerHTML', {
                    writable: false,
                    configurable: false
                });
                
                // Self-healing if removed
                setInterval(() => {
                    if (!document.querySelector('.protected-footer small')) {
                        location.reload();
                    }
                }, 3000);
            }
            
            // Focus on username field
            document.getElementById('username').focus();
        });
    </script>
</body>
</html>