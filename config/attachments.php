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
    |--------------------------------------------------------------------------
    | Draft retention
    |--------------------------------------------------------------------------
    |
    | How long a file dropped into a creation form is kept before it is assumed
    | abandoned. A draft is an attachment with no owner — see the
    | allow_draft_attachments migration — and it only exists between the drop
    | and the Save that adopts it.
    |
    | Most are adopted within a minute. The ones that are not belong to somebody
    | who closed the tab, and nothing in the application can reach them again:
    | the only list of their ids was the Livewire component that has since gone.
    |
    | Twenty-four hours rather than minutes, because the cost of being wrong is
    | asymmetric. Too long keeps a few unreferenced files for a day; too short
    | deletes the screenshot out from under somebody who left a half-written
    | ticket open over lunch. `workspace:prune` removes them, file and row
    | together.
    |
    */

    'draft_retention_hours' => (int) env('ATTACHMENT_DRAFT_RETENTION_HOURS', 24),

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

    /*
    |--------------------------------------------------------------------------
    | Profile pictures
    |--------------------------------------------------------------------------
    |
    | A profile picture is not an attachment — it hangs off no record, it has no
    | board, and it is not served by AttachmentController. Its settings live
    | here anyway because it shares the one thing that matters operationally:
    | the disk above, and therefore the production durability check in
    | App\Providers\AppServiceProvider. Somebody asking "where do uploaded files
    | go and do they survive a deploy" should find one answer, not two.
    |
    | The ceilings are deliberately tighter than the attachment ones. A picture
    | is cropped to a square and scaled down to `max_dimension`, so anything
    | larger than a few megabytes is bytes nobody will ever see — and the file
    | that is finally stored was written by this application, not uploaded.
    |
    | `allowed_mimes` has no `svg`, unlike the attachment list above. An SVG is a
    | document that can carry script rather than a picture, and a profile
    | picture is the one upload in the product that is rendered inline in an
    | <img> on every screen. The list is also what the `image` validation rule
    | narrows: that rule already excludes SVG, and this names what is left.
    |
    */

    'avatars' => [

        'max_size_kb' => (int) env('AVATAR_MAX_SIZE_KB', 4096),

        'max_dimension' => (int) env('AVATAR_MAX_DIMENSION', 512),

        'jpeg_quality' => (int) env('AVATAR_JPEG_QUALITY', 85),

        'allowed_mimes' => ['jpg', 'jpeg', 'png', 'webp'],

    ],

];
