<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One provider API key, at rest.
 *
 * The model deliberately does very little. It has no accessor that returns the
 * key, no cast that decrypts it, and no `toArray()` that could carry it into a
 * JSON response — `$hidden` covers the column, and every read of the plaintext
 * goes through App\Services\AI\AiCredentialVault, which is the only class that
 * calls Crypt on it.
 *
 * That is the whole design. A model with a `decryptedSecret()` accessor would be
 * one `dd($credential)` away from a key in a browser, and one `->toJson()` away
 * from a key in a log. So the accessor does not exist, and the class that does
 * the decrypting has no path to a view.
 *
 * What IS safe to show is `maskedSecret()`: the last four characters behind
 * dots. Four characters cannot authenticate anything, and being able to tell
 * two keys apart is the difference between rotating a credential confidently
 * and hoping.
 */
class AiCredential extends Model
{
    /**
     * Written only through AiCredentialVault, which encrypts first.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Belt as well as braces.
     *
     * Nothing should ever serialise one of these, but if something does, the
     * ciphertext does not travel with it. Ciphertext is not plaintext; it is
     * also not something an application has any reason to hand out.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'rotated_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    // ---------------------------------------------------------------------
    // Display
    // ---------------------------------------------------------------------

    /**
     * What the settings screen renders in place of the key.
     *
     * Twelve dots and the last four characters, which is what every credential
     * dashboard shows and for the same reason. The dot count is fixed rather
     * than derived from the key's length, because the length of a secret is
     * information about the secret.
     */
    public function maskedSecret(): string
    {
        $lastFour = trim((string) $this->last_four);

        return str_repeat('•', 12).($lastFour === '' ? '' : $lastFour);
    }

    /**
     * The last four characters, or null if this row predates them.
     */
    public function lastFour(): ?string
    {
        $lastFour = trim((string) $this->last_four);

        return $lastFour === '' ? null : $lastFour;
    }
}
