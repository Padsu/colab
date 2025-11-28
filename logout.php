<?php
session_start(); // Mulai sesi

// Hapus semua variabel sesi
$_SESSION = array();

// Jika ingin menghapus cookie sesi, hapus juga cookie-nya.
// Perhatikan bahwa ini akan mengharuskan penggunaan nama sesi standar
// atau mengetahui nama sesi kustom.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Hancurkan sesi
session_destroy();

// Arahkan pengguna ke halaman login atau halaman utama
header("Location: login.php"); // Ganti 'login.php' dengan halaman yang Anda inginkan
exit;
?>