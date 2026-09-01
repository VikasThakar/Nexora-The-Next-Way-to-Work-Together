<?php

declare(strict_types=1);

namespace App\Services\SMS;

use App\Services\SMS\Data\SmsMessage;
use App\Services\SMS\Data\SmsResult;
use App\Services\SMS\Exceptions\SmsException;

/**
 * The default provider: refuses, and says exactly what is missing.
 *
 * This is what a deployment with no SMS credentials gets, and it is deliberately
 * the *default* rather than a fallback that silently succeeds. The alternative —
 * quietly discarding messages when nothing is configured — produces a system
 * that looks like it is alerting an on-call engineer and is not, which is worse
 * than one that visibly is not.
 *
 * The failure is recorded on the message row like any other, so a board that
 * turned alerts on without credentials can see why nothing arrived.
 */
class UnavailableSmsProvider implements SmsProviderInterface
{
    public function send(SmsMessage $message): SmsResult
    {
        throw SmsException::notConfigured();
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'unavailable';
    }
}
