<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per inbound webhook delivery, written before it is processed.
     *
     * Two jobs, both of which matter more than they look:
     *
     * De-duplication. GitHub redelivers when an endpoint times out, and a
     * maintainer can replay a delivery by hand from the repository settings.
     * Both carry the original `X-GitHub-Delivery` header, so the unique index
     * on (source, delivery_id) is what stops a replayed merge writing a second
     * set of links. It is a database constraint rather than a cache check
     * because the check has to survive a cache flush and a race between two web
     * containers, and only the database can promise that.
     *
     * Evidence. When somebody asks why a pull request never appeared on a
     * ticket, this table answers it: whether GitHub delivered at all, whether
     * the signature verified, and what the processor made of it. Without it the
     * only answer available is "nothing in the logs", which is not an answer.
     *
     * The payload is deliberately NOT stored. It can contain private repository
     * contents — commit diffs, branch names, review text — and keeping it would
     * turn this table into a copy of source history with none of the access
     * control GitHub applies to it. What is kept is the shape of the delivery,
     * not its contents.
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();

            // Room for other providers later without another table.
            $table->string('source', 30);

            // The provider's delivery id. Always present for GitHub; a
            // generated fallback is used if it ever is not, so the column can
            // stay non-nullable and the unique index can stay meaningful.
            $table->string('delivery_id', 191);

            $table->string('event', 60);

            // received → processed | ignored | failed
            $table->string('status', 20)->default('received');

            // Populated for events the processor acted on, so a busy repository
            // can be audited without opening the job logs.
            $table->unsignedSmallInteger('links_written')->default(0);

            // Short, and rebuilt by the processor rather than forwarded from an
            // exception, so it can never carry a credential.
            $table->string('error', 500)->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // The idempotency guarantee.
            $table->unique(['source', 'delivery_id']);

            // Pruning old rows, and the "recent deliveries" audit view.
            $table->index(['source', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
