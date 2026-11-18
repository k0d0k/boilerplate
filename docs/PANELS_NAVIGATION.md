# Three-Panel Navigation and Page Map

The [v3.1 TailAdmin and Principal-only CRUD Generator addendum](ARCHITECTURE_ADDENDUM_V3_1_TAILADMIN_CRUD_GENERATOR.md) defines the target staff UI. Existing Filament pages serve features until their TailAdmin replacements are ready.

**Panel A:** `/principal` · platform governance · only Principal-role users.
**Panel B:** `/clinic` · tenant/branch operations · only authorized staff.
**Panel C:** `/patient` · own self-service · only scoped patient/customer identities.

## Principal Panel — TailAdmin target

| Sidebar | UI | Module / permission |
|---|---|---|
| Ringkasan Platform | Dashboard Widgets | Platform analytics / principal.* |
| Brand & Mitra | Resource | Brands + Tenants |
| Klinik & Cabang | Resource | Tenant metadata/branch counts; NOT clinical records |
| Paket Langganan | Resources | Plans + Subscriptions |
| Penggunaan & Token AI | Resource + Widgets | Tenant wallet + usage aggregates |
| Manajer Modul | Custom page | Trusted modules / principal.module.manage |
| Pengembangan Modul → CRUD Generator | Generator preview and export | Active account with an active `principal_super_admin` assignment only; allowlisted central master data |
| Hak Akses Fitur | Resource | Entitlements by plan/brand/tenant/branch |
| Penyedia Pembayaran | Resource | Gateway contract/merchant mapping with secrets redacted |
| Analitik Bisnis | Dashboard | Non-clinical aggregates |
| Audit Sistem | Resource | Platform audit events |
| Pengaturan Platform | Custom Page | Global non-clinical settings |

## Clinic / Mitra Panel — TailAdmin target

| Sidebar | UI | Module |
|---|---|---|
| Dasbor Klinik | Widgets | Clinic analytics |
| Pasien | Resource + Profile custom page | Patients |
| Dokter & Tenaga Ahli | Resource | Staff/Consultants |
| Janji Temu | Resource + Calendar custom page | Bookings |
| Lokasi Pemeriksaan | Resources | ClinicLocations |
| Acara & Pemesanan | Resources | Bookings/Events |
| Pemeriksaan LBA > Semua Pemeriksaan | Resource | LBA |
| Pemeriksaan LBA > Analisis Manual | Filtered page | LBA |
| Pemeriksaan LBA > Analisis AI | Filtered page | LBA |
| Pemeriksaan LBA > Analisis Hybrid | Filtered page | LBA |
| Pemeriksaan LBA > Tinjauan & Hasil | Resource / approval Inbox | LBA |
| LBA Microscope Workspace | Workflow page | LBA + Livewire/JS viewer |
| Konsultasi | Resource + Custom Page | Consultations |
| SINA AI | Custom Page | SINAConsult |
| Produk & Pesanan | Resources | Commerce |
| POS & Inventori | Custom Page/Resources | commerce add-on (later) |
| Laporan | Widgets/Resource | Reports |
| Token & Tagihan | Resources | Wallet |
| Tim & Cabang | Resources | Staff |
| Pengaturan Klinik | Custom Page | Tenant settings |

**Show module menu items only when installed, healthy, entitled, subscribed and authorized.** During transition, use a single canonical entry per feature. Enforce the generator role on its Principal routes and actions.

## Patient Panel — existing Filament portal

`Beranda` · `Cari Lokasi LBA` · `Booking Pemeriksaan` · `Janji Saya` · `Konsultasi Tenaga Ahli` · `Chat SINA` · `Produk` · `Keranjang & Pesanan` · `Dompet Token` · `Hasil Pemeriksaan Saya` · `Profil`.

Only approved report downloads are visible; self-only resource authorization on both page and API. Portal may use custom Filament Pages with a mobile-first layout.

## Registration contract

- Panel IDs fixed: `principal`, `clinic`, `patient`.
- Module manifest declares `panels`: list of allowable IDs and minimum permission/entitlement keys.
- Existing Filament screens use their module `FilamentRegistration` definitions. Migrated staff screens register TailAdmin routes and navigation with the same module entitlements and authorization checks.
- Panel registration honors panel scope and resource policies.
- TailAdmin, Filament and the REST API invoke the same domain workflows.
