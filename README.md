# VPN and WhatsApp API Manager

Panduan cepat instalasi aplikasi pada server lokal maupun VPS.

## 1. Persiapan Server

Pastikan komponen berikut tersedia:
- PHP 8.1 atau lebih baru
- MySQL atau MariaDB
- Web server Apache atau Nginx
- Ekstensi PHP: pdo_mysql, curl, json, mbstring

Untuk pengguna Windows, direkomendasikan memakai XAMPP.

## 2. Letakkan Project

Contoh untuk XAMPP Windows:
- Salin project ke folder htdocs
- Lokasi akhir contoh: F:/xampp/htdocs/templatemo

## 3. Buat Database dan Import SQL

1. Buat database baru dengan nama:
- vpn_wa_manager

2. Import file SQL berikut:
- vpn_wa_manager.sql

File SQL ini sudah berisi:
- Struktur tabel aplikasi
- Tabel queue WA (wa_message_queue)
- Seed role dan pengaturan default
- Akun admin awal

## 4. Konfigurasi Environment

Edit file .env di root project.

Parameter minimum yang wajib dicek:
- DB_HOST
- DB_NAME
- DB_USER
- DB_PASS
- APP_URL
- GOWA_BASE_URL
- GOWA_USERNAME
- GOWA_PASSWORD
- CRON_SECRET

Contoh APP_URL lokal:
- http://localhost/templatemo

Catatan:
- Ubah semua kredensial default sebelum produksi.
- Pastikan APP_URL sesuai domain/path aplikasi saat deploy.

## 5. Jalankan Aplikasi

1. Start Apache dan MySQL.
2. Buka halaman login:
- http://localhost/templatemo/login.php

Akun admin default dari seed SQL:
- admin / admin
- admin@local / admin

Segera ganti password admin setelah login pertama.

## 6. Konfigurasi WA API dari Dashboard

Masuk ke menu:
- Admin -> Config -> Pengaturan WA API

Lakukan pengaturan:
- Server WA API (GoWA)
- Basic Auth username/password
- Default device admin
- Runtime WA (delay, burst limit, queue)

Runtime WA sekarang bisa diatur dari panel admin dan disimpan ke database app_settings.

## 6.1 Konfigurasi VPN API (FastAPI L2TP/IPsec)

Masuk ke menu:
- Admin -> Config -> Pengaturan VPN API

Set nilai sesuai server FastAPI:
- Server VPN API: contoh `http://SERVER_IP:8080`
- Auth Scheme: `basic`
- Basic Auth Username: dari `.env` server VPN (`API_USERNAME`)
- Basic Auth Password: dari `.env` server VPN (`API_PASSWORD`)
- Endpoint Users: `/users`
- Endpoint Port Forwardings: `/port-forwardings`

Endpoint yang dipakai backend VPN API:
- `POST /users` (create user)
- `POST /users/{username}/disable` (disable user)
- `DELETE /users/{username}` (delete user)
- `POST /users/{username}/disconnect` (disconnect user)
- `POST /port-forwardings` (create NAT)
- `DELETE /port-forwardings/{name}` (delete NAT)

Catatan:
- Untuk server pada dokumen integrasi, semua endpoint memakai HTTP Basic Auth.
- Gunakan HTTPS/reverse proxy pada produksi agar kredensial tidak terkirim plain text.

## 6.2 VPN Management (Halaman Terpisah)

Modul pengelolaan VPN sekarang tersedia sebagai halaman khusus:
- Admin: `admin/vpn-users.php`
- User: `user/vpn-users.php`
- Redirect otomatis by role: `vpn-users.php`

Fitur yang tersedia di halaman ini:
- Create user VPN (username + password + IP static)
- Disable user VPN
- Delete user VPN
- Disconnect user VPN (best effort)
- Create port forwarding NAT
- Delete port forwarding NAT

Data tersimpan di database:
- `vpn_users`
- `vpn_port_forwardings`

## 7. Setup Cron Wajib

Aplikasi memiliki 2 cron utama:

1. Auto reconnect device WA
- File: api/cron-reconnect.php
- Fungsi: cek status device dan reconnect otomatis jika perlu

2. Queue worker WA
- File: api/cron-process-wa-queue.php
- Fungsi: memproses antrean pesan WA

### Contoh cron Linux

Jalankan reconnect tiap 5 menit:
*/5 * * * * php /path/to/templatemo/api/cron-reconnect.php >> /var/log/wa-reconnect.log 2>&1

Jalankan queue worker tiap 1 menit:
*/1 * * * * php /path/to/templatemo/api/cron-process-wa-queue.php >> /var/log/wa-queue-worker.log 2>&1

### Contoh Task Scheduler Windows

Buat 2 task terpisah:

Task 1 (reconnect, tiap 5 menit):
- Program/script: F:/xampp/php/php.exe
- Arguments: F:/xampp/htdocs/templatemo/api/cron-reconnect.php

Task 2 (queue worker, tiap 1 menit):
- Program/script: F:/xampp/php/php.exe
- Arguments: F:/xampp/htdocs/templatemo/api/cron-process-wa-queue.php

## 8. Cara Cek Instalasi Berhasil

Checklist:
- Halaman login terbuka normal
- Bisa login akun admin
- Menu admin dan config dapat diakses
- Simpan konfigurasi WA berhasil
- Cron reconnect berjalan tanpa error
- Queue worker memproses data pending

## 9. Catatan Keamanan Produksi

Wajib dilakukan sebelum go live:
- Ganti password admin default
- Ganti DB_PASS dan kredensial GoWA
- Set CRON_SECRET dengan nilai acak kuat
- Nonaktifkan akses publik yang tidak diperlukan
- Gunakan HTTPS

## 10. Troubleshooting Singkat

1. Error 403 pada endpoint cron HTTP
- Pastikan token pada URL sama persis dengan CRON_SECRET di .env

2. Login gagal meskipun data benar
- Pastikan database yang dipakai sesuai DB_NAME
- Pastikan import SQL berhasil penuh

3. Queue tidak berjalan
- Pastikan task cron queue worker aktif
- Cek log task scheduler atau log file worker

4. Device tidak reconnect otomatis
- Pastikan cron reconnect aktif
- Pastikan konfigurasi GoWA valid
