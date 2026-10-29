<section class="application-settings-section">
    <div class="application-settings-section-header">
        <div>
            <h2>Member access</h2>
            <p>Members marked "assigned projects only" see only the projects they are given here. A deployer
                can also deploy, start, stop and restart resources. Admins and owners always see everything.</p>
        </div>
    </div>
    <div class="application-settings-section-body flex flex-col gap-3">
        @forelse ($members as $member)
            <div wire:key="project-member-{{ $member['id'] }}" class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <div class="truncate text-[13px] font-medium">{{ $member['name'] }}</div>
                    <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">
                        {{ $member['email'] }}
                        @if ($member['restricted'])
                            · assigned projects only
                        @endif
                    </div>
                </div>
                <div class="w-44 shrink-0">
                    <x-forms.listbox :wire="false" :value="$member['access']" htmlId="project-access-{{ $member['id'] }}"
                        onChange="setAccess" :onChangeArgs="[$member['id']]" canGate="update" :canResource="$project"
                        :options="[
                            ['value' => 'none', 'label' => $member['restricted'] ? 'No access' : 'Team default'],
                            ['value' => 'viewer', 'label' => 'Viewer'],
                            ['value' => 'deployer', 'label' => 'Deployer'],
                        ]" />
                </div>
            </div>
        @empty
            <p class="text-sm text-neutral-500 dark:text-fg-dim">This team has no members with the member role.</p>
        @endforelse
    </div>
</section>
