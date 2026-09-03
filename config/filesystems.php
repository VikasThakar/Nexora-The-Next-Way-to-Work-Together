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
         * A mounted persistent volume.
         *
         * The same `local` driver as above, pointed somewhere that is NOT the
         * container filesystem. It exists so a deployment can keep attachments
         * without an object-storage account: on Railway, Fly or a plain VPS you
         * attach a volume, mount it at ATTACHMENT_VOLUME_PATH, and set
         * FILESYSTEM_DISK=volume.
         *
         * It is listed in config/attachments.php's `durable_disks`, and unlike
         * the entry for `s3` that claim has a precondition: this disk is durable
         * only because something is mounted at its root. So the production check
         * in App\Providers\AppServiceProvider does not take the disk name as
         * proof — it stats the root and refuses to boot if the mount is not
         * there. A forgotten volume would otherwise write into the container and
         * lose every file at the next deploy, which is precisely the failure the
         * check exists to prevent.
         *
         * `throw` is true here, and that is the one place this disk differs from
         * `local`. App\Services\AttachmentStorage does not inspect the return
         * value of the write — with `throw => false` a full or read-only volume
         * would leave an attachment row pointing at a file that was never
         * written. Failing the upload is the honest outcome.
         *
         * Not shared between services: one Railway volume attaches to one
         * service. Attachments are only ever written and read by web requests
         * (Livewire uploads and App\Http\Controllers\AttachmentController), never
         * by a queued job, so the web service is the only one that needs it.
         */
        'volume' => [
            'driver' => 'local',
            'root' => env('ATTACHMENT_VOLUME_PATH', '/data'),
            'serve' => true,
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
