<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A profile picture for a user.
     *
     * Two columns rather than one, and the second is the interesting one.
     * `avatar_path` is the generated storage key; `avatar_disk` records which
     * disk it was written to, at the moment it was written. That is the same
     * rule App\Services\AttachmentStorage applies to every file in the
     * product, for the same reason: a deployment that later points
     * ATTACHMENT_DISK somewhere else must not orphan the pictures taken before
     * the change. A single column would make "where is this file" depend on
     * configuration as it stands now rather than on what actually happened.
     *
     * A reference, never the bytes
     * ----------------------------
     * The image itself lives on the attachment disk under `avatars/{ULID}`. A
     * blob column would be read on every page that renders a user — which, once
     * avatars appear on ticket cards and in conversations, is most of them —
     * and would carry a megabyte of binary through every `select *` that
     * touches `users`, including the one the session guard runs on each
     * request.
     *
     * Null is the whole of "no picture"
     * ---------------------------------
     * There is no flag and no placeholder row. A user without a picture has a
     * null path, and App\Models\User::initials() answers instead — the fallback
     * that has always been there, now actually called. Nothing has to be
     * backfilled and no existing row changes meaning, so this is safe to apply
     * to a live table.
     *
     * Placed after `theme_preference` so the two columns a person sets about
     * themselves sit together, after the ones an administrator sets about them.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path', 2048)->nullable()->after('theme_preference');
            $table->string('avatar_disk', 40)->nullable()->after('avatar_path');
        });
    }

    /**
     * Rolling back loses every picture's reference and nothing else.
     *
     * The stored objects are deliberately left on disk rather than deleted from
     * a migration: a rollback is usually a mistake being undone, and a
     * migration that destroys files cannot be undone a second time. They become
     * unreferenced, which is exactly the condition `workspace:prune` collects.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['avatar_path', 'avatar_disk']);
        });
    }
};
