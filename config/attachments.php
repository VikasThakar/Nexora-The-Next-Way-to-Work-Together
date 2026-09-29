<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    |
    | Where uploaded files are kept. Defaults to the application filesystem disk
    | so a single FILESYSTEM_DISK setting moves everything at once, but can be
    | pointed somewhere else (a dedicated bucket, say) without touching the rest
    | of the application.
    |
    | The container filesystem on Railway is replaced on every deploy. `local`
    | is therefore a development convenience only, and AttachmentsAreDurable
    | (see App\Providers\AppServiceProvider) refuses to boot a production
    | application configured that way.
    |
    */

    'disk' => env('ATTACHMENT_DISK', env('FILESYSTEM_DISK', 'local')),

    /*
    |--------------------------------------------------------------------------
    | Disks that survive a deploy
    |--------------------------------------------------------------------------
    |
    | Any disk not listed here is treated as ephemeral and rejected in
    | production. Add S3-compatible disks (R2, MinIO, B2) as they are defined
    | in config/filesystems.php.
    |
    | The two entries are durable for different reasons, and the production
    | check in App\Providers\AppServiceProvider treats them differently:
    |
    |   s3       durable by construction. The bucket is somewhere else; there is
    |            nothing local to verify.
    |   volume   durable only while a persistent volume is mounted at its root.
    |            Being named here is therefore not sufficient proof, so the
    |            check stats the root and refuses to boot when the mount is
    |            absent — otherwise a forgotten volume writes into the container
    |            and loses every file at the next deploy, silently, which is the
    |            exact failure this list exists to prevent.
    |
    */

    'durable_disks' => ['s3', 'volume'],

    /*
    |--------------------------------------------------------------------------
    | Signed URL lifetime
    |--------------------------------------------------------------------------
    |
    | How long a download link generated for an S3-compatible disk stays valid.
    | Short on purpose: the link carries no further authorization, so it should
    | outlive the click and little else.
    |
    */

    'url_ttl_minutes' => (int) env('ATTACHMENT_URL_TTL_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | `max_size_kb` is enforced by validation on every upload. The MIME list is
    | an allow-list: anything not named here is rejected rather than stored and
    | served back later.
    |
    */

    'max_size_kb' => (int) env('ATTACHMENT_MAX_SIZE_KB', 30720),

    'max_per_owner' => (int) env('ATTACHMENT_MAX_PER_OWNER', 20),

    /*
    | Screen recordings are in the list alongside screenshots, because a bug
    | report is as often a recording as a picture. The default size ceiling
    | above is set with them in mind and stays under the 32 MB request limit
    | in docker/php.ini and docker/nginx.conf; raise all three together.
    */

    'allowed_mimes' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'mp4', 'm4v', 'mov', 'webm',
        'pdf', 'txt', 'md', 'csv',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip',
        'log', 'json', 'yml', 'yaml',
    ],

];
