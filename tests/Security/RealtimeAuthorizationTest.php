<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Models\Board;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Realtime must not be a second, weaker copy of the visibility rules.
 *
 * This class covers the first of the two protections: a subscription is an
 * authorized request against POST /broadcasting/auth, decided by
 * routes/channels.php, which asks BoardAccess rather than restating the rules.
 * A customer is refused the internal channel outright.
 *
 * The second protection — events carrying no content at all — is covered by
 * RealtimeBroadcastAudienceTest.
 *
 * These tests drive the real HTTP endpoint. The test environment defaults
 * to the null broadcaster, whose auth() is a no-op and would pass everything,
 * so each test switches to a real driver with throwaway credentials and
 * re-registers the channel routes against it.
 */
class RealtimeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => [
                    'host' => '127.0.0.1',
                    'port' => 8080,
                    'scheme' => 'http',
                    'useTLS' => false,
                ],
                'client_options' => [],
            ],
        ]);

        // Channels were registered against the null driver at boot; the driver
        // just switched, so register them against the new one.
        require base_path('routes/channels.php');
    }

    private function subscribe(string $channel)
    {
        return $this->postJson('/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ]);
    }

    private function internalChannel(Board $board): string
    {
        return 'private-board.'.$board->getKey().'.internal';
    }

    private function customerChannel(Board $board): string
    {
        return 'private-board.'.$board->getKey().'.customer';
    }

    public function test_a_customer_cannot_subscribe_to_the_internal_channel_of_their_own_board(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->actingAs($customer)
            ->subscribe($this->internalChannel($board))
            ->assertForbidden();
    }

    public function test_a_customer_may_subscribe_to_the_customer_channel_of_their_own_board(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->actingAs($customer)
            ->subscribe($this->customerChannel($board))
            ->assertOk();
    }

    public function test_a_staff_member_of_the_board_may_subscribe_to_the_internal_channel(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->actingAs($team)
            ->subscribe($this->internalChannel($board))
            ->assertOk();
    }

    public function test_a_non_member_is_refused_both_channels(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->actingAs($outsider)->subscribe($this->internalChannel($board))->assertForbidden();
        $this->actingAs($outsider)->subscribe($this->customerChannel($board))->assertForbidden();
    }

    public function test_a_deactivated_user_is_refused(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $team->forceFill(['deactivated_at' => now()])->save();

        $response = $this->actingAs($team)->subscribe($this->internalChannel($board));

        // Refused before the channel callback is even reached: the
        // EnsureUserIsActive middleware ends the session, so this comes back as
        // 401 rather than 403. Either is a refusal; what matters is that a
        // deactivated account cannot hold a subscription.
        $this->assertContains(
            $response->status(),
            [401, 403],
            'A deactivated user must not be able to subscribe, got '.$response->status()
        );
    }

    public function test_a_guest_cannot_subscribe_to_anything(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->subscribe($this->internalChannel($board))->assertForbidden();
    }

    public function test_a_channel_for_a_board_that_does_not_exist_is_refused(): void
    {
        $team = $this->teamMember();

        $this->actingAs($team)->subscribe('private-board.999999.internal')->assertForbidden();
        $this->actingAs($team)->subscribe('private-board.999999.customer')->assertForbidden();
    }

    public function test_a_user_channel_belongs_only_to_that_user(): void
    {
        $one = $this->teamMember();
        $two = $this->teamMember();

        $this->actingAs($one)->subscribe('private-users.'.$one->getKey())->assertOk();
        $this->actingAs($one)->subscribe('private-users.'.$two->getKey())->assertForbidden();
    }
}
