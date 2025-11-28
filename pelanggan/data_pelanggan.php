<?php
ob_start();

// Load dependencies
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../config/config_mikrotik.php';
require_once __DIR__ . '/../config/routeros_api.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/sidebar.php';

// Initialize CSRF
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Customer Service Class - Handles all customer data operations
 */
class CustomerService {
    private $db;
    private $cache = [];
    
    public function __construct($mysqli) {
        $this->db = $mysqli;
    }
    
    /**
     * Build dynamic WHERE conditions for filters
     */
    private function buildFilters($filters) {
        $conditions = [];
        $params = [];
        $types = '';
        
        if (!empty($filters['search'])) {
            $conditions[] = "(dp.nama_pelanggan LIKE ? OR dp.alamat_pelanggan LIKE ? OR dp.telepon_pelanggan LIKE ? OR dp.mikrotik_username LIKE ?)";
            $search = "%{$filters['search']}%";
            $params = array_merge($params, [$search, $search, $search, $search]);
            $types .= 'ssss';
        }
        
        if (!empty($filters['status_aktif'])) {
            $conditions[] = "dp.status_aktif = ?";
            $params[] = $filters['status_aktif'];
            $types .= 's';
        }
        
        if (!empty($filters['id_paket'])) {
            $conditions[] = "dp.id_paket = ?";
            $params[] = $filters['id_paket'];
            $types .= 'i';
        }
        
        if (!empty($filters['odp_id'])) {
            $conditions[] = "dp.odp_id = ?";
            $params[] = $filters['odp_id'];
            $types .= 'i';
        }
        
        return [
            'where' => empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions),
            'params' => $params,
            'types' => $types
        ];
    }
    
    /**
     * Get customer data with pagination and filters
     */
    public function getCustomers($filters, $page = 1, $limit = 10) {
        $offset = ($page - 1) * $limit;
        $filter_data = $this->buildFilters($filters);
        
        // Get total count - simple query tanpa JOIN
        $count_sql = "SELECT COUNT(*) as total FROM data_pelanggan dp {$filter_data['where']}";
        $total = $this->executeQuery($count_sql, $filter_data['params'], $filter_data['types'])->fetch_assoc()['total'];
        
        // Get customer data - simple query tanpa JOIN kompleks
        $main_sql = "SELECT 
                        dp.id_pelanggan, dp.nama_pelanggan, dp.alamat_pelanggan, dp.telepon_pelanggan,
                        dp.email_pelanggan, dp.tgl_daftar, dp.tgl_expired, dp.status_aktif,
                        dp.mikrotik_username, dp.mikrotik_password,
                        dp.last_paid_date, dp.onu_id,
                        dp.id_paket, dp.odp_id, dp.odp_port_id, dp.created_at
                    FROM data_pelanggan dp
                    {$filter_data['where']}
                    ORDER BY dp.created_at DESC LIMIT {$limit} OFFSET {$offset}";
        
        $result = $this->executeQuery($main_sql, $filter_data['params'], $filter_data['types']);
        $customers = [];
        
        while ($row = $result->fetch_assoc()) {
            // Get related data dengan query terpisah untuk setiap record
            $row = $this->enrichCustomerData($row);
            $customers[] = $row;
        }
        
        return [
            'data' => $customers,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ];
    }
    
    /**
     * Enrich customer data dengan data terkait
     */
    private function enrichCustomerData($customer) {
        // Initialize default values untuk semua field yang mungkin undefined
        $default_fields = [
            'nama_paket' => null, 'harga' => 0, 'rate_limit_rx' => null, 'rate_limit_tx' => null,
            'nama_odp' => null, 'odp_lokasi' => null, 'odp_status' => null,
            'odp_port_name' => null, 'port_status' => null,
            'nama_pop' => null, 'nama_olt' => null,
            'unpaid_bills' => 0, 'payment_count' => 0, 'active_connections' => 0
        ];
        
        $customer = array_merge($default_fields, $customer);
        
        // Get paket data
        if (!empty($customer['id_paket'])) {
            $paket_sql = "SELECT nama_paket, harga, rate_limit_rx, rate_limit_tx FROM paket_internet WHERE id_paket = ?";
            $paket_result = $this->executeQuery($paket_sql, [$customer['id_paket']], 'i');
            if ($paket_data = $paket_result->fetch_assoc()) {
                $customer = array_merge($customer, $paket_data);
            }
        }
        
        // Get ODP data
        if (!empty($customer['odp_id'])) {
            $odp_sql = "SELECT nama_odp, lokasi, status as odp_status FROM ftth_odp WHERE id = ?";
            $odp_result = $this->executeQuery($odp_sql, [$customer['odp_id']], 'i');
            if ($odp_data = $odp_result->fetch_assoc()) {
                $customer['nama_odp'] = $odp_data['nama_odp'];
                $customer['odp_lokasi'] = $odp_data['lokasi'];
                $customer['odp_status'] = $odp_data['odp_status'];
            }
        }
        
        // Get ODP Port data
        if (!empty($customer['odp_port_id'])) {
            $port_sql = "SELECT port_name, status FROM ftth_odp_ports WHERE id = ?";
            $port_result = $this->executeQuery($port_sql, [$customer['odp_port_id']], 'i');
            if ($port_data = $port_result->fetch_assoc()) {
                $customer['odp_port_name'] = $port_data['port_name'];
                $customer['port_status'] = $port_data['status'];
            }
        }
        
        // Skip infrastructure data (POP/OLT) - tidak diperlukan untuk list view
        // Data POP/OLT hanya ditampilkan di detail page jika diperlukan
        
        // Get counts dengan query terpisah
        $customer['unpaid_bills'] = $this->getCount("SELECT COUNT(*) FROM tagihan WHERE id_pelanggan = ? AND status_tagihan IN ('belum_bayar', 'terlambat')", $customer['id_pelanggan']);
        $customer['payment_count'] = $this->getCount("SELECT COUNT(*) FROM pembayaran WHERE id_pelanggan = ?", $customer['id_pelanggan']);
        $customer['active_connections'] = $this->getCount("SELECT COUNT(*) FROM monitoring_pppoe WHERE id_pelanggan = ? AND status = 'active'", $customer['id_pelanggan']);
        
        return $customer;
    }
    
    /**
     * Helper untuk mendapatkan count
     */
    private function getCount($sql, $id) {
        $result = $this->executeQuery($sql, [$id], 'i');
        return (int)$result->fetch_row()[0];
    }
    
    /**
     * Get dropdown options with caching
     */
    public function getDropdownOptions($type) {
        if (isset($this->cache[$type])) {
            return $this->cache[$type];
        }
        
        $queries = [
            'paket' => "SELECT pi.id_paket, pi.nama_paket FROM paket_internet pi WHERE pi.status_paket = 'aktif' ORDER BY pi.nama_paket",
            'odp' => "SELECT odp.id, odp.nama_odp, odp.lokasi FROM ftth_odp odp WHERE odp.status = 'active' ORDER BY odp.nama_odp"
        ];
        
        if (!isset($queries[$type])) return [];
        
        $result = $this->db->query($queries[$type]);
        $options = [];
        while ($row = $result->fetch_assoc()) {
            $options[] = $row;
        }
        
        $this->cache[$type] = $options;
        return $options;
    }
    
    /**
     * Update customer status
     */
    public function updateStatus($id, $status) {
        $valid_statuses = ['aktif', 'nonaktif', 'isolir'];
        if (!in_array($status, $valid_statuses)) {
            throw new Exception("Status tidak valid");
        }
        
        $stmt = $this->db->prepare("UPDATE data_pelanggan SET status_aktif = ? WHERE id_pelanggan = ?");
        $stmt->bind_param("si", $status, $id);
        return $stmt->execute();
    }
    
    /**
     * Execute prepared query helper
     */
    private function executeQuery($sql, $params = [], $types = '') {
        if (empty($params)) {
            return $this->db->query($sql);
        }
        
        $stmt = $this->db->prepare($sql);
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result();
    }
}

