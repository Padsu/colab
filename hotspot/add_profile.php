<?php
// Start output buffering to prevent header issues
ob_start();

session_start();
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../config/config_mikrotik.php';

$api_connected = $mikrotik_connected;
$router_name = "Main Router";
$router_ip = $mikrotik_ip;

function getAllQueues($api) {
    if (!$api) return [];
    try {
        $queues = $api->comm("/queue/simple/print", array("?dynamic" => "false"));
        $list = [];
        foreach ($queues as $queue) {
            if (isset($queue['name'])) {
                $list[] = [
                    'id' => $queue['.id'] ?? '',
                    'name' => $queue['name'],
                    'target' => $queue['target'] ?? '',
                    'max_limit' => $queue['max-limit'] ?? ''
                ];
            }
        }
        return $list;
    } catch (Exception $e) {
        return [];
    }
}

function getAddressPools($api) {
    if (!$api) return [];
    try {
        $pools = $api->comm("/ip/pool/print");
        $list = [];
        foreach ($pools as $pool) {
            if (isset($pool['name'])) {
                $list[] = [
                    'id' => $pool['.id'] ?? '',
                    'name' => $pool['name'],
                    'ranges' => $pool['ranges'] ?? ''
                ];
            }
        }
        return $list;
    } catch (Exception $e) {
        return [];
    }
}

