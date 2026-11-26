# Checklist Rotasi Kredensial

Dokumen ini berisi **tindakan manual**. Jangan menempelkan nilai kredensial ke chat, issue, ticket, atau file yang ter-commit.

## A. Rotasi Xendit Secret Key & Callback Token (dilakukan di Dashboard Xendit)

1. Masuk ke dashboard Xendit dengan akun yang memiliki izin admin.
2. Buat **secret key baru** untuk mode yang sama (production/test sesuai environment). Jangan hapus yang lama terlebih dahulu.
3. Simpan nilai baru hanya di secret manager / panel environment hosting. Jangan tulis ke `.env` lokal yang ikut ter-sync.
4. Perbarui variabel environment aplikasi:
   - `XENDIT_SECRET_KEY`
   - `XENDIT_CALLBACK_TOKEN` (nama mengikuti `config/services.php`; cek mapping sebelum mengubah)
5. Restart/redeploy aplikasi dan worker scheduler/queue agar konfigurasi terbaca ulang.
6. Verifikasi alur sehat:
   - buat satu invoice uji melalui API client yang valid;
   - kirim webhook uji dengan callback token baru;
   - pastikan status transaksi berubah dan log webhook tercatat.
7. Setelah verifikasi sukses, **cabut/revoke kredensial lama** di dashboard Xendit.
8. Catat waktu rotasi, nama operator, dan environment di log internal (tanpa nilai kredensial).

> Callback token diverifikasi dengan `hash_equals` di `App\Http\Requests\WebhookRequest`. IP allowlist tidak dijadikan satu-satunya kontrol karena Xendit tidak menyediakan rentang IP webhook yang stabil; jika WAF Anda memiliki daftar resmi dari Xendit, pakai sebagai lapisan tambahan.

## B. Rotasi API Key Client Aplikasi

1. Buat key baru:
   ```sh
   php artisan clients:create "Nama Client"
   ```
   Command hanya menampilkan plaintext **sekali**. Salin langsung ke secret manager client.
2. Database menyimpan `api_key_hash` (SHA-256) dan `api_key_prefix`, bukan plaintext.
3. Distribusikan key baru ke client melalui kanal aman. Minta konfirmasi penerimaan.
4. Setelah client beralih, nonaktifkan client lama:
   ```sql
   UPDATE clients SET status = 'inactive' WHERE id = <id_client_lama>;
   ```
   (Lakukan melalui prosedur tim database Anda; jangan menjalankan SQL sembarangan di production.)
5. Pastikan client lama mendapat 401 dan client baru mendapat 200/201.

## C. Rotasi Status Token Transaksi

`status_token` dibuat per transaksi dan tersimpan sebagai hash + ciphertext. Tidak ada command rotasi global. Jika token diduga bocor:

1. Identifikasi `transaction_number` yang terdampak.
2. Putuskan apakah transaksi perlu dibatalkan/rekonsiliasi (lihat `payments:cancel` dan `payments:reconcile`).
3. Informasi token **tidak boleh** dikirim ulang melalui email/chat biasa; client harus membuat transaksi baru bila memerlukan token baru.

## D. Pemeriksaan Berkala

- [ ] `composer audit` tidak menampilkan advisory.
- [ ] `php artisan test` lulus setelah perubahan dependency/konfigurasi.
- [ ] `CORS_ALLOWED_ORIGINS` berisi hanya origin produksi yang disetujui.
- [ ] Tidak ada commit yang mengandung `.env`, API key, callback token, atau `APP_KEY`.
- [ ] Schedule `payments:reconcile` berjalan dan `withoutOverlapping` memakai cache lock yang benar.
