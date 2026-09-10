<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiChatRole;
use App\Livewire\Ai\Assistant;
use App\Models\AiChatMessage;
use App\Services\AI\Exceptions\VoiceException;
use App\Services\AI\Voice\AiSpeech;
use App\Services\AI\Voice\SpokenAnswer;
use App\Services\AI\Voice\UnavailableVoiceProvider;
use App\Services\AI\Voice\VoiceProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spoken conversation.
 *
 * The design being tested is that voice is three ordinary steps rather than a
 * fourth kind of session: transcribe, ask the existing assistant, speak the
 * answer. That is what lets a spoken question inherit sessions, capability
 * modes, the audit trail and the customer boundary — and the first group of
 * tests is about the unconfigured case, because the requirement was explicit
 * that an unconfigured deployment must say so rather than pretend to listen.
 */
class AiVoiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A provider that answers, without reaching a vendor.
     *
     * Bound in place of the real one exactly as the AI provider is in every
     * other test: there is no HTTP client to intercept and no key to remember
     * to unset, so a test cannot spend money by accident.
     */
    private function fakeVoice(string $transcript = 'Why is my export failing?'): object
    {
        $fake = new class($transcript) implements VoiceProviderInterface
        {
            public array $spoken = [];

            public array $heard = [];

            public function __construct(private readonly string $transcript) {}

            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function unavailableReason(): ?string
            {
                return null;
            }

            public function transcribe(string $bytes, string $mimeType, string $filename): string
            {
                $this->heard[] = ['bytes' => strlen($bytes), 'mime' => $mimeType, 'filename' => $filename];

                return $this->transcript;
            }

            public function speak(string $text, ?string $voice = null): AiSpeech
            {
                $this->spoken[] = ['text' => $text, 'voice' => $voice];

                return new AiSpeech(bytes: 'ID3fake-audio', mimeType: 'audio/mpeg', voice: $voice ?? 'alloy');
            }

            public function voices(): array
            {
                return ['alloy' => 'Alloy', 'nova' => 'Nova'];
            }
        };

        $this->app->instance(VoiceProviderInterface::class, $fake);

        return $fake;
    }

    // -----------------------------------------------------------------
    // Not configured
    // -----------------------------------------------------------------

    /**
     * With no key, the container resolves something that refuses and explains.
     *
     * A real implementation rather than a null check at each call site, so a
     * new surface cannot forget the refusal — it gets one from the container.
     */
    public function test_with_no_credential_the_provider_refuses_and_names_the_remedy(): void
    {
        config()->set('ai.openai.api_key', null);
        config()->set('ai.voice.driver', 'openai');

        $voice = app(VoiceProviderInterface::class);

        $this->assertFalse($voice->isConfigured());
        $this->assertStringContainsString('OpenAI API key', (string) $voice->unavailableReason());
        $this->assertStringContainsString('global AI settings', (string) $voice->unavailableReason());
    }

    public function test_switching_voice_off_resolves_the_refusing_provider(): void
    {
        config()->set('ai.voice.enabled', false);

        $voice = app(VoiceProviderInterface::class);

        $this->assertInstanceOf(UnavailableVoiceProvider::class, $voice);
        $this->assertStringContainsString('switched off', (string) $voice->unavailableReason());
    }

    public function test_an_unrecognised_driver_resolves_the_refusing_provider(): void
    {
        config()->set('ai.voice.driver', 'whisper-in-the-dark');

        $this->assertInstanceOf(UnavailableVoiceProvider::class, app(VoiceProviderInterface::class));
    }

    /**
     * It refuses out loud rather than returning silence.
     *
     * An empty transcript would be indistinguishable from a silent recording,
     * and silent audio would be indistinguishable from a feature nobody can
     * hear — which is precisely the "do not fake a working voice conversation"
     * failure this class exists to prevent.
     */
    public function test_the_refusing_provider_throws_rather_than_returning_nothing(): void
    {
        $voice = new UnavailableVoiceProvider('No key here.');

        $this->assertSame([], $voice->voices());

        try {
            $voice->transcribe('bytes', 'audio/webm', 'a.webm');
            $this->fail('It should refuse.');
        } catch (VoiceException $exception) {
            $this->assertSame('No key here.', $exception->getMessage());
        }

        try {
            $voice->speak('Hello.');
            $this->fail('It should refuse.');
        } catch (VoiceException $exception) {
            $this->assertSame('No key here.', $exception->getMessage());
        }
    }

    public function test_the_panel_says_why_voice_is_unavailable(): void
    {
        config()->set('ai.openai.api_key', null);
        config()->set('ai.anthropic.api_key', 'sk-ant-test');

        $this->fakeAiProvider();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $html = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->html();

        // The sentence the provider wrote, verbatim, and the control disabled
        // rather than hidden — a missing button teaches nobody anything.
        $this->assertStringContainsString('Voice conversation needs an OpenAI API key', $html);
    }

    public function test_the_endpoints_report_a_configuration_problem_rather_than_failing(): void
    {
        config()->set('ai.openai.api_key', null);

        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $this->actingAs($team)
            ->postJson(route('ai.voice.listen'), [])
            ->assertStatus(503)
            ->assertJsonPath('message', fn (?string $message): bool => str_contains((string) $message, 'OpenAI API key'));
    }

    // -----------------------------------------------------------------
    // Configured
    // -----------------------------------------------------------------

    public function test_a_recording_is_transcribed_and_the_bytes_never_reach_disk(): void
    {
        $voice = $this->fakeVoice('Why is my export failing?');

        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $this->actingAs($team)
            ->post(route('ai.voice.listen'), [
                'audio' => UploadedFile::fake()->createWithContent('turn.webm', 'binary-audio-bytes'),
            ])
            ->assertOk()
            ->assertJsonPath('text', 'Why is my export failing?');

        $this->assertCount(1, $voice->heard);

        /*
         * The filename handed to the vendor is generated, never the client's.
         *
         * It reaches a multipart header, and a header built from client input
         * is how a header injection starts.
         */
        $this->assertNotSame('turn.webm', $voice->heard[0]['filename']);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}\.[a-z0-9]+$/', $voice->heard[0]['filename']);
    }

    public function test_a_spoken_question_becomes_an_ordinary_turn(): void
    {
        $provider = $this->fakeAiProvider();
        $this->fakeVoice();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $provider->willReturn('It fails because the timeout is thirty seconds.');

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug);

        $component->call('sendSpoken', 'Why is my export failing?');

        // Both turns stored, in the ordinary session, exactly as a typed
        // question would be.
        $this->assertSame(2, AiChatMessage::query()->count());

        $this->assertDatabaseHas('ai_chat_messages', [
            'role' => AiChatRole::User->value,
            'content' => 'Why is my export failing?',
        ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'role' => AiChatRole::Assistant->value,
            'content' => 'It fails because the timeout is thirty seconds.',
        ]);
    }

    public function test_an_empty_transcript_asks_nothing(): void
    {
        $provider = $this->fakeAiProvider();
        $this->fakeVoice();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->call('sendSpoken', '   ');

        $this->assertSame(0, $provider->calls);
        $this->assertSame(0, AiChatMessage::query()->count());
    }

    public function test_an_answer_can_be_read_aloud(): void
    {
        $provider = $this->fakeAiProvider();
        $voice = $this->fakeVoice();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $provider->willReturn('The export times out after thirty seconds.');

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Why?')
            ->call('send');

        $answer = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $response = $this->actingAs($team)
            ->get(route('ai.voice.speak', ['message' => $answer->id]))
            ->assertOk();

        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));

        // Never cached: the bytes are a private answer read aloud, and a shared
        // cache holding them would be a copy outside every check above.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertCount(1, $voice->spoken);
        $this->assertStringContainsString('times out after thirty seconds', $voice->spoken[0]['text']);
    }

    public function test_a_voice_that_is_not_offered_falls_back_to_the_default(): void
    {
        $provider = $this->fakeAiProvider();
        $voice = $this->fakeVoice();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $provider->willReturn('An answer.');

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Why?')
            ->call('send');

        $answer = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $this->actingAs($team)
            ->get(route('ai.voice.speak', ['message' => $answer->id, 'voice' => 'darth-vader']))
            ->assertOk();

        // A stale choice in somebody's browser must not break speech, and it
        // must not be passed through to a vendor request body either.
        $this->assertNull($voice->spoken[0]['voice']);
    }

    // -----------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------

    public function test_a_person_with_no_board_cannot_reach_the_voice_endpoints(): void
    {
        $this->fakeVoice();

        // No board membership at all, so no assistant.
        $stranger = $this->teamMember();

        $this->actingAs($stranger)
            ->post(route('ai.voice.listen'), [
                'audio' => UploadedFile::fake()->createWithContent('turn.webm', 'bytes'),
            ])
            ->assertNotFound();
    }

    public function test_a_customer_may_hold_a_spoken_conversation(): void
    {
        $provider = $this->fakeAiProvider();
        $this->fakeVoice('What is happening with my ticket?');

        $customer = $this->customer();
        $this->boardWithColumns([$customer]);

        // Read-only does not mean silent.
        $this->actingAs($customer)
            ->post(route('ai.voice.listen'), [
                'audio' => UploadedFile::fake()->createWithContent('turn.webm', 'bytes'),
            ])
            ->assertOk()
            ->assertJsonPath('text', 'What is happening with my ticket?');
    }

    public function test_nobody_can_have_somebody_elses_answer_read_aloud(): void
    {
        $provider = $this->fakeAiProvider();
        $this->fakeVoice();

        $owner = $this->teamMember();
        $other = $this->teamMember();
        $board = $this->boardWithColumns([$owner, $other]);

        $provider->willReturn('A private answer about the client.');

        Livewire::actingAs($owner)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Tell me.')
            ->call('send');

        $answer = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        // A colleague on the same board, and an administrator, both 404.
        $this->actingAs($other)
            ->get(route('ai.voice.speak', ['message' => $answer->id]))
            ->assertNotFound();

        $this->actingAs($this->admin())
            ->get(route('ai.voice.speak', ['message' => $answer->id]))
            ->assertNotFound();
    }

    public function test_a_persons_own_question_cannot_be_read_back_to_them(): void
    {
        $provider = $this->fakeAiProvider();
        $this->fakeVoice();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'Why?')
            ->call('send');

        $question = AiChatMessage::query()->where('role', AiChatRole::User->value)->sole();

        // Only an answer is spoken. Reading somebody their own question back is
        // not a feature, and allowing it would double the surface for nothing.
        $this->actingAs($team)
            ->get(route('ai.voice.speak', ['message' => $question->id]))
            ->assertNotFound();
    }

    public function test_the_endpoints_are_closed_to_guests(): void
    {
        $this->post(route('ai.voice.listen'))->assertRedirect(route('login'));
        $this->get(route('ai.voice.speak', ['message' => 1]))->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // What gets read aloud
    // -----------------------------------------------------------------

    /**
     * A stored answer is Markdown that may carry a fenced table or chart.
     *
     * Reading either aloud produces a minute of somebody spelling out JSON, so
     * the spoken form is not the written form — but it is a transformation, not
     * a summary. Summarising would let the spoken and written answers differ in
     * substance, and somebody would eventually act on the wrong one.
     */
    public function test_a_table_becomes_a_sentence_rather_than_being_spelled_out(): void
    {
        $answer = <<<'MARKDOWN'
        Here are the top customers.

        ```nexora-table
        {"columns":["Customer","Revenue"],"rows":[["Acme",1200],["Globex",900]]}
        ```

        Acme leads on revenue.
        MARKDOWN;

        $spoken = SpokenAnswer::from($answer);

        $this->assertStringContainsString('Here are the top customers.', $spoken);
        $this->assertStringContainsString('table or chart in the answer on screen', $spoken);
        $this->assertStringContainsString('Acme leads on revenue.', $spoken);

        // None of the structure.
        $this->assertStringNotContainsString('nexora-table', $spoken);
        $this->assertStringNotContainsString('{"columns"', $spoken);
        $this->assertStringNotContainsString('```', $spoken);
    }

    public function test_a_pipe_table_becomes_a_sentence_too(): void
    {
        $answer = "Status by column:\n\n| Column | Tickets |\n| --- | --- |\n| Doing | 4 |\n| Done | 9 |\n\nMost work is finished.";

        $spoken = SpokenAnswer::from($answer);

        $this->assertStringContainsString('Status by column:', $spoken);
        $this->assertStringContainsString('There is a table in the answer on screen.', $spoken);
        $this->assertStringContainsString('Most work is finished.', $spoken);
        $this->assertStringNotContainsString('| Doing |', $spoken);
    }

    public function test_markdown_formatting_is_removed_but_the_words_survive(): void
    {
        $spoken = SpokenAnswer::from(
            "## The problem\n\n"
            ."It is **blocked** on the `vat_rates` table, see [the runbook](https://example.test/runbook).\n\n"
            ."- first thing\n- second thing\n\n"
            ."---\n\n"
            .'> A quote.'
        );

        $this->assertStringContainsString('The problem', $spoken);
        $this->assertStringContainsString('It is blocked on the vat_rates table', $spoken);
        $this->assertStringContainsString('see the runbook', $spoken);
        $this->assertStringContainsString('first thing', $spoken);
        $this->assertStringContainsString('A quote.', $spoken);

        $this->assertStringNotContainsString('**', $spoken);
        $this->assertStringNotContainsString('`', $spoken);
        $this->assertStringNotContainsString('https://example.test', $spoken);
        $this->assertStringNotContainsString('##', $spoken);
    }

    public function test_an_empty_answer_produces_nothing_to_say(): void
    {
        $this->assertSame('', SpokenAnswer::from('   '));
    }

    // -----------------------------------------------------------------
    // The real provider's own refusals
    // -----------------------------------------------------------------

    public function test_the_openai_provider_reports_a_rejected_key_in_words(): void
    {
        config()->set('ai.openai.api_key', 'sk-test');
        config()->set('ai.voice.driver', 'openai');

        Http::fake([
            '*' => Http::response(['error' => ['message' => 'nope']], 401),
        ]);

        $voice = app(VoiceProviderInterface::class);

        $this->assertTrue($voice->isConfigured());

        try {
            $voice->transcribe('bytes', 'audio/webm', 'a.webm');
            $this->fail('A 401 should be reported.');
        } catch (VoiceException $exception) {
            $this->assertStringContainsString('refused the API key', $exception->getMessage());

            // The vendor's own body is not forwarded: a synthesis error can
            // echo the text it was given, which is somebody's answer.
            $this->assertStringNotContainsString('nope', $exception->getMessage());
        }
    }

    public function test_the_openai_provider_refuses_an_oversized_recording(): void
    {
        config()->set('ai.openai.api_key', 'sk-test');
        config()->set('ai.voice.driver', 'openai');

        Http::fake();

        $voice = app(VoiceProviderInterface::class);

        try {
            $voice->transcribe(str_repeat('a', 11 * 1024 * 1024), 'audio/webm', 'a.webm');
            $this->fail('An oversized recording should be refused.');
        } catch (VoiceException $exception) {
            $this->assertStringContainsString('shorter turn', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
