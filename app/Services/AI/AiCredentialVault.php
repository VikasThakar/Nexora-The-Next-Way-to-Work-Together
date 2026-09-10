<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Actions\Boards\UpdateBoard;
use App\Enums\AiProvider;
use App\Models\AiCredential;
use App\Models\Board;
use App\Models\User;
use App\Support\BoardAiSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * The only class in the application that reads or writes a provider API key.
 *
 * Everything about keys is here so that the number of places a key can escape
 * from is one. Nothing above this class ever holds a plaintext credential: the
 * settings screen asks whether one exists and what its last four characters
 * are, the resolver asks where one would come from, and the provider adapters
 * are handed one at the moment of the call and do not keep it.
 *
 * Precedence
 * ----------
 * Three sources, most specific first:
 *
 *   1. the board's own key, in `boards.settings.ai.credentials`. The exception
 *      rather than the norm — a project billed to its own account.
 *   2. the workspace key, an `ai_credentials` row. What an administrator sets
 *      on the global AI settings screen, and what almost every deployment will
 *      use.
 *   3. the environment, via config. Still first-class: a deployment that has
 *      always set ANTHROPIC_API_KEY keeps working with no database row at all,
 *      which is what makes this feature additive rather than a migration.
 *
 * A board that only wants a different *model* therefore inherits the workspace
 * key and nothing is duplicated. That was an explicit requirement and it falls
 * out of resolving each field independently rather than copying a configuration
 * down a level.
 *
 * Failure is silent and closed
 * ----------------------------
 * A key that cannot be decrypted — which in practice means APP_KEY was rotated
 * after it was stored — reads as absent. Not as an exception: throwing here
 * would break every page that asks "is AI configured?", and the honest
 * consequence of a key rotation is that the AI stops working until somebody
 * pastes the key again. Exactly the choice BoardSlackSettings makes for its
 * webhook URL, and for the same reasons.
 *
 * Nothing here is logged, ever
 * ----------------------------
 * No log line, no activity record, no exception message in this class contains
 * a key or any part of one beyond the last four characters. The masking helpers
 * are the only thing that turns a key into something printable, and they throw
 * away all but four characters before returning.
 */
class AiCredentialVault
{
    /**
     * Credential rows already looked up this request, keyed by provider value.
     *
     * A null entry is a real answer — "there is no row for this provider" —
     * which is why membership is tested with array_key_exists rather than by
     * truthiness. Without that, the absence of a key would be re-queried on
     * every check, which is the common case.
     *
     * @var array<string, AiCredential|null>
     */
    private array $records = [];

    public function __construct(private readonly UpdateBoard $updateBoard) {}

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * The key to send for this provider, in this context, or null.
     *
     * The return value is a live credential. Callers pass it straight to a
     * provider adapter and must not store it, assign it to a property a dump
     * could reach, or interpolate it into a message.
     */
    public function keyFor(AiProvider $provider, ?Board $board = null): ?string
    {
        if ($board instanceof Board) {
            $boardKey = $this->decrypt(
                BoardAiSettings::forBoard($board)->encryptedCredential($provider)
            );

            if ($boardKey !== null) {
                return $boardKey;
            }
        }

        $stored = $this->record($provider);

        if ($stored instanceof AiCredential) {
            $workspaceKey = $this->decrypt($stored->secret);

            if ($workspaceKey !== null) {
                return $workspaceKey;
            }
        }

        return $this->environmentKey($provider);
    }

    /**
     * Where the key would come from: 'board', 'global', 'config', or 'none'.
     *
     * The settings screens render this. It deliberately re-walks the same chain
     * rather than being derived from keyFor() — a method that returned both the
     * key and its origin would be a method somebody calls to get the origin.
     */
    public function sourceFor(AiProvider $provider, ?Board $board = null): string
    {
        if ($board instanceof Board && BoardAiSettings::forBoard($board)->hasCredentialFor($provider)) {
            return 'board';
        }

        if ($this->record($provider) !== null) {
            return 'global';
        }

        return filled($this->environmentKey($provider)) ? 'config' : 'none';
    }

    /**
     * Is a key reachable for this provider at all?
     */
    public function has(AiProvider $provider, ?Board $board = null): bool
    {
        return filled($this->keyFor($provider, $board));
    }

