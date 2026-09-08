<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the ticket type — Bug, Task or Feature.
 *
 * The only schema change this phase needs, and it is additive: a new column
 * with a default, so every existing row is a Task the moment it is applied and
 * nothing has to be backfilled or rewritten. No existing column is touched, so
 * there is no path by which this loses a description.
 *
 * `string(20)` with the default living in the database rather than only in the
 * enum, because rows are also written by the seeders and by
 * App\Actions\AI\* automation, and a NOT NULL column with no default would
 * make every one of those a required-field change.
 *
 * Reversible: down() drops the column. That does lose the classification, which
 * is unavoidable for a column that did not exist before — nothing else depends
 * on it, and no other data goes with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('type', 20)
                ->default('task')
                ->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
