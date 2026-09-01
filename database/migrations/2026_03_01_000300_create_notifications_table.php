<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel database notifications.
     *
     * The `data` payload deliberately holds identifiers only - never a ticket
     * title, a comment body or any other content. Everything shown in the bell
     * is re-read from the live record through the ordinary visibility scope at
     * render time (App\Services\NotificationReader), so a notification cannot
     * keep showing something that has since been made internal.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The unread badge: "how many unread for this user?".
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
