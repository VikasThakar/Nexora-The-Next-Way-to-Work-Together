<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiChatMessage;
use App\Support\CsvDownload;
use App\Support\RichResponse\RichBlock;
use App\Support\RichResponse\RichResponseParser;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads one table or chart out of an assistant answer, as a CSV.
 *
 * The exported data is the rendered data, guaranteed structurally
 * ---------------------------------------------------------------
 * Nothing is cached, nothing is posted back from the browser, and no data
 * arrives in the request. The controller re-reads the stored answer and parses
 * it with the same App\Support\RichResponse\RichResponseParser the screen used,
 * then exports block N of the result. So the CSV cannot drift from the table
 * on screen: the only way they could disagree is if the same parser gave two
 * answers for the same text.
 *
 * That is why the route addresses a *block index* rather than accepting a
 * payload. A browser that could POST the rows it is showing would be a browser
 * that could export rows the model never produced, which for a file somebody
 * forwards to a client is exactly the wrong property.
 *
 * Authorization
 * -------------
 * Four filters on the message lookup, matching what
 * App\Livewire\Ai\Concerns\TalksToWorkspaceAi applies to a proposal, and each
 * closes a different door:
 *
 *   the route group's `role:admin,team`, so a customer never arrives;
 *   `visibleTo`, so a swapped id cannot reach a board this person is not on;
 *   `ownedBy`, so it cannot reach a colleague's — or an administrator's —
 *   conversation, which is the rule for every AI surface in this product;
 *   and `whereNotNull('ai_session_id')`, so an orphaned historical turn is not
 *   exportable either.
 *
 * A miss is a 404 rather than a 403: a conversation somebody may not read must
 * not be distinguishable from one that does not exist.
 *
 * Charts export their data, not their picture. The PNG is produced in the
 * browser from the SVG that is already on the page — see the chart component —
 * because rasterising server-side would mean an image library this deployment
 * does not need for anything else.
 */
class AiBlockExportController extends Controller
{
    public function __invoke(int $message, int $block, RichResponseParser $parser): StreamedResponse
    {
        $user = auth()->user();

        $stored = AiChatMessage::query()
            ->visibleTo($user)
            ->ownedBy($user)
            ->whereNotNull('ai_chat_messages.ai_session_id')
            ->whereKey($message)
            ->first();

        abort_unless($stored instanceof AiChatMessage, 404);

        /*
         * Only an assistant turn has blocks.
         *
         * A person's own question is prose by definition, and letting the route
         * parse one would mean a pipe table somebody typed became an
         * "exportable" artefact of the assistant's, which it is not.
         */
        abort_unless($stored->role->isAssistant(), 404);

        $parsed = $parser->block($stored->content, $user, $block, $stored->board);

        abort_unless($parsed instanceof RichBlock && $parsed->isExportable(), 404);

        $table = $parsed->asTable();

        abort_unless($table !== null, 404);

        return CsvDownload::stream($table->toCsvTable(), $this->filename($stored, $parsed));
    }

    /**
     * A filename somebody can find again in a downloads folder.
     *
     * The block's own title when it has one, because "tickets-by-month.csv" is
     * worth far more than "ai-1042-block-3.csv"; the conversation reference
     * otherwise. Slugged, so nothing model-authored reaches a
     * Content-Disposition header — a filename is a header value, and a header
     * value assembled from untrusted text is a response-splitting bug waiting
     * to happen.
     */
    private function filename(AiChatMessage $message, RichBlock $block): string
    {
        $title = Str::slug((string) $block->title());

        if ($title === '') {
            $title = 'ai-'.$message->getKey().'-'.$block->index;
        }

        return Str::limit($title, 60, '').'.csv';
    }
}
