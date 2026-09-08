<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the appearance preference — light, dark or follow the device.
 *
 * One column on `users` rather than a preferences table, because there is
 * exactly one preference and a table would be an architecture built for a
 * second one that has not been asked for. If a third or fourth arrives, moving
 * these to a JSON `preferences` column is a later migration that this one does
 * not stand in the way of.
 *
 * Additive, with the default in the database rather than only in the enum: rows
 * are also written by the seeders and by the registration flow, and a NOT NULL
 * column with no default would make every one of those a required-field change.
 *
 * The default is `light`, which is what the product looked like before this
 * column existed. So applying this changes nothing anybody sees: every
 * existing account keeps the appearance it already had, and dark and
 * follow-the-device become things somebody chooses rather than things that
 * happen to them. See App\Enums\ThemePreference::default().
 *
 * `string(10)` rather than a native enum, matching `tickets.type` and
 * `users.role`: the allowed values live in App\Enums\ThemePreference, so adding
 * a case later is a code change rather than a table rewrite. The value is
 * validated on the way in by App\Http\Controllers\ThemePreferenceController and
 * cast on the way out by the model, so an unknown string cannot reach a view.
 *
 * Reversible: down() drops the column. That loses each person's choice and
 * nothing else — every screen falls back to the light appearance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('theme_preference', 10)
                ->default('light')
                ->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('theme_preference');
        });
    }
};
