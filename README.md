# RTRWNet Billing System

Sistem billing lengkap untuk pengelolaan jaringan RT/RW Net dengan integrasi Mikrotik RouterOS.

## 🚀 Fitur Utama

- **Manajemen Pelanggan**: Pendaftaran, monitoring, dan pengelolaan data pelanggan
- **Sistem Billing**: Generate invoice otomatis, pencatatan pembayaran
- **Integrasi Mikrotik**: Sinkronisasi PPPoE users, monitoring real-time
- **Hotspot Voucher**: Generate dan manage voucher hotspot
- **Laporan Keuangan**: Dashboard analitik dan laporan lengkap
- **Multi-user**: Level admin dan operator dengan permission berbeda

## 📋 Persyaratan Sistem

- **PHP**: 8.0 atau lebih tinggi
- **MySQL**: 8.0 atau lebih tinggi  
- **Web Server**: Apache/Nginx
- **Extension PHP**: mysqli, curl, json, gd
- **Mikrotik RouterOS**: v6.x atau v7.x (opsional)

### Extension PHP yang Diperlukan
```bash
php-mysql
php-curl
php-json
php-gd
php-mbstring
```

## 🔧 Instalasi

### Instalasi Otomatis (Recommended)

1. Upload semua file ke web server
2. Akses `http://yourdomain.com/install.php`
3. Ikuti wizard instalasi:
   - Konfigurasi database
   - Setup Mikrotik (opsional)
   - Buat akun administrator
   - Konfirmasi instalasi

### Instalasi Manual

1. **Persiapan Database**
   ```sql
   CREATE DATABASE billingrtrwnet;
   ```

2. **Import Database**
   ```bash
   mysql -u username -p billingrtrwnet < database.sql
   ```

3. **Konfigurasi Database**
   
   Buat file `config/config_database.php`:
   ```php
   <?php
   $db_host = 'localhost';
   $db_user = 'username';
   $db_pass = 'password';
   $db_name = 'billingrtrwnet';
   $db_port = 3306;

   $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
   $mysqli->set_charset('utf8mb4');

   if ($mysqli->connect_error) {
       die('Database connection failed: ' . $mysqli->connect_error);
   }
   ?>
   ```

4. **Konfigurasi Mikrotik**
   
   Buat file `config/config_mikrotik.php`:
   ```php
   <?php
   $mikrotik_ip = '192.168.1.1';
   $mikrotik_user = 'admin';
   $mikrotik_pass = 'password';
   $mikrotik_port = 8728;

   require_once __DIR__ . '/routeros_api.php';
   // ... (lihat contoh lengkap di installer)
   ?>
   ```

5. **Set Permission**
   ```bash
   chmod 755 assets/images/
   chmod 755 uploads/
   chmod 755 logs/
   chmod 755 temp/
   ```

## 🔐 Keamanan

### Setelah Instalasi

1. **Hapus file installer** (untuk keamanan):
   ```bash
   rm install.php install_process.php
   ```

2. **Upload file .htaccess** (sudah disediakan)

3. **Ganti password default**:
   - Login dengan akun admin
   - Ganti password di menu Users

4. **Backup database secara berkala**

### Pengaturan .htaccess

File `.htaccess` sudah dikonfigurasi untuk:
- Blokir akses ke file konfigurasi
- Blokir akses ke direktori sensitif
- Set security headers
- Kompres file untuk performa
- Caching static files

## 🎯 Penggunaan

### Login Pertama

- **Username**: admin (sesuai yang dibuat saat instalasi)
- **Password**: (sesuai yang dibuat saat instalasi)

### Konfigurasi Awal

1. **Pengaturan Perusahaan**:
   - Menu: Settings → Company Settings
   - Isi data perusahaan lengkap
   - Upload logo perusahaan

2. **Konfigurasi Mikrotik**:
   - Menu: Settings → Mikrotik Settings
   - Test koneksi ke router
   - Sinkronisasi profiles

3. **Setup Paket Internet**:
   - Menu: Packages → Internet Packages
   - Buat paket sesuai offering
   - Sinkronisasi dengan Mikrotik

### Workflow Operasional

1. **Tambah Pelanggan Baru**:
   - Customer → Add Customer
   - Pilih paket internet
   - Generate PPPoE credentials
   - Set tanggal expired

2. **Generate Invoice**:
   - Billing → Generate Invoice
   - Atau otomatis via cron job

3. **Record Pembayaran**:
   - Billing → Payment Record
   - Input pembayaran pelanggan

4. **Monitor Koneksi**:
   - Monitoring → Active Connections
   - Lihat usage real-time

## 🔄 Cron Jobs (Optional)


## 📊 Struktur Database

### Tabel Utama:
- `data_pelanggan`: Data pelanggan dan konfigurasi PPPoE
- `paket_internet`: Paket layanan internet
- `tagihan`: Invoice/tagihan pelanggan
- `pembayaran`: Record pembayaran
- `hotspot_users`: User voucher hotspot
- `monitoring_pppoe`: Data monitoring koneksi

### Views:
- `v_dashboard_summary`: Summary untuk dashboard
- `v_laporan_tagihan`: Report tagihan
- `v_laporan_pembayaran`: Report pembayaran
- `v_monitoring_aktif`: Monitoring users aktif

## 🔧 Troubleshooting

### Database Connection Error
```
Error: Database connection failed
```
**Solusi**: 
- Cek kredensial database di `config/config_database.php`
- Pastikan MySQL service running
- Cek permission user database

### Mikrotik Connection Failed
```
Error: Gagal terhubung ke Mikrotik
```
**Solusi**:
- Cek IP, username, password Mikrotik
- Pastikan API service enabled di Mikrotik
- Cek firewall rules

### File Permission Error
```
Error: Unable to write file
```
**Solusi**:
```bash
sudo chown -R www-data:www-data /path/to/project/
sudo chmod -R 755 /path/to/project/
sudo chmod -R 777 /path/to/project/assets/images/
sudo chmod -R 777 /path/to/project/uploads/
```

### Session Issues
```
Error: Session tidak bisa dibuat
```
**Solusi**:
- Cek permission direktori session PHP
- Restart web server
- Cek setting session.save_path di php.ini

## 📝 API Integration

### Mikrotik API Commands

```php
// Get PPPoE users
$users = $api->comm('/ppp/secret/print');

// Add new PPPoE user
$api->comm('/ppp/secret/add', [
    'name' => 'username',
    'password' => 'password',
    'profile' => 'profile_name'
]);

// Monitor active sessions
$active = $api->comm('/ppp/active/print');
```

## 🆘 Support

### Log Files
- `logs/error.log`: Error sistem
- `logs/mikrotik.log`: Log koneksi Mikrotik
- `logs/payment.log`: Log transaksi

### Debug Mode
Aktifkan debug mode di `config/config_database.php`:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

### Contact Support
- Email: support@rtrwnet.id
- Documentation: [Link to detailed docs]
- Issue Tracker: [Link to issue tracker]

## 📄 License

MIT License - silakan gunakan untuk tujuan komersial dan non-komersial.

## 🔄 Update Log

### v1.0.0
- Initial release
- Basic billing system
- Mikrotik integration
- Hotspot voucher management
- Financial reporting

---

**Developed by Donie Thambas, AnuNet Development Team**

Sistem ini bersifat Open Source serta dikembangkan khusus untuk kebutuhan billing RT/RW Net di Indonesia dengan fitur-fitur yang disesuaikan dengan workflow operasional lokal dan akan dikembangkan lagi ke depan nya untuk penambahan fitur-fitur lain