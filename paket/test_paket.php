<?php
// File: test_mikrotik_params.php
// Script untuk testing parameter Mikrotik yang didukung

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config_mikrotik.php';

echo "<h2>Test Mikrotik Connection & Parameters</h2>";

if (!$mikrotik_connected) {
    echo "<p style='color:red;'>❌ Tidak dapat terhubung ke Mikrotik!</p>";
    exit;
}

echo "<p style='color:green;'>✅ Berhasil terhubung ke Mikrotik</p>";

// 1. Test: Cek PPP Profile yang sudah ada
echo "<h3>1. PPP Profiles yang sudah ada:</h3>";
try {
    $existing_profiles = $api->comm('/ppp/profile/print');
    echo "<pre>" . print_r($existing_profiles, true) . "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

// 2. Test: Cek IP Pools yang ada
echo "<h3>2. IP Pools yang ada:</h3>";
try {
    $ip_pools = $api->comm('/ip/pool/print');
    echo "<pre>" . print_r($ip_pools, true) . "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

// 3. Test: Coba buat profile dengan parameter minimal
echo "<h3>3. Test membuat profile dengan parameter minimal:</h3>";
$test_profile_name = "test-profile-" . date('His');

try {
    $test_params = [
        '=name' => $test_profile_name,
        '=local-address' => '192.168.1.1',
        '=remote-address' => '192.168.100.1-192.168.100.10',
        '=rate-limit' => '1M/512k'
    ];
    
    echo "<p>Parameter yang akan dikirim:</p>";
    echo "<pre>" . print_r($test_params, true) . "</pre>";
    
    $response = $api->comm('/ppp/profile/add', $test_params);
    echo "<p style='color:green;'>✅ Berhasil membuat test profile!</p>";
    echo "<pre>" . print_r($response, true) . "</pre>";
    
    // Hapus test profile
    $remove_response = $api->comm('/ppp/profile/remove', ['=.id' => $test_profile_name]);
    echo "<p>Test profile dihapus.</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
}

// 4. Test: Coba dengan parameter lengkap satu per satu
echo "<h3>4. Test parameter satu per satu:</h3>";

$test_profile_name2 = "test-profile2-" . date('His');
$base_params = [
    '=name' => $test_profile_name2,
    '=local-address' => '192.168.1.1',
    '=remote-address' => '192.168.100.1-192.168.100.10',
    '=rate-limit' => '1M/512k'
];

$additional_params = [
    '=dns-server' => '8.8.8.8,8.8.4.4',
    '=only-one' => 'yes',
    '=shared-users' => '1',
    '=burst-limit' => '2M/1M',
    '=burst-threshold' => '512k/256k',
    '=burst-time' => '10/10'
];

foreach ($additional_params as $key => $value) {
    $test_params = $base_params;
    $test_params[$key] = $value;
    
    echo "<p>Testing parameter: <strong>$key</strong> = $value</p>";
    
    try {
        $response = $api->comm('/ppp/profile/add', $test_params);
        echo "<p style='color:green;'>✅ Parameter $key berhasil</p>";
        
        // Hapus test profile
        $api->comm('/ppp/profile/remove', ['=.id' => $test_profile_name2]);
        
    } catch (Exception $e) {
        echo "<p style='color:red;'>❌ Parameter $key gagal: " . $e->getMessage() . "</p>";
    }
}

// Disconnect
$api->disconnect();
echo "<p>Selesai testing.</p>";
?>