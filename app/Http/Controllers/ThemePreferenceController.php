<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ThemePreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Remembering which appearance somebody chose.
 *
 * A one-line controller rather than a Livewire action, and that is the whole
 * design: the appearance has already changed in the browser by the time this
 * request is sent. resources/js/theme.js sets the class on <html> and writes
 * localStorage synchronously on click, then posts here so the choice follows
 * the person to their other devices. A Livewire round trip would put a server
 * response between the click and the colour changing, and a theme toggle that
 * waits is a theme toggle that feels broken.
 *
 * Which also means this endpoint failing is not an error worth showing anybody.
 * The theme is already applied and already in localStorage; all that is lost is
 * the cross-device part, and it will be written again the next time somebody
 * touches the control.
 *
 * The only thing that must not happen is an unvalidated string reaching the
 * column, because it is rendered into a class attribute in the layout head. So
 * the value is checked against the enum and refused otherwise — there is no
 * path here that stores what it was given.
 */
class ThemePreferenceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', new Enum(ThemePreference::class)],
        ]);

        $user = $request->user();

        // Assigned by name rather than through update([...]): the column is
        // deliberately absent from User::$fillable.
        $user->theme_preference = ThemePreference::from($validated['theme']);
        $user->save();

        return response()->json([
            'theme' => $user->theme_preference->value,
        ]);
    }
}
