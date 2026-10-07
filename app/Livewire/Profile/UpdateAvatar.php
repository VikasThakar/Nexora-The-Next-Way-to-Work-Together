<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Actions\Users\RemoveAvatar;
use App\Actions\Users\UpdateAvatar as UpdateAvatarAction;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Your own profile picture.
 *
 * Self-service only, and the shape of this class is what enforces it: there is
 * no user property, no id in any method signature, and nothing a request can
 * set to name somebody else. The subject is auth()->user() in both methods, and
 * cannot be anything else.
 *
 * That is the same reasoning as App\Livewire\Profile\UpdateProfile, which is
 * also ungated — UserPolicy deliberately has no "update own profile" ability,
 * because an unauthorized-by-construction component does not need one. An
 * administrator cannot set another person's picture: there is no control on the
 * user administration screen and no route that would accept one.
 *
 * Why the updated hook stores immediately
 * ---------------------------------------
 * Choosing a file uploads it; there is no second button to press. That is the
 * pattern in App\Livewire\Attachments\Panel, and it fits here for the reason it
 * does not fit App\Livewire\Concerns\EditsRichText: nothing is waiting to be
 * told a URL. The page simply re-renders with the new picture, which is the
 * confirmation.
 */
class UpdateAvatar extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $photo = null;

    /**
     * Validation is the first filter, not the only one.
     *
     * `image` rejects what is not an image by extension and declared type, and
     * in Laravel 11+ it excludes SVG — which matters here because this is the
     * one upload in the product that is rendered inline in an <img>. `mimes`
     * then narrows to what we are prepared to re-encode.
     *
     * Neither is the real check. A file is only accepted if GD can decode it
     * (see App\Support\Images\ImageTranscoder), which is what turns "a .png
     * whose bytes are a shell script" into a refusal rather than a stored file.
     */
    public function updatedPhoto(UpdateAvatarAction $updateAvatar): void
    {
        $this->validate([
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:'.implode(',', (array) config('attachments.avatars.allowed_mimes')),
                'max:'.config('attachments.avatars.max_size_kb'),
            ],
        ], attributes: ['photo' => 'picture']);

        $stored = $updateAvatar->handle(auth()->user(), $this->photo);

        $this->reset('photo');

        if (! $stored) {
            $this->addError('photo', 'That file could not be read as a picture. Try exporting it again as a PNG or a JPEG.');

            return;
        }

        session()->flash('status', 'Profile picture updated.');
    }

    public function remove(RemoveAvatar $removeAvatar): void
    {
        $removeAvatar->handle(auth()->user());

        $this->reset('photo');
        $this->resetValidation();

        session()->flash('status', 'Profile picture removed.');
    }

    public function render()
    {
        return view('livewire.profile.update-avatar', [
            'user' => auth()->user(),
            'maxSizeMb' => round(((int) config('attachments.avatars.max_size_kb')) / 1024, 1),
        ]);
    }
}
