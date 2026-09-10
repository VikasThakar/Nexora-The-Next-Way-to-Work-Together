<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A language model vendor the workspace can talk to.
 *
 * The enum is the list of providers the *application* has an adapter for, not
 * the list a deployment has switched on. Whether a provider is usable is a
 * separate question with a separate answer — is a credential stored, and does
 * the catalogue offer any models for it — and it is asked by
 * App\Services\AI\AiConfigurationResolver, never here.
 *
 * Adding a third provider is: a case here, an implementation of
 * AiProviderInterface, a line in AiProviderRegistry, and a models block in
 * config/ai.php. Nothing else in the application names a vendor.
 *
 * The credential
 * --------------
 * Each case knows the config key its environment-supplied credential lives
 * under, and nothing else about it. The value is read in exactly two places —
 * the vault that stores the database-held key, and the adapter that sends it —
 * and is never returned to a view, a Livewire property or a log line. See
 * App\Services\AI\AiCredentialVault.
 */
enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';

    /**
     * The raw value, for config files that cannot call a method.
     */
    public const ANTHROPIC = 'anthropic';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenAi => 'OpenAI',
        };
    }

    /**
     * Where an environment-supplied credential for this provider is read from.
     *
     * The environment remains a first-class source: a deployment that has
     * always set ANTHROPIC_API_KEY keeps working with no database row at all.
     * A key stored through the admin screen simply takes precedence.
     */
    public function credentialConfigKey(): string
    {
        return match ($this) {
            self::Anthropic => 'ai.anthropic.api_key',
            self::OpenAi => 'ai.openai.api_key',
        };
    }

    /**
     * The environment variable a deployment would set instead, named for the
     * benefit of the settings screen's help text. Not read here.
     */
    public function environmentVariable(): string
    {
        return match ($this) {
            self::Anthropic => 'ANTHROPIC_API_KEY',
            self::OpenAi => 'OPENAI_API_KEY',
        };
    }

    /**
     * What a key from this provider looks like, roughly.
     *
     * Used to reject an obvious paste error — a webhook URL, a truncated copy,
     * somebody's password — before it is encrypted and stored, and never as a
     * claim that a well-shaped key is valid. The provider decides that.
     */
    public function keyPrefixHint(): string
    {
        return match ($this) {
            self::Anthropic => 'sk-ant-',
            self::OpenAi => 'sk-',
        };
    }

    public static function default(): self
    {
        return self::tryFrom((string) config('ai.provider.default')) ?? self::Anthropic;
    }

    public static function fromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
