<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NotificationEvent;
use App\Models\Board;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;

/**
 * The Slack configuration of one board.
 *
 * Stored under `boards.settings.slack`, the same convention as the AI settings,
 * with one important difference: the webhook URL is a credential.
 *
 * A Slack incoming-webhook URL is a bearer token wearing a URL's clothes.
 * Anyone holding it can post into that channel as the app, for ever, with no
 * further authentication — Slack has no concept of "this URL, but read-only".
 * So it is encrypted at rest with the application key rather than stored as
 * plain text in a JSON column that appears in database dumps, in backups, and
 * in the output of anybody who runs `select settings from boards`.
 *
 * Two consequences worth knowing about:
 *
 *   Rotating APP_KEY makes stored webhook URLs unreadable. That is the correct
 *   failure — the alternative is a key rotation that leaves credentials
 *   readable — and decryption failures degrade to "not configured" rather than
 *   throwing, so a board with an unreadable URL simply stops posting instead of
 *   breaking every ticket save.
 *
 *   The URL is never rendered back to a user. The settings screen shows the
 *   Slack workspace hint and "configured", never the value, so a URL pasted in
 *   once cannot be read back out through the browser by somebody who later
 *   gains access to the screen.
 */
final readonly class BoardSlackSettings
{
    /**
     * @param  array<string, bool>  $events
     */
    private function __construct(
        public bool $enabled,
        public ?string $webhookUrl,
        public array $events,
        public ?string $channelHint,
    ) {}

    public static function forBoard(Board $board): self
    {
        return self::fromArray(self::rawFor($board));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $defaults = (array) config('slack.board_defaults', []);

        return new self(
            enabled: (bool) ($raw['enabled'] ?? ($defaults['enabled'] ?? false)),
            webhookUrl: self::decrypt($raw['webhook_url'] ?? null),
            events: self::events($raw['events'] ?? null, (array) ($defaults['events'] ?? [])),
            channelHint: self::text($raw['channel_hint'] ?? null),
        );
    }

    /**
     * The stored shape, for writing back through UpdateBoard.
     *
     * The URL goes back encrypted. Re-encrypting an unchanged value on every
     * save produces different ciphertext each time, which is harmless and is
     * the price of not keeping a plaintext copy anywhere to compare against.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'webhook_url' => $this->webhookUrl === null ? null : Crypt::encryptString($this->webhookUrl),
            'events' => $this->events,
            'channel_hint' => $this->channelHint,
        ];
    }

    /**
     * Is this board able to post at all?
     *
     * Three switches, all of which must agree: the deployment-wide one (so a
     * staging copy of production cannot post into the real channel), the
     * board's own, and the presence of a URL.
     */
    public function isActive(): bool
    {
        return (bool) config('slack.enabled')
            && $this->enabled
            && $this->webhookUrl !== null;
    }

    /**
     * Should this board be told about this event?
     */
    public function wants(NotificationEvent $event): bool
    {
        return $this->isActive() && ($this->events[$event->value] ?? false);
    }

    /**
     * Whether a URL is stored, without revealing it.
     *
     * What the settings screen renders. See the class comment.
     */
    public function isConfigured(): bool
    {
        return $this->webhookUrl !== null;
    }

    /**
     * A Slack incoming-webhook URL, or null.
     *
     * Only ever three fixed segments after the host, so a rough shape check is
     * enough to catch a pasted channel link or a truncated copy — and cheap
     * enough to run on every save.
     */
    public static function normaliseUrl(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! str_starts_with($value, 'https://hooks.slack.com/')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) === false ? null : $value;
    }

    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function rawFor(Board $board): array
    {
        $raw = Arr::get($board->settings ?? [], 'slack');

        return is_array($raw) ? $raw : [];
    }

    /**
     * Decrypt a stored URL, treating anything unreadable as absent.
     *
     * A DecryptException here means the application key changed since the URL
     * was saved. Degrading to "not configured" stops the board posting and asks
     * somebody to paste the URL again; throwing would break every ticket save
     * on that board instead.
     */
    private static function decrypt(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return self::normaliseUrl(Crypt::decryptString($value));
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * @param  array<string, bool>  $defaults
     * @return array<string, bool>
     */
    private static function events(mixed $value, array $defaults): array
    {
        $stored = is_array($value) ? $value : [];
        $resolved = [];

        // Driven by the enum rather than by whatever keys the row happens to
        // hold, so a new event type appears with its default and a retired one
        // disappears rather than lingering as an unreadable flag.
        foreach (NotificationEvent::slackEvents() as $event) {
            $resolved[$event->value] = (bool) ($stored[$event->value] ?? ($defaults[$event->value] ?? false));
        }

        return $resolved;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 80);
    }
}
