<?php

declare(strict_types=1);

namespace App\Actions\Docs;

use App\Models\DocPage;
use App\Models\User;

/**
 * Edit a page's title and body.
 *
 * Not its slug, its parent or its visibility. Those three are the things that
 * can break a link, restructure the tree or expose material to a customer, so
 * each has its own action and its own ability:
 * App\Actions\Docs\MovePage and App\Actions\Docs\SetPageVisibility.
 *
 * A rename therefore never changes the URL. Somebody who pasted a link to a
 * page in a ticket last month still lands on it.
 */
class UpdatePage
{
    /**
     * @param  array{title?: string, body_md?: ?string}  $attributes
     */
    public function handle(DocPage $page, array $attributes, User $actor): DocPage
    {
        if (array_key_exists('title', $attributes)) {
            $title = trim((string) $attributes['title']);

            if ($title !== '') {
                $page->title = $title;
            }
        }

        if (array_key_exists('body_md', $attributes)) {
            $body = (string) $attributes['body_md'];

            $page->body_md = trim($body) === '' ? null : $body;
        }

        if ($page->isDirty()) {
            $page->updated_by_id = $actor->getKey();
            $page->save();
        }

        return $page;
    }
}
