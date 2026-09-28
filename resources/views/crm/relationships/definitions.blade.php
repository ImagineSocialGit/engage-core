<x-layouts.crm
    title="Relationships"
    heading="Relationships"
    subheading="Define the business contexts and stages available for contacts."
>
    <div class="mx-auto max-w-5xl space-y-8">
        @if(session('success'))
            <x-ui.feedback.alert type="success">{{ session('success') }}</x-ui.feedback.alert>
        @endif

        @if($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-950">Add relationship type</h2>
            <form method="POST" action="{{ route('crm.relationships.definitions.types.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                @csrf
                <label class="text-sm font-medium text-slate-700">Key
                    <input name="key" value="{{ old('key') }}" required pattern="[a-z0-9]+(_[a-z0-9]+)*" maxlength="120" placeholder="realtor" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="text-sm font-medium text-slate-700">Singular
                    <input name="singular" value="{{ old('singular') }}" required maxlength="120" placeholder="Realtor" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="text-sm font-medium text-slate-700">Plural
                    <input name="plural" value="{{ old('plural') }}" required maxlength="120" placeholder="Realtors" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="text-sm font-medium text-slate-700">Sort order
                    <input name="sort_order" type="number" value="{{ old('sort_order', 0) }}" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="text-sm font-medium text-slate-700">Visibility
                    <select name="visible" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        <option value="1">Visible</option>
                        <option value="0">Hidden</option>
                    </select>
                </label>
                <div class="flex items-end">
                    <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">Add type</button>
                </div>
            </form>
        </section>

        @foreach($definitions as $definition)
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-semibold text-slate-950">{{ $definition['singular'] }}</h2>
                    <span class="text-xs text-slate-500">{{ $definition['key'] }}</span>
                </div>

                <form method="POST" action="{{ route('crm.relationships.definitions.types.update', $definition['key']) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <label class="text-sm font-medium text-slate-700">Singular
                        <input name="singular" value="{{ $definition['singular'] }}" required maxlength="120" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="text-sm font-medium text-slate-700">Plural
                        <input name="plural" value="{{ $definition['plural'] }}" required maxlength="120" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="text-sm font-medium text-slate-700">Sort order
                        <input name="sort_order" type="number" value="{{ $definition['sort_order'] }}" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="text-sm font-medium text-slate-700">Visibility
                        <select name="visible" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="1" @selected($definition['visible'])>Visible</option>
                            <option value="0" @selected(! $definition['visible'])>Hidden</option>
                        </select>
                    </label>
                    <div class="sm:col-span-2">
                        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900">Save type</button>
                    </div>
                </form>

                <div class="mt-6 border-t border-slate-200 pt-5">
                    <h3 class="font-semibold text-slate-950">Stages</h3>
                    <div class="mt-3 space-y-3">
                        @foreach($definition['stages'] as $stage)
                            <form method="POST" action="{{ route('crm.relationships.definitions.stages.update', [$definition['key'], $stage['key']]) }}" class="grid items-end gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_7rem_8rem_auto]">
                                @csrf
                                @method('PUT')
                                <label class="text-sm font-medium text-slate-700">{{ $stage['key'] }}
                                    <input name="label" value="{{ $stage['label'] }}" required maxlength="120" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                </label>
                                <label class="text-sm font-medium text-slate-700">Order
                                    <input name="sort_order" type="number" value="{{ $stage['sort_order'] }}" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                </label>
                                <label class="text-sm font-medium text-slate-700">Availability
                                    <select name="active" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                        <option value="1" @selected($stage['active'])>Active</option>
                                        <option value="0" @selected(! $stage['active'])>Inactive</option>
                                    </select>
                                </label>
                                <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900">Save</button>
                            </form>
                        @endforeach
                    </div>

                    <form method="POST" action="{{ route('crm.relationships.definitions.stages.store', $definition['key']) }}" class="mt-4 grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_7rem_8rem_auto]">
                        @csrf
                        <label class="text-sm font-medium text-slate-700">New stage key
                            <input name="key" required pattern="[a-z0-9]+(_[a-z0-9]+)*" maxlength="120" placeholder="follow_up" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        </label>
                        <label class="text-sm font-medium text-slate-700">Label
                            <input name="label" required maxlength="120" placeholder="Follow Up" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        </label>
                        <label class="text-sm font-medium text-slate-700">Order
                            <input name="sort_order" type="number" value="0" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        </label>
                        <label class="text-sm font-medium text-slate-700">Availability
                            <select name="active" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </label>
                        <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">Add stage</button>
                    </form>
                </div>
            </section>
        @endforeach
    </div>
</x-layouts.crm>