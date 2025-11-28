<?php
/**
 * Auto Invoice Generator - Cron Job
 * File: cron/auto_invoice.php
 * 
 * Jalankan setiap hari untuk generate invoice otomatis
 * Crontab: 0 6 * * * /usr/bin/php /path/to/project/cron/auto_invoice.php
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line.');
}

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Include required files
require_once __DIR__ . '/../config/config_database.php';

// Log function
function writeLog($message) {
    $logFile = __DIR__ . '/../logs/auto_invoice.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
    echo "[$timestamp] $message\n";
}

writeLog("Starting auto invoice generation...");

try {
    // Call stored procedure to generate invoices
    $result = $mysqli->query("CALL sp_auto_generate_invoices()");
    
    if ($result) {
        $row = $result->fetch_assoc();
        writeLog("Invoice generation completed: " . $row['result']);
        
        // Send notification email if needed
        if (strpos($row['result'], 'Successfully generated') !== false) {
            $count = filter_var($row['result'], FILTER_SANITIZE_NUMBER_INT);
            if ($count > 0) {
                writeLog("$count new invoices generated successfully");
                
                // Optional: Send email notification to admin
                // sendNotificationEmail($count);
            } else {
                writeLog("No new invoices to generate today");
            }
        }
    } else {
        writeLog("Error calling stored procedure: " . $mysqli->error);
    }
    
    // Update overdue invoices status
    $updateQuery = "
        UPDATE tagihan 
        SET status_tagihan = 'terlambat' 
        WHERE tgl_jatuh_tempo < CURDATE() 
        AND status_tagihan = 'belum_bayar'
    ";
    
    if ($mysqli->query($updateQuery)) {
        $affectedRows = $mysqli->affected_rows;
        if ($affectedRows > 0) {
            writeLog("Updated $affectedRows overdue invoices to 'terlambat' status");
        }
    } else {
        writeLog("Error updating overdue invoices: " . $mysqli->error);
    }
    
    // Clean up old log files (keep last 30 days)
    cleanupLogs();
    
    writeLog("Auto invoice generation completed successfully");
    
} catch (Exception $e) {
    writeLog("Error during auto invoice generation: " . $e->getMessage());
    exit(1);
}

function cleanupLogs() {
    $logDir = __DIR__ . '/../logs/';
    $cutoffDate = strtotime('-30 days');
    
    if (is_dir($logDir)) {
        $files = glob($logDir . '*.log');
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffDate) {
                unlink($file);
                writeLog("Deleted old log file: " . basename($file));
            }
        }
    }
}

function sendNotificationEmail($count) {
    // Optional email notification
    // Implement if email functionality is needed
    
    $to = 'admin@yourcompany.com';
    $subject = 'Auto Invoice Generation Report';
    $message = "
        Auto invoice generation completed.
        
        Generated: $count new invoices
        Date: " . date('Y-m-d H:i:s') . "
        
        Please check the billing system for details.
    ";
    
    $headers = [
        'From: noreply@yourcompany.com',
        'Content-Type: text/plain; charset=UTF-8'
    ];
    
    // Uncomment to enable email notifications
    // mail($to, $subject, $message, implode("\r\n", $headers));
    writeLog("Email notification sent to $to");
}

writeLog("Script execution finished");
?>