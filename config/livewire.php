<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Temporary file uploads
    |--------------------------------------------------------------------------
    |
    | Every file that reaches the application through a Livewire component —
    | a pasted screenshot, a dropped recording, a chosen document — is first
    | written to Livewire's temporary directory and validated against these
    | rules, before the component ever sees it. Livewire's own default is a
    | 12 MB ceiling, which is smaller than the attachment limit in
    | config/attachments.php, so a recording that the product allows would be
    | refused one layer earlier with an error nobody can explain. The ceiling
    | here is the attachment ceiling, so there is one answer to "how big".
    |
    | The rest of the block repeats Livewire's defaults, because the package
    | config is merged one level deep: naming `temporary_file_upload` at all
    | replaces the whole array.
    |
    */

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:'.(int) env('ATTACHMENT_MAX_SIZE_KB', 30720)],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
