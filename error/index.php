<?php
// error/index.php - Proteksi direktori error
// Path: /error/index.php

// Redirect ke halaman utama jika mengakses direktori error langsung
header('Location: ../index.php/');
exit;
?>

---

# error/.htaccess - Proteksi tambahan untuk direktori error
# Path: /error/.htaccess

# Proteksi direktori error
Options -Indexes

# Hanya izinkan akses ke file error tertentu
<FilesMatch "^(404|403|500)\.php$">
    Require all granted
</FilesMatch>

# Block semua file lain kecuali yang diizinkan
<FilesMatch "^(?!(404|403|500|index)\.php$).*">
    Require all denied
</FilesMatch>

# Disable PHP execution untuk file selain error pages
<FilesMatch "\.php$">
    <If "%{REQUEST_FILENAME} !~ m#/(404|403|500|index)\.php$#">
        Require all denied
    </If>
</FilesMatch>

---

<?php
// assets/css/error-common.css - Stylesheet terpisah (optional)
// Path: /assets/css/error-common.css
?>

/* Common Error Page Styles */
.error-animation {
    animation: fadeInUp 0.6s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.pulse-icon {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
    100% {
        transform: scale(1);
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .error-container {
        background: #1a1a1a;
        color: #ffffff;
    }
    
    .error-description {
        color: #cccccc;
    }
    
    .security-info, 
    .troubleshoot, 
    .contact-info {
        background: #2d2d2d;
    }
}

---

<?php
// config/error_handler.php - Custom Error Handler
// Path: /config/error_handler.php

// Custom error handler untuk logging yang lebih baik
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    $error_types = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING', 
        E_PARSE => 'PARSE',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE_ERROR',
        E_CORE_WARNING => 'CORE_WARNING',
        E_COMPILE_ERROR => 'COMPILE_ERROR',
        E_COMPILE_WARNING => 'COMPILE_WARNING',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE',
        E_STRICT => 'STRICT',
        E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER_DEPRECATED'
    ];
    
    $error_type = $error_types[$errno] ?? 'UNKNOWN';
    
    // Log error dengan format yang konsisten
    $log_message = "[{$error_type}] {$errstr} in {$errfile} on line {$errline}";
    error_log($log_message);
    
    // Redirect ke error page untuk fatal errors
    if (in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        if (!headers_sent()) {
            header('Location: /error/500.php');
            exit;
        }
    }
    
    return true;
}

// Set custom error handler
set_error_handler('customErrorHandler');

// Custom exception handler
function customExceptionHandler($exception) {
    $error_id = uniqid('EXC-');
    $log_message = "[EXCEPTION] {$error_id}: " . $exception->getMessage() . 
                   " in " . $exception->getFile() . 
                   " on line " . $exception->getLine();
    error_log($log_message);
    
    if (!headers_sent()) {
        header('Location: /error/500.php');
        exit;
    }
}

set_exception_handler('customExceptionHandler');

// Fatal error handler
function fatalErrorHandler() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $error_id = uniqid('FATAL-');
        $log_message = "[FATAL] {$error_id}: " . $error['message'] . 
                       " in " . $error['file'] . 
                       " on line " . $error['line'];
        error_log($log_message);
        
        if (!headers_sent()) {
            header('Location: /error/500.php');
            exit;
        }
    }
}

register_shutdown_function('fatalErrorHandler');
?>

---

# Update .htaccess untuk menggunakan error pages
# Tambahkan ke .htaccess utama:

# === ERROR PAGES ===
ErrorDocument 404 /error/404.php
ErrorDocument 403 /error/403.php  
ErrorDocument 500 /error/500.php

# Untuk server yang tidak support PHP error pages, gunakan HTML:
# ErrorDocument 404 /error/404.html
# ErrorDocument 403 /error/403.html
# ErrorDocument 500 /error/500.html