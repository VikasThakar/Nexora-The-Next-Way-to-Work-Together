<?php

declare(strict_types=1);

namespace App\Services\SMS;

use App\Services\SMS\Data\SmsMessage;
use App\Services\SMS\Data\SmsResult;

/**
 * The boundary between the application and whoever actually sends text messages.
 *
 * Exactly the same shape as App\Services\AI\AiProviderInterface, and for the
 * same three reasons:
 *
 *   Nothing above this line names 46elks. Swapping to Twilio, MessageBird or a
 *   national aggregator is one new class and one config value, not a search
 *   through the alerting logic.
 *
 *   Tests cannot reach the network. The binding is resolved from the container,
 *   so a test binds a fake and there is no HTTP client to intercept and no
 *   credential to remember to unset.
 *
 *   Credentials live below it. Only the implementation reads them, so there is
 *   one file to audit for "can this leak the API password".
 */
interface SmsProviderInterface
{
    /**
     * Send one message.
     *
     * Implementations return a failed SmsResult for anything the provider
     * rejected permanently, and throw App\Services\SMS\Exceptions\SmsException
     * for anything worth retrying. That split is what lets the queue back off
     * on an outage without retrying a malformed number three times.
     */
    public function send(SmsMessage $message): SmsResult;

    /**
     * Are the credentials this implementation needs actually present?
     */
    public function isConfigured(): bool;

    /**
     * A short name for logs and for the `provider` column. Never a credential.
     */
    public function name(): string;
}
