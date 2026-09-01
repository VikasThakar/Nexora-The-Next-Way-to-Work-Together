<?php

declare(strict_types=1);

namespace App\Actions\Labels;

use App\Models\Label;

/**
 * Remove a label from the board.
 *
 * Unlike a column, a label carries no content: deleting one detaches it from
 * every ticket (the pivot cascades) and no ticket is lost. It is therefore safe
 * to delete without asking for a destination.
 */
class DeleteLabel
{
    public function handle(Label $label): void
    {
        $label->delete();
    }
}
