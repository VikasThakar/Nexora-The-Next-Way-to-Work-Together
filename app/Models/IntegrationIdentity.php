<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Nexora account that stands for a person in another system.
 *
 * Only ever written by App\Actions\Gritch\ProvisionGritchMember. See the
 * migration for why accounts are matched on (provider, external_id) and never
 * on email.
 */
class IntegrationIdentity extends Model
{
    public const PROVIDER_GRITCH = 'gritch';

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'external_id',
        'user_id',
        'last_login_at',
        'last_token_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'last_token_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
