{{--
    The OAuth consent screen for the remote MCP server.

    Shown by Passport's AuthorizationController after the person has signed in,
    when an AI client (Claude, ChatGPT, …) asks to connect. Receives $client,
    $user, $scopes, $request and $authToken.

    The client's name is whatever it called itself when it registered, so the
    screen does not lean on it alone: it also shows the host the approval will
    be sent back to, which is the part an attacker cannot fake (Passport has
    already checked it against the client's registered redirect URIs, and
    config/mcp.php limits registration to the AI clients we support).

    Plain forms and no script. The package's version closed the window a few
    seconds after approving, which can kill the flow in the middle of the
    redirect back to the client.
--}}
@php
    $redirectUri = (string) $request->query('redirect_uri', '');
    $redirectHost = (string) (parse_url($redirectUri, PHP_URL_HOST) ?? '');
    $isLoopback = in_array($redirectHost, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    $product = config('workspace.short_name', config('workspace.name'));
@endphp

@component('layouts.guest', ['title' => 'Connect '.$client->name])
    <div>
        <div class="mb-6">
            <h1 class="text-lg font-semibold text-slate-900">Allow {{ $client->name }} to use {{ $product }} as you?</h1>
            <p class="mt-1 text-sm text-slate-500">
                An AI app is asking to answer questions from your {{ $product }} data and to make changes for you.
            </p>
        </div>

        <dl class="mb-6 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
            <div>
                <dt class="text-slate-500">Signed in as</dt>
                <dd class="font-medium text-slate-900">{{ $user->name }} &middot; {{ $user->email }}</dd>
            </div>

            <div>
                <dt class="text-slate-500">Approving sends you back to</dt>
                <dd class="font-medium text-slate-900 break-all">{{ $redirectHost !== '' ? $redirectHost : 'the app that asked' }}</dd>
            </div>
        </dl>

        <ul class="mb-6 space-y-2 text-sm text-slate-600">
            {{--
                What the app will be able to do, said plainly — this is the
                consent the write tools rest on. Staff and customers get
                different tools (App\Mcp\Servers\NexoraServer), so they are
                told different things.
            --}}
            <li>
                @if ($user->canSeeInternalContent())
                    <span class="font-medium text-slate-900">Reads and changes.</span>
                    It can look up boards, tickets, documentation and statistics, and create and update tickets, comment, tick off checklists and edit documentation.
                    Deleting a ticket, writing to a customer and changing a published page wait for you to confirm.
                @else
                    <span class="font-medium text-slate-900">Reads and replies.</span>
                    It can look up the tickets and pages shared with you, raise tickets, edit the ones you raised and reply to the team.
                @endif
            </li>
            <li>
                <span class="font-medium text-slate-900">Your access, nothing more.</span>
                @if ($user->canSeeInternalContent())
                    It sees and changes exactly what you can on your boards. Every change is recorded as yours, marked “via {{ $client->name }}”.
                @else
                    It sees only the tickets and pages that have been shared with you. Every change is recorded as yours.
                @endif
            </li>
            <li>
                <span class="font-medium text-slate-900">You stay in control.</span>
                Your AI app normally asks you before each change. Disconnect it at any time in the AI app's settings. Changing your password disconnects every AI app.
            </li>
        </ul>

        @if ($isLoopback)
            <p class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                This sends access to a program running on this computer (such as Claude Code). Only approve it if you started that program yourself.
            </p>
        @else
            <p class="mb-6 text-sm text-slate-500">
                Only approve this if you just asked {{ $client->name }} to connect to {{ $product }} yourself.
            </p>
        @endif

        <div class="flex gap-3">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">

                <x-ui.button variant="secondary" class="w-full">Cancel</x-ui.button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                @csrf
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">

                <x-ui.button class="w-full">Allow</x-ui.button>
            </form>
        </div>
    </div>
@endcomponent
