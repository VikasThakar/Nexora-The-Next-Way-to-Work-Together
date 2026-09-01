<x-ui.card title="Members" description="Access to this board is granted one person at a time.">
    <form wire:submit="addMember" class="mb-5 flex flex-wrap items-end gap-3">
        <x-ui.field label="Add a member" for="userId" :error="$errors->first('userId')" class="min-w-64 flex-1">
            <x-ui.select id="userId" wire:model="userId" :invalid="$errors->has('userId')">
                <option value="">Select a person…</option>
                @foreach ($candidates as $candidate)
                    <option value="{{ $candidate->id }}">
                        {{ $candidate->name }} ({{ $candidate->role->label() }}) — {{ $candidate->email }}
                    </option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="addMember">Add</x-ui.button>
    </form>

    @if ($members->isEmpty())
        <p class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-500">
            This board has no members yet. Administrators can always see it; nobody else can.
        </p>
    @else
        <ul class="divide-y divide-slate-100">
            @foreach ($members as $member)
                <li wire:key="member-{{ $member->id }}" class="flex items-center gap-3 py-2.5">
                    <x-ui.avatar :name="$member->name" size="sm" />

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-900">{{ $member->name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ $member->email }}</p>
                    </div>

                    <x-ui.badge :variant="$member->isCustomer() ? 'amber' : 'slate'">
                        {{ $member->role->label() }}
                    </x-ui.badge>

                    <x-ui.button
                        type="button"
                        variant="ghost"
                        size="sm"
                        wire:click="removeMember({{ $member->id }})"
                        :confirm="[
                            'title' => 'Remove '.$member->name.' from this board?',
                            'body' => 'They lose access to every ticket, conversation and document on it immediately. You can add them back later.',
                            'confirmText' => 'Remove member',
                        ]"
                        class="text-rose-600 hover:bg-rose-50"
                    >
                        Remove
                    </x-ui.button>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
