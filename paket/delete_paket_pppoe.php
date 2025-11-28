<?php
require_once __DIR__ . '/../config/config_database.php';
require_once __DIR__ . '/../config/config_mikrotik.php'; // Untuk koneksi ke Mikrotik

$message = '';
$message_type = '';
$id_paket = $_GET['id'] ?? null;

if (!$id_paket) {
    header('Location: list_paket_pppoe.php');
    exit();
}

$paket_to_delete = null;
// Ambil data paket sebelum dihapus untuk mendapatkan profile_name
$stmt_get_paket = $mysqli->prepare("SELECT `profile_name`, `sync_mikrotik` FROM `paket_internet` WHERE `id_paket` = ?");
$stmt_get_paket->bind_param("i", $id_paket);
$stmt_get_paket->execute();
$result_get_paket = $stmt_get_paket->get_result();
if ($result_get_paket->num_rows > 0) {
    $paket_to_delete = $result_get_paket->fetch_assoc();
}
$stmt_get_paket->close();

if ($paket_to_delete) {
    $mysqli->begin_transaction();
    try {
        // 1. Hapus dari Database
        $stmt_delete_db = $mysqli->prepare("DELETE FROM `paket_internet` WHERE `id_paket` = ?");
        $stmt_delete_db->bind_param("i", $id_paket);

        if ($stmt_delete_db->execute()) {
            // 2. Hapus dari Mikrotik jika status sync adalah 'yes' dan Mikrotik terhubung
            if ($mikrotik_connected && $paket_to_delete['sync_mikrotik'] === 'yes') {
                $profile_name_mikrotik = $paket_to_delete['profile_name'];

                // Cari Mikrotik ID berdasarkan nama profile
                $find_profile = $api->comm('/ppp/profile/print', ['?name' => $profile_name_mikrotik]);

                if (!empty($find_profile)) {
                    $mikrotik_id = $find_profile[0]['.id'];
                    $response = $api->comm('/ppp/profile/remove', ['.id' => $mikrotik_id]);

                    if (isset($response['!trap'])) {
                        // Jika ada error dari Mikrotik, rollback database
                        $mysqli->rollback();
                        $message = "Gagal menghapus paket dari Mikrotik: " . $response['!trap'][0]['message'] . ". Data di database tidak dihapus.";
                        $message_type = 'error';
                    } else {
                        $mysqli->commit();
                        $message = "Paket berhasil dihapus dari database dan Mikrotik.";
                        $message_type = 'success';
                    }
                } else {
                    // Profile tidak ditemukan di Mikrotik, tetap commit database delete
                    $mysqli->commit();
                    $message = "Paket berhasil dihapus dari database, tetapi profile tidak ditemukan di Mikrotik untuk dihapus.";
                    $message_type = 'warning';
                }
            } else {
                // Jika Mikrotik tidak terhubung atau status sync adalah 'no', hanya hapus dari database
                $mysqli->commit();
                $message = "Paket berhasil dihapus dari database. Tidak ada operasi Mikrotik yang dilakukan.";
                $message_type = 'success';
            }
        } else {
            $mysqli->rollback();
            $message = "Gagal menghapus paket dari database: " . $stmt_delete_db->error;
            $message_type = 'error';
        }
        $stmt_delete_db->close();
    } catch (Exception $e) {
        $mysqli->rollback();
        $message = "Terjadi kesalahan: " . $e->getMessage();
        $message_type = 'error';
    }
} else {
    $message = "Paket tidak ditemukan atau sudah dihapus.";
    $message_type = 'warning';
}

// Setelah selesai operasi, redirect kembali ke halaman daftar
header('Location: list_paket_pppoe.php?msg_type=' . $message_type . '&msg=' . urlencode($message));
exit();

$mysqli->close();
if ($mikrotik_connected) {
    $api->disconnect();
}
?>