/**
 * UI Helper Class - Handles all formatting and display logic
 */
class UIHelper {
    public static function formatRupiah($amount) {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
    
    public static function formatDate($date) {
        return date('d/m/Y', strtotime($date));
    }
    
    public static function getStatusBadge($status) {
        $badges = [
            'aktif' => '<span class="badge badge-success"><i class="fa fa-check-circle"></i> Aktif</span>',
            'nonaktif' => '<span class="badge badge-secondary"><i class="fa fa-times-circle"></i> Non Aktif</span>',
            'isolir' => '<span class="badge badge-warning"><i class="fa fa-exclamation-triangle"></i> Isolir</span>'
        ];
        return $badges[$status] ?? '<span class="badge badge-secondary">Unknown</span>';
    }
    
    public static function truncateText($text, $length = 35) {
        return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
    }
}

/**
 * Page Controller - Handles request processing
 */
class PageController {
    private $customerService;
    private $error = '';
    private $success = '';
    
    public function __construct($mysqli) {
        $this->customerService = new CustomerService($mysqli);
        $this->handleMessages();
        $this->handleActions();
    }
    
    private function handleMessages() {
        if (isset($_SESSION['success_message'])) {
            $this->success = $_SESSION['success_message'];
            unset($_SESSION['success_message']);
        }
        
        if (isset($_SESSION['error_message'])) {
            $this->error = $_SESSION['error_message'];
            unset($_SESSION['error_message']);
        }
    }
    
