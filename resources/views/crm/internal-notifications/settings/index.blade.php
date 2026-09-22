<x-layouts.crm
    title="Team notifications"
    heading="Team notifications"
    subheading="Choose who receives reply alerts and who can receive scheduled report emails."
    module="internal_notifications"
>
    <div class="space-y-6">
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-4 text-sm text-red-900">
                <p class="font-semibold">That change could not be saved.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <h2 class="text-lg font-semibold text-slate-950">Add notification recipient</h2>

            <form
                method="POST"
                action="{{ route('crm.internal-notifications.settings.recipients.store') }}"
                class="mt-5 grid gap-4 lg:grid-cols-[minmax(12rem,1fr)_minmax(16rem,1.4fr)_auto]"
            >
                @csrf
                <input type="hidden" name="is_active" value="1">

                <div>
                    <label for="notification-recipient-name" class="block text-sm font-semibold text-slate-800">
                        Name
                    </label>
                    <input
                        id="notification-recipient-name"
                        name="name"
                        type="text"
                        required
                        value="{{ old('name') }}"
                        class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                    >
                </div>

                <div>
                    <label for="notification-recipient-email" class="block text-sm font-semibold text-slate-800">
                        Email
                    </label>
                    <input
                        id="notification-recipient-email"
                        name="email"
                        type="email"
                        required
                        value="{{ old('email') }}"
                        class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                    >
                </div>

                <div class="flex items-end">
                    <button
                        type="submit"
                        class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"
                    >
                        Add recipient
                    </button>
                </div>

                <div class="lg:col-span-3 flex flex-wrap gap-5">
                    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="hidden" name="receive_inbound_replies" value="0">
                        <input
                            name="receive_inbound_replies"
                            type="checkbox"
                            value="1"
                            @checked(old('receive_inbound_replies', '1') === '1')
                            class="rounded border-slate-300"
                        >
                        Immediate reply emails
                    </label>

                    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="hidden" name="receive_scheduled_reports" value="0">
                        <input
                            name="receive_scheduled_reports"
                            type="checkbox"
                            value="1"
                            @checked(old('receive_scheduled_reports', '1') === '1')
                            class="rounded border-slate-300"
                        >
                        Scheduled report emails
                    </label>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            @forelse($recipients as $row)
                <form
                    method="POST"
                    action="{{ route('crm.internal-notifications.settings.recipients.update', $row['team_member']) }}"
                    class="border-b border-slate-100 p-5 last:border-b-0 sm:p-7"
                >
                    @csrf
                    @method('PATCH')

                    <div class="grid gap-4 lg:grid-cols-[minmax(12rem,1fr)_minmax(16rem,1.4fr)_auto]">
                        <div>
                            <label class="block text-sm font-semibold text-slate-800">Name</label>
                            <input
                                name="name"
                                type="text"
                                required
                                value="{{ $row['team_member']->name }}"
                                class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                            >
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-800">Email</label>
                            <input
                                name="email"
                                type="email"
                                required
                                value="{{ $row['team_member']->email }}"
                                class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                            >
                        </div>

                        <div class="flex items-end">
                            <button
                                type="submit"
                                class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:border-slate-400"
                            >
                                Save
                            </button>
                        </div>

                        <div class="lg:col-span-3 flex flex-wrap gap-5">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                <input type="hidden" name="is_active" value="0">
                                <input
                                    name="is_active"
                                    type="checkbox"
                                    value="1"
                                    @checked($row['team_member']->is_active)
                                    class="rounded border-slate-300"
                                >
                                Active
                            </label>

                            <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                <input type="hidden" name="receive_inbound_replies" value="0">
                                <input
                                    name="receive_inbound_replies"
                                    type="checkbox"
                                    value="1"
                                    @checked($row['receive_inbound_replies'])
                                    class="rounded border-slate-300"
                                >
                                Immediate reply emails
                            </label>

                            <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                <input type="hidden" name="receive_scheduled_reports" value="0">
                                <input
                                    name="receive_scheduled_reports"
                                    type="checkbox"
                                    value="1"
                                    @checked($row['receive_scheduled_reports'])
                                    class="rounded border-slate-300"
                                >
                                Scheduled report emails
                            </label>
                        </div>
                    </div>
                </form>
            @empty
                <div class="px-6 py-12 text-center text-sm text-slate-500">
                    No notification recipients have been added yet.
                </div>
            @endforelse
        </section>
    </div>
</x-layouts.crm>