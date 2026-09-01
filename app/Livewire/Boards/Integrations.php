<?php

declare(strict_types=1);

namespace App\Livewire\Boards;

use App\Actions\Notifications\UpdateBoardIntegrations;
use App\Enums\NotificationEvent;
use App\Models\Board;
use App\Models\SmsMessage;
use App\Services\SMS\SmsService;
use App\Support\BoardSlackSettings;
use App\Support\BoardSmsSettings;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Slack and SMS configuration for one board.
 *
 * Authorized by BoardPolicy::manageAiSettings — the same bar as the AI screen,
 * because it is the same question: may this person configure how this board
 * behaves? Staff members of the board, denied as 404 for a customer so the
 * screen's existence is not confirmed.
 *
 * The webhook URL is write-only.
 *
 * It is a bearer credential (see BoardSlackSettings), so the form never
 * receives it back from the server. The field is blank on every load and blank
 * means "leave it as it is"; removing it is a separate, explicit button. That
 * costs one extra click and buys the property that somebody who gains access to
 * this screen cannot read out a credential they did not already have — they can
 * only replace it, which is visible in the board's history.
 *
 * The rota field is not write-only, and should not be: somebody has to be able
 * to remove a colleague's number when they leave the rota, which means reading
 * the list. The delivery *history* below it is masked, though, because it is a
 * different thing — a growing log that accumulates numbers no longer on the
 * rota, scanned to answer "was anybody reached?", a question that does not need
 * the digits.
 */
#[Layout('layouts.app')]
class Integrations extends Component
{
    public Board $board;

    // --- Slack ---
    public bool $slackEnabled = false;

    /** Blank on purpose, on every load. See the class comment. */
    public string $slackWebhookUrl = '';

    public string $slackChannelHint = '';

    /** @var array<string, bool> */
    public array $slackEvents = [];

    // --- SMS ---
    public bool $smsEnabled = false;

    /** One number per line, as a person would paste them. */
    public string $smsRecipients = '';

    public function mount(Board $board): void
    {
        $this->authorize('manageAiSettings', $board);

        $this->board = $board;

        $this->loadSettings($board);
    }

    private function loadSettings(Board $board): void
    {
        $slack = BoardSlackSettings::forBoard($board);
        $sms = BoardSmsSettings::forBoard($board);

        $this->slackEnabled = $slack->enabled;
        $this->slackChannelHint = (string) $slack->channelHint;
        $this->slackEvents = $slack->events;
        $this->slackWebhookUrl = '';

        $this->smsEnabled = $sms->enabled;
        $this->smsRecipients = implode("\n", $sms->recipients);
    }

    // -----------------------------------------------------------------
    // Slack
    // -----------------------------------------------------------------

    public function saveSlack(UpdateBoardIntegrations $integrations): void
    {
        $this->authorize('manageAiSettings', $this->board);

        $url = trim($this->slackWebhookUrl);

        // Validated even though BoardSlackSettings would reject it anyway, so
        // a typo produces a message next to the field rather than a silent
        // no-op that looks like it saved.
        if ($url !== '' && BoardSlackSettings::normaliseUrl($url) === null) {
            $this->addError('slackWebhookUrl', 'That is not a Slack incoming webhook URL. It should begin with https://hooks.slack.com/.');

            return;
        }

        $this->validate([
            'slackChannelHint' => ['nullable', 'string', 'max:80'],
        ], attributes: ['slackChannelHint' => 'channel']);

        $current = BoardSlackSettings::forBoard($this->board);

        // Turning the integration on with nothing to post to is the one
        // combination that looks configured and does nothing.
        if ($this->slackEnabled && $url === '' && ! $current->isConfigured()) {
            $this->addError('slackWebhookUrl', 'Add a webhook URL before switching Slack notifications on.');

            return;
        }

        $integrations->updateSlack($this->board, [
            'enabled' => $this->slackEnabled,
            'webhook_url' => $url === '' ? null : $url,
            'events' => $this->slackEvents,
            'channel_hint' => trim($this->slackChannelHint) ?: null,
        ]);

        $this->board->refresh();
        $this->loadSettings($this->board);

        session()->flash('status', 'Slack settings saved.');
    }

    public function clearSlackWebhook(UpdateBoardIntegrations $integrations): void
    {
        $this->authorize('manageAiSettings', $this->board);

        $integrations->clearSlackWebhook($this->board);

        $this->board->refresh();
        $this->loadSettings($this->board);

        session()->flash('status', 'The Slack webhook URL has been removed and notifications are off.');
    }

    // -----------------------------------------------------------------
    // SMS
    // -----------------------------------------------------------------

    public function saveSms(UpdateBoardIntegrations $integrations): void
    {
        $this->authorize('manageAiSettings', $this->board);

        $parsed = BoardSmsSettings::parseList($this->smsRecipients);
        $submitted = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,;]+/', $this->smsRecipients) ?: []
        )));

        // Something was typed and none of it parsed: almost always a local
        // number without a country code, which would text a different person in
        // a different country. Refuse rather than silently drop it.
        if ($submitted !== [] && $parsed === []) {
            $this->addError('smsRecipients', 'Use full international numbers, for example +46701234567.');

            return;
        }

        if (count($submitted) > count($parsed)) {
            $this->addError('smsRecipients', 'Some of those are not valid international numbers. Each one needs a country code, for example +46701234567.');

            return;
        }

        if ($this->smsEnabled && $parsed === [] && config('sms.recipients') === []) {
            $this->addError('smsRecipients', 'Add at least one number before switching SMS alerts on.');

            return;
        }

        $integrations->updateSms($this->board, [
            'enabled' => $this->smsEnabled,
            'recipients' => $parsed,
        ]);

        $this->board->refresh();
        $this->loadSettings($this->board);

        session()->flash('status', 'SMS alert settings saved.');
    }

    // -----------------------------------------------------------------

    public function render(SmsService $sms)
    {
        // Re-authorized on every render: Livewire rehydrates public properties
        // from the browser on each request, so authorizing only at mount would
        // authorize a decision the client can replay.
        $this->authorize('manageAiSettings', $this->board);

        $slack = BoardSlackSettings::forBoard($this->board);
        $smsSettings = BoardSmsSettings::forBoard($this->board);

        return view('livewire.boards.integrations', [
            'slack' => $slack,
            'smsSettings' => $smsSettings,
            'slackEventTypes' => NotificationEvent::slackEvents(),

            // Whether the deployment allows any of this at all, so a board that
            // is configured correctly but sitting on a deployment with the
            // master switch off is told why nothing arrives.
            'slackAvailable' => (bool) config('slack.enabled'),
            'smsAvailable' => (bool) config('sms.enabled'),
            'smsProvider' => $sms->providerName(),
            'smsProviderConfigured' => $sms->providerIsConfigured(),
            'workspaceRecipients' => count((array) config('sms.recipients', [])),

            // Masked. See the class comment.
            'recentMessages' => SmsMessage::query()
                ->where('board_id', $this->board->getKey())
                ->orderByDesc('id')
                ->limit(8)
                ->get(),
        ]);
    }
}
