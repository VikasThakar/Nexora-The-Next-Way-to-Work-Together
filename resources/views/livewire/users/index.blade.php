<div>
    <x-ui.page-header
        title="Users"
        description="Accounts are created here. A new account sees nothing until it is added to a board."
        :trail="\App\Support\Breadcrumbs::users()"
    >
        <x-slot:actions>
            <x-ui.button :href="route('users.create')">New user</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-5 flex flex-wrap items-center gap-3">
        <div class="min-w-64 flex-1">
            <x-ui.input
                type="search"
                placeholder="Search by name or email…"
                wire:model.live.debounce.300ms="search"
                aria-label="Search users"
            />
        </div>

        <x-ui.select wire:model.live="role" class="w-48" aria-label="Filter by role">
            <option value="">All roles</option>
            @foreach ($roles as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </x-ui.select>
    </div>

    @if ($users->isEmpty())
        <x-ui.empty-state title="No users match" description="Try a different search or role filter." />
    @else
        <x-ui.card :padded="false">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-5 py-3">Name</th>
                        <th scope="col" class="px-5 py-3">Role</th>
                        <th scope="col" class="px-5 py-3">Boards</th>
                        <th scope="col" class="px-5 py-3">Status</th>
                        <th scope="col" class="px-5 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($users as $user)
                        <tr wire:key="user-{{ $user->id }}" class="transition hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :name="$user->name" size="sm" />
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-slate-900">{{ $user->name }}</p>
                                        <p class="truncate text-xs text-slate-500">{{ $user->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <x-ui.badge :variant="$user->isCustomer() ? 'amber' : ($user->isAdmin() ? 'brand' : 'slate')">
                                    {{ $user->role->label() }}
                                </x-ui.badge>
                            </td>
                            <td class="px-5 py-3 text-slate-600">
                                {{ $user->isAdmin() ? 'All' : $user->board_memberships_count }}
                            </td>
                            <td class="px-5 py-3">
                                @if ($user->isActive())
                                    <x-ui.badge variant="emerald">Active</x-ui.badge>
                                @else
                                    <x-ui.badge variant="rose">Deactivated</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <x-ui.button :href="route('users.edit', $user)" variant="secondary" size="sm">Edit</x-ui.button>

                                    @unless (auth()->user()->is($user))
                                        <x-ui.button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            wire:click="toggleActive({{ $user->id }})"
                                            :confirm="$user->isActive()
                                                ? [
                                                    'title' => 'Deactivate '.$user->name.'?',
                                                    'body' => 'Every session they have open ends immediately and they cannot sign in again. Their tickets, comments and history are kept.',
                                                    'confirmText' => 'Deactivate account',
                                                ]
                                                : [
                                                    'title' => 'Reactivate '.$user->name.'?',
                                                    'body' => 'They will be able to sign in again and regain access to every board they are a member of.',
                                                    'confirmText' => 'Reactivate account',
                                                    'tone' => 'brand',
                                                ]"
                                        >
                                            {{ $user->isActive() ? 'Deactivate' : 'Reactivate' }}
                                        </x-ui.button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">
            {{ $users->links() }}
        </div>
    @endif
</div>
