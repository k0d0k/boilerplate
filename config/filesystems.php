<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Phase 4 — private microscopy object storage.
         *
         * Original microscopy images are clinical records: they are written to a
         * PRIVATE disk that is never symlinked into `public/` and never served
         * by the web server directly. They are only ever read back through an
         * authenticated, tenant-scoped streaming endpoint (`serve` is off, so
         * the local driver cannot emit a public URL for them).
         */
        'lba' => [
            'driver' => 'local',
            'root' => storage_path('app/private/lba'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        /*
         * Phase 7B — private, immutable PDF report storage.
         *
         * A generated report is a patient-facing clinical document: it is written
         * to a PRIVATE disk that is never symlinked into `public/` and never
         * served by the web server directly (`serve` is off). It is only ever
         * read back through an authenticated, tenant- and branch-scoped streaming
         * endpoint. Brand logos are also uploaded here (private object storage).
         */
        'lba-reports' => [
            'driver' => 'local',
            'root' => storage_path('app/private/lba-reports'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        /*
         * WS9.A — private knowledge base source storage.
         *
         * An approved (or pending) knowledge PDF is reference material, not a
         * clinical record, but its content is still PRIVATE: it is written to a
         * disk that is never symlinked into `public/` and never served by the
         * web server directly (`serve` is off). It is only ever read back through
         * the authenticated, tenant-scoped knowledge stream endpoint.
         */
        'lba-knowledge' => [
            'driver' => 'local',
            'root' => storage_path('app/private/lba-knowledge'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        /*
         * Account profile photos.
         *
         * A profile photo is personal data, not a clinical record, but it is
         * still stored PRIVATELY: written to a disk that is never symlinked into
         * `public/` and never served by the web server directly (`serve` is
         * off). It is only read back through the authenticated account-photo
         * streaming endpoint.
         */
        'account-photos' => [
            'driver' => 'local',
            'root' => storage_path('app/private/account-photos'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
