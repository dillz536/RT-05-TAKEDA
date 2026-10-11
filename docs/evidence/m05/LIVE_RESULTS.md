# M05 — bukti emulator dan batas penerimaan

11 Oktober 2026. Codex menjalankan pemeriksaan nyata berikut setelah delapan
check PR17 lulus pada source `13de24e230b44dd78b382260013108b5daf1d84a`.
Build dan checksum: [CI_RESULTS.md](CI_RESULTS.md). Ini fondasi mobile M05,
bukan penerimaan layanan identitas M06 atau rilis production.

## Perangkat dan artifact

- Pixel_8a, `emulator-5554`, boot selesai, layar 1080×2400, density420.
- APK staging dari [run38135976078](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38135976078),
  SHA256 `88c2b29aba14acb1a111c3907d036795cf1c116f7812ad5fc99062568f3a176b`.
- Package `tech.morriz.takeda`; activity `.rt05_takeda.MainActivity`;
  versionName0.1.0/versionCode1; label RT05 TAKEDA.
- `aapt2 dump badging/xmltree` pada APK aktual: debuggable=true,
  allowBackup=false, network base cleartext=false; pengecualian debug hanya
  10.0.2.2 dan 127.0.0.1, tanpa includeSubdomains.
- `adb install -r` awal ditolak UPDATE_INCOMPATIBLE karena signing debug CI
  berbeda. Layar paket uji lama diperiksa berupa login kosong; hanya paket itu
  dihapus. Instalasi APK terverifikasi berikutnya Success. Tidak menghapus
  paket lain, akun nyata, atau data warga.
- Tidak membangun Gradle/APK lokal; SDK Android hanya untuk inspeksi artifact,
  instalasi dan pengoperasian emulator.

## Health HTTPS dan offline/retry

APK memakai `https://takeda-staging.morriz.tech`, tanpa proxy/mock adapter atau
bypass TLS. Startup memanggil GET `/health/live` tanpa token. Implementasi
mensyaratkan HTTP200 dan JSON status=ok; UUID dibaca dari header response.
UI ServerInfo dan logcat menunjukkan UUID yang sama.

| Skenario nyata | Hasil/logcat, waktu WIB |
| --- | --- |
| Startup online, PID22925 | 18:43:42.102 health ok; `31bace65-6b48-4e1a-9ff6-258e9fb2d5f0` |
| Airplane enable, Wi-Fi/data disable, startup PID23103 | 18:44:36.344 `RT05 health gagal: offline`; splash pesan koneksi dan tombol Coba lagi |
| Airplane disable, Wi-Fi/data enable, tap Coba lagi pada proses23103 yang sama | 18:48:47.581 health ok; `d71823ee-b78c-442f-a665-775eee609f4f`; kembali ke login kosong tanpa restart aplikasi |
| Skala teks1,3, PID23608 | 18:49:05.053 health ok; `a9a789a0-2b0f-4950-8091-a49a6aebbbc0` |
| Skala teks2,0, PID23713 | 18:49:16.566 health ok; `94bbf2f2-6e7f-42ae-af05-2c88cc0d59a5` |

Screenshot [online](screenshots/online.png), [offline](screenshots/offline.png),
dan [retry](screenshots/retry.png) berasal dari `adb shell screencap`/`adb pull`,
bukan render desain. Tidak ada email/password diisi. Request UUID ini adalah
bukti response nyata di aplikasi; **tidak diklaim telah dicocokkan dengan
access log VPS**. Logging health tambahan tidak diperlukan oleh gate M05 dan
tidak dideploy untuk mengambil bukti ini.

Preservasi token saat offline/timeout/5xx/403, penghapusan pada401/logout,
serta race sesi/storage dibuktikan oleh 14 tes session pada run77 tes CI.
Emulator memakai keadaan guest tanpa token: drill ini membuktikan error/retry
nyata, **bukan preservasi token akun nyata**. Login/seed akun hanya untuk
memenuhi skenario ini tidak dilakukan; layanan M06 bukan prasyarat M05.

## Brand, ikon dan ukuran teks

[App info Android](screenshots/app-info.png) menunjukkan ikon gapura resmi
dan label RT05 TAKEDA dari APK terpasang. Dua SVG dalam kedua APK cocok
byte-per-byte dengan sumber website; delapan font TTF tersedia dalam artifact.
Layar menggunakan warna navy/sky/kuning, font terbundel, logo resmi dan badge
STAGING. Website baseline tidak diubah.

Skala Android1,3 pada [login](screenshots/scale13.png) tetap terbaca. Skala2,0
pada [bagian atas](screenshots/scale20-top.png) dan
[bagian bawah setelah scroll](screenshots/scale20-bottom.png) menampilkan
semua isian/tombol dan ServerInfo; tombol akun tambahan membungkus dua baris.
Tidak ditemukan RenderFlex overflow, exception Flutter, atau crash pada
logcat aplikasi untuk pemeriksaan ini. Shell warga/pengurus dan lima tab
diperiksa oleh widget test lebar320/360 dan skala1,3/2,0, bukan melalui akun
bisnis di emulator. Batas label navigasi1,3 dan chip status1,5 tetap
didokumentasikan pada [MOBILE.md](../../MOBILE.md).

Catatan kosmetik: banner debug STAGING diagonal beririsan dengan jam status
bar pada emulator ini. Konten/form tetap terbaca; badge tidak tampil pada
prod. Ini bukan kegagalan koneksi atau fitur bisnis dan dicatat sebagai
perbedaan kecil terhadap tampilan acuan.

Sesudah pemeriksaan, pengaturan emulator dikembalikan dan dibaca ulang:
font_scale1.0, airplane_mode_on0, wifi_on1, mobile_data1. Aplikasi kembali
dibuka pada login kosong dengan jaringan aktif. Tidak mengubah jaringan host.

## Batas hasil

- Kedua checksum APK cocok; scan pola DSN PostgreSQL/Redis, password Neon dan
  APP_KEY pada semua ZIP member menemukan0. Ini melengkapi review/check-repo,
  bukan bukti ketiadaan semua kemungkinan secret.
- Secure-storage OS startup tidak menghasilkan exception pada aplikasi uji;
  persistensi token akun nyata belum diuji pada tahap guest ini.
- Tidak ada tes/factory Neon, migrasi baru, redeploy VPS, DNS/firewall, UAT
  mitra, signed release, SMTP atau FCM.
- Insiden credential historis tetap open; penolakan otomatis rotasi secret
  sebelumnya tidak diakali. Keberhasilan M05 tidak menyatakan production aman.
- Review independen akhir dan pembukuan gate dicatat pada
  [KIMI_ACCEPTANCE_REVIEW.md](KIMI_ACCEPTANCE_REVIEW.md) dan
  [PROGRESS.md](../../PROGRESS.md).
