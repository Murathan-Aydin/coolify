<div>
    @can('manageInvitations', currentTeam())
        <form wire:submit="viaLink">
            <x-application.settings-section title="Invite a member"
                description="Create a reusable invitation link or deliver it by email.">
                <x-slot:actions>
                    @if (is_transactional_emails_enabled())
                        <x-forms.button type="button" wire:click.prevent="viaEmail">
                            <x-reicon name="notifications" class="size-3.5" />
                            Send email
                        </x-forms.button>
                    @endif
                    <x-forms.button type="submit" wire:target="viaLink"
                        defaultClass="button button-highlighted">
                        <x-reicon name="plus" class="size-3.5" />
                        Generate link
                    </x-forms.button>
                </x-slot:actions>

                @if (!is_transactional_emails_enabled() && isInstanceAdmin())
                    <x-callout type="warning" title="Email delivery is not configured">
                        Configure transactional email in instance settings to send invitations directly.
                    </x-callout>
                @endif

                @php($instanceFqdn = instanceSettings()->fqdn)
                @if (isInstanceAdmin() && (blank($instanceFqdn) || parse_url($instanceFqdn, PHP_URL_HOST) !== request()->getHost()))
                    <x-callout type="warning" title="Invitation links may not open for invitees">
                        Links are built from the instance URL ({{ $instanceFqdn ?: 'not set' }}), but you are browsing
                        {{ request()->getHost() }}. Set the instance domain in Settings so invitees can reach it.
                    </x-callout>
                @endif

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="email" type="email" label="Email address"
                        placeholder="teammate@example.com" required />
                    <div class="flex items-end gap-2">
                        <div class="min-w-0 flex-1">
                            <x-forms.listbox id="role" label="Role" :options="array_values(array_filter([
                                auth()->user()->role() === 'owner' ? ['value' => 'owner', 'label' => 'Owner'] : null,
                                ['value' => 'admin', 'label' => 'Admin'],
                                ['value' => 'member', 'label' => 'Member'],
                            ]))" />
                        </div>
                        <div x-show="$wire.role === 'member'" class="shrink-0">
                            <x-forms.button type="button" defaultClass="button button-highlighted"
                                title="{{ \App\Livewire\Team\Member\Access::summary($grants) }}"
                                wire:click="$dispatch('open-invitation-access', { access: {{ Js::from($grants) }} })">
                                <x-reicon name="keys" class="size-3.5" />
                                Scopes
                            </x-forms.button>
                        </div>
                    </div>
                    <p x-show="$wire.role === 'member'" class="text-[12px] text-neutral-500 lg:col-span-2 dark:text-fg-faint">
                        Scopes: <span class="text-neutral-700 dark:text-fg-dim">{{ \App\Livewire\Team\Member\Access::summary($grants) }}</span>
                    </p>
                </div>
            </x-application.settings-section>
        </form>

        {{-- Fork: invite a whole list with the role and scopes chosen above. --}}
        <form wire:submit="bulkInvite(false)" class="mt-6">
            <x-application.settings-section title="Invite a list"
                description="Paste one email per line (or separated by commas). They all get the role and scopes chosen above.">
                <x-slot:actions>
                    @if (is_transactional_emails_enabled())
                        <x-forms.button type="button" wire:click.prevent="bulkInvite(true)">
                            <x-reicon name="notifications" class="size-3.5" />
                            Send emails
                        </x-forms.button>
                    @endif
                    <x-forms.button type="submit" wire:target="bulkInvite" defaultClass="button button-highlighted">
                        <x-reicon name="plus" class="size-3.5" />
                        Generate links
                    </x-forms.button>
                </x-slot:actions>

                <div class="mt-4">
                    <x-forms.textarea id="emails" label="Email addresses" rows="6"
                        placeholder="alice@school.edu&#10;bob@school.edu" />
                </div>

                @if (count($bulkResults))
                    <div class="mt-4 flex flex-col gap-1 text-[12px]" x-data="{ copied: false }">
                        @foreach ($bulkResults as $result)
                            <div class="flex items-center justify-between gap-3">
                                <span class="truncate">{{ $result['email'] }}</span>
                                <span @class([
                                    'shrink-0 font-medium',
                                    'text-success' => $result['status'] === 'invited',
                                    'text-warning' => in_array($result['status'], ['member', 'pending']),
                                    'text-error' => in_array($result['status'], ['invalid', 'error']),
                                ])>
                                    {{ [
                                        'invited' => 'Invited',
                                        'member' => 'Already a member',
                                        'pending' => 'Invitation already pending',
                                        'invalid' => 'Invalid address',
                                        'error' => 'Failed',
                                    ][$result['status']] }}
                                </span>
                            </div>
                        @endforeach
                        @php($bulkLinks = collect($bulkResults)->filter(fn ($r) => filled($r['link']))->map(fn ($r) => $r['email'].' '.$r['link'])->implode("\n"))
                        @if (filled($bulkLinks))
                            <div class="mt-2">
                                <x-forms.button type="button"
                                    x-on:click="navigator.clipboard.writeText(@js($bulkLinks)); copied = true; setTimeout(() => copied = false, 2000)">
                                    <span x-text="copied ? 'Copied' : 'Copy all links'"></span>
                                </x-forms.button>
                            </div>
                        @endif
                    </div>
                @endif
            </x-application.settings-section>
        </form>
    @endcan
</div>