// Process form submission BEFORE any output
if (isset($_POST['action']) && $_POST['action'] == 'add_profile') {
    if (!$api_connected) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Error: Router tidak terhubung!'];
    } else {
        $nama_profile = htmlspecialchars($_POST['nama_profile'] ?? '');
        $harga = filter_var($_POST['selling_price'] ?? 0, FILTER_VALIDATE_FLOAT); // Use selling_price as harga
        $selling_price = filter_var($_POST['selling_price'] ?? 0, FILTER_VALIDATE_FLOAT);
        $rate_limit_rx_tx = htmlspecialchars($_POST['rate_limit_rx_tx'] ?? '');
        $shared_users = intval($_POST['shared_users'] ?? 1);
        $mac_cookie_timeout = htmlspecialchars($_POST['mac_cookie_timeout'] ?? '3d 00:00:00');
        $address_list = htmlspecialchars($_POST['address_list'] ?? '');
        $address_pool = htmlspecialchars($_POST['address_pool'] ?? '');
        $parent = htmlspecialchars($_POST['parent'] ?? '');
        $lockunlock = htmlspecialchars($_POST['lockunlock'] ?? 'Disable');
        $session_timeout = htmlspecialchars($_POST['session_timeout'] ?? '');
        $idle_timeout = htmlspecialchars($_POST['idle_timeout'] ?? '');
        $expired_mode = htmlspecialchars($_POST['expired_mode'] ?? 'none');
        $validity = htmlspecialchars($_POST['validity'] ?? '');
        $grace_period = htmlspecialchars($_POST['grace_period'] ?? '5m');

        if (!empty($nama_profile)) {
            try {
                // Generate OnLogin Script Mikhmon Style
                $onlogin_script = '';
                if ($expired_mode !== 'none' && !empty($validity)) {
                    $price = $harga > 0 ? $harga : 0;
                    $sprice = $selling_price > 0 ? $selling_price : 0;
                    
                    $record = '; :local mac $"mac-address"; :local time [/system clock get time ]; /system script add name="$date-|-$time-|-$user-|-'.$price.'-|-$address-|-$mac-|-' . $validity . '-|-'.$nama_profile.'-|-$comment" owner="$month$year" source="$date" comment="mikhmon"';
                    
                    $onlogin_script = ':put (",'.$expired_mode.',' . $price . ',' . $validity . ','.$sprice.',,' . $lockunlock . ',"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pic $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local date [ /system clock get date ];:local year [ :pick $date 7 11 ];:local month [ :pick $date 0 3 ]; /sys sch add name="$user" disable=no start-date=$date interval="' . $validity . '"; :delay 5s; :local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run]; :local getxp [len $exp]; :if ($getxp = 15) do={ :local d [:pic $exp 0 6]; :local t [:pic $exp 7 16]; :local s ("/"); :local exp ("$d$s$year $t"); /ip hotspot user set comment="$exp" [find where name="$user"];}; :if ($getxp = 8) do={ /ip hotspot user set comment="$date $exp" [find where name="$user"];}; :if ($getxp > 15) do={ /ip hotspot user set comment="$exp" [find where name="$user"];};:delay 5s; /sys sch remove [find where name="$user"]';
                    
                    if ($expired_mode == "remc" || $expired_mode == "ntfc") {
                        $onlogin_script .= $record;
                    }
                    
                    $onlogin_script .= "}}";
                    
                } elseif ($expired_mode == "none" && $selling_price != "") {
                    $onlogin_script = ':put (",,' . $selling_price . ',,,noexp,' . $lockunlock . ',")';
                } else {
                    $onlogin_script = '';
                }

                // Lock User Script
                if ($lockunlock == "Enable") {
                    $lock_script = '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]';
                    $onlogin_script .= $lock_script;
                }

                // Add to MikroTik
                $params = array();
                $params['name'] = $nama_profile;
                if (!empty($rate_limit_rx_tx)) $params['rate-limit'] = $rate_limit_rx_tx;
                if (!empty($shared_users)) $params['shared-users'] = $shared_users;
                if (!empty($address_list)) $params['address-list'] = $address_list;
                if (!empty($address_pool)) $params['address-pool'] = $address_pool;
                if (!empty($mac_cookie_timeout)) $params['mac-cookie-timeout'] = $mac_cookie_timeout;
                if (!empty($session_timeout)) $params['session-timeout'] = $session_timeout;
                if (!empty($idle_timeout)) $params['idle-timeout'] = $idle_timeout;
                if (!empty($parent)) $params['parent-queue'] = $parent;
                if (!empty($onlogin_script)) $params['on-login'] = $onlogin_script;
                $params['status-autorefresh'] = '1m';

                $api->comm('/ip/hotspot/user/profile/add', $params);

                // Create Background Scheduler Mikhmon Style
                if ($expired_mode !== 'none' && !empty($validity)) {
                    $mode = '';
                    if ($expired_mode == "rem" || $expired_mode == "remc") {
                        $mode = "remove";
                    } elseif ($expired_mode == "ntf" || $expired_mode == "ntfc") {
                        $mode = "set limit-uptime=1s";
                    }

                    $bgservice = ':local dateint do={:local montharray ( "jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec" );:local days [ :pick $d 4 6 ];:local month [ :pick $d 0 3 ];:local year [ :pick $d 7 11 ];:local monthint ([ :find $montharray $month]);:local month ($monthint + 1);:if ( [len $month] = 1) do={:local zero ("0");:return [:tonum ("$year$zero$month$days")];} else={:return [:tonum ("$year$month$days")];}}; :local timeint do={ :local hours [ :pick $t 0 2 ]; :local minutes [ :pick $t 3 5 ]; :return ($hours * 60 + $minutes) ; }; :local date [ /system clock get date ]; :local time [ /system clock get time ]; :local today [$dateint d=$date] ; :local curtime [$timeint t=$time] ; :foreach i in [ /ip hotspot user find where profile="'.$nama_profile.'" ] do={ :local comment [ /ip hotspot user get $i comment]; :local userName [ /ip hotspot user get $i name]; :local gettime [:pic $comment 12 20]; :if ([:pic $comment 3] = "/" and [:pic $comment 6] = "/") do={:local expd [$dateint d=$comment] ; :local expt [$timeint t=$gettime] ; :if (($expd < $today and $expt < $curtime) or ($expd < $today and $expt > $curtime) or ($expd = $today and $expt < $curtime)) do={ [ /ip hotspot user '.$mode.' $i ]; [ /ip hotspot active remove [find where user=$userName] ];}}}';

                    $randstarttime = "0".rand(1,5).":".rand(10,59).":".rand(10,59);
                    $randinterval = "00:02:".rand(10,59);

                    try {
                        $api->comm("/system/scheduler/add", array(
                            "name" => $nama_profile,
                            "start-time" => $randstarttime,
                            "interval" => $randinterval,
                            "on-event" => $bgservice,
                            "disabled" => "no",
                            "comment" => "Monitor Profile ".$nama_profile
                        ));
                    } catch (Exception $e) {
                        // Scheduler sudah ada atau error lain
                    }
                }

                // Save to Database - FIXED VERSION
                if ($mysqli) {
                    $stmt = $mysqli->prepare("INSERT INTO mikrotik_hotspot_profiles (
                        nama_profile, 
                        harga, 
                        selling_price, 
                        rate_limit_rx_tx, 
                        shared_users, 
                        mac_cookie_timeout, 
                        address_list, 
                        address_pool, 
                        queue, 
                        session_timeout, 
                        idle_timeout, 
                        lock_user_enabled, 
                        expired_mode, 
                        validity, 
                        grace_period, 
                        status_profile, 
                        sync_mikrotik, 
                        last_sync, 
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

                    $status_profile = 'aktif';
                    $sync_mikrotik = 'yes';
                    $lock_enabled = ($lockunlock == "Enable") ? 'yes' : 'no';

                    // FIXED: Corrected bind_param with proper type count (17 parameters = 17 type chars)
                    $stmt->bind_param("sddsissssssssssss", 
                        $nama_profile,          // s - string
                        $harga,                 // d - double/float
                        $selling_price,         // d - double/float
                        $rate_limit_rx_tx,      // s - string
                        $shared_users,          // i - integer
                        $mac_cookie_timeout,    // s - string
                        $address_list,          // s - string
                        $address_pool,          // s - string
                        $parent,                // s - string (queue)
                        $session_timeout,       // s - string
                        $idle_timeout,          // s - string
                        $lock_enabled,          // s - string
                        $expired_mode,          // s - string
                        $validity,              // s - string
                        $grace_period,          // s - string
                        $status_profile,        // s - string
                        $sync_mikrotik          // s - string
                        // last_sync and created_at use NOW() in SQL
                    );

                    if ($stmt->execute()) {
                        $_SESSION['alert'] = ['type' => 'success', 'message' => "Profile hotspot '$nama_profile' berhasil ditambahkan dengan fitur advanced Mikhmon!"];
                    } else {
                        $_SESSION['alert'] = ['type' => 'warning', 'message' => "Profile ditambahkan ke MikroTik, tapi gagal disimpan ke database: " . $stmt->error];
                    }
                    $stmt->close();
                } else {
                    $_SESSION['alert'] = ['type' => 'warning', 'message' => "Profile ditambahkan ke MikroTik, tapi koneksi database tidak tersedia!"];
                }

            } catch (Exception $e) {
                $_SESSION['alert'] = ['type' => 'danger', 'message' => "Error: " . $e->getMessage()];
            }
        } else {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Nama Profile wajib diisi!'];
        }
    }
    
    // Clean output buffer and redirect
    ob_clean();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

$available_queues = [];
$address_pools = [];

if ($api_connected) {
    $available_queues = getAllQueues($api);
    $address_pools = getAddressPools($api);
}

// Include templates AFTER processing
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Profile Hotspot Advanced - Mikhmon Style</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
    :root {
      --primary: #1ABB9C; --success: #26B99A; --info: #23C6C8; --warning: #F8AC59;
      --danger: #ED5565; --secondary: #73879C; --dark: #2A3F54; --light: #F7F7F7;
    }
    body { background-color: #F7F7F7; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; color: #73879C; }
    .main-content { 
        padding: 20px; 
        margin-left: 220px; 
        min-height: calc(100vh - 52px);
        transition: margin-left 0.3s ease;
    }
    .page-title { margin-bottom: 30px; }
    .page-title h1 { font-size: 24px; color: var(--dark); margin: 0; font-weight: 400; }
    .page-title .page-subtitle { color: var(--secondary); font-size: 13px; margin: 5px 0 0 0; }
    .card { border: none; border-radius: 5px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); margin-bottom: 20px; }
    .card-header { background-color: white; border-bottom: 1px solid #e5e5e5; padding: 15px 20px; border-radius: 5px 5px 0 0 !important; }
    .card-header h5 { font-size: 16px; font-weight: 500; color: var(--dark); margin: 0; }
    .alert { padding: 10px 15px; font-size: 13px; border-radius: 3px; border: none; }
    .alert-danger { background-color: rgba(237, 85, 101, 0.1); color: var(--danger); }
    .alert-success { background-color: rgba(38, 185, 154, 0.1); color: var(--success); }
    .alert-warning { background-color: rgba(248, 172, 89, 0.1); color: var(--warning); }
    .alert-info { background-color: rgba(35, 198, 200, 0.1); color: var(--info); }
    .form-control, .form-select { border-radius: 3px; border: 1px solid #D5D5D5; font-size: 13px; padding: 8px 12px; height: auto; }
    .form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 0.2rem rgba(26, 187, 156, 0.25); }
    .btn { border-radius: 3px; font-size: 13px; padding: 8px 15px; font-weight: 500; }
    .btn-primary { background-color: var(--primary); border-color: var(--primary); }
    .btn-primary:hover { background-color: #169F85; border-color: #169F85; }
    .feature-section { border-left: 4px solid var(--primary); padding-left: 15px; margin-bottom: 25px; }
    .feature-section h6 { color: var(--primary); font-weight: 600; margin-bottom: 15px; }
    .advanced-feature { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 15px; border-radius: 8px; margin-bottom: 15px; }
    .mikhmon-badge { background: linear-gradient(45deg, #FF6B6B, #4ECDC4); color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; }
    
    .sidebar-collapsed .main-content {
        margin-left: 60px;
    }
    
    @media (max-width: 992px) { 
        .main-content { 
            margin-left: 0; 
            padding: 15px; 
        }
        .sidebar-collapsed .main-content {
            margin-left: 0;
        }
    }
    </style>
</head>
<body>
    <main class="main-content" id="mainContent">
        <div class="page-title">
            <h1>Manajemen Profile Hotspot</h1>
            <p class="page-subtitle">Tambah profile hotspot</p>
        </div>

        <?php if (isset($_SESSION['alert'])): ?>
            <div class="alert alert-<?= $_SESSION['alert']['type'] ?> d-flex align-items-center">
                <i class="fas fa-<?= $_SESSION['alert']['type'] === 'danger' ? 'exclamation-circle' : ($_SESSION['alert']['type'] === 'success' ? 'check-circle' : 'info-circle') ?> me-2"></i>
                <?= $_SESSION['alert']['message'] ?>
            </div>
            <?php unset($_SESSION['alert']); ?>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-plus-circle me-2"></i>Tambah Profile Baru</h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="action" value="add_profile">

                            <?php if (!$api_connected): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Router tidak terhubung. Pastikan koneksi router aktif.
                                </div>
                            <?php endif; ?>

                            <div class="feature-section">
                                <h6><i class="fas fa-info-circle me-2"></i>Basic Information</h6>
                                
                                <table class="table">
                                    <tr>
                                        <td class="align-middle" style="width: 30%;">Nama Profile <span class="text-danger">*</span></td>
                                        <td>
                                            <input type="text" name="nama_profile" id="nama_profile" class="form-control" required <?= !$api_connected ? 'disabled' : '' ?>>
                                            <div class="form-text">Nama profile untuk MikroTik dan database (tidak boleh spasi).</div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="align-middle">Selling Price</td>
                                        <td>
                                            <input type="number" name="selling_price" class="form-control" step="0.01" value="0.00" <?= !$api_connected ? 'disabled' : '' ?>>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <div class="feature-section">
                                <h6><i class="fas fa-network-wired me-2"></i>Network Configuration</h6>
                                
                                <table class="table">
                                    <tr>
                                        <td class="align-middle" style="width: 30%;">Rate Limit RX/TX</td>
                                        <td>
                                            <input type="text" name="rate_limit_rx_tx" class="form-control" placeholder="1M/1M" <?= !$api_connected ? 'disabled' : '' ?>>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="align-middle">Shared Users</td>
                                        <td>
                                            <select name="shared_users" class="form-control" <?= !$api_connected ? 'disabled' : '' ?>>
                                                <option value="1" selected>1</option>
                                                <option value="2">2</option>
                                                <option value="3">3</option>
                                                <option value="4">4</option>
                                                <option value="5">5</option>
                                                <option value="unlimited">Unlimited</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="align-middle">Lock User to MAC</td>
                                        <td>
                                            <select name="lockunlock" class="form-control" <?= !$api_connected ? 'disabled' : '' ?>>
                                                <option value="Disable" selected>Disable</option>
                                                <option value="Enable">Enable</option>
                                            </select>
                                        </td>
                                    </tr>
									<tr>
                                        <td class="align-middle">Address List</td>
                                        <td>
                                            <input type="text" name="address_list" class="form-control" placeholder="" <?= !$api_connected ? 'disabled' : '' ?>>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="align-middle">Expired Mode</td>
                                        <td>
                                            <select name="expired_mode" id="expired_mode" class="form-control" onchange="toggleValidityFields()" required <?= !$api_connected ? 'disabled' : '' ?>>
                                                <option value="none">None</option>
                                                <option value="rem">Auto Remove User</option>
                                                <option value="ntf">Notice</option>
                                                <option value="remc">Remove & Record</option>
                                                <option value="ntfc">Notice & Record</option>
                                            </select>
                                        </td>
                                    </tr>

                                    <tr id="validity_row" style="display:none;">
                                        <td class="align-middle">Validity Period </td>
                                        <td>
                                            <input type="text" name="validity" id="validity" class="form-control" placeholder="1h, 1d, 7d, 30d" <?= !$api_connected ? 'disabled' : '' ?>>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <div class="feature-section">
                                <h6><i class="fas fa-tachometer-alt me-2"></i>Queue Management</h6>
                                
                                <table class="table">
                                    <tr>
                                        <td class="align-middle" style="width: 30%;">Parent Queue</td>
                                        <td>
                                            <select name="parent" class="form-control" <?= !$api_connected ? 'disabled' : '' ?>>
                                                <option value="">none</option>
                                                <?php foreach ($available_queues as $queue): ?>
                                                    <option value="<?= htmlspecialchars($queue['name']) ?>">
                                                        <?= htmlspecialchars($queue['name']) ?>
                                                        <?php if (!empty($queue['max_limit'])): ?>
                                                            (<?= htmlspecialchars($queue['max_limit']) ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <div class="text-center mt-4">
                                <button type="submit" class="btn btn-primary btn-lg px-5" <?= !$api_connected ? 'disabled' : '' ?>>
                                    <i class="fas fa-save me-2"></i> Simpan Profile Advanced
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-star me-2"></i>Advanced Features</h5>
                    </div>
                    <div class="card-body">
                        <div class="advanced-feature mb-3">
                            <h6><i class="fas fa-clock me-2"></i>Auto Expiry System</h6>
                            <ul class="small mb-0">
                                <li><strong>Remove:</strong> Auto hapus user setelah expired</li>
                                <li><strong>Notice:</strong> Set limit 1s (notifikasi expired)</li>
                                <li><strong>Record:</strong> Simpan log aktivitas user</li>
                                <li><strong>None:</strong> Tidak ada auto-expiry</li>
                            </ul>
                        </div>

                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-info-circle me-2"></i>Status & Resources</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <h6><i class="fas fa-server me-2"></i>Status Router</h6>
                            <?php if ($api_connected): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle me-2"></i>
                                    Terhubung ke <?= $router_ip ?> (<?= $router_name ?>)
                                </div>
                            <?php else: ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-times-circle me-2"></i>
                                    Tidak terhubung ke <?= $router_ip ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-4">
                            <h6><i class="fas fa-lock me-2"></i>Lock User Feature</h6>
                            <div class="alert alert-warning">
                                <strong>Enable:</strong> User terkunci pada MAC address pertama.<br>
                                <strong>Disable:</strong> User bisa login dari perangkat manapun.
                            </div>
                        </div>

                        <div>
                            <h6><i class="fas fa-lightbulb me-2"></i>Format Guide</h6>
                            <ul class="small">
                                <li><strong>Waktu:</strong> s (detik), m (menit), h (jam), d (hari)</li>
                                <li><strong>Kecepatan:</strong> k (kilobit), M (megabit)</li>
                                <li><strong>Rate Limit:</strong> 1M/1M = 1 Mbps download/upload</li>
                                <li><strong>Validity:</strong> 1h=1jam, 1d=1hari, 7d=7hari, 30d=30hari</li>
                                <li><strong>Session:</strong> 24h=auto disconnect after 24h</li>
                                <li><strong>Idle:</strong> 30m=disconnect if idle 30 minutes</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggle = document.querySelector('.sidebar-toggle');
        const mainContent = document.getElementById('mainContent');
        
        if (sidebarToggle && mainContent) {
            sidebarToggle.addEventListener('click', function() {
                document.body.classList.toggle('sidebar-collapsed');
            });
        }
        
        if (document.body.classList.contains('sidebar-collapsed')) {
            mainContent.style.marginLeft = '60px';
        }
        
        window.addEventListener('resize', function() {
            if (window.innerWidth <= 992) {
                mainContent.style.marginLeft = '0';
            } else {
                if (document.body.classList.contains('sidebar-collapsed')) {
                    mainContent.style.marginLeft = '60px';
                } else {
                    mainContent.style.marginLeft = '220px';
                }
            }
        });
    });

    function toggleValidityFields() {
        const expiredMode = document.getElementById('expired_mode').value;
        const validityRow = document.getElementById('validity_row');
        const validityInput = document.getElementById('validity');
        
        if (expiredMode !== 'none') {
            validityRow.style.display = 'table-row';
            validityInput.required = true;
        } else {
            validityRow.style.display = 'none';
            validityInput.required = false;
        }
    }

    document.getElementById('nama_profile').addEventListener('input', function() {
        this.value = this.value.replace(/\s+/g, '-');
    });

    document.querySelector('form').addEventListener('submit', function(e) {
        const namaProfile = document.querySelector('input[name="nama_profile"]').value;
        const expiredMode = document.getElementById('expired_mode').value;
        const validity = document.getElementById('validity').value;
        
        if (namaProfile.includes(' ')) {
            alert('Nama Profile tidak boleh berisi spasi!');
            e.preventDefault();
            return false;
        }
        
        if (expiredMode !== 'none' && !validity) {
            alert('Validity Period wajib diisi jika menggunakan Expired Mode!');
            e.preventDefault();
            return false;
        }
        
        return true;
    });

    function previewOnLoginScript() {
        const expiredMode = document.getElementById('expired_mode').value;
        const validity = document.getElementById('validity').value;
        const sellingPrice = document.querySelector('input[name="selling_price"]').value;
        const lockUser = document.querySelector('select[name="lockunlock"]').value;
        
        let script = '';
        if (expiredMode !== 'none' && validity) {
            script = ':put (",' + expiredMode + ',' + sellingPrice + ',' + validity + ',' + sellingPrice + ',,' + lockUser + ',");';
            script += ' // ... complex mikhmon expiry logic ...';
        } else if (expiredMode === 'none' && sellingPrice) {
            script = ':put (",,' + sellingPrice + ',,,noexp,' + lockUser + ',")';
        }
        
        if (lockUser === 'Enable') {
            script += '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]';
        }
        
        console.log('Generated OnLogin Script:', script);
    }

    document.getElementById('expired_mode').addEventListener('change', previewOnLoginScript);
    document.getElementById('validity').addEventListener('input', previewOnLoginScript);
    document.querySelector('input[name="selling_price"]').addEventListener('input', previewOnLoginScript);
    document.querySelector('select[name="lockunlock"]').addEventListener('change', previewOnLoginScript);
    </script>

<?php
require_once __DIR__ . '/../templates/footer.php';

if (isset($api) && $mikrotik_connected) {
    $api->disconnect();
}

if (isset($mysqli) && $mysqli) {
    $mysqli->close();
}
?>