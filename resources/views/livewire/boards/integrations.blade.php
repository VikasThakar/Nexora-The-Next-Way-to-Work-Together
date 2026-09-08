<div>
    <x-ui.page-header
        title="Integrations"
        :description="'How '.$board->name.' talks to the outside world.'"
        :trail="\App\Support\Breadcrumbs::boardChild($board, 'Integrations')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('boards.ai-settings', $board)" variant="secondary" size="md">AI settings</x-ui.button>
            <x-ui.button :href="route('boards.show', $board)" variant="secondary" size="md">Back to board</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Slack ------------------------------------------------------ --}}
            <x-ui.card title="Slack" description="Post updates from this board into a Slack channel.">
                @unless ($slackAvailable)
                    <div class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Slack notifications are switched off for this deployment
                        (<span class="font-mono">SLACK_NOTIFICATIONS_ENABLED=false</span>). Settings can be saved,
                        but nothing will be posted until it is switched on.
                    </div>
                @endunless

                <form wire:submit="saveSlack" class="space-y-5">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model="slackEnabled" class="mt-0.5 size-4 rounded border-slate-300 text-brand-600" />
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Send notifications to Slack</span>
                            <span class="block text-xs text-slate-500">Mutes everything below when off, without losing the settings.</span>
                        </span>
                    </label>

                    <x-ui.field label="Incoming webhook URL" for="slack-url">
                        <x-ui.input
                            id="slack-url"
                            type="password"
                            autocomplete="off"
                            wire:model="slackWebhookUrl"
                            :placeholder="$slack->isConfigured() ? 'A URL is stored. Paste a new one to replace it.' : 'https://hooks.slack.com/services/…'"
                            :invalid="$errors->has('slackWebhookUrl')"
                        />

                        <x-slot:hint>
                            @if ($slack->isConfigured())
                                A webhook URL is stored for this board. It is encrypted and is never shown again —
                                paste a new one to replace it, or remove it below.
                            @else
                                Create one in Slack under <span class="font-medium">Apps → Incoming Webhooks</span>,
                                choose the channel, and paste the URL here. It is stored encrypted.
                            @endif
                        </x-slot:hint>

                        @error('slackWebhookUrl')
                            <x-slot:error>{{ $message }}</x-slot:error>
                        @enderror
                    </x-ui.field>

                    <x-ui.field label="Channel name" for="slack-channel" hint="Shown on this screen only, so the team can see where messages go. Purely a label.">
                        <x-ui.input id="slack-channel" wire:model="slackChannelHint" placeholder="#delivery-aqueduct" />
                        @error('slackChannelHint')
                            <x-slot:error>{{ $message }}</x-slot:error>
                        @enderror
                    </x-ui.field>

                    <fieldset>
                        <legend class="mb-2 text-sm font-medium text-slate-800">Post when</legend>

                        <div class="space-y-3">
                            @foreach ($slackEventTypes as $event)
                                <label class="flex items-start gap-3" wire:key="slack-event-{{ $event->value }}">
                                    <input
                                        type="checkbox"
                                        wire:model="slackEvents.{{ $event->value }}"
                                        class="mt-0.5 size-4 rounded border-slate-300 text-brand-600"
                                    />
                                    <span>
                                        <span class="block text-sm text-slate-800">{{ $event->label() }}</span>
                                        <span class="block text-xs text-slate-500">{{ $event->description() }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="flex items-center gap-3 border-t border-slate-100 pt-4">
                        <x-ui.button type="submit">Save Slack settings</x-ui.button>

                        @if ($slack->isConfigured())
                            <x-ui.button
                                type="button"
                                variant="ghost"
                                wire:click="clearSlackWebhook"
                                :confirm="[
                                    'title' => 'Remove the Slack webhook?',
                                    'body' => 'Notifications are switched off for this board and the stored URL is forgotten. You will need to paste it again from Slack to turn them back on.',
                                    'confirmText' => 'Remove webhook',
                                ]"
                            >
                                Remove webhook
                            </x-ui.button>
                        @endif
                    </div>
                </form>
            </x-ui.card>

            {{-- SMS -------------------------------------------------------- --}}
            <x-ui.card title="Critical ticket SMS" description="Text the on-call rota when a critical ticket is raised.">
                @unless ($smsAvailable)
                    <div class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        SMS alerts are switched off for this deployment (<span class="font-mono">SMS_ENABLED=false</span>).
                    </div>
                @endunless

                @if ($smsAvailable && ! $smsProviderConfigured)
                    <div class="mb-4 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-800">
                        No SMS provider is configured (<span class="font-mono">SMS_DRIVER={{ $smsProvider }}</span>).
                        Alerts will be recorded as failed rather than sent, and the reason will appear in the history
                        below. Nothing is silently discarded.
                    </div>
                @endif

                <form wire:submit="saveSms" class="space-y-5">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model="smsEnabled" class="mt-0.5 size-4 rounded border-slate-300 text-brand-600" />
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Text the on-call numbers</span>
                            <span class="block text-xs text-slate-500">
                                Only when a ticket is raised at <span class="font-medium">Critical</span> priority.
                                One message per ticket per number.
                            </span>
                        </span>
                    </label>

                    <x-ui.field
                        label="On-call numbers"
                        for="sms-recipients"
                        hint="One per line, in full international form: +46701234567. At most {{ config('sms.max_recipients') }} are texted per alert."
                    >
                        <x-ui.textarea
                            id="sms-recipients"
                            rows="4"
                            wire:model="smsRecipients"
                            placeholder="+46701234567"
                            :invalid="$errors->has('smsRecipients')"
                        />
                        @error('smsRecipients')
                            <x-slot:error>{{ $message }}</x-slot:error>
                        @enderror
                    </x-ui.field>

                    @if ($smsSettings->recipients === [] && $workspaceRecipients > 0)
                        <p class="text-xs text-slate-500">
                            With no numbers here, alerts go to the
                            {{ $workspaceRecipients }} workspace {{ \Illuminate\Support\Str::plural('number', $workspaceRecipients) }}
                            configured in <span class="font-mono">SMS_ALERT_RECIPIENTS</span>.
                        </p>
                    @endif

                    <div class="border-t border-slate-100 pt-4">
                        <x-ui.button type="submit">Save SMS settings</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            @if ($recentMessages->isNotEmpty())
                <x-ui.card title="Recent alerts" description="Numbers are masked. The full value is stored so delivery can be audited.">
                    <div class="-mx-5 overflow-x-auto">
                        <table class="w-full min-w-lg text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-xs text-slate-500">
                                    <th class="px-5 py-2 font-medium">Ticket</th>
                                    <th class="px-3 py-2 font-medium">To</th>
                                    <th class="px-3 py-2 font-medium">Status</th>
                                    <th class="px-5 py-2 font-medium">When</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($recentMessages as $message)
                                    <tr wire:key="sms-{{ $message->id }}">
                                        <td class="px-5 py-2 font-mono text-xs text-slate-600">{{ $message->ticket_key ?? '—' }}</td>
                                        <td class="px-3 py-2 font-mono text-xs text-slate-500">{{ $message->maskedRecipient() }}</td>
                                        <td class="px-3 py-2">
                                            <x-ui.badge :variant="$message->status->badgeVariant()">
                                                {{ $message->status->label() }}
                                            </x-ui.badge>
                                            @if ($message->error)
                                                <span class="mt-0.5 block text-xs text-rose-700">{{ $message->error }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-2 text-xs text-slate-400">
                                            {{ $message->created_at?->diffForHumans(short: true) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            @endif
        </div>

        {{-- Sidebar ---------------------------------------------------- --}}
        <div class="space-y-6">
            <x-ui.card title="What travels outside">
                <div class="space-y-3 text-sm text-slate-600">
                    <p>
                        A Slack channel and a phone are outside this workspace's permission model: whoever is in the
                        room reads the message. So outbound messages carry an identifier, a title and a link — never
                        a ticket description, a comment body, or AI output.
                    </p>
                    <p>
                        The link is the access control. Following it requires signing in, and the ordinary visibility
                        rules apply to whatever the reader sees then.
                    </p>
                    <p class="text-xs text-slate-500">
                        Internal notes are never announced to Slack, in any configuration.
                    </p>
                </div>
            </x-ui.card>

            <x-ui.card title="GitHub">
                <div class="space-y-3 text-sm text-slate-600">
                    <p>
                        Mention <span class="font-mono text-xs">{{ $board->ticket_prefix }}-123</span> in a branch
                        name, commit message or pull request and it is linked to that ticket automatically.
                    </p>
                    <p class="text-xs text-slate-500">
                        Repositories are attached on the
                        <a href="{{ route('boards.ai-settings', $board) }}" wire:navigate class="text-brand-700 underline">AI settings</a>
                        screen. A delivery can only reach tickets on boards where its repository is attached.
                    </p>
                    <p class="text-xs text-slate-500">
                        Webhook endpoint:
                        <span class="font-mono break-all">{{ route('webhooks.github') }}</span>
                    </p>
                    <p class="text-xs {{ filled(config('github.webhook.secret')) ? 'text-emerald-700' : 'text-amber-700' }}">
                        {{ filled(config('github.webhook.secret'))
                            ? 'A webhook secret is configured; deliveries are verified.'
                            : 'No webhook secret is configured, so every delivery is rejected. Set GITHUB_WEBHOOK_SECRET.' }}
                    </p>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
