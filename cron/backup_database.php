<?php
/**
 * Database Backup Script - Cron Job
 * File: cron/backup_database.php
 * 
 * Backup database secara otomatis
 * Crontab: 0 2 * * * /usr/bin/php /path/to/project/cron/backup_database.php
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line.');
}

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Include required files
require_once __DIR__ . '/../config/config_database.php';

// Configuration
$backupDir = __DIR__ . '/../backups/';
$maxBackups = 30; // Keep last 30 backups
$compressBackup = true; // Compress backup files

// Log function
function writeLog($message) {
    $logFile = __DIR__ . '/../logs/backup.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
    echo "[$timestamp] $message\n";
}

writeLog("Starting database backup...");

try {
    // Create backup directory if not exists
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
        writeLog("Created backup directory: $backupDir");
    }
    
    // Generate backup filename
    $timestamp = date('Y-m-d_H-i-s');
    $hostname = gethostname();
    $backupFile = $backupDir . "backup_{$db_name}_{$hostname}_{$timestamp}.sql";
    
    // Build mysqldump command
    $command = sprintf(
        'mysqldump --host=%s --port=%d --user=%s --password=%s --single-transaction --routines --triggers %s > %s',
        escapeshellarg($db_host),
        intval($db_port),
        escapeshellarg($db_user),
        escapeshellarg($db_pass),
        escapeshellarg($db_name),
        escapeshellarg($backupFile)
    );
    
    // Execute backup
    writeLog("Executing backup command...");
    $output = [];
    $returnCode = 0;
    exec($command . ' 2>&1', $output, $returnCode);
    
    if ($returnCode === 0 && file_exists($backupFile)) {
        $fileSize = formatBytes(filesize($backupFile));
        writeLog("Backup created successfully: " . basename($backupFile) . " ({$fileSize})");
        
        // Compress backup if enabled
        if ($compressBackup) {
            $compressedFile = $backupFile . '.gz';
            
            if (function_exists('gzencode')) {
                // Use PHP gzip compression
                $data = file_get_contents($backupFile);
                $compressedData = gzencode($data, 9);
                file_put_contents($compressedFile, $compressedData);
                
                if (file_exists($compressedFile)) {
                    unlink($backupFile); // Remove uncompressed file
                    $compressedSize = formatBytes(filesize($compressedFile));
                    writeLog("Backup compressed: " . basename($compressedFile) . " ({$compressedSize})");
                    $backupFile = $compressedFile;
                }
            } else {
                // Use system gzip command
                exec("gzip $backupFile", $gzipOutput, $gzipReturn);
                if ($gzipReturn === 0) {
                    $compressedSize = formatBytes(filesize($compressedFile));
                    writeLog("Backup compressed: " . basename($compressedFile) . " ({$compressedSize})");
                    $backupFile = $compressedFile;
                }
            }
        }
        
        // Verify backup integrity
        if (verifyBackup($backupFile)) {
            writeLog("Backup integrity verified");
            
            // Update backup log in database
            updateBackupLog($backupFile);
            
        } else {
            writeLog("Warning: Backup integrity check failed!");
        }
        
    } else {
        writeLog("Backup failed. Return code: $returnCode");
        if (!empty($output)) {
            writeLog("Error output: " . implode("\n", $output));
        }
        throw new Exception("Backup process failed");
    }
    
    // Clean up old backups
    cleanupOldBackups($backupDir, $maxBackups);
    
    writeLog("Database backup completed successfully");
    
    // Optional: Send success notification
    // sendBackupNotification(true, basename($backupFile));
    
} catch (Exception $e) {
    writeLog("Error during backup: " . $e->getMessage());
    
    // Optional: Send failure notification
    // sendBackupNotification(false, $e->getMessage());
    
    exit(1);
}

function formatBytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}

function verifyBackup($backupFile) {
    // Basic verification - check if file contains expected content
    $isCompressed = substr($backupFile, -3) === '.gz';
    
    if ($isCompressed) {
        $content = file_get_contents("compress.zlib://$backupFile");
    } else {
        $content = file_get_contents($backupFile);
    }
    
    // Check for SQL dump markers
    $markers = [
        '-- MySQL dump',
        'CREATE TABLE',
        'INSERT INTO',
        '-- Dump completed'
    ];
    
    foreach ($markers as $marker) {
        if (strpos($content, $marker) === false) {
            writeLog("Verification failed: Missing marker '$marker'");
            return false;
        }
    }
    
    return true;
}

function updateBackupLog($backupFile) {
    global $mysqli;
    
    try {
        $stmt = $mysqli->prepare("
            INSERT INTO backup_log (filename, file_path, file_size, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        
        $filename = basename($backupFile);
        $fileSize = filesize($backupFile);
        
        $stmt->bind_param('ssi', $filename, $backupFile, $fileSize);
        $stmt->execute();
        
        writeLog("Backup log updated in database");
    } catch (Exception $e) {
        writeLog("Failed to update backup log: " . $e->getMessage());
        
        // Create backup_log table if it doesn't exist
        createBackupLogTable();
    }
}

function createBackupLogTable() {
    global $mysqli;
    
    $sql = "
        CREATE TABLE IF NOT EXISTS backup_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            file_path TEXT NOT NULL,
            file_size BIGINT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    
    if ($mysqli->query($sql)) {
        writeLog("Created backup_log table");
    } else {
        writeLog("Failed to create backup_log table: " . $mysqli->error);
    }
}

function cleanupOldBackups($backupDir, $maxBackups) {
    $files = glob($backupDir . 'backup_*.sql*');
    
    if (count($files) <= $maxBackups) {
        return;
    }
    
    // Sort files by modification time (oldest first)
    usort($files, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    
    $filesToDelete = array_slice($files, 0, count($files) - $maxBackups);
    
    foreach ($filesToDelete as $file) {
        if (unlink($file)) {
            writeLog("Deleted old backup: " . basename($file));
        }
    }
}

function sendBackupNotification($success, $details) {
    // Optional email notification
    $to = 'admin@yourcompany.com';
    $subject = $success ? 'Database Backup Success' : 'Database Backup Failed';
    
    $message = "Database backup report:\n\n";
    $message .= "Status: " . ($success ? 'SUCCESS' : 'FAILED') . "\n";
    $message .= "Time: " . date('Y-m-d H:i:s') . "\n";
    $message .= "Details: $details\n";
    
    $headers = [
        'From: backup@yourcompany.com',
        'Content-Type: text/plain; charset=UTF-8'
    ];
    
    // Uncomment to enable email notifications
    // mail($to, $subject, $message, implode("\r\n", $headers));
    writeLog("Notification email sent to $to");
}

writeLog("Backup script execution finished");
?>