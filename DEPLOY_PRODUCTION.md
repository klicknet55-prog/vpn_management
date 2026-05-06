# Deploy Produksi - Checklist Singkat

Dokumen ini adalah SOP ringkas untuk go-live aplikasi.

## 1. Persiapan Server

- Pastikan PHP 8.1+ terpasang
- Pastikan MySQL/MariaDB aktif
- Pastikan ekstensi PHP aktif: pdo_mysql, curl, json, mbstring
- Pastikan timezone server sudah benar

## 2. Deploy Kode

- Upload project ke web root
- Pastikan permission file/folder benar
- Pastikan folder uploads/ dapat ditulis web server

## 3. Setup Database

- Buat database produksi
- Import schema dari vpn_wa_manager.sql
- Verifikasi tabel penting ada:
  - users
  - user_roles
  - wa_accounts
  - wa_message_queue
  - api_configurations
  - app_settings

## 4. Hardening .env (Wajib)

Periksa dan ubah semua nilai berikut:

- DB_HOST
- DB_NAME
- DB_USER
- DB_PASS (jangan default)
- APP_URL (harus URL produksi)
- GOWA_BASE_URL
- GOWA_USERNAME
- GOWA_PASSWORD
- GOWA_ADMIN_SEND_DEVICE_ID
- CRON_SECRET (nilai acak kuat)

Rekomendasi:
- Jangan commit .env ke repository
- Batasi akses file .env dari web server

## 5. Ganti Akun Default

Setelah login pertama:

- Login dengan admin default
- Ganti password semua akun default
- Buat akun admin operasional baru
- Nonaktifkan atau ubah akun default jika tidak dipakai

## 6. Aktifkan HTTPS

- Pasang SSL/TLS valid
- Redirect semua HTTP ke HTTPS
- Pastikan APP_URL memakai https://

## 7. Cron yang Harus Aktif

Pastikan semua cron berikut aktif:

1. `api/cron-reconnect.php` (setiap 5 menit)
2. `api/cron-process-wa-queue.php` (setiap 1 menit)
3. `api/cron-expire-payments.php` (setiap 30 menit)
4. `api/cron-expire-subscriptions.php` (setiap 1 jam)
5. `api/cron-notify-expiring.php` (setiap hari jam 08:00)

## 7.1 Cron reconnect (setiap 5 menit)

Linux:
*/5 * * * * php /path/to/templatemo/api/cron-reconnect.php >> /var/log/wa-reconnect.log 2>&1

Windows Task Scheduler:
- Program: php.exe
- Argumen: /path/to/templatemo/api/cron-reconnect.php
- Interval: 5 menit

## 7.2 Cron queue worker (setiap 1 menit)

Linux:
*/1 * * * * php /path/to/templatemo/api/cron-process-wa-queue.php >> /var/log/wa-queue-worker.log 2>&1

Windows Task Scheduler:
- Program: php.exe
- Argumen: /path/to/templatemo/api/cron-process-wa-queue.php
- Interval: 1 menit

## 7.3 Cron expire payments (setiap 30 menit)

Linux:
30 * * * * php /path/to/templatemo/api/cron-expire-payments.php >> /var/log/cron-payments.log 2>&1

Windows Task Scheduler:
- Program: php.exe
- Argumen: /path/to/templatemo/api/cron-expire-payments.php
- Interval: 30 menit

## 7.4 Cron expire subscriptions (setiap 1 jam)

Linux:
0 * * * * php /path/to/templatemo/api/cron-expire-subscriptions.php >> /var/log/cron-subscriptions.log 2>&1

Windows Task Scheduler:
- Program: php.exe
- Argumen: /path/to/templatemo/api/cron-expire-subscriptions.php
- Interval: 1 jam

## 7.5 Cron notify expiring (setiap hari jam 08:00)

Linux:
0 8 * * * php /path/to/templatemo/api/cron-notify-expiring.php >> /var/log/cron-notify.log 2>&1

Windows Task Scheduler:
- Program: php.exe
- Argumen: /path/to/templatemo/api/cron-notify-expiring.php
- Interval: harian 08:00

## 8. Verifikasi Runtime WA

Di Admin Config:
- Cek endpoint GoWA, user, password
- Set default device admin
- Cek delay, burst limit, pause burst
- Tentukan queue default admin/user

Rekomendasi awal:
- Admin queue: aktif
- User queue: nonaktif (flexible per request)

## 9. Validasi Go-Live

Checklist final:

- Halaman login bisa diakses dari domain produksi
- Login admin berhasil
- Test kirim WA direct berhasil
- Test kirim WA queued berhasil
- Queue worker memproses pending job
- Auto reconnect berjalan
- Expire payments berjalan (pending lama menjadi expired)
- Expire subscriptions berjalan (subscription lewat masa aktif menjadi nonaktif)
- Reminder expiring berjalan (WA H-3/H-1 terkirim)
- Tidak ada error fatal di log PHP/web server

## 10. Monitoring & Backup

- Aktifkan rotasi log
- Backup database harian
- Pantau ukuran tabel wa_message_queue dan audit_logs
- Bersihkan data lama berkala (retensi sesuai kebutuhan)

## 11. Incident Quick Actions

Jika queue menumpuk:
- Cek cron worker aktif
- Cek kredensial GoWA
- Cek koneksi DB

Jika cron HTTP 403:
- Cek token URL sama dengan CRON_SECRET

Jika login gagal:
- Cek DB_NAME/DB_USER/DB_PASS di .env
- Pastikan import SQL sukses
