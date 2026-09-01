<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Board;
use Illuminate\Support\Arr;

/**
 * The SMS alert configuration of one board.
 *
 * Stored under `boards.settings.sms`, alongside the AI and Slack settings.
 *
 * Unlike the Slack webhook URL, the recipients are not encrypted. They are
 * phone numbers belonging to the delivery team, they have to be readable back
 * on the settings screen so somebody can remove a number when a colleague
 * leaves the rota, and encrypting a value you then display achieves nothing.
 * They are still personal data: they are masked in every log line and in the
 * delivery history (see SmsMessage::maskedRecipient), and the screen that shows
 * them in full is staff-only.
 *
 * A board with no recipients of its own falls back to the workspace list in
 * config/sms.php, so a deployment can configure one on-call number once rather
 * than on every board.
 */
final readonly class BoardSmsSettings
{
    /**
     * @param  array<int, string>  $recipients
     */
    private function __construct(
        public bool $enabled,
        public array $recipients,
    ) {}

    public static function forBoard(Board $board): self
    {
        $raw = Arr::get($board->settings ?? [], 'sms');

        return self::fromArray(is_array($raw) ? $raw : []);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: (bool) ($raw['enabled'] ?? false),
            recipients: self::numbers($raw['recipients'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'recipients' => $this->recipients,
        ];
    }

    /**
     * Should a critical ticket on this board wake somebody up?
     *
     * Three switches again: the deployment-wide one, the board's own, and
     * whether there is anybody to text.
     */
    public function isActive(): bool
    {
        return (bool) config('sms.enabled')
            && $this->enabled
            && $this->numbersToAlert() !== [];
    }

    /**
     * Who to text, capped by the deployment ceiling.
     *
     * The board's own list wins outright rather than merging with the workspace
     * fallback. Merging would mean a board that deliberately narrowed its rota
     * to one person still texts everybody on the global list — the opposite of
     * what setting a board list means.
     *
     * @return array<int, string>
     */
    public function numbersToAlert(): array
    {
        $numbers = $this->recipients !== []
            ? $this->recipients
            : self::numbers((array) config('sms.recipients', []));

        return array_slice($numbers, 0, max(0, (int) config('sms.max_recipients', 5)));
    }

    /**
     * Normalise and validate a list of numbers, dropping anything unusable.
     *
     * E.164 only: a leading `+`, then 8 to 15 digits. Local formats are refused
     * rather than guessed at — "0701234567" is a different number in every
     * country, and an alert sent to the wrong one is worse than an alert
     * refused at the point somebody typed it.
     *
     * @param  array<int|string, mixed>  $values
     * @return array<int, string>
     */
    public static function numbers(array $values): array
    {
        $numbers = [];

        foreach ($values as $value) {
            $number = self::number(is_scalar($value) ? (string) $value : '');

            if ($number !== null && ! in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }

        return $numbers;
    }

    /**
     * One number in E.164, or null.
     *
     * Spaces, hyphens and parentheses are stripped because that is how people
     * write phone numbers, and refusing "+46 70 123 45 67" would be pedantry
     * rather than safety.
     */
    public static function number(string $value): ?string
    {
        $value = preg_replace('/[\s\-().]/', '', trim($value)) ?? '';

        // "00" is the international prefix in much of the world and means the
        // same thing as "+". Accepting it saves a support conversation.
        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $value) === 1 ? $value : null;
    }

    /**
     * Split a textarea of numbers — one per line or comma separated — into a
     * validated list.
     *
     * @return array<int, string>
     */
    public static function parseList(?string $input): array
    {
        $parts = preg_split('/[\r\n,;]+/', (string) $input) ?: [];

        return self::numbers($parts);
    }
}
