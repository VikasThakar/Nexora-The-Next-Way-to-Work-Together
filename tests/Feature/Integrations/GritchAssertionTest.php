<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Services\Gritch\GritchAssertion;
use App\Services\Gritch\InvalidGritchAssertion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The one credential the Gritch integration accepts.
 *
 * The first test is a fixed vector that gritch-backend's
 * tests/Unit/Nexora/NexoraAssertionTest.php checks too, byte for byte: the
 * two sides sign independently, and this is what stops them drifting apart.
 */
class GritchAssertionTest extends TestCase
{
    private const VECTOR_SECRET = 'nexora-gritch-shared-test-vector-secret-0001';

    private const VECTOR = 'eyJpc3MiOiJncml0Y2giLCJhdWQiOiJuZXhvcmEiLCJ0eXAiOiJlbWJlZCIsInN1YiI6IjY1ZjBjMGZmZWUwMDAwMDAwMDAwYWJjZCIsIm5hbWUiOiLDhXNhIMOWYmVyZyIsImlhdCI6MTc5MjUwMDAwMCwiZXhwIjoxNzkyNTAwMDYwLCJqdGkiOiJ0ZXN0LXZlY3Rvci1qdGktMDAwMDAwMDAwMSJ9.wvBuremjupnw-hlrlAvHuRhjHSTz05KCTFsdOCkt3Fg';

    protected function setUp(): void
    {
        parent::setUp();

        config(['gritch.secret' => self::VECTOR_SECRET]);
    }

    public function test_the_shared_vector_signs_and_verifies_identically_on_both_sides(): void
    {
        $claims = [
            'iss' => 'gritch',
            'aud' => 'nexora',
            'typ' => 'embed',
            'sub' => '65f0c0ffee0000000000abcd',
            'name' => 'Åsa Öberg',
            'iat' => 1792500000,
            'exp' => 1792500060,
            'jti' => 'test-vector-jti-0000000001',
        ];

        $this->assertSame(self::VECTOR, GritchAssertion::sign($claims));

        $assertion = GritchAssertion::verify(self::VECTOR, 'embed', CarbonImmutable::createFromTimestamp(1792500030));

        $this->assertSame('65f0c0ffee0000000000abcd', $assertion->subject);
        $this->assertSame('Åsa Öberg', $assertion->name);
        $this->assertSame('test-vector-jti-0000000001', $assertion->id);
    }

    public function test_an_assertion_is_redeemed_once(): void
    {
        $token = $this->assertion();

        GritchAssertion::redeem($token, 'token');

        $this->expectExceptionObject(new InvalidGritchAssertion('Assertion already used.'));

        GritchAssertion::redeem($token, 'token');
    }

    public function test_everything_wrong_is_refused(): void
    {
        $now = CarbonImmutable::now();

        $cases = [
            'another secret' => GritchAssertion::sign($this->claims(), str_repeat('x', 40)),
            'wrong type' => $this->assertion(['typ' => 'embed']),
            'wrong audience' => $this->assertion(['aud' => 'gritch']),
            'wrong issuer' => $this->assertion(['iss' => 'someone']),
            'expired' => $this->assertion(['iat' => $now->getTimestamp() - 200, 'exp' => $now->getTimestamp() - 100]),
            'from the future' => $this->assertion(['iat' => $now->getTimestamp() + 300, 'exp' => $now->getTimestamp() + 360]),
            'lives too long' => $this->assertion(['exp' => $now->getTimestamp() + 3600]),
            'times as strings' => $this->assertion(['iat' => (string) $now->getTimestamp()]),
            'subject with an at sign' => $this->assertion(['sub' => 'a@b']),
            'empty subject' => $this->assertion(['sub' => '']),
            'short id' => $this->assertion(['jti' => 'abc']),
            'tampered claims' => $this->tampered(),
            'two dots' => $this->assertion().'.x',
            'empty' => '',
            'garbage' => 'not-an-assertion',
        ];

        foreach ($cases as $label => $token) {
            try {
                GritchAssertion::redeem($token, 'token', $now);
                $this->fail("Accepted: {$label}");
            } catch (InvalidGritchAssertion) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_without_a_real_secret_nothing_verifies(): void
    {
        $token = $this->assertion();

        foreach (['', 'short-secret'] as $secret) {
            config(['gritch.secret' => $secret]);

            try {
                GritchAssertion::verify($token, 'token');
                $this->fail('Verified with secret '.json_encode($secret));
            } catch (InvalidGritchAssertion $e) {
                $this->assertSame('No integration secret configured.', $e->getMessage());
            }
        }
    }

    public function test_the_name_is_cleaned_not_trusted(): void
    {
        $assertion = GritchAssertion::verify($this->assertion(['name' => "  Vikas\u{0007}\nThakar ".str_repeat('x', 200)]), 'token');

        $this->assertStringStartsWith('Vikas Thakar', $assertion->name);
        $this->assertSame(120, mb_strlen($assertion->name));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertion(array $overrides = []): string
    {
        return GritchAssertion::sign($this->claims($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        $now = now()->getTimestamp();

        return [
            'iss' => 'gritch',
            'aud' => 'nexora',
            'typ' => 'token',
            'sub' => '65f0c0ffee0000000000abcd',
            'name' => 'Vikas Thakar',
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => Str::random(32),
            ...$overrides,
        ];
    }

    private function tampered(): string
    {
        [, $signature] = explode('.', $this->assertion());
        $forged = rtrim(strtr(base64_encode((string) json_encode($this->claims(['sub' => 'someone-else']))), '+/', '-_'), '=');

        return $forged.'.'.$signature;
    }
}
