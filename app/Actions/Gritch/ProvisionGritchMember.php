<?php

declare(strict_types=1);

namespace App\Actions\Gritch;

use App\Actions\Boards\AddBoardMember;
use App\Actions\Boards\RemoveBoardMember;
use App\Actions\Users\CreateUser;
use App\Enums\UserRole;
use App\Models\Board;
use App\Models\IntegrationIdentity;
use App\Models\User;
use App\Services\Gritch\GritchAccessRefused;
use App\Services\Gritch\GritchAssertion;
use App\Services\Gritch\GritchEmbed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Nexora account for a Gritch admin, made ready for use.
 *
 * Runs every time Gritch signs somebody in — for the iframe and for the admin
 * AI alike — and is idempotent, so the account is created on the first visit
 * and only checked on every one after it:
 *
 *   found by SaleSys id, never by email. An account created here is linked to
 *   nobody else's, so a Gritch admin who changed an email address in SaleSys
 *   still only reaches the account made for them.
 *
 *   role `team`, and refused if it is anything else. A team member's reach is
 *   their board memberships; an administrator's is every board. If somebody
 *   promotes the account, Gritch stops working for that person rather than
 *   widening their view — fail closed.
 *
 *   a member of the Gritch board and of nothing else. Memberships added by
 *   hand are removed again, through the ordinary actions, so the activity log
 *   records each change.
 *
 *   refused while deactivated. Deactivating the account in Nexora is how an
 *   administrator here turns one person's Gritch access off.
 *
 * The address is a placeholder under .invalid, a domain reserved never to
 * resolve (RFC 2606): unique per SaleSys id, unable to collide with a real
 * account and unable to receive a password reset. The password is random and
 * known to nobody.
 */
class ProvisionGritchMember
{
    public function __construct(
        private readonly CreateUser $createUser,
        private readonly AddBoardMember $addMember,
        private readonly RemoveBoardMember $removeMember,
    ) {}

    /**
     * @throws GritchAccessRefused
     */
    public function handle(GritchAssertion $assertion): User
    {
        $board = GritchEmbed::board()
            ?? throw new GritchAccessRefused('The Gritch board is not set up in Nexora yet. Ask a Nexora administrator.');

        // Two first visits at once (the iframe and the admin AI) must not both
        // create an account; the unique index would turn the loser into a 500.
        return Cache::lock('gritch-provision:'.$assertion->subject, 10)
            ->block(5, fn (): User => $this->provision($assertion, $board));
    }

    private function provision(GritchAssertion $assertion, Board $board): User
    {
        $identity = IntegrationIdentity::query()
            ->where('provider', IntegrationIdentity::PROVIDER_GRITCH)
            ->where('external_id', $assertion->subject)
            ->with('user')
            ->first();

        $user = $identity?->user ?? $this->create($assertion);

        if (! $user->isActive()) {
            throw new GritchAccessRefused('Your Nexora access has been turned off. Ask a Nexora administrator.');
        }

        if ($user->role !== UserRole::Team) {
            throw new GritchAccessRefused('Your Nexora account was changed and can no longer be opened from Gritch. Ask a Nexora administrator.');
        }

        if ($assertion->name !== '' && $user->name !== $assertion->name) {
            $user->name = $assertion->name;
            $user->save();
        }

        $this->keepOnlyThisBoard($user, $board);

        return $user;
    }

    private function create(GritchAssertion $assertion): User
    {
        return DB::transaction(function () use ($assertion): User {
            $user = $this->createUser->handle([
                'name' => $assertion->name !== '' ? $assertion->name : 'Gritch admin '.$assertion->subject,
                'email' => 'salesys-'.Str::lower($assertion->subject).'@gritch.invalid',
                'password' => Str::password(64),
            ], UserRole::Team);

            IntegrationIdentity::query()->create([
                'provider' => IntegrationIdentity::PROVIDER_GRITCH,
                'external_id' => $assertion->subject,
                'user_id' => $user->getKey(),
            ]);

            return $user;
        });
    }

    private function keepOnlyThisBoard(User $user, Board $board): void
    {
        $user->boards()->whereKeyNot($board->getKey())->get()
            ->each(fn (Board $other) => $this->removeMember->handle($other, $user));

        $this->addMember->handle($board, $user);
    }
}
