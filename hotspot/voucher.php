<?php
session_start();

if (!isset($_SESSION['voucher_print_data']) || empty($_SESSION['voucher_print_data']['vouchers'])) {
    echo "<div class='alert alert-warning'>Tidak ada data voucher untuk dicetak.</div>";
    exit;
}

$data = $_SESSION['voucher_print_data'];
$vouchers = $data['vouchers'];

// Load configurations
require_once __DIR__ . '/../config/config_database.php';

// Get company settings from database
$company_settings = [
    'nama_perusahaan' => 'PT. AREA NEAR URBAN NETSINDO',
    'alamat_perusahaan' => '',
    'telepon_perusahaan' => '',
    'email_perusahaan' => '',
    'logo_perusahaan' => ''
];

$result = $mysqli->query("SELECT * FROM pengaturan_perusahaan LIMIT 1");
if ($result && $result->num_rows > 0) {
    $db_settings = $result->fetch_assoc();
    $company_settings = [
        'nama_perusahaan' => $db_settings['nama_perusahaan'] ?? 'PT. AREA NEAR URBAN NETSINDO',
        'alamat_perusahaan' => $db_settings['alamat_perusahaan'] ?? '',
        'telepon_perusahaan' => $db_settings['telepon_perusahaan'] ?? '',
        'email_perusahaan' => $db_settings['email_perusahaan'] ?? '',
        'logo_perusahaan' => $db_settings['logo_perusahaan'] ?? ''
    ];
}

// Check if logo exists
$has_logo = file_exists(__DIR__ . '/../img/logo.png');
?>

<!DOCTYPE html>
<html>
<head>
    <title>Cetak Voucher (50 per A4)</title>

    <style>
        @page {
            size: A4;
            margin: 0;
        }
        body {
            font-family: 'Segoe ui', sans-serif;
            margin: 0;
            padding: 10px;
            background-color: #f9f9f9;
        }
        .page {
            width: 210mm;
            height: 297mm;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(auto, 1fr));
            grid-template-rows: repeat(auto, 1fr);
            gap: 5px;
            padding: 10px;
            box-sizing: border-box;
        }

        .voucher-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            grid-template-rows: repeat(10, 1fr);
            gap: 2px;
            width: 100%;
            height: 100%;
            padding: 2mm;
            box-sizing: border-box;
        }

        .voucher {
            border: 0.6pt solid #000;
            padding: 1mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            text-align: center;
            break-inside: avoid;
            box-sizing: border-box;
            position: relative;
            width: 95%;
            margin: 0 auto;
        }

        .voucher-header {
            font-weight: bold;
            font-size: 12pt;
            margin-bottom: 1mm;
        }

        .voucher-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
        }

        .voucher-table td {
            border: 0.4pt solid #000;
            padding: 1mm 2mm;
            font-weight: bold;
        }

        .price {
            font-size: 7.5pt;
            font-weight: bold;
            margin-top: 1mm;
        }

        .voucher-info {
            font-size: 10pt;
            width: 100%;
            margin-top: 1mm;
            text-align: left;
        }

        .voucher-info td {
            padding: 0.5mm;
            line-height: 1;
        }

        .brand {
            position: absolute;
            top: 0;
            right: 0;
            background: #000;
            color: #fff;
            writing-mode: vertical-rl;
            font-size: 8pt;
            padding: 2mm 1mm;
            font-weight: bold;
            height: 100%;
        }

        @media print {
            .back-button {
                display: none;
            }
        }

        .kredit, .password  {
            font-size: 18px;
            font-weight: bold;
        }

        .company {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 5px;
            text-align: center;
            width: 100%;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }

        .company-name {
            font-size: 14px;
            font-weight: bold;
            color: #c65900;
            margin-bottom: 3px;
            text-align: center;
            width: 100%;
        }

        .company-logo {
            text-align: center;
            margin-bottom: 3px;
        }

        .company-logo img {
            max-height: 20px;
            max-width: 80px;
        }

        .agen {
            font-size: 9px;
        }

        .contact-info {
            font-size: 7px;
            text-align: center;
            color: #666;
            margin-top: 1mm;
            line-height: 1.1;
        }
    </style>
</head>
<body>
<a href="generate_voucher.php" class="back-button">Kembali</a>

<div class="voucher-grid">
    <?php foreach ($vouchers as $v): ?>
    <div class="voucher">
        <?php if ($has_logo): ?>
        <div class="company-logo">
            <img src="../img/logo.png?v=<?= time() ?>" alt="Logo">
        </div>
        <?php else: ?>
        <div class="company-name"><?= htmlspecialchars($company_settings['nama_perusahaan']) ?></div>
        <?php endif; ?>
        
        <div class="company">VOUCHER HOTSPOT</div>
        
        <table class="voucher-table">
            <tr>
                <th>USERNAME</th>
                <th>PASSWORD</th>
            </tr>
            <tr>
                <td><span class="kredit"><?= htmlspecialchars($v['name']) ?></span></td>
                <td><span class="kredit"><?= htmlspecialchars($v['password']) ?></span></td>
            </tr>
        </table>
        
        <table class="voucher-info">
            <tr>
                <td>Profile</td><td>: <?= htmlspecialchars($v['profile']) ?></td>
            </tr>
            <tr>
                <td>Durasi</td><td>: <?= htmlspecialchars($v['uptime_limit']) ?></td>
            </tr>
            <?php if (!empty($v['data_limit'])): ?>
            <tr>
                <td>Kuota</td><td>: <?= htmlspecialchars($v['data_limit']) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td><span class="agen">Batch:</span></td><td><span class="agen"> <?= htmlspecialchars($v['comment']) ?></span></td>
            </tr>
        </table>

        <?php if (!empty($company_settings['telepon_perusahaan']) || !empty($company_settings['email_perusahaan'])): ?>
        <div class="contact-info">
            <?php if (!empty($company_settings['telepon_perusahaan'])): ?>
                Tel: <?= htmlspecialchars($company_settings['telepon_perusahaan']) ?>
            <?php endif; ?>
            <?php if (!empty($company_settings['telepon_perusahaan']) && !empty($company_settings['email_perusahaan'])): ?>
                | 
            <?php endif; ?>
            <?php if (!empty($company_settings['email_perusahaan'])): ?>
                Email: <?= htmlspecialchars($company_settings['email_perusahaan']) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<a href="generate_voucher.php" class="back-button">Kembali</a>

</body>
</html>