    /**
     * The stored workspace credential row for a provider, if there is one.
     *
     * Memoised per provider for the request, because "is a key configured?" is
     * asked several times per render — the navigation, the panel header, the
     * composer, the model picker — and each ask is otherwise an indexed
     * lookup. A credential row cannot change within a request except through
     * this class, and the writes below drop the memo.
     *
     * The memo holds the row, ciphertext included, for the length of one
     * request. It does not hold a decrypted key: decrypt() is called per read
     * and its result is returned, never kept.
     */
    public function record(AiProvider $provider): ?AiCredential
    {
        if (array_key_exists($provider->value, $this->records)) {
            return $this->records[$provider->value];
        }

        return $this->records[$provider->value] = AiCredential::query()
            ->where('provider', $provider->value)
            ->first();
    }

    /**
     * Drop the memo, after a write or when configuration changes underneath.
     */
    public function flush(): void
    {
        $this->records = [];
    }

    /**
     * Every stored workspace credential, keyed by provider value.
     *
     * For the settings screen, which renders presence, the last four characters
     * and who last changed it — never the value.
     *
     * @return Collection<int, AiCredential>
     */
    public function records(): Collection
    {
        return AiCredential::query()->with(['updatedBy', 'createdBy'])->get();
    }

    // -----------------------------------------------------------------
    // Writing: the workspace key
    // -----------------------------------------------------------------

    /**
     * Store or replace the workspace key for a provider.
     *
     * Add and Replace are the same operation on purpose. There is no way to
     * read a stored key, so "change it" can only ever mean "supply a new one",
     * and a separate update path would only differ in which row it wrote.
     *
     * The plaintext lives in this method and in the encrypt call it makes. It
     * is not assigned to the model, not returned, and not passed on.
     */
    public function store(AiProvider $provider, string $key, ?string $hint, ?User $actor = null): AiCredential
    {
        $key = trim($key);

        $credential = $this->record($provider) ?? new AiCredential;

        $isNew = ! $credential->exists;

        $credential->provider = $provider;
        $credential->secret = Crypt::encryptString($key);
        $credential->last_four = self::lastFour($key);
        $credential->hint = self::hint($hint);
        $credential->rotated_at = now();

        if ($isNew) {
            $credential->created_by_id = $actor?->getKey();
        }

        $credential->updated_by_id = $actor?->getKey();

        $credential->save();

        $this->flush();

        return $credential;
    }

    /**
     * Change only the label, leaving the key alone.
     *
     * Separate from store() because it must be possible to correct a typo in
     * "Producton account" without re-pasting a credential — and because a
     * single method taking an optional key would make forgetting to pass it
     * silently blank the key.
     */
    public function updateHint(AiProvider $provider, ?string $hint, ?User $actor = null): ?AiCredential
    {
        $credential = $this->record($provider);

        if (! $credential instanceof AiCredential) {
            return null;
        }

        $credential->hint = self::hint($hint);
        $credential->updated_by_id = $actor?->getKey();

        $credential->save();

        $this->flush();

        return $credential;
    }

    /**
     * Remove the workspace key for a provider.
     *
     * The row goes rather than being blanked, so "is a key stored?" stays a
     * question about existence. Note what this does *not* do: it does not stop
     * AI working if the environment still holds a key, because the environment
     * is a legitimate source and removing a database row is not a statement
     * about the deployment's configuration. The screen says so.
     */
    public function remove(AiProvider $provider): bool
    {
        $credential = $this->record($provider);

        if (! $credential instanceof AiCredential) {
            return false;
        }

        $deleted = (bool) $credential->delete();

        $this->flush();

        return $deleted;
    }

    // -----------------------------------------------------------------
    // Writing: a board's own key
    // -----------------------------------------------------------------

    /**
     * Give one board its own key for a provider.
     *
     * Written through App\Actions\Boards\UpdateBoard, which merges rather than
     * replaces `settings`, so a screen that knows only about credentials cannot
     * drop the board's automation settings on its way past — the same reason
     * UpdateBoardAiSettings goes through it.
     */
    public function storeForBoard(Board $board, AiProvider $provider, string $key): void
    {
        $key = trim($key);

        $credentials = BoardAiSettings::forBoard($board)->withCredential(
            $provider,
            Crypt::encryptString($key),
            self::lastFour($key),
        );

        $this->writeBoardCredentials($board, $credentials);
    }

    /**
     * Take a board's own key away, so it inherits the workspace's again.
     */
    public function removeForBoard(Board $board, AiProvider $provider): void
    {
        $credentials = BoardAiSettings::forBoard($board)->withCredential($provider, null, null);

        $this->writeBoardCredentials($board, $credentials);
    }

