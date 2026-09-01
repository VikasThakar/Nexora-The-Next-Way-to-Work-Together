<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\SMS\Data\SmsMessage;
use App\Services\SMS\Data\SmsResult;
use App\Services\SMS\Exceptions\SmsException;
use App\Services\SMS\SmsProviderInterface;

/**
 * An SMS provider that records instead of sending.
 *
 * Bound in place of the real one, so a test that exercises the alerting path
 * has no HTTP client to intercept and no credential to remember to unset — the
 * same structural guarantee the fake AI provider gives.
 *
 * It reports itself as configured and returns `sent`, because a test about
 * duplicate suppression or recipient selection is not a test about the
 * provider. Use willFail() to exercise the failure recording.
 */
class FakeSmsProvider implements SmsProviderInterface
{
    /** @var array<int, SmsMessage> */
    public array $sent = [];

    public bool $configured = true;

    private ?SmsException $failure = null;

    private bool $permanentFailure = false;

    public function send(SmsMessage $message): SmsResult
    {
        $this->sent[] = $message;

        if ($this->permanentFailure) {
            return SmsResult::failed('The provider rejected the message.');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return SmsResult::sent('fake-'.count($this->sent), '0.0450');
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function name(): string
    {
        return 'fake';
    }

    /** Raise, as a transient failure would — the queue should retry. */
    public function willThrow(?SmsException $exception = null): self
    {
        $this->failure = $exception ?? SmsException::unreachable();

        return $this;
    }

    /** Return a failed result, as a permanently bad number would. */
    public function willReject(): self
    {
        $this->permanentFailure = true;

        return $this;
    }

    /** @return array<int, string> */
    public function recipients(): array
    {
        return array_map(fn (SmsMessage $message): string => $message->to, $this->sent);
    }

    public function lastBody(): ?string
    {
        $last = end($this->sent);

        return $last instanceof SmsMessage ? $last->body : null;
    }
}
