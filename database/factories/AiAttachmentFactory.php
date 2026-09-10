<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiAttachmentKind;
use App\Enums\AiAttachmentStatus;
use App\Models\AiAttachment;
use App\Models\AiSession;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAttachment>
 */
class AiAttachmentFactory extends Factory
{
    protected $model = AiAttachment::class;

    /**
     * A plain text file that was read successfully.
     *
     * Ready rather than Pending, because almost every test wants an attachment
     * that works and the exceptions say so explicitly. `context_sent_at` is
     * null, so a fresh one goes into a prompt in full — which is the state the
     * digest tests need to move away from deliberately.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => AiAttachmentKind::Text,
            'status' => AiAttachmentStatus::Ready,
            'extracted_text' => "The quarterly plan.\n\nShip the thing.",
            'structured' => null,
            'summary' => '2 lines',
            'characters' => 38,
            'token_estimate' => 10,
            'truncated' => false,
            'error' => null,
            'checksum' => hash('sha256', (string) fake()->unique()->uuid()),
            'context_sent_at' => null,
            'processed_at' => now(),
        ];
    }

    /**
     * Attach it to a conversation, taking the board and owner from there.
     *
     * The board and the user are denormalised from the session in production
     * too — see App\Services\AI\Attachments\AiAttachmentPipeline — so the
     * factory mirrors that rather than letting a test set them independently
     * and produce a row the application could never create.
     */
    public function forSession(AiSession $session): static
    {
        return $this->state(fn (): array => [
            'ai_session_id' => $session->getKey(),
            'board_id' => $session->board_id,
            'user_id' => $session->user_id,

            /*
             * And a stored file for it to hang off.
             *
             * Created here rather than in definition() because an attachment
             * needs an owner, and the session is the owner — there is no
             * sensible default without one. forAttachment() applied afterwards
             * overrides this, which is the order every caller uses.
             */
            'attachment_id' => $this->storedFile($session)->getKey(),
        ]);
    }

    /**
     * A minimal attachment row owned by a conversation.
     *
     * Written straight rather than through AttachmentStorage: a factory should
     * not need a faked disk, and the tests that care about storage exercise
     * the real pipeline instead.
     */
    private function storedFile(AiSession $session): Attachment
    {
        $attachment = new Attachment([
            'board_id' => $session->board_id,
            'disk' => 'local',
            'path' => 'attachments/factory/'.fake()->unique()->uuid().'.txt',
            'filename' => 'plan.txt',
            'mime_type' => 'text/plain',
            'size' => 38,
            'uploaded_by_id' => $session->user_id,
        ]);

        $attachment->attachable()->associate($session);
        $attachment->save();

        return $attachment;
    }

    public function forAttachment(Attachment $attachment): static
    {
        return $this->state(fn (): array => ['attachment_id' => $attachment->getKey()]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    public function ofKind(AiAttachmentKind $kind): static
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }

    public function withText(string $text): static
    {
        return $this->state(fn (): array => [
            'extracted_text' => $text,
            'characters' => mb_strlen($text),
            'token_estimate' => (int) ceil(mb_strlen($text) / 4),
        ]);
    }

    /**
     * An image: Ready, and correctly carrying no text at all.
     */
    public function image(): static
    {
        return $this->state(fn (): array => [
            'kind' => AiAttachmentKind::Image,
            'extracted_text' => null,
            'structured' => ['width' => 800, 'height' => 600, 'type' => 'image/png'],
            'summary' => '800 × 600',
            'characters' => 0,
            'token_estimate' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => AiAttachmentStatus::Pending,
            'extracted_text' => null,
            'summary' => null,
            'characters' => null,
            'token_estimate' => null,
            'processed_at' => null,
        ]);
    }

    public function failed(string $error = 'That PDF could not be read.'): static
    {
        return $this->state(fn (): array => [
            'status' => AiAttachmentStatus::Failed,
            'extracted_text' => null,
            'summary' => null,
            'error' => $error,
        ]);
    }

    public function unsupported(string $error = 'Nexora cannot read that kind of file.'): static
    {
        return $this->state(fn (): array => [
            'status' => AiAttachmentStatus::Unsupported,
            'extracted_text' => null,
            'error' => $error,
        ]);
    }

    /**
     * Already put in front of the model once, so the next turn digests it.
     */
    public function alreadySent(): static
    {
        return $this->state(fn (): array => ['context_sent_at' => now()->subMinutes(5)]);
    }

    public function truncated(): static
    {
        return $this->state(fn (): array => ['truncated' => true]);
    }
}