    /**
     * @param  array<string, array<string, mixed>>  $credentials
     */
    private function writeBoardCredentials(Board $board, array $credentials): void
    {
        $settings = BoardAiSettings::forBoard($board)->toArray();
        $settings['credentials'] = $credentials;

        $this->updateBoard->handle($board, ['settings' => ['ai' => $settings]]);

        $board->refresh();
    }

    // -----------------------------------------------------------------
    // Shaping a key for display, and refusing an obvious mistake
    // -----------------------------------------------------------------

    /**
     * The last four characters, or null for something too short to have any.
     *
     * A key shorter than eight characters gets no hint at all: showing four of
     * six characters is showing most of a secret.
     */
    public static function lastFour(string $key): ?string
    {
        $key = trim($key);

        return mb_strlen($key) < 8 ? null : mb_substr($key, -4);
    }

    /**
     * Twelve dots and the last four characters.
     */
    public static function mask(string $key): string
    {
        return str_repeat('•', 12).(self::lastFour($key) ?? '');
    }

    /**
     * Does this look like a key for this provider?
     *
     * A shape check, run before anything is stored, to catch the mistakes that
     * actually happen — a pasted URL, a truncated copy, somebody's password in
     * the wrong field, a key for the other vendor. It is emphatically not
     * validation: only the provider can say whether a key works, and a
     * well-shaped key that has been revoked will pass this and fail on the
     * first request, which is correct.
     *
     * The prefixes overlap, and that is the whole subtlety here. An Anthropic
     * key begins `sk-ant-`, which also begins `sk-` — OpenAI's prefix — so a
     * plain "starts with" test would accept an Anthropic key as an OpenAI one
     * and store it where it can only ever fail. So the *longest* matching
     * prefix wins, and it has to be this provider's.
     */
    public static function looksLikeKey(AiProvider $provider, string $key): bool
    {
        $key = trim($key);

        if (mb_strlen($key) < 20 || mb_strlen($key) > 400) {
            return false;
        }

        // Whitespace inside a key means a copy that took a line break with it.
        if (preg_match('/\s/u', $key) === 1) {
            return false;
        }

        return self::bestPrefixMatch($key) === $provider;
    }

    /**
     * The provider whose prefix most specifically matches this key, or null.
     *
     * Longest match rather than first match, so overlapping prefixes resolve to
     * the more specific vendor. Used only by looksLikeKey(); it takes a key and
     * returns a provider, never the other way round.
     */
    private static function bestPrefixMatch(string $key): ?AiProvider
    {
        $best = null;
        $bestLength = 0;

        foreach (AiProvider::cases() as $candidate) {
            $prefix = $candidate->keyPrefixHint();

            if (str_starts_with($key, $prefix) && mb_strlen($prefix) > $bestLength) {
                $best = $candidate;
                $bestLength = mb_strlen($prefix);
            }
        }

        return $best;
    }

    /**
     * A short free-text label, or null.
     *
     * Truncated hard, and refused outright if it looks like somebody pasted a
     * key into the label field — where it would be rendered back to the screen.
     */
    private static function hint(?string $hint): ?string
    {
        $hint = trim((string) $hint);

        if ($hint === '') {
            return null;
        }

        foreach (AiProvider::cases() as $provider) {
            if (str_starts_with($hint, $provider->keyPrefixHint()) && mb_strlen($hint) >= 20) {
                return null;
            }
        }

        return mb_substr($hint, 0, 60);
    }

    // -----------------------------------------------------------------

    /**
     * What config, and therefore the environment, has to say.
     */
    private function environmentKey(AiProvider $provider): ?string
    {
        $key = trim((string) config($provider->credentialConfigKey()));

        return $key === '' ? null : $key;
    }

    /**
     * Decrypt stored ciphertext, treating anything unreadable as absent.
     *
     * See the class comment: a DecryptException here means APP_KEY changed
     * since the key was stored, and degrading to "not configured" stops the AI
     * rather than breaking every page that asks about it.
     */
    private function decrypt(?string $ciphertext): ?string
    {
        if ($ciphertext === null || trim($ciphertext) === '') {
            return null;
        }

        try {
            $key = trim(Crypt::decryptString($ciphertext));
        } catch (DecryptException) {
            return null;
        }

        return $key === '' ? null : $key;
    }
}
