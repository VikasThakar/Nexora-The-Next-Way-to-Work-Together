<?php

declare(strict_types=1);

namespace App\Notifications\Slack;

/**
 * One message bound for a Slack incoming webhook.
 *
 * Deliberately small. Slack's Block Kit can render almost anything, and the
 * temptation with a ticket integration is to render the ticket — description,
 * comment body, AI summary — straight into the channel.
 *
 * This does not, and that is a security decision rather than a stylistic one.
 * A Slack channel is outside this application's authorization model: whoever is
 * in the room reads the message, and the workspace has no idea which of them is
 * a board member, which is a customer, and which is a contractor added last
 * week. So a message carries an identifier, a title, who did it, and a link.
 * The link is the access control — following it requires logging in, and the
 * ordinary visibility rules then apply to whatever they see.
 *
 * The `text` field is not a fallback nobody reads: it is what appears in the
 * desktop notification and the channel list preview, and a Block Kit message
 * without it shows up as "This content can't be displayed".
 */
final readonly class SlackMessage
{
    /**
     * @param  array<int, array{label: string, value: string}>  $fields
     */
    private function __construct(
        public string $text,
        public string $headline,
        public array $fields,
        public ?string $url,
        public ?string $linkLabel,
        public ?string $context,
    ) {}

    /**
     * @param  array<int, array{label: string, value: string}>  $fields
     */
    public static function make(
        string $text,
        string $headline,
        array $fields = [],
        ?string $url = null,
        ?string $linkLabel = null,
        ?string $context = null,
    ): self {
        return new self($text, $headline, $fields, $url, $linkLabel, $context);
    }

    /**
     * The Block Kit payload.
     *
     * `mrkdwn` is Slack's own dialect, not Markdown, and it treats `<`, `>` and
     * `&` as control characters for its link syntax. Escaping them is what
     * stops a ticket titled `<https://evil.example|Click here>` rendering as a
     * link in the channel — a small thing that becomes a phishing vector once
     * the audience is a room full of people who trust the bot.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $blocks = [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => '*'.self::escape($this->headline).'*'],
        ]];

        if ($this->fields !== []) {
            $blocks[] = [
                'type' => 'section',
                // Slack renders at most ten fields in one section and silently
                // drops the rest, so the slice is explicit rather than trusting
                // the caller to have counted.
                'fields' => array_map(
                    fn (array $field): array => [
                        'type' => 'mrkdwn',
                        'text' => '*'.self::escape($field['label']).'*'."\n".self::escape($field['value']),
                    ],
                    array_slice($this->fields, 0, 10),
                ),
            ];
        }

        if ($this->url !== null) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [[
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => $this->linkLabel ?? 'Open in workspace'],
                    'url' => $this->url,
                ]],
            ];
        }

        if ($this->context !== null) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => self::escape($this->context)]],
            ];
        }

        return [
            'text' => self::escape($this->text),
            'blocks' => $blocks,
        ];
    }

    /**
     * Escape Slack's three control characters, and nothing else.
     *
     * Not htmlspecialchars: that would also escape quotes and turn every
     * apostrophe in a ticket title into `&#039;` in the channel.
     */
    public static function escape(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }
}
