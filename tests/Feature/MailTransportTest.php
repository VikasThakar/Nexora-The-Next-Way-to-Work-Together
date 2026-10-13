<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Production mail goes through Resend's HTTPS API.
 *
 * Railway blocks outbound SMTP below the Pro plan, so an SMTP mailer there
 * fails at send time with a network error — on the queue worker, long after
 * the request that asked for the mail has returned. The `resend` mailer uses
 * HTTPS and works on every plan, but only if resend/resend-php is installed:
 * without it the transport cannot be built and every queued mail fails.
 *
 * This builds the transport the way production does. Nothing is sent.
 */
class MailTransportTest extends TestCase
{
    public function test_the_resend_mailer_can_be_built(): void
    {
        config(['services.resend.key' => 're_test_not_a_real_key']);

        $transport = Mail::mailer('resend')->getSymfonyTransport();

        $this->assertInstanceOf(ResendTransport::class, $transport);
    }
}
