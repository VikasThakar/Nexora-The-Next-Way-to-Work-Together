<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Actions\Boards\UpdateBoard;
use App\Enums\NotificationEvent;
use App\Models\Board;
use App\Support\BoardSlackSettings;
use App\Support\BoardSmsSettings;
use Illuminate\Support\Facades\Crypt;

/**
 * Write a board's Slack and SMS configuration.
 *
 * Thin, like UpdateBoardAiSettings, and for the same reason: the normalisation
 * lives in the two settings objects so it applies on read as well as on write,
 * and a value that reaches the JSON column by another route still resolves to
 * something safe.
 *
 * The one piece of real logic is the webhook URL, and it is the interesting
 * case: a screen that renders "configured" rather than the value cannot submit
 * the value back, so an empty field must mean "leave it alone" and not "delete
 * it". Clearing is therefore an explicit action of its own (`clearWebhook`),
 * which is also what makes it safe to have the field blank on every page load.
 */
class UpdateBoardIntegrations
{
    public function __construct(private readonly UpdateBoard $updateBoard) {}

    /**
     * @param  array{enabled?: bool, webhook_url?: ?string, events?: array<string, bool>, channel_hint?: ?string}  $attributes
     */
    public function updateSlack(Board $board, array $attributes): BoardSlackSettings
    {
        $current = BoardSlackSettings::forBoard($board);

        $webhookUrl = array_key_exists('webhook_url', $attributes)
            ? BoardSlackSettings::normaliseUrl($attributes['webhook_url'])
            : null;

        $settings = BoardSlackSettings::fromArray([
            'enabled' => (bool) ($attributes['enabled'] ?? $current->enabled),
            // Only replaced when a new, valid URL was supplied. See the class
            // comment: a blank field means "unchanged", never "remove".
            'webhook_url' => $this->encrypted($webhookUrl ?? $current->webhookUrl),
            'events' => $this->events($attributes['events'] ?? null, $current),
            'channel_hint' => $attributes['channel_hint'] ?? $current->channelHint,
        ]);

        return $this->persistSlack($board, $settings);
    }

    /**
     * Forget the stored webhook URL.
     *
     * Also switches the integration off: leaving `enabled` true with no URL
     * would be a board that believes it is announcing and is not.
     */
    public function clearSlackWebhook(Board $board): BoardSlackSettings
    {
        $current = BoardSlackSettings::forBoard($board);

        return $this->persistSlack($board, BoardSlackSettings::fromArray([
            'enabled' => false,
            'webhook_url' => null,
            'events' => $current->events,
            'channel_hint' => null,
        ]));
    }

    /**
     * @param  array{enabled?: bool, recipients?: string|array<int, string>}  $attributes
     */
    public function updateSms(Board $board, array $attributes): BoardSmsSettings
    {
        $current = BoardSmsSettings::forBoard($board);

        $recipients = match (true) {
            is_string($attributes['recipients'] ?? null) => BoardSmsSettings::parseList($attributes['recipients']),
            is_array($attributes['recipients'] ?? null) => BoardSmsSettings::numbers($attributes['recipients']),
            default => $current->recipients,
        };

        $settings = BoardSmsSettings::fromArray([
            'enabled' => (bool) ($attributes['enabled'] ?? $current->enabled),
            'recipients' => $recipients,
        ]);

        $this->updateBoard->handle($board, [
            'settings' => ['sms' => $settings->toArray()],
        ]);

        return $settings;
    }

    // -----------------------------------------------------------------

    private function persistSlack(Board $board, BoardSlackSettings $settings): BoardSlackSettings
    {
        // UpdateBoard merges rather than replaces `settings`, so writing the
        // `slack` key cannot drop the board's AI or SMS configuration.
        $this->updateBoard->handle($board, [
            'settings' => ['slack' => $settings->toArray()],
        ]);

        return $settings;
    }

    /**
     * BoardSlackSettings decrypts on the way in and encrypts on the way out, so
     * a plaintext URL is what fromArray() must NOT be given — it would be
     * treated as ciphertext, fail to decrypt, and silently become null.
     */
    private function encrypted(?string $plaintext): ?string
    {
        return $plaintext === null ? null : Crypt::encryptString($plaintext);
    }

    /**
     * Only the events the enum knows about, so a hand-crafted form post cannot
     * add a key that later collides with a real event name.
     *
     * @param  array<string, mixed>|null  $submitted
     * @return array<string, bool>
     */
    private function events(?array $submitted, BoardSlackSettings $current): array
    {
        if ($submitted === null) {
            return $current->events;
        }

        $events = [];

        foreach (NotificationEvent::slackEvents() as $event) {
            $events[$event->value] = (bool) ($submitted[$event->value] ?? false);
        }

        return $events;
    }
}
