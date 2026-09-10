<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\AiModelCatalogue;

/**
 * What the person asking wants this conversation to do.
 *
 * Three settings, chosen next to the Send button, held on the session, and
 * deliberately NOT the same question as AiCapabilityMode. That enum is the
 * administrator's ceiling on what the AI may ever attempt anywhere in the
 * workspace; this one is the asker's own choice within that ceiling, for the
 * conversation in front of them:
 *
 *   Reading     look things up and answer. No proposal tool is sent, so the
 *               conversation cannot draft a change even by accident.
 *   Writing     draft changes — tickets and documentation pages. The lookup
 *               tools are withheld, so the turn is about producing the change
 *               rather than surveying the board.
 *   Everything  both, which is what somebody wants when the answer to "what
 *               is missing here" is "and make a ticket for it".
 *
 * The two are combined by intersection and never by union: the mode below can
 * only ever narrow what AiCapabilityGuard already permits. Choosing Writing in
 * an AI Observer workspace gets no write tool, and choosing it as a customer
 * gets no write tool, because the guard says no first. See
 * App\Services\AI\WorkspaceChatService::exchange(), where both answers are
 * combined in one place.
 *
 * The model follows the mode
 * --------------------------
 * Reading runs on the balanced model and writing on the deep-reasoning one,
 * because the two jobs have genuinely different shapes: a lookup-and-summarise
 * turn is bounded and mostly about retrieval, while drafting a ticket or a page
 * somebody will act on is the turn worth spending on. Which model each maps to
 * is deployment configuration (`ai.chat.modes`), not a constant here, and the
 * chosen id is still resolved against the effective provider — a workspace
 * running on OpenAI gets that provider's default rather than an Anthropic id it
 * cannot serve. See App\Services\AI\AiSessionManager::useChatMode().
 */
enum AiChatMode: string
{
    case Reading = 'reading';
    case Writing = 'writing';
    case Everything = 'everything';

    /**
     * The raw value, for config files and column defaults that cannot call a
     * method.
     */
    public const READING = 'reading';

    public function label(): string
    {
        return match ($this) {
            self::Reading => 'Reading',
            self::Writing => 'Writing',
            self::Everything => 'Everything',
        };
    }

    /**
     * One line, for the picker's title and the hint under the composer.
     */
    public function summary(): string
    {
        return match ($this) {
            self::Reading => 'Looks things up and answers. Proposes no changes.',
            self::Writing => 'Drafts tickets and pages for you to confirm.',
            self::Everything => 'Looks things up and drafts changes.',
        };
    }

    /**
     * May the model be given the read-only lookup tools?
     *
     * False for Writing on purpose. The board context block still goes in on
     * every turn — the model is never asked to draft blind — but withholding
     * the lookups is what keeps a "write me a ticket for the login bug" turn
     * from becoming four searches and a summary before it gets to the draft.
     */
    public function allowsReadTools(): bool
    {
        return $this !== self::Writing;
    }

    /**
     * May the model be offered the `propose_*` tools?
     *
     * A ceiling of its own, checked alongside AiCapabilityGuard rather than
     * instead of it.
     */
    public function allowsWriteTools(): bool
    {
        return $this !== self::Reading;
    }

    /**
     * Which model this mode asks for, before the provider has its say.
     *
     * Null when the deployment has not named one, which means "use whatever
     * the configuration already resolved" rather than "fail".
     */
    public function preferredModel(): ?string
    {
        $model = trim((string) config('ai.chat.modes.'.$this->value.'.model', ''));

        return $model === '' ? null : $model;
    }

    /**
     * How the model is described where the choice is shown.
     *
     * Reads the catalogue rather than repeating a label, so a deployment that
     * points Writing at a different model does not have to remember to edit a
     * sentence. Empty when the model is not in the catalogue, in which case the
     * picker simply says nothing about it.
     */
    public function modelLabel(): string
    {
        return (string) (AiModelCatalogue::find($this->preferredModel())?->label ?? '');
    }

    /**
     * The mode a conversation starts in when nothing says otherwise.
     *
     * Reading, so the safe setting is the one somebody gets by not choosing.
     */
    public static function default(): self
    {
        return self::tryFrom((string) config('ai.chat.modes.default')) ?? self::Reading;
    }

    /**
     * A value from anywhere — a browser, a stored column — as a mode.
     *
     * Never throws. An unknown value is the default, for the same reason
     * AiModelCatalogue coerces a retired model id: the failure mode of a stale
     * setting should be "it answered" rather than "every request 500s".
     */
    public static function coerce(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::default()) : self::default();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
