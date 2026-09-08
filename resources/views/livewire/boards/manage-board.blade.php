<div>
    <x-ui.page-header
        :title="$board ? 'Board settings' : 'New board'"
        :description="$board
            ? 'Change how this board is identified. Membership is managed on the board page.'
            : 'Create a board and choose who can see it. Nobody except administrators sees a board they are not a member of.'"
        :trail="$board
            ? \App\Support\Breadcrumbs::boardChild($board, 'Board settings')
            : \App\Support\Breadcrumbs::newBoard()"
    />

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Board details">
                <div class="space-y-5">
                    <x-ui.field label="Name" for="name" :error="$errors->first('name')" required>
                        <x-ui.input id="name" wire:model.blur="name" autofocus :invalid="$errors->has('name')" />
                    </x-ui.field>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.field
                            label="URL identifier"
                            for="slug"
                            :error="$errors->first('slug')"
                            hint="Used in the board address."
                            required
                        >
                            <x-ui.input id="slug" wire:model.blur="slug" :invalid="$errors->has('slug')" />
                        </x-ui.field>

                        <x-ui.field
                            label="Ticket prefix"
                            for="ticket_prefix"
                            :error="$errors->first('ticket_prefix')"
                            hint="Uppercase letters and digits, used for ticket references."
                            required
                        >
                            <x-ui.input id="ticket_prefix" class="font-mono uppercase" wire:model.blur="ticket_prefix" :invalid="$errors->has('ticket_prefix')" />
                        </x-ui.field>
                    </div>

                    <x-ui.field label="Description" for="description" :error="$errors->first('description')">
                        <x-ui.textarea id="description" wire:model="description" :invalid="$errors->has('description')">{{ $description }}</x-ui.textarea>
                    </x-ui.field>

                    @if ($board)
                        <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <input
                                type="checkbox"
                                wire:model="archived"
                                class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span class="text-sm">
                                <span class="font-medium text-slate-900">Archive this board</span>
                                <span class="mt-0.5 block text-slate-500">
                                    Archived boards stay accessible to their members but are hidden from the default lists.
                                </span>
                            </span>
                        </label>
                    @endif
                </div>
            </x-ui.card>

            @unless ($board)
                <x-ui.card title="Initial members" description="You can add or remove people at any time afterwards.">
                    @if ($assignableUsers->isEmpty())
                        <p class="text-sm text-slate-500">No other active users exist yet.</p>
                    @else
                        <div class="max-h-80 space-y-1 overflow-y-auto pr-1">
                            @foreach ($assignableUsers as $candidate)
                                <label
                                    wire:key="candidate-{{ $candidate->id }}"
                                    class="flex items-center gap-3 rounded-lg px-3 py-2 transition hover:bg-slate-50"
                                >
                                    <input
                                        type="checkbox"
                                        value="{{ $candidate->id }}"
                                        wire:model="memberIds"
                                        class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                    >
                                    <x-ui.avatar :name="$candidate->name" size="sm" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-slate-900">{{ $candidate->name }}</span>
                                        <span class="block truncate text-xs text-slate-500">{{ $candidate->email }}</span>
                                    </span>
                                    <x-ui.badge :variant="$candidate->isCustomer() ? 'amber' : 'slate'">
                                        {{ $candidate->role->label() }}
                                    </x-ui.badge>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            @endunless
        </div>

        <div class="space-y-6">
            <x-ui.card title="Visibility">
                <p class="text-sm text-slate-600">
                    Adding a customer to a board lets them see customer-visible content on it. Internal tickets,
                    internal comments and internal documentation stay hidden from them regardless of membership.
                </p>
            </x-ui.card>

            <div class="flex items-center gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ $board ? 'Save changes' : 'Create board' }}
                </x-ui.button>

                <x-ui.button
                    variant="secondary"
                    :href="$board ? route('boards.show', $board) : route('boards.index')"
                >
                    Cancel
                </x-ui.button>
            </div>
        </div>
    </form>

    @if ($board)
        <div class="mt-8 grid max-w-5xl gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                {{-- Membership is the access boundary, so it lives on the
                     administrator screen alongside the board record itself. --}}
                <livewire:boards.members :board="$board" :key="'members-'.$board->id" />
            </div>

            <div class="space-y-6">
                <x-ui.card title="Configuration">
                    <p class="text-sm text-slate-600">
                        Columns and labels are managed separately, and any staff member of the board can
                        change them.
                    </p>
                    <div class="mt-3">
                        <x-ui.button :href="route('boards.settings', $board)" variant="secondary" size="sm">
                            Columns and labels
                        </x-ui.button>
                    </div>
                </x-ui.card>

                @if ($canDelete)
                    <x-ui.card title="Danger zone">
                        @if ($confirmingDelete)
                            <p class="text-sm text-slate-700">
                                This permanently deletes <strong>{{ $board->name }}</strong>, its
                                {{ $ticketCount }} {{ \Illuminate\Support\Str::plural('ticket', $ticketCount) }},
                                all history and all attachments. Type the board name to confirm.
                            </p>

                            <div class="mt-3 space-y-3">
                                <x-ui.field for="delete-confirmation" :error="$errors->first('deleteConfirmation')">
                                    <x-ui.input id="delete-confirmation" wire:model="deleteConfirmation"
                                                placeholder="{{ $board->name }}"
                                                :invalid="$errors->has('deleteConfirmation')" />
                                </x-ui.field>

                                <div class="flex items-center gap-2">
                                    <x-ui.button type="button" variant="danger" size="sm" wire:click="destroyBoard">
                                        Delete this board
                                    </x-ui.button>
                                    <x-ui.button type="button" variant="secondary" size="sm" wire:click="cancelDelete">
                                        Cancel
                                    </x-ui.button>
                                </div>
                            </div>
                        @else
                            <p class="text-sm text-slate-600">
                                Deleting a board removes every ticket on it. There is no undo.
                            </p>
                            <div class="mt-3">
                                <x-ui.button type="button" variant="secondary" size="sm" class="text-rose-600"
                                             wire:click="confirmDelete">
                                    Delete board
                                </x-ui.button>
                            </div>
                        @endif
                    </x-ui.card>
                @endif
            </div>
        </div>
    @endif
</div>