    private function handleActions() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) return;
        
        $action = $_POST['action'];
        $id = (int)($_POST['id_pelanggan'] ?? 0);
        
        if ($id <= 0) return;
        
        try {
            $success_messages = [
                'activate' => 'Pelanggan berhasil diaktifkan!',
                'deactivate' => 'Pelanggan berhasil dinonaktifkan!',
                'isolir' => 'Pelanggan berhasil diisolir!'
            ];
            
            if (isset($success_messages[$action])) {
                $this->customerService->updateStatus($id, $action === 'activate' ? 'aktif' : 
                    ($action === 'deactivate' ? 'nonaktif' : 'isolir'));
                $this->success = $success_messages[$action];
            }
        } catch (Exception $e) {
            $this->error = "Error: " . $e->getMessage();
        }
    }
    
    public function getFilters() {
        return [
            'search' => trim($_GET['search'] ?? ''),
            'status_aktif' => $_GET['status'] ?? '',
            'id_paket' => $_GET['paket'] ?? '',
            'odp_id' => $_GET['odp'] ?? ''
        ];
    }
    
    public function getData() {
        $filters = $this->getFilters();
        $page = max(1, (int)($_GET['page'] ?? 1));
        
        return [
            'customers' => $this->customerService->getCustomers($filters, $page),
            'paket_options' => $this->customerService->getDropdownOptions('paket'),
            'odp_options' => $this->customerService->getDropdownOptions('odp'),
            'filters' => $filters,
            'page' => $page,
            'error' => $this->error,
            'success' => $this->success
        ];
    }
}

