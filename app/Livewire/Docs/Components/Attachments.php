<?php

declare(strict_types=1);

namespace App\Livewire\Docs\Components;

use App\Livewire\Attachments\Panel;
use App\Models\Board;
use App\Models\DocPage;
use Illuminate\Database\Eloquent\Model;

/**
 * Files on a documentation page.
 *
 * Same panel as tickets use. The one addition is a Markdown snippet per file,
 * so an uploaded image can be embedded in the page body — the snippet points at
 * the authorized download route, never at storage, so an image inside an
 * internal page is served only to somebody who may read that page.
 */
class Attachments extends Panel
{
    public DocPage $page;

    public function mount(DocPage $page): void
    {
        $this->authorize('view', $page);

        $this->page = $page;
    }

    protected function owner(): Model
    {
        return $this->page;
    }

    protected function board(): Board
    {
        $this->page->loadMissing('board');

        return $this->page->board;
    }

    protected function offersMarkdownSnippets(): bool
    {
        return true;
    }
}
