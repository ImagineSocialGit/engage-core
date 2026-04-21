<x-layous.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
>
    <div class="space-y-6">
        @if (session('status'))
            <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <div class="font-semibold">Please fix the following:</div>
                <ul class="mt-2 list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">Source Webinar</h2>

            <div class="mt-4 grid gap-4 text-sm text-slate-700 md:grid-cols-2">
                <div>
                    <div class="text-slate-500">Title</div>
                    <div class="font-medium">{{ $webinar->title }}</div>
                </div>

                <div>
                    <div class="text-slate-500">Slug</div>
                    <div class="font-medium">{{ $webinar->slug }}</div>
                </div>

                <div>
                    <div class="text-slate-500">Platform</div>
                    <div class="font-medium">{{ $webinar->platform }}</div>
                </div>

                <div>
                    <div class="text-slate-500">Timezone</div>
                    <div class="font-medium">{{ $webinar->timezone }}</div>
                </div>

                <div>
                    <div class="text-slate-500">Starts At</div>
                    <div class="font-medium">
                        {{ $webinar->starts_at?->copy()->setTimezone($webinar->timezone)->format('M j, Y g:i A') }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Ends At</div>
                    <div class="font-medium">
                        {{ $webinar->ends_at?->copy()->setTimezone($webinar->timezone)->format('M j, Y g:i A') }}
                    </div>
                </div>
            </div>
        </div>

        <form
            method="POST"
            action="{{ route('crm.webinar.copies.store', $webinar) }}"
            x-data="webinarCopiesForm()"
            class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
        >
            @csrf

            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900">Create Copies</h2>

                <button
                    type="button"
                    @click="addRow()"
                    class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700"
                >
                    Add Another Date
                </button>
            </div>

            <div class="mt-6 space-y-6">
                <template x-for="(copy, index) in copies" :key="copy.key">
                    <div class="rounded-xl border border-slate-200 p-4">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-slate-900" x-text="`Copy ${index + 1}`"></h3>

                            <button
                                type="button"
                                @click="removeRow(index)"
                                x-show="copies.length > 1"
                                class="text-sm font-medium text-red-600 hover:text-red-500"
                            >
                                Remove
                            </button>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Start Date/Time</label>
                                <input
                                    type="datetime-local"
                                    :name="`copies[${index}][starts_at]`"
                                    x-model="copy.starts_at"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none"
                                    required
                                >
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">External ID</label>
                                <input
                                    type="text"
                                    :name="`copies[${index}][external_id]`"
                                    x-model="copy.external_id"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none"
                                    required
                                >
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">End Date/Time (optional)</label>
                                <input
                                    type="datetime-local"
                                    :name="`copies[${index}][ends_at]`"
                                    x-model="copy.ends_at"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none"
                                >
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Title Override (optional)</label>
                                <input
                                    type="text"
                                    :name="`copies[${index}][title]`"
                                    x-model="copy.title"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none"
                                >
                            </div>

                            <div class="md:col-span-2">
                                <label class="mb-1 block text-sm font-medium text-slate-700">Slug Override (optional)</label>
                                <input
                                    type="text"
                                    :name="`copies[${index}][slug]`"
                                    x-model="copy.slug"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none"
                                >
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="mt-6">
                <button
                    type="submit"
                    class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700"
                >
                    Create Webinar Copies
                </button>
            </div>
        </form>
    </div>

    <script>
        function webinarCopiesForm() {
            return {
                copies: [
                    {
                        key: crypto.randomUUID(),
                        starts_at: '',
                        external_id: '',
                        ends_at: '',
                        title: '',
                        slug: '',
                    }
                ],

                addRow() {
                    this.copies.push({
                        key: crypto.randomUUID(),
                        starts_at: '',
                        external_id: '',
                        ends_at: '',
                        title: '',
                        slug: '',
                    });
                },

                removeRow(index) {
                    this.copies.splice(index, 1);
                },
            };
        }
    </script>
</x-layous.crm>