// Initialize controller and get data
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    $controller = new PageController($mysqli);
    $data = $controller->getData();
    extract($data); // Extract variables for template compatibility
    
    // For backward compatibility
    $pelanggan_list = $customers['data'];
    $total_pelanggan = $customers['total'];
    $total_pages = $customers['total_pages'];
    $search = $filters['search'];
    $status_filter = $filters['status_aktif'];
    $paket_filter = $filters['id_paket'];
    $odp_filter = $filters['odp_id'];
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    // Helper functions for template compatibility
    function format_rupiah($amount) { return UIHelper::formatRupiah($amount); }
    function format_date($date) { return UIHelper::formatDate($date); }
    function getStatusBadge($status) { return UIHelper::getStatusBadge($status); }
    
} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    $pelanggan_list = [];
    $total_pelanggan = 0;
    $paket_options = [];
    $odp_options = [];
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
.card { border: none; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); margin-bottom: 20px; background: white; border-radius: 6px; }
.card-header { background-color: white; border-bottom: 1px solid #e9ecef; padding: 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 6px 6px 0 0; }
.card-header h5 { font-size: 16px; font-weight: 500; color: var(--dark); margin: 0; }
.card-body { padding: 20px; }
.alert { padding: 12px 20px; font-size: 14px; border: none; margin-bottom: 20px; border-radius: 4px; }
.alert-danger { background-color: rgba(237, 85, 101, 0.1); color: var(--danger); border-left: 4px solid var(--danger); }
.alert-success { background-color: rgba(38, 185, 154, 0.1); color: var(--success); border-left: 4px solid var(--success); }
.alert-warning { background-color: rgba(248, 172, 89, 0.1); color: var(--warning); border-left: 4px solid var(--warning); }
.alert-info { background-color: rgba(35, 198, 200, 0.1); color: var(--info); border-left: 4px solid var(--info); }
.btn { font-size: 14px; padding: 8px 16px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s ease-in-out; text-decoration: none; display: inline-block; text-align: center; border-radius: 4px; }
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
.btn-outline-primary { border: 1px solid var(--primary); color: var(--primary); background: white; }
.btn-outline-primary:hover { background-color: var(--primary); color: white; }
.btn-outline-info { border: 1px solid var(--info); color: var(--info); background: white; }
.btn-outline-info:hover { background-color: var(--info); color: white; }
.btn-outline-danger { border: 1px solid var(--danger); color: var(--danger); background: white; }
.btn-outline-danger:hover { background-color: var(--danger); color: white; }
.btn-outline-secondary { border: 1px solid var(--secondary); color: var(--secondary); background: white; }
.btn-outline-secondary:hover { background-color: var(--secondary); color: white; }
.table { width: 100%; margin-bottom: 0; border-collapse: collapse; }
.table th, .table td { padding: 8px; vertical-align: middle; border-top: 1px solid #dee2e6; font-size: 12px; }
.table thead th { background-color: #f8f9fa; border-bottom: 2px solid #dee2e6; font-weight: 600; color: var(--dark); font-size: 11px; }
.table-striped tbody tr:nth-of-type(odd) { background-color: rgba(0, 0, 0, 0.02); }
.table-responsive { overflow-x: auto; }
.badge { display: inline-block; padding: 3px 6px; font-size: 10px; font-weight: 500; line-height: 1; text-align: center; white-space: nowrap; vertical-align: baseline; border-radius: 3px; }
.badge-success { background-color: var(--success); color: white; }
.badge-danger { background-color: var(--danger); color: white; }
.badge-warning { background-color: var(--warning); color: white; }
.badge-info { background-color: var(--info); color: white; }
.badge-secondary { background-color: var(--secondary); color: white; }
.form-control, .form-select { border: 1px solid #ddd; border-radius: 4px; padding: 8px 12px; font-size: 14px; transition: border-color 0.15s ease-in-out; }
.form-control:focus, .form-select:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 0.1rem rgba(26, 187, 156, 0.25); }
.form-label { font-size: 13px; font-weight: 500; color: var(--dark); margin-bottom: 5px; }
.row { display: flex; flex-wrap: wrap; margin-left: -15px; margin-right: -15px; }
.col-md-12 { flex: 0 0 100%; max-width: 100%; padding-left: 15px; padding-right: 15px; }
.col-12 { flex: 0 0 100%; max-width: 100%; padding-left: 15px; padding-right: 15px; }
.mb-3 { margin-bottom: 1rem; }
.d-flex { display: flex; }
.gap-2 { gap: 0.5rem; }
.me-1 { margin-right: 0.25rem; }
.me-2 { margin-right: 0.5rem; }
.fw-bold { font-weight: 600; }
.text-muted { color: #6c757d; }
.text-success { color: var(--success); }
.text-primary { color: var(--primary); }
.text-info { color: var(--info); }
.text-warning { color: var(--warning); }
.text-danger { color: var(--danger); }
.small { font-size: 0.875em; }
.close { background: none; border: none; font-size: 20px; cursor: pointer; color: #aaa; }
.close:hover { color: #000; }
.filter-section { background: white; padding: 20px; border-radius: 6px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); }
.filter-group { display: flex; flex-direction: column; min-width: 0; }
.filter-group:first-child { flex: 1; min-width: 280px; }
.filter-group:not(:first-child) { min-width: 140px; }
.filter-actions { display: flex; gap: 8px; align-items: end; }
.empty-state { text-align: center; padding: 60px 20px; }
.empty-state i { font-size: 48px; color: #ccc; margin-bottom: 20px; }
.btn-group-sm { display: flex; gap: 4px; }
.pagination { list-style: none; padding: 0; margin: 0; display: flex; }
.page-item { margin: 0 2px; }
.page-link { display: block; padding: 6px 12px; text-decoration: none; border: 1px solid #dee2e6; color: var(--primary); border-radius: 4px; }
.page-item.active .page-link { background-color: var(--primary); border-color: var(--primary); color: white; }
.page-link:hover { background-color: #f8f9fa; }
.justify-content-between { justify-content: space-between; }
.align-items-center { align-items: center; }
.align-items-end { align-items: flex-end; }
.g-3 > * { margin-bottom: 1rem; }
.flex-grow-1 { flex-grow: 1; }
.w-100 { width: 100%; }
.force-down { padding-top: 1.75rem; }
.warning-indicator { color: var(--warning); margin-left: 5px; }
.odp-info .text-primary { color: var(--primary) !important; }
@media (max-width: 768px) { 
    .btn-group-sm { flex-direction: column; gap: 2px; }
    .btn-sm { padding: 4px 8px; font-size: 11px; }
    .table th, .table td { font-size: 11px; padding: 6px; }
    .filter-section form { flex-direction: column; gap: 15px; }
    .filter-group { width: 100%; }
    .filter-actions { justify-content: space-between; }
    .filter-actions .btn { flex: 1; }
}
</style>

<main class="main-content" id="mainContent">
    <div class="page-title">
        <h1>Data Pelanggan</h1>
        <div class="page-subtitle">Manajemen data pelanggan FTTH</div>
    </div>

    <!-- Alert Messages -->
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
        <i class="fa fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
        <i class="fa fa-check"></i> <?php echo htmlspecialchars($success); ?>
    </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="GET" class="d-flex gap-2 align-items-end">
            <div class="filter-group">
                <label for="search_input" class="form-label">Pencarian</label>
                <input type="text" name="search" id="search_input" class="form-control"
                       placeholder="Nama, alamat, telepon..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="filter-group">
                <label for="status_select" class="form-label">Status</label>
                <select name="status" id="status_select" class="form-select">
                    <option value="">Semua</option>
                    <option value="aktif" <?= $status_filter === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                    <option value="nonaktif" <?= $status_filter === 'nonaktif' ? 'selected' : '' ?>>Non Aktif</option>
                    <option value="isolir" <?= $status_filter === 'isolir' ? 'selected' : '' ?>>Isolir</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="paket_select" class="form-label">Paket</label>
                <select name="paket" id="paket_select" class="form-select">
                    <option value="">Semua</option>
                    <?php foreach ($paket_options as $paket): ?>
                        <option value="<?= $paket['id_paket'] ?>" <?= $paket_filter == $paket['id_paket'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($paket['nama_paket']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="odp_select" class="form-label">ODP</label>
                <select name="odp" id="odp_select" class="form-select">
                    <option value="">Semua</option>
                    <?php foreach ($odp_options as $odp): ?>
                        <option value="<?= $odp['id'] ?>" <?= $odp_filter == $odp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($odp['nama_odp']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-search"></i> Filter
                </button>
                <a href="data_pelanggan.php" class="btn btn-outline-secondary">
                    <i class="fa fa-undo"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Data Table -->
    <div class="card">
        <div class="card-header">
            <h5>
                <i class="fa fa-table me-2"></i>Data Pelanggan 
                <span class="text-muted">(<?= $total_pelanggan ?> total)</span>
            </h5>
            <a href="../pelanggan/tambah_pelanggan.php" class="btn btn-primary">
                <i class="fa fa-plus"></i> Tambah Pelanggan
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($pelanggan_list)): ?>
                <div class="empty-state">
                    <i class="fa fa-users"></i>
                    <h5 class="text-muted">Tidak ada data pelanggan</h5>
                    <p class="text-muted mb-3">Silakan tambah pelanggan baru untuk memulai.</p>
                    <a href="../pelanggan/tambah_pelanggan.php" class="btn btn-primary">
                        <i class="fa fa-plus me-1"></i>Tambah Pelanggan
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th width="4%">No</th>
                                <th width="25%">Nama Pelanggan</th> 
                                <th width="12%">Paket</th>
                                <th width="10%">PPPoE</th>
                                <th width="15%">ODP & Infrastruktur</th>
                                <th width="10%">Status</th>
                                <th width="9%">Tgl Daftar</th>
                                <th width="15%">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = $offset + 1;
                            foreach ($pelanggan_list as $pelanggan): 
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td>
                                        <div class="fw-bold">
                                            <?= htmlspecialchars($pelanggan['nama_pelanggan']) ?>
                                            <?php if (($pelanggan['unpaid_bills'] ?? 0) > 0): ?>
                                                <span class="warning-indicator" title="<?= $pelanggan['unpaid_bills'] ?> tagihan belum dibayar">
                                                    <i class="fa fa-exclamation-triangle"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= htmlspecialchars(UIHelper::truncateText($pelanggan['alamat_pelanggan'] ?? '')) ?> - <?= htmlspecialchars($pelanggan['telepon_pelanggan'] ?? '') ?>
                                        </small>
                                    </td>

                                    <td>
                                        <?php if (!empty($pelanggan['nama_paket'])): ?>
                                            <div class="fw-bold"><?= htmlspecialchars($pelanggan['nama_paket']) ?></div>
                                            <small class="text-muted">
                                                <?= format_rupiah($pelanggan['harga']) ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">Belum dipilih</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($pelanggan['mikrotik_username'])): ?>
                                            <div class="fw-bold">
                                                <?= htmlspecialchars($pelanggan['mikrotik_username']) ?>
                                                <?php if (($pelanggan['active_connections'] ?? 0) > 0): ?>
                                                    <span class="text-success" title="<?= $pelanggan['active_connections'] ?> koneksi aktif">
                                                        <i class="fa fa-wifi"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">Belum diatur</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($pelanggan['nama_odp'])): ?>
                                            <div class="odp-info">
                                                <div class="fw-bold text-primary">
                                                    <i class="fa fa-network-wired"></i> 
                                                    <?= htmlspecialchars($pelanggan['nama_odp']) ?>
                                                </div>
                                                
                                                <?php if (!empty($pelanggan['odp_port_name'])): ?>
                                                    <div class="text-muted">
                                                        Port: <?= htmlspecialchars($pelanggan['odp_port_name']) ?>
                                                        <span class="badge <?= ($pelanggan['port_status'] ?? '') == 'connected' ? 'badge-success' : 'badge-secondary' ?>">
                                                            <?= ucfirst($pelanggan['port_status'] ?? 'unknown') ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($pelanggan['onu_id'])): ?>
                                                    <div class="text-info small">
                                                        ONU: <?= htmlspecialchars($pelanggan['onu_id']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">
                                                <i class="fa fa-exclamation-triangle text-warning"></i>
                                                Belum terhubung ODP
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= getStatusBadge($pelanggan['status_aktif']) ?>
                                    </td>

                                    <td>
                                        <div><?= format_date($pelanggan['tgl_daftar']) ?></div>
                                        <?php if (!empty($pelanggan['tgl_expired'])): ?>
                                            <small class="text-muted">Exp: <?= format_date($pelanggan['tgl_expired']) ?></small>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="btn-group-sm">
                                            <!-- View Button -->
                                            <a href="detail_pelanggan.php?id=<?= $pelanggan['id_pelanggan'] ?>" 
                                               class="btn btn-outline-info btn-sm" title="Detail">
                                                <i class="fa fa-eye"></i>
                                            </a>
                                            
                                            <!-- Edit Button -->
                                            <a href="edit_pelanggan.php?id=<?= $pelanggan['id_pelanggan'] ?>" 
                                               class="btn btn-outline-primary btn-sm" title="Edit">
                                                <i class="fa fa-edit"></i>
                                            </a>
                                            
                                            <!-- Delete Button - Form POST ke delete_pelanggan.php -->
                                            <form method="POST" action="delete_pelanggan.php" style="display: inline-block;" 
                                                  onsubmit="return confirmDelete('<?= htmlspecialchars($pelanggan['nama_pelanggan']) ?>', <?= $pelanggan['unpaid_bills'] ?? 0 ?>, <?= $pelanggan['payment_count'] ?? 0 ?>, <?= $pelanggan['active_connections'] ?? 0 ?>);">
                                                <input type="hidden" name="id_pelanggan" value="<?= $pelanggan['id_pelanggan'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Hapus Pelanggan">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #dee2e6;">
                        <div>
                            <small class="text-muted">
                                Menampilkan <?= $offset + 1 ?> - <?= min($offset + $limit, $total_pelanggan) ?> 
                                dari <?= $total_pelanggan ?> data
                            </small>
                        </div>
                        <nav>
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&paket=<?= urlencode($paket_filter) ?>&odp=<?= urlencode($odp_filter) ?>">
                                            <i class="fa fa-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&paket=<?= urlencode($paket_filter) ?>&odp=<?= urlencode($odp_filter) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&paket=<?= urlencode($paket_filter) ?>&odp=<?= urlencode($odp_filter) ?>">
                                            <i class="fa fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
// Simple confirm dialog for delete action
function confirmDelete(customerName, unpaidBills, paymentHistory, activeConnections) {
    let message = `Apakah Anda yakin ingin menghapus pelanggan "${customerName}"?\n\n`;
    
    if (unpaidBills > 0) {
        message += `⚠️ Pelanggan memiliki ${unpaidBills} tagihan yang belum dibayar!\n`;
    }
    
    if (paymentHistory > 0) {
        message += `📊 Pelanggan memiliki riwayat pembayaran yang akan ikut terhapus.\n`;
    }
    
    if (activeConnections > 0) {
        message += `🌐 Pelanggan sedang terhubung (${activeConnections} koneksi aktif).\n`;
    }
    
    message += `\nTindakan ini TIDAK DAPAT DIBATALKAN!`;
    
    return confirm(message);
}
</script>

<?php 
require_once __DIR__ . '/../templates/footer.php';
ob_end_flush();
?>