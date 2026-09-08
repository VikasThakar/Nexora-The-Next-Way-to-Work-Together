<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Services\NotificationReader;
use App\Support\Broadcasting;
use Livewire\Component;

/**
 * The notification bell in the top bar.
 *
 * Holds no notification data of its own. Everything is read on each render
 * through NotificationReader, which re-checks every subject against the
 * visibility scopes before returning a single line — so what is shown here is
 * what the viewer may see right now, not what they were allowed to see when the
 * notification was written.
 *
 * With realtime enabled the bell re-reads when the server says something
 * arrived. Without it, it polls slowly. Either way the work is the same and it
 * is bounded: at most NotificationReader::WINDOW rows, three lookups.
 */
class Bell extends Component
{
    public bool $open = false;

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        $user = auth()->user();

        if ($user === null || ! Broadcasting::enabled()) {
            return [];
        }

        return [
            'echo-private:users.'.$user->getKey().',.notification.received' => '$refresh',
        ];
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /**
     * Mark one notification read.
     *
     * The id comes from the browser and is scoped to this user's own
     * notifications inside the reader, so somebody else's id does nothing.
     */
    public function markRead(string $id, NotificationReader $reader): void
    {
        $reader->markRead(auth()->user(), $id);
    }

    public function markAllRead(NotificationReader $reader): void
    {
        $reader->markAllRead(auth()->user());
    }

    public function render(NotificationReader $reader)
    {
        $user = auth()->user();
        $items = $user === null ? collect() : $reader->items($user);

        return view('livewire.notifications.bell', [
            'items' => $items,
            'unread' => $user === null ? 0 : $reader->unreadCount($user),

            /*
             * How many more there are than the bell is showing.
             *
             * Worth the extra pass: the badge counts everything readable inside
             * the reader's window while the list shows fifteen, so without this
             * a badge of thirty above a list of fifteen reads as a bug rather
             * than as a truncation. Only computed when the list is actually
             * full.
             */
            'hidden' => $user === null || $items->count() < NotificationReader::PAGE
                ? 0
                : max(0, $reader->visibleCount($user) - $items->count()),

            // Polling is the fallback for deployments without a websocket
            // server; with Reverb running the bell is pushed instead.
            'polling' => ! Broadcasting::enabled(),
        ]);
    }
}
