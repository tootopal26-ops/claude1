<div>
    <x-ui.page-header
        :title="$rule ? 'Edit salary rule' : 'New salary rule'"
        description="Each time the staff member performs this action, the amount below is added to their weekly settlement."
        :breadcrumbs="[
            ['label' => 'Salary rules', 'url' => portal_route('staff-salary.rules.index')],
            ['label' => $rule ? 'Edit' : 'New rule'],
        ]"
    />

    <form wire:submit="save" novalidate>
        <x-ui.form-section title="Who is paid" description="The staff member this rule applies to.">
            <x-ui.select label="Staff member" wire:model="staff_user_id" required wrapperClass="sm:col-span-2">
                <option value="">Select a staff member</option>
                @foreach ($staffUsers as $staffUser)
                    <option value="{{ $staffUser->id }}">{{ $staffUser->display_name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.form-section>

        <x-ui.form-section title="What earns pay" description="The action and how much each one is worth. Changing a rule later never alters pay already generated.">
            <x-ui.input label="Action type" wire:model="action_type" list="action-type-options" required placeholder="e.g. tournament.credentials_released" autocomplete="off" spellcheck="false" hint="Pick a known action or type your own." />
            <datalist id="action-type-options">
                @foreach ($actionTypes as $type)
                    <option value="{{ $type }}"></option>
                @endforeach
            </datalist>

            <x-ui.input label="Amount per action (coins)" type="number" step="0.01" min="0.01" wire:model="amount_per_action" required hint="Must be more than 0." />

            <div class="sm:col-span-2">
                <x-ui.switch wire:model="is_active" label="Active" hint="Inactive rules stop earning pay for new actions." />
            </div>
        </x-ui.form-section>

        <div class="mm-glass-bar sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-line px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <x-ui.button :href="portal_route('staff-salary.rules.index')" variant="ghost">Cancel</x-ui.button>
            <x-ui.button type="submit" loading="save">Save rule</x-ui.button>
        </div>
    </form>
</div>
