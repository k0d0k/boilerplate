# LBA Cloud — Architecture Plan dan Addendum v3.1

**Status keputusan arsitektur:** LOCKED · **Tanggal:** 13 November 2026 (WIB)
**Cakupan:** TailAdmin untuk panel Principal dan Clinic/Mitra, serta CRUD Generator khusus Principal Super Admin.
**Baseline yang ditinjau:** repositori `viacom-id/lba-cloud`, commit `ec870c9`.

## 1. Addendum keputusan arsitektur

Baseline v3.0 mengunci Filament 5 sebagai antarmuka tiga panel dan Filament Resource sebagai pola CRUD. Addendum ini mencatat target baru dan masa transisinya, sehingga implementasi baru punya acuan yang jelas. v3.1 mengunci target antarmuka, batas akses generator, dan alur rilisnya. Pilihan teknis yang masih perlu review dicatat secara terpisah. [Keputusan v3.0](https://github.com/viacom-id/lba-cloud/blob/ec870c9/docs/DECISIONS.md) · [Design system v3.0](https://github.com/viacom-id/lba-cloud/blob/ec870c9/docs/DESIGN_SYSTEM.md)

| Acuan | Rumusan addendum |
|---|---|
| `ARCH-01` dan design system | Laravel 13 tetap menjadi fondasi aplikasi. TailAdmin Laravel menjadi template target panel staf Principal dan Clinic. Gunakan Blade, Tailwind CSS, dan Vite untuk layout dan komponen bersama. Livewire dipakai pada interaksi yang membutuhkannya. |
| `ARCH-02` | Aplikasi tetap memiliki tiga panel dengan prefix `/principal`, `/clinic`, dan `/patient`. Masing-masing punya navigasi, dashboard, dan otorisasi sesuai fungsi. Principal dan Clinic bermigrasi ke TailAdmin per halaman. |
| `ARCH-08` | Portal Patient tetap memakai implementasi yang ada pada tahap ini. |
| `UX-02` | Principal dan Clinic berbagi komponen visual TailAdmin dengan sidebar kiri responsif. Menu, data, dan dashboard mengikuti konteks masing-masing panel. |
| Keputusan baru | CRUD Generator berada di panel Principal dan hanya dapat digunakan oleh akun aktif dengan penetapan role aktif `principal_super_admin`. Scope generator adalah master data pusat yang diizinkan secara eksplisit. |
| Integritas data | Gunakan kembali model, layanan bisnis, database pusat, database tenant terisolasi, autentikasi, RBAC, audit, dan kontrak API aplikasi. Brand, tenant, dan cabang tetap entitas yang berbeda. |

**LOCKED:** TailAdmin sebagai template admin Principal dan Clinic, migrasi per halaman, portal Patient pada implementasi saat ini, dan CRUD Generator khusus Principal Super Admin untuk master data pusat yang diizinkan.
**Pilihan teknis terbuka:** edisi TailAdmin Laravel dan paket generator. Edisi gratis dan generator internal berbasis stub Artisan adalah opsi. Master Brand adalah pilot pertama yang direncanakan. Jika tim sudah memilih paket generator, pakai persyaratan akses, keluaran, dan pengujian dalam dokumen ini untuk menilainya. [TailAdmin Laravel](https://github.com/TailAdmin/tailadmin-laravel) · [Laravel Artisan](https://laravel.com/docs/13.x/artisan#stub-customization)

## 2. Arsitektur target

| Panel | Antarmuka | Akses dan fungsi |
|---|---|---|
| **Principal** · `/principal` | TailAdmin. Dashboard tata kelola, Brand, Mitra, modul, profil. | Role Principal sesuai izin masing-masing. Menu **Pengembangan Modul → CRUD Generator** khusus Principal Super Admin. |
| **Clinic / Mitra** · `/clinic` | TailAdmin. Dashboard klinik, jadwal, pasien, pemeriksaan, review, commerce. | Staf berizin bekerja pada tenant aktif dan cabang yang diizinkan. Halaman Clinic dibangun dari komponen TailAdmin dan layanan modulnya. |
| **Patient** · `/patient` | Portal pasien yang ada. | Pasien mengakses akun, janji, pesanan, dan laporan miliknya sesuai policy. |

**Alur permintaan:** rute panel → autentikasi dan middleware konteks → controller atau komponen Livewire → policy dan layanan modul → database sesuai scope. Pada Clinic, konteks tenant diverifikasi sebelum model tenant dimuat. Akses cabang diperiksa pada fitur yang memang memiliki pembatasan cabang. Akses Principal untuk **Buka Tenant** tetap melalui tampilan dukungan terbatas pada data registri pusat. [Peta panel](https://github.com/viacom-id/lba-cloud/blob/ec870c9/docs/PANELS_NAVIGATION.md) · [Arsitektur data](https://github.com/viacom-id/lba-cloud/blob/ec870c9/docs/DATA_ARCHITECTURE.md) · [Matriks role](https://github.com/viacom-id/lba-cloud/blob/ec870c9/docs/ROLE_PERMISSION_MATRIX.md)

TailAdmin diintegrasikan sebagai layout dan komponen Blade ke aplikasi yang ada. Sediakan aset Vite khusus layout TailAdmin selama transisi. Audit penggunaan Alpine pada halaman yang memakai Livewire agar setiap halaman memuat satu runtime Alpine. Migrasikan halaman yang sudah siap lewat satu rute kanonis per fitur, dengan rute percobaan terbatas pada lingkungan pengembangan bila diperlukan. Tautan lama yang dipakai pengguna tetap punya jalur akses yang sesuai saat perpindahan.

## 3. Spesifikasi CRUD Generator

### Pengguna dan scope

- Gate generator memeriksa akun aktif dan penetapan role pusat aktif dengan key `principal_super_admin` pada setiap permintaan. Otorisasi berlaku untuk halaman, preview, dan ekspor, termasuk akses URL langsung.
- Role Principal lain, akun Clinic, dan pasien tidak dapat menggunakan generator. Role untuk mengoperasikan halaman hasil generator tetap diperiksa oleh policy resource. Contohnya, halaman Brand memakai izin yang sudah ada, `platform.brand.manage`.
- Model harus dipilih dari katalog kode yang ditinjau, dengan scope pusat dan daftar field yang diizinkan. Pilot hanya menyediakan `App\Models\Brand`. Model identitas, assignment role, kredensial, data klinis, dan ledger berada di luar katalog generator.
- Operasi standar mencakup daftar, pencarian, filter, tambah, edit, dan perubahan status bila policy mengizinkan. Operasi hapus permanen tidak tersedia pada pilot.

### Alur penggunaan

1. Principal Super Admin membuka generator, memilih model dari katalog, lalu memilih field, validasi, kolom tabel, filter, dan aksi yang diizinkan.
2. Server memvalidasi definisi terhadap katalog lalu menampilkan preview. Super Admin mengekspor definisi terstruktur sebagai JSON. Pilot cukup memakai ekspor dan tidak membutuhkan tabel baru untuk menyimpan definisi.
3. Di lingkungan development, developer menjalankan generator yang dipilih menggunakan definisi tersebut. Generator memvalidasi ulang definisi dan menghasilkan perubahan yang bisa dilihat di Git. Pengulangan dengan masukan yang sama memberi keluaran yang sama. File yang sudah ada dilaporkan sebagai konflik sebelum penulisan.
4. Developer meninjau diff dan menjalankan tes. Rilis halaman dilakukan melalui proses PR dan deployment yang berlaku.

Keluaran yang diusulkan meliputi controller, Form Request, deklarasi rute untuk direview, view Blade TailAdmin, dan referensi policy resource. Model, tabel, dan policy yang sudah ada dipakai kembali. Untuk pilot, generator hanya menerima parameter terstruktur dari pilihan yang diizinkan. Browser menangani preview dan ekspor. Penulisan source code dilakukan oleh generator di development melalui template atau stub yang ditinjau. [Laravel Authorization](https://laravel.com/docs/13.x/authorization) · [Laravel Resource Controllers](https://laravel.com/docs/13.x/controllers#resource-controllers)

## 4. Pilot Master Brand

Gunakan model `App\Models\Brand` dan tabel pusat `brands`. Field inti pilot adalah nama, slug, dan status sesuai kontrak yang ada. Cakupan awal meliputi daftar berhalaman, pencarian, filter status, tambah, edit, serta perubahan status sesuai izin. Validasi slug unik juga harus bekerja saat mengedit record yang sama. [Model Brand](https://github.com/viacom-id/lba-cloud/blob/ec870c9/app/Models/Brand.php) · [Brand Resource saat ini](https://github.com/viacom-id/lba-cloud/blob/ec870c9/app/Filament/Principal/Resources/BrandResource.php)

Brand juga menyimpan identitas laporan dan memiliki relasi tenant. Pertahankan jalur Filament untuk fitur Brand tersebut sampai tersedia padanannya. Sebelum memindahkan menu Brand utama, cek kesetaraan fungsi untuk identitas laporan, relasi tenant dan brand utama, serta aksi **Buka Tenant** dari daftar tenant di dalam Brand. Pilot tidak membutuhkan tabel Brand kedua.

## 5. Tahapan implementasi

| Tahap | Pekerjaan | Syarat selesai |
|---|---|---|
| **A — Keputusan dan baseline** | Review perubahan dokumentasi, pilih edisi TailAdmin dan pendekatan generator, lalu tetapkan commit dasar pilot. | Tim menyepakati scope generator, panel target, dan fitur pilot. Perubahan punya PR tersendiri. |
| **B — Fondasi TailAdmin** | Buat layout Principal, sidebar, topbar, komponen form/tabel, dan Vite entry. Siapkan kontrak navigasi berdasarkan izin. | Layout berjalan di desktop dan mobile. Login, logout, locale, serta profil terhubung ke jalur yang ada. |
| **C — Generator dan pilot** | Implementasikan gate, katalog pusat, preview/ekspor, stub development, dan halaman Master Brand. | Keluaran bisa direview di Git. CRUD Brand berfungsi pada tabel pusat dan pemeriksaan akses lulus. |
| **D — Perluasan Principal** | Pindahkan halaman master data pusat lainnya berdasarkan prioritas dan kesiapan layanan. | Setiap halaman punya rute kanonis, hasil uji kesetaraan, dan cara kembali ke halaman sebelumnya bila rilis perlu dibatalkan. |
| **E — TailAdmin Clinic** | Gunakan layout bersama dengan konteks tenant/cabang. Pindahkan alur operasional satu per satu. | Pengujian lintas tenant dan cabang lulus. Pemeriksaan LBA, unggah gambar, review, rilis laporan, pembayaran, dan wallet tetap memakai layanan domain masing-masing. |

Rencana ini mencakup desain dan migrasi antarmuka. Pekerjaan hosting dan penyelesaian koneksi database tenant yang sebelumnya dijeda berada pada jalur operasional terpisah.

## 6. Kriteria penerimaan pilot

- Principal Super Admin aktif dapat membuka generator, melihat preview, dan mengekspor definisi. Pengguna tanpa role tersebut dan akun yang penetapannya dicabut ditolak pada setiap endpoint.
- Definisi di luar katalog atau dengan field dan aksi yang tidak diizinkan ditolak di server. Input yang sama menghasilkan keluaran stabil. File sumber yang sudah ada tetap utuh ketika target bertabrakan.
- Brand tersimpan di database pusat. Pengguna dengan izin Brand yang sesuai tetap dapat menjalankan aksi Brand berdasarkan policy. Pengguna tanpa izin tidak dapat membaca atau mengubahnya lewat URL langsung.
- Daftar dan form bekerja di desktop/mobile, dengan state kosong, validasi, dan pesan hasil. Label baru tersedia dalam `id`, `en`, dan `ms-MY`.
- Tes login dan panel lama tetap lulus. Uji alur upload, review, dan rilis LBA kembali saat halaman Clinic mulai dipindahkan. Pemindahan rute dapat dibatalkan melalui perubahan kode tanpa perubahan skema pilot.

## 7. Sinkronisasi dokumen repositori

Acuan aktif meliputi [arsitektur](../LBA_CLOUD_ARCHITECTURE.md), [keputusan](DECISIONS.md), [design system](DESIGN_SYSTEM.md), [navigasi](PANELS_NAVIGATION.md), [matriks role](ROLE_PERMISSION_MATRIX.md), dan [rencana implementasi](IMPLEMENTATION_PLAN.md). Pertahankan riwayat v3.0 dan tandai keputusan yang digantikan agar developer dapat membedakan baseline, masa transisi, dan target.
