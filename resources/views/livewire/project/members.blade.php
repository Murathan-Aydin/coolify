<section class="application-settings-section">
    <div class="application-settings-section-header">
        <div>
            <h2>Member access</h2>
            <p>Give team members a role on this project. A viewer sees it, a deployer also deploys, starts, stops and
                restarts, and a manager also edits settings, environment variables, domains and storage, but cannot
                delete anything. Change the role of single resources below each member. Members marked "assigned
                projects only" see nothing else. Admins and owners always have every right.</p>
        </div>
    </div>
    <div class="application-settings-section-body flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
        @forelse ($members as $member)
            <div wire:key="project-member-{{ $member['id'] }}" x-data="{ open: false }" class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0">
                <div class="flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="truncate text-[13px] font-medium">{{ $member['name'] }}</div>
                        <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">
                            {{ $member['email'] }}
                            @if ($member['restricted'])
                                · assigned projects only
                            @endif
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        @if (count($member['resources']) > 0)
                            <button type="button" @click="open = !open" class="button shrink-0 px-2.5 text-xs"
                                :aria-expanded="open">
                                Resources{{ $member['customized'] ? ' ('.$member['customized'].')' : '' }}
                            </button>
                        @endif
                        <div class="w-52">
                            <x-forms.listbox :wire="false" :value="$member['access']" htmlId="project-access-{{ $member['id'] }}"
                                onChange="setAccess" :onChangeArgs="[$member['id']]" canGate="update" :canResource="$project"
                                :options="[
                                    ['value' => 'none', 'label' => $member['restricted'] ? 'No access' : 'Team default'],
                                    ['value' => 'selected', 'label' => 'Selected resources only'],
                                    ['value' => 'viewer', 'label' => 'Viewer'],
                                    ['value' => 'deployer', 'label' => 'Deployer'],
                                    ['value' => 'manager', 'label' => 'Manager'],
                                ]" />
                        </div>
                    </div>
                </div>
                @if (count($member['resources']) > 0)
                    <div x-show="open" x-cloak class="flex flex-col gap-2 rounded-lg border border-neutral-200 p-3 dark:border-white/[0.07]">
                        @foreach ($member['resources'] as $resource)
                            <div wire:key="resource-access-{{ $member['id'] }}-{{ $resource['key'] }}" class="flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="truncate text-[13px]">{{ $resource['name'] }}</div>
                                    <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">
                                        {{ $resource['type'] }} · {{ $resource['environment'] }}
                                    </div>
                                </div>
                                <div class="w-52 shrink-0">
                                    <x-forms.listbox :wire="false" :value="$resource['access']"
                                        htmlId="resource-access-{{ $member['id'] }}-{{ $loop->index }}"
                                        onChange="setResourceAccess" :onChangeArgs="[$member['id'], $resource['key']]"
                                        canGate="update" :canResource="$project"
                                        :options="[
                                            ['value' => 'inherit', 'label' => 'Same as project'],
                                            ['value' => 'none', 'label' => 'No access'],
                                            ['value' => 'viewer', 'label' => 'Viewer'],
                                            ['value' => 'deployer', 'label' => 'Deployer'],
                                            ['value' => 'manager', 'label' => 'Manager'],
                                        ]" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm text-neutral-500 dark:text-fg-dim">This team has no members with the member role.</p>
        @endforelse
    </div>
</section>
