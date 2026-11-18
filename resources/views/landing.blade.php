<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ 'LBA Cloud — Integrated Clinical Intelligence' }}</title>
    <meta name="description" content="LBA Cloud menyatukan operasional klinik dan laboratorium LBA: pendaftaran pasien, pemeriksaan mikroskopis digital, peninjauan oleh tenaga ahli, dan laporan privat yang terkontrol.">
    <meta name="theme-color" content="#14263D">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ url('/') }}">

    {{-- Favicon: branded SVG with an .ico fallback. --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">

    {{-- Open Graph / social --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="LBA Cloud">
    <meta property="og:title" content="LBA Cloud — Integrated Clinical Intelligence">
    <meta property="og:description" content="Satu platform untuk operasional klinik dan pemeriksaan LBA digital — privat, terkontrol, dan ditinjau oleh manusia.">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="LBA Cloud — Integrated Clinical Intelligence">
    <meta name="twitter:description" content="Satu platform untuk operasional klinik dan pemeriksaan LBA digital — privat, terkontrol, dan ditinjau oleh manusia.">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="lba-landing">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-lg focus:bg-lba-navy focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">
        Lewati ke konten
    </a>

    {{--
        Header — sticky, restrained. Brand on the left, in-page anchors in the
        middle and the two verified entry points on the right. Mobile uses a
        native <details> disclosure so navigation stays keyboard accessible
        with no external runtime dependency.
    --}}
    <header class="sticky top-0 z-50 border-b border-lba-line bg-white/85 backdrop-blur">
        <div class="lba-container flex h-16 items-center justify-between gap-4">
            <a href="{{ url('/') }}" class="lba-brand" aria-label="LBA Cloud — beranda">
                <span class="lba-brand__mark" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none">
                        <path d="M16 3 27 9.5v13L16 29 5 22.5v-13L16 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        <circle cx="16" cy="16" r="4.5" fill="currentColor" />
                    </svg>
                </span>
                <span class="lba-brand__name">LBA CLOUD</span>
            </a>

            <nav class="hidden items-center gap-8 lg:flex" aria-label="Navigasi utama">
                <a href="#platform" class="text-sm font-medium text-lba-ink transition hover:text-lba-teal">Platform</a>
                <a href="#features" class="text-sm font-medium text-lba-ink transition hover:text-lba-teal">Fitur</a>
                <a href="#workflow" class="text-sm font-medium text-lba-ink transition hover:text-lba-teal">Alur Kerja</a>
            </nav>

            <div class="hidden items-center gap-3 lg:flex">
                <a href="{{ route('login') }}" class="lba-btn lba-btn--outline">Login Staff</a>
                <a href="{{ url('/patient/login') }}" class="lba-btn lba-btn--primary">Portal Pasien</a>
            </div>

            <details class="relative lg:hidden">
                <summary class="lba-btn lba-btn--outline list-none cursor-pointer [&::-webkit-details-marker]:hidden" aria-label="Buka menu navigasi">
                    <span>Menu</span>
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </summary>
                <nav class="absolute right-0 top-[calc(100%+0.5rem)] w-64 rounded-xl border border-lba-line bg-white p-2 shadow-xl" aria-label="Navigasi seluler">
                    <a href="#platform" class="block rounded-lg px-3 py-2 text-sm font-medium text-lba-ink transition hover:bg-lba-canvas hover:text-lba-teal">Platform</a>
                    <a href="#features" class="block rounded-lg px-3 py-2 text-sm font-medium text-lba-ink transition hover:bg-lba-canvas hover:text-lba-teal">Fitur</a>
                    <a href="#workflow" class="block rounded-lg px-3 py-2 text-sm font-medium text-lba-ink transition hover:bg-lba-canvas hover:text-lba-teal">Alur Kerja</a>
                    <div class="my-2 border-t border-lba-line"></div>
                    <a href="{{ route('login') }}" class="lba-btn lba-btn--outline mb-2 w-full">Login Staff</a>
                    <a href="{{ url('/patient/login') }}" class="lba-btn lba-btn--primary w-full">Portal Pasien</a>
                </nav>
            </details>
        </div>
    </header>

    <main id="main">
        {{-- 1 — Hero ------------------------------------------------ --}}
        <section class="lba-container pt-12 sm:pt-16 lg:pt-20">
            <div class="lba-hero-panel px-6 py-14 sm:px-10 sm:py-16 lg:px-16 lg:py-24">
                <div class="relative z-10 max-w-3xl">
                    <p class="lba-eyebrow text-lba-mint">LBA Cloud</p>
                    <h1 class="mt-4 text-4xl font-semibold leading-[1.08] tracking-tight text-white sm:text-5xl lg:text-6xl">
                        Integrated Clinical Intelligence
                    </h1>
                    <p class="mt-5 max-w-2xl text-base leading-relaxed text-white/75 sm:text-lg">
                        Platform klinis terintegrasi untuk operasional laboratorium LBA — pendaftaran pasien, pemeriksaan
                        mikroskopis digital, peninjauan oleh tenaga ahli, dan laporan privat yang terkontrol dalam satu sistem.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="{{ route('login') }}" class="lba-btn lba-btn--primary">Login Staff</a>
                        <a href="{{ url('/patient/login') }}" class="lba-btn lba-btn--light">Portal Pasien</a>
                    </div>

                    <ul class="mt-10 grid gap-x-6 gap-y-3 text-sm text-white/70 sm:grid-cols-2">
                        <li class="flex items-start gap-2.5">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-lba-mint" aria-hidden="true"></span>
                            Gambar mikroskopis disimpan privat
                        </li>
                        <li class="flex items-start gap-2.5">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-lba-mint" aria-hidden="true"></span>
                            Peninjauan dan persetujuan oleh manusia
                        </li>
                        <li class="flex items-start gap-2.5">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-lba-mint" aria-hidden="true"></span>
                            Basis data mitra terisolasi
                        </li>
                        <li class="flex items-start gap-2.5">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-lba-mint" aria-hidden="true"></span>
                            Multi-mitra dan multi-brand
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        {{-- 2 — Platform overview ----------------------------------- --}}
        <section id="platform" class="lba-section">
            <div class="lba-container">
                <div class="lba-reveal max-w-2xl">
                    <p class="lba-eyebrow">Platform</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">Satu platform, tiga ruang kerja</h2>
                    <p class="mt-4 text-base leading-relaxed text-lba-ink">
                        LBA Cloud menyatukan tata kelola platform, operasional klinik, dan layanan pasien dalam satu sistem yang
                        konsisten — dengan peran, izin, dan modul yang terkendali.
                    </p>
                </div>

                <div class="mt-10 grid gap-6 md:grid-cols-3">
                    <article class="lba-card lba-reveal">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-lba-navy">Principal</h3>
                            <span class="lba-badge lba-badge--available">Tersedia</span>
                        </div>
                        <p class="text-xs font-medium uppercase tracking-[0.14em] text-lba-mist">Tata kelola platform</p>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Kelola mitra, brand, langganan, modul yang terpasang, dan hak akses fitur dari satu tempat.
                        </p>
                    </article>

                    <article class="lba-card lba-reveal">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-lba-navy">Klinik</h3>
                            <span class="lba-badge lba-badge--available">Tersedia</span>
                        </div>
                        <p class="text-xs font-medium uppercase tracking-[0.14em] text-lba-mist">Operasional harian</p>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Pasien, jadwal, pemeriksaan LBA, peninjauan hasil, dan dompet token AI dalam alur kerja harian.
                        </p>
                    </article>

                    <article class="lba-card lba-reveal">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-lba-navy">Pasien</h3>
                            <span class="lba-badge lba-badge--planned">Sebagian tersedia</span>
                        </div>
                        <p class="text-xs font-medium uppercase tracking-[0.14em] text-lba-mist">Portal pasien</p>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Portal ramah seluler untuk melihat jadwal dan hasil pemeriksaan; fitur layanan mandiri bertahap.
                        </p>
                    </article>
                </div>

                <div class="lba-reveal mt-8 flex flex-col gap-2 rounded-xl border border-dashed border-lba-line bg-white/60 px-5 py-4 sm:flex-row sm:items-center sm:gap-4">
                    <span class="lba-badge lba-badge--planned shrink-0">Segera hadir</span>
                    <p class="text-sm leading-relaxed text-lba-ink">
                        Pemesanan mandiri pasien, konsultasi &amp; SINA chat, katalog produk &amp; dompet token pasien, serta
                        integrasi pembayaran masih dalam pengembangan.
                    </p>
                </div>
            </div>
        </section>

        {{-- 3 — LBA digital examination workflow -------------------- --}}
        <section id="workflow" class="lba-section border-t border-lba-line bg-white">
            <div class="lba-container">
                <div class="lba-reveal max-w-2xl">
                    <p class="lba-eyebrow">Alur kerja</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">Alur pemeriksaan LBA digital</h2>
                    <p class="mt-4 text-base leading-relaxed text-lba-ink">
                        Setiap langkah tercatat dan dapat diaudit, sementara keputusan klinis tetap berada di tangan tenaga ahli.
                    </p>
                </div>

                <ol class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    @foreach ([
                        ['Pendaftaran & jadwal', 'Pasien dan janji temu dicatat pada mitra dan cabang yang terverifikasi.'],
                        ['Unggah gambar privat', 'Gambar mikroskopis (JPEG, PNG, WebP) disimpan privat; gambar asli tidak pernah diubah atau dipublikasikan.'],
                        ['Analisis manual', 'Operator menandai observasi (titik, persegi panjang, poligon) langsung pada gambar asli di ruang kerja mikroskop.'],
                        ['Saran AI (opsional)', 'Jika gateway visi AI dikonfigurasi, saran observasi ditampilkan tanpa peninjauan — bukan diagnosis.'],
                        ['Peninjauan & laporan privat', 'Peninjau manusia menyetujui, lalu versi laporan PDF privat dibuat dengan checksum SHA-256.'],
                    ] as $index => $step)
                        <li class="lba-card lba-reveal">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-lba-teal/10 text-sm font-semibold text-lba-teal" aria-hidden="true">
                                {{ $index + 1 }}
                            </span>
                            <h3 class="text-base font-semibold text-lba-navy">{{ $step[0] }}</h3>
                            <p class="text-sm leading-relaxed text-lba-ink">{{ $step[1] }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- 4 — Manual / AI / Hybrid capabilities ------------------- --}}
        <section id="features" class="lba-section">
            <div class="lba-container">
                <div class="lba-reveal max-w-2xl">
                    <p class="lba-eyebrow">Kemampuan analisis</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">Manual, AI, dan Hybrid</h2>
                    <p class="mt-4 text-base leading-relaxed text-lba-ink">
                        Tiga mode kerja dengan satu alur persetujuan manusia. Mode AI hanya aktif bila gateway AI dikonfigurasi
                        untuk lingkungan tersebut.
                    </p>
                </div>

                <div class="mt-10 grid gap-6 md:grid-cols-3">
                    <article class="lba-card lba-reveal">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-lba-navy">Manual</h3>
                            <span class="lba-badge lba-badge--available">Tersedia</span>
                        </div>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Analisis sepenuhnya oleh operator dengan alat anotasi pada gambar asli — tanpa ketergantungan pada
                            layanan AI.
                        </p>
                    </article>

                    <article class="lba-card lba-reveal">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-lba-navy">AI</h3>
                            <span class="lba-badge lba-badge--planned">Bergantung konfigurasi</span>
                        </div>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Gateway visi AI menghasilkan saran observasi yang ditampilkan untuk dipertimbangkan, bukan diagnosis.
                        </p>
                    </article>

                    <article class="lba-card lba-reveal">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-lba-navy">Hybrid</h3>
                            <span class="lba-badge lba-badge--available">Tersedia</span>
                        </div>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Menggabungkan anotasi manual dan saran AI dalam satu pemeriksaan untuk mempercepat peninjauan.
                        </p>
                    </article>
                </div>

                <div class="lba-reveal mt-8 flex items-start gap-3 rounded-xl border border-lba-line bg-white px-5 py-4">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-lba-teal" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5" />
                        <path d="M10 6.5v4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        <circle cx="10" cy="13.6" r="0.9" fill="currentColor" />
                    </svg>
                    <p class="text-sm leading-relaxed text-lba-ink">
                        <span class="font-semibold text-lba-navy">Catatan penting:</span> saran AI tidak divalidasi dan bukan
                        diagnosis. Seluruh hasil harus diverifikasi oleh operator atau peninjau berkualifikasi sebelum digunakan
                        secara klinis.
                    </p>
                </div>
            </div>
        </section>

        {{-- 5 — Multi-Clinic & Multi-Brand architecture ------------- --}}
        <section id="architecture" class="lba-section border-t border-lba-line bg-white">
            <div class="lba-container">
                <div class="lba-reveal max-w-2xl">
                    <p class="lba-eyebrow">Arsitektur</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">Multi-mitra &amp; multi-brand</h2>
                    <p class="mt-4 text-base leading-relaxed text-lba-ink">
                        Satu deployment melayani banyak organisasi klinik. Setiap mitra bekerja dalam batas datanya sendiri.
                    </p>
                </div>

                <div class="mt-10 grid gap-6 sm:grid-cols-2">
                    <article class="lba-card lba-reveal">
                        <h3 class="text-base font-semibold text-lba-navy">Brand</h3>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Identitas komersial dan identitas laporan pada PDF pasien. Sebuah brand bukan mitra dan tidak
                            menyimpan data klinis.
                        </p>
                    </article>
                    <article class="lba-card lba-reveal">
                        <h3 class="text-base font-semibold text-lba-navy">Mitra (Tenant)</h3>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Organisasi klinik independen dengan basis datanya sendiri yang terisolasi dan siklus hidupnya sendiri.
                        </p>
                    </article>
                    <article class="lba-card lba-reveal">
                        <h3 class="text-base font-semibold text-lba-navy">Cabang</h3>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Setiap mitra dapat memiliki beberapa cabang beserta staf dan perannya masing-masing.
                        </p>
                    </article>
                    <article class="lba-card lba-reveal">
                        <h3 class="text-base font-semibold text-lba-navy">Isolasi data</h3>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Koneksi ke basis data mitra dipilih dan diverifikasi sesuai konteks; tidak ada akses lintas mitra.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        {{-- 6 — Clinical review & private reports -------------------- --}}
        <section id="review" class="lba-section">
            <div class="lba-container">
                <div class="lba-reveal max-w-2xl">
                    <p class="lba-eyebrow">Peninjauan klinis</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">Peninjauan klinis &amp; laporan privat</h2>
                    <p class="mt-4 text-base leading-relaxed text-lba-ink">
                        Persetujuan adalah tindakan manusia, dan laporan pasien tetap privat.
                    </p>
                </div>

                <div class="mt-10 grid gap-6 sm:grid-cols-2">
                    <article class="lba-card lba-reveal">
                        <h3 class="text-base font-semibold text-lba-navy">Kotak masuk peninjau</h3>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Penilaian yang dikirim masuk ke antrean peninjauan khusus untuk peran peninjau.
                        </p>
                    </article>
                    <article class="lba-card lba-reveal">
                        <h3 class="text-base font-semibold text-lba-navy">Pemisahan tugas</h3>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Operator tidak dapat menyetujui pekerjaannya sendiri; peninjau lain yang menandatangani.
                        </p>
                    </article>
                    <article class="lba-card lba-reveal">
                        <h3 class="text-base font-semibold text-lba-navy">Laporan versi immutabel</h3>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Persetujuan menghasilkan versi laporan PDF pertama yang tidak berubah, lengkap dengan checksum SHA-256.
                        </p>
                    </article>
                    <article class="lba-card lba-reveal">
                        <h3 class="text-base font-semibold text-lba-navy">Penyimpanan privat</h3>
                        <p class="text-sm leading-relaxed text-lba-ink">
                            Laporan disimpan privat dan hanya dapat diakses melalui tautan terautentikasi — tidak ada tautan publik.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        {{-- 7 — Platform security and data privacy ------------------- --}}
        <section id="security" class="lba-section border-t border-lba-line bg-white">
            <div class="lba-container">
                <div class="lba-reveal max-w-2xl">
                    <p class="lba-eyebrow">Keamanan &amp; privasi</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">Keamanan platform &amp; privasi data</h2>
                    <p class="mt-4 text-base leading-relaxed text-lba-ink">
                        Privasi pasien dirancang sejak awal — bukan ditambahkan kemudian.
                    </p>
                </div>

                <ul class="mt-10 grid gap-x-8 gap-y-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        ['Basis data terisolasi', 'Setiap mitra menggunakan basis data terpisah dengan migrasi dan siklus hidupnya sendiri.'],
                        ['Penyimpanan privat', 'Gambar mikroskopis dan laporan disimpan privat, tanpa tautan penyimpanan publik.'],
                        ['Persetujuan pemrosesan gambar', 'Pemrosesan gambar memerlukan persetujuan pemrosesan gambar yang dicatat secara eksplisit.'],
                        ['Kendali akses berbasis peran', 'Izin peran dan entitlement modul membatasi setiap tindakan di seluruh ruang kerja.'],
                        ['Jejak audit', 'Perubahan status, keputusan peninjauan, dan aktivasi modul tercatat.'],
                        ['Tanpa diagnosis otomatis', 'Platform tidak menampilkan diagnosis AI atau tingkat risiko penyakit yang belum divalidasi.'],
                    ] as $item)
                        <li class="lba-reveal flex items-start gap-3">
                            <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-lba-teal/10 text-lba-teal" aria-hidden="true">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none">
                                    <path d="m5.5 10.5 3 3L14.5 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </span>
                            <div>
                                <h3 class="text-sm font-semibold text-lba-navy">{{ $item[0] }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-lba-ink">{{ $item[1] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        {{-- 8 — Final call to action -------------------------------- --}}
        <section class="lba-section">
            <div class="lba-container">
                <div class="lba-hero-panel lba-reveal px-6 py-12 text-center sm:px-10 sm:py-16">
                    <h2 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">Siap memulai?</h2>
                    <p class="mx-auto mt-4 max-w-xl text-base leading-relaxed text-white/75">
                        Masuk ke ruang kerja staf atau portal pasien LBA Cloud.
                    </p>
                    <div class="mt-8 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('login') }}" class="lba-btn lba-btn--primary">Login Staff</a>
                        <a href="{{ url('/patient/login') }}" class="lba-btn lba-btn--light">Portal Pasien</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-lba-line bg-white">
        <div class="lba-container grid gap-10 py-14 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <a href="{{ url('/') }}" class="lba-brand" aria-label="LBA Cloud — beranda">
                    <span class="lba-brand__mark" aria-hidden="true">
                        <svg viewBox="0 0 32 32" fill="none">
                            <path d="M16 3 27 9.5v13L16 29 5 22.5v-13L16 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                            <circle cx="16" cy="16" r="4.5" fill="currentColor" />
                        </svg>
                    </span>
                    <span class="lba-brand__name">LBA CLOUD</span>
                </a>
                <p class="mt-4 max-w-sm text-sm leading-relaxed text-lba-ink">
                    Integrated Clinical Intelligence — platform pemeriksaan LBA digital yang privat dan ditinjau oleh manusia.
                </p>
            </div>

            <nav aria-label="Navigasi platform">
                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-lba-mist">Platform</h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li><a href="#platform" class="text-lba-ink transition hover:text-lba-teal">Platform</a></li>
                    <li><a href="#features" class="text-lba-ink transition hover:text-lba-teal">Fitur</a></li>
                    <li><a href="#workflow" class="text-lba-ink transition hover:text-lba-teal">Alur Kerja</a></li>
                </ul>
            </nav>

            <nav aria-label="Navigasi akses">
                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-lba-mist">Akses</h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li><a href="{{ route('login') }}" class="text-lba-ink transition hover:text-lba-teal">Login Staff</a></li>
                    <li><a href="{{ url('/patient/login') }}" class="text-lba-ink transition hover:text-lba-teal">Portal Pasien</a></li>
                </ul>
            </nav>
        </div>

        <div class="border-t border-lba-line">
            <div class="lba-container flex flex-col gap-3 py-6 text-xs text-lba-mist sm:flex-row sm:items-center sm:justify-between">
                <p>{{ __('lba.auth.legal', ['year' => date('Y')]) }}</p>
                <p>Sebagian modul masih dalam pengembangan dan ditandai &ldquo;Segera hadir&rdquo;.</p>
            </div>
        </div>
    </footer>
</body>
</html>
