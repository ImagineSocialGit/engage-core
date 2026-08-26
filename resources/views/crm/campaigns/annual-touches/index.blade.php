<x-layouts.crm
    title="Annual Touches"
    heading="Annual Touches"
    subheading="Set recurring birthday and calendar-date messages for a Contact Status audience."
    module="campaigns"
>
    @php
        $editing = $editingProgram instanceof \App\Modules\Campaigns\Models\CampaignTouchProgram;
        $defaultAudienceKey = old('audience_key', $editing ? $editingProgram->audience_key : '');
        $defaultRepeatYears = old('repeat_years', $editing ? $editingProgram->repeat_years : 10);
        $defaultStartsOn = old('starts_on', $editing && $editingProgram->starts_on ? $editingProgram->starts_on->toDateString() : '');
        $defaultActive = old('is_active', $editing ? $editingProgram->is_active : true);

        $initialTouches = old('touches');

        if (! is_array($initialTouches)) {
            if ($editing) {
                $initialTouches = $editingProgram->touchDates
                    ->where('is_active', true)
                    ->values()
                    ->map(function ($touchDate) {
                        $variants = $touchDate->variants->where('is_active', true)->keyBy('channel');

                        return [
                            'id' => $touchDate->getKey(),
                            'name' => $touchDate->name ?: 'Annual touch',
                            'source_type' => $touchDate->source_type === \App\Modules\Campaigns\Models\CampaignTouchDate::SOURCE_CONTACT_FIELD
                                && $touchDate->source_key === 'birthday'
                                    ? 'birthday'
                                    : 'fixed_date',
                            'month' => $touchDate->month,
                            'day' => $touchDate->day,
                            'send_time' => is_string($touchDate->send_time)
                                ? substr($touchDate->send_time, 0, 5)
                                : '09:00',
                            'email_template_preset_id' => $variants->get('email')?->message_template_preset_id,
                            'sms_template_preset_id' => $variants->get('sms')?->message_template_preset_id,
                        ];
                    })
                    ->all();
            } else {
                $initialTouches = [[
                    'id' => null,
                    'name' => 'Birthday',
                    'source_type' => 'birthday',
                    'month' => null,
                    'day' => null,
                    'send_time' => '09:00',
                    'email_template_preset_id' => null,
                    'sms_template_preset_id' => null,
                ]];
            }
        }

    @endphp

    <div class="min-w-0 space-y-6">
        @if(session('status'))
            <div class="break-words rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                <div class="font-bold">Please fix the highlighted annual-touch setup.</div>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.campaigns.index') }}"
                class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 hover:bg-slate-50"
            >
                Back to Campaigns
            </a>

            @if($editing)
                <a
                    href="{{ route('crm.campaigns.annual-touches.index') }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800"
                >
                    Add another program
                </a>
            @endif
        </div>

        <section class="rounded-3xl border border-rose-200 bg-white p-4 shadow-sm sm:p-7">
            <div class="max-w-3xl">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Recurring nurture</p>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                    Have recurring annual touch-base dates
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Choose who this applies to, how many years it runs, then add annual dates. Messaging still decides whether each Email or SMS is eligible to send.
                </p>
            </div>

            <form
                method="POST"
                action="{{ $editing
                    ? route('crm.campaigns.annual-touches.update', $editingProgram)
                    : route('crm.campaigns.annual-touches.store') }}"
                class="mt-6 space-y-6"
                x-data="{
                    rows: @js(array_values($initialTouches)),
                    newEmailTemplates: [],
                    newSmsTemplates: [],
                    templateCreator: {
                        open: false,
                        saving: false,
                        error: '',
                        channel: 'email',
                        rowIndex: 0,
                        name: '',
                        subject: '',
                        body: '',
                        message: '',
                        activeField: 'body',
                    },
                    init() {
                        this.rows = this.rows.map((row) => ({
                            ...row,
                            email_template_preset_id: row.email_template_preset_id ? String(row.email_template_preset_id) : '',
                            sms_template_preset_id: row.sms_template_preset_id ? String(row.sms_template_preset_id) : '',
                        }));
                    },
                    addRow() {
                        this.rows.push({
                            id: null,
                            name: '',
                            source_type: 'fixed_date',
                            month: null,
                            day: null,
                            send_time: '09:00',
                            email_template_preset_id: null,
                            sms_template_preset_id: null,
                        });
                    },
                    removeRow(index) {
                        this.rows.splice(index, 1);
                    },
                    openTemplateCreator(channel, index) {
                        this.templateCreator = {
                            open: true,
                            saving: false,
                            error: '',
                            channel,
                            rowIndex: index,
                            name: this.rows[index]?.name ?? '',
                            subject: '',
                            body: '',
                            message: '',
                            activeField: channel === 'sms' ? 'message' : 'body',
                        };
                    },
                    closeTemplateCreator() {
                        if (! this.templateCreator.saving) {
                            this.templateCreator.open = false;
                        }
                    },
                    setActiveTemplateField(field) {
                        this.templateCreator.activeField = field;
                    },
                    insertField(syntax) {
                        const field = this.templateCreator.channel === 'sms'
                            ? 'message'
                            : (['subject', 'body'].includes(this.templateCreator.activeField)
                                ? this.templateCreator.activeField
                                : 'body');
                        const element = this.$root.querySelector(`[data-template-authoring-field='${field}']`);
                        const currentValue = String(this.templateCreator[field] ?? '');
                        const selectionStart = element && Number.isInteger(element.selectionStart)
                            ? element.selectionStart
                            : currentValue.length;
                        const selectionEnd = element && Number.isInteger(element.selectionEnd)
                            ? element.selectionEnd
                            : selectionStart;

                        this.templateCreator[field] = currentValue.slice(0, selectionStart)
                            + syntax
                            + currentValue.slice(selectionEnd);

                        this.$nextTick(() => {
                            const updatedElement = this.$root.querySelector(`[data-template-authoring-field='${field}']`);

                            if (! updatedElement) {
                                return;
                            }

                            const nextPosition = selectionStart + syntax.length;
                            updatedElement.focus();

                            if (typeof updatedElement.setSelectionRange === 'function') {
                                updatedElement.setSelectionRange(nextPosition, nextPosition);
                            }
                        });
                    },
                    async createTemplate() {
                        this.templateCreator.saving = true;
                        this.templateCreator.error = '';

                        try {
                            const response = await fetch(@js(route('crm.campaigns.annual-touches.message-templates.store')), {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': @js(csrf_token()),
                                },
                                body: JSON.stringify({
                                    channel: this.templateCreator.channel,
                                    name: this.templateCreator.name,
                                    subject: this.templateCreator.subject,
                                    body: this.templateCreator.body,
                                    message: this.templateCreator.message,
                                }),
                            });

                            const result = await response.json().catch(() => ({}));

                            if (! response.ok) {
                                const validationMessages = Object.values(result.errors ?? {}).flat();
                                this.templateCreator.error = validationMessages[0] ?? result.message ?? 'The message template could not be created.';
                                return;
                            }

                            const option = { id: String(result.id), name: result.name };

                            if (result.channel === 'sms') {
                                this.newSmsTemplates.push(option);
                                this.newSmsTemplates.sort((a, b) => a.name.localeCompare(b.name));
                                this.$nextTick(() => {
                                    this.rows[this.templateCreator.rowIndex].sms_template_preset_id = option.id;
                                });
                            } else {
                                this.newEmailTemplates.push(option);
                                this.newEmailTemplates.sort((a, b) => a.name.localeCompare(b.name));
                                this.$nextTick(() => {
                                    this.rows[this.templateCreator.rowIndex].email_template_preset_id = option.id;
                                });
                            }

                            this.templateCreator.open = false;
                        } catch (error) {
                            this.templateCreator.error = 'The message template could not be created. Try again.';
                        } finally {
                            this.templateCreator.saving = false;
                        }
                    },
                }"
            >
                @csrf
                @if($editing)
                    @method('PUT')
                @endif

                <div class="grid min-w-0 gap-4 lg:grid-cols-3">
                    <div class="min-w-0 lg:col-span-2">
                        <label class="text-sm font-bold text-slate-900">Contact Status</label>
                        <select
                            name="audience_key"
                            required
                            class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                        >
                            <option value="">Select a status</option>
                            @foreach($contactStatuses as $status)
                                <option value="{{ $status->key }}" @selected((string) $defaultAudienceKey === (string) $status->key)>
                                    {{ $status->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="min-w-0">
                        <label class="text-sm font-bold text-slate-900">Repeat for</label>
                        <div class="mt-2 flex items-center gap-2">
                            <input
                                type="number"
                                min="1"
                                max="50"
                                name="repeat_years"
                                value="{{ $defaultRepeatYears }}"
                                required
                                class="min-h-11 min-w-0 flex-1 rounded-xl border border-slate-300 px-3 text-sm text-slate-900"
                            >
                            <span class="text-sm font-semibold text-slate-600">years</span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-bold text-slate-900">Start date <span class="font-normal text-slate-500">(optional)</span></label>
                        <input
                            type="date"
                            name="starts_on"
                            value="{{ $defaultStartsOn }}"
                            class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm text-slate-900"
                        >
                    </div>

                    <label class="flex min-h-11 items-center gap-3 self-end rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <input type="hidden" name="is_active" value="0">
                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            @checked((bool) $defaultActive)
                            class="h-4 w-4 rounded border-slate-300"
                        >
                        <span>
                            <span class="block text-sm font-bold text-slate-900">Enabled</span>
                            <span class="block text-xs text-slate-500">This annual-touch program can send its configured messages when each date becomes due.</span>
                        </span>
                    </label>
                </div>

                <div class="space-y-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-950">Annual dates</h3>
                            <p class="mt-1 text-sm text-slate-600">Birthday uses the Contact birthday field. Fixed dates are useful for holidays or client-defined annual touches.</p>
                        </div>
                        <button
                            type="button"
                            @click="addRow()"
                            class="inline-flex min-h-11 items-center justify-center rounded-full border border-rose-300 bg-rose-50 px-5 text-sm font-bold text-rose-900 hover:bg-rose-100"
                        >
                            Add date
                        </button>
                    </div>

                    <template x-for="(row, index) in rows" :key="row.id ?? `new-${index}`">
                        <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                            <input type="hidden" :name="`touches[${index}][id]`" x-model="row.id">

                            <div class="grid min-w-0 gap-4 lg:grid-cols-12">
                                <div class="min-w-0 lg:col-span-3">
                                    <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Name</label>
                                    <input
                                        type="text"
                                        required
                                        :name="`touches[${index}][name]`"
                                        x-model="row.name"
                                        placeholder="Birthday, Christmas, Anniversary..."
                                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                                    >
                                </div>

                                <div class="min-w-0 lg:col-span-2">
                                    <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Date type</label>
                                    <select
                                        :name="`touches[${index}][source_type]`"
                                        x-model="row.source_type"
                                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                                    >
                                        <option value="birthday">Contact birthday</option>
                                        <option value="fixed_date">Fixed annual date</option>
                                    </select>
                                </div>

                                <div class="min-w-0 lg:col-span-2" x-show="row.source_type === 'fixed_date'">
                                    <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Month / day</label>
                                    <div class="mt-2 grid grid-cols-2 gap-2">
                                        <select
                                            :name="`touches[${index}][month]`"
                                            x-model="row.month"
                                            class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-2 text-sm text-slate-900"
                                        >
                                            <option value="">Month</option>
                                            @foreach(range(1, 12) as $month)
                                                <option value="{{ $month }}">{{ \Carbon\Carbon::create(2000, $month, 1)->format('M') }}</option>
                                            @endforeach
                                        </select>
                                        <select
                                            :name="`touches[${index}][day]`"
                                            x-model="row.day"
                                            class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-2 text-sm text-slate-900"
                                        >
                                            <option value="">Day</option>
                                            @foreach(range(1, 31) as $day)
                                                <option value="{{ $day }}">{{ $day }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="min-w-0 lg:col-span-2">
                                    <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Send time</label>
                                    <input
                                        type="time"
                                        required
                                        :name="`touches[${index}][send_time]`"
                                        x-model="row.send_time"
                                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                                    >
                                </div>

                                <div class="min-w-0 lg:col-span-3 lg:text-right">
                                    <button
                                        type="button"
                                        @click="removeRow(index)"
                                        x-show="rows.length > 1"
                                        class="mt-6 inline-flex min-h-10 items-center justify-center rounded-full border border-red-200 bg-white px-4 text-sm font-bold text-red-700 hover:bg-red-50"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>

                            <div class="mt-4 rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-xs leading-5 text-slate-600">
                                Only saved reusable marketing messages appear here. Campaign steps, webinar reminders, reply acknowledgements, and other lifecycle-owned messages are intentionally excluded.
                            </div>

                            <div class="mt-4 grid min-w-0 gap-4 lg:grid-cols-2">
                                <div class="min-w-0">
                                    <div class="flex items-center justify-between gap-3">
                                        <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Saved email message</label>
                                        <button
                                            type="button"
                                            @click="openTemplateCreator('email', index)"
                                            class="text-xs font-bold text-rose-700 hover:text-rose-900"
                                        >
                                            + Add new message
                                        </button>
                                    </div>
                                    <select
                                        :name="`touches[${index}][email_template_preset_id]`"
                                        x-model="row.email_template_preset_id"
                                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                                    >
                                        <option value="">No email</option>
                                        @foreach($emailTemplates as $template)
                                            <option
                                                value="{{ $template->getKey() }}"
                                                data-template-option-id="{{ $template->getKey() }}"
                                            >{{ $template->name }}</option>
                                        @endforeach
                                        <template x-for="template in newEmailTemplates" :key="`new-email-${template.id}`">
                                            <option :value="template.id" x-text="template.name" data-new-template-option></option>
                                        </template>
                                    </select>
                                </div>

                                <div class="min-w-0">
                                    <div class="flex items-center justify-between gap-3">
                                        <label class="text-xs font-bold uppercase tracking-wide text-slate-600">Saved SMS message</label>
                                        <button
                                            type="button"
                                            @click="openTemplateCreator('sms', index)"
                                            class="text-xs font-bold text-rose-700 hover:text-rose-900"
                                        >
                                            + Add new message
                                        </button>
                                    </div>
                                    <select
                                        :name="`touches[${index}][sms_template_preset_id]`"
                                        x-model="row.sms_template_preset_id"
                                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900"
                                    >
                                        <option value="">No SMS</option>
                                        @foreach($smsTemplates as $template)
                                            <option
                                                value="{{ $template->getKey() }}"
                                                data-template-option-id="{{ $template->getKey() }}"
                                            >{{ $template->name }}</option>
                                        @endforeach
                                        <template x-for="template in newSmsTemplates" :key="`new-sms-${template.id}`">
                                            <option :value="template.id" x-text="template.name" data-new-template-option></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                        </article>
                    </template>
                </div>

                <div
                    x-show="templateCreator.open"
                    x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4"
                    @keydown.escape.window="closeTemplateCreator()"
                    @message-field-insert="insertField($event.detail.syntax)"
                >
                    <div
                        class="w-full max-w-2xl rounded-3xl bg-white p-5 shadow-2xl sm:p-7"
                        @click.outside="closeTemplateCreator()"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Annual Touch message</p>
                                <h3 class="mt-2 text-xl font-semibold text-slate-950" x-text="templateCreator.channel === 'sms' ? 'Create SMS message' : 'Create email message'"></h3>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    Messaging fills in the standalone annual-touch purpose, scope, dispatch context, catalog grouping, and valid fields automatically.
                                </p>
                            </div>
                            <button type="button" @click="closeTemplateCreator()" class="text-sm font-bold text-slate-500 hover:text-slate-900">Close</button>
                        </div>

                        <div x-show="templateCreator.error" x-text="templateCreator.error" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800"></div>

                        <div class="mt-5 space-y-4">
                            <div>
                                <label class="text-sm font-bold text-slate-900">Message name</label>
                                <input type="text" x-model="templateCreator.name" maxlength="191" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm text-slate-900" placeholder="Birthday greeting">
                            </div>

                            <template x-if="templateCreator.channel === 'email'">
                                <div class="space-y-4">
                                    <div>
                                        <label class="text-sm font-bold text-slate-900">Subject</label>
                                        <input
                                            type="text"
                                            x-model="templateCreator.subject"
                                            @focus="setActiveTemplateField('subject')"
                                            maxlength="255"
                                            data-template-authoring-field="subject"
                                            class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm text-slate-900"
                                        >
                                    </div>
                                    <div>
                                        <label class="text-sm font-bold text-slate-900">Body</label>
                                        <textarea
                                            x-model="templateCreator.body"
                                            @focus="setActiveTemplateField('body')"
                                            rows="9"
                                            data-template-authoring-field="body"
                                            class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                        ></textarea>
                                    </div>
                                </div>
                            </template>

                            <template x-if="templateCreator.channel === 'sms'">
                                <div>
                                    <label class="text-sm font-bold text-slate-900">Message</label>
                                    <textarea
                                        x-model="templateCreator.message"
                                        @focus="setActiveTemplateField('message')"
                                        rows="6"
                                        maxlength="1600"
                                        data-template-authoring-field="message"
                                        class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                    ></textarea>
                                </div>
                            </template>

                            <x-messaging.available-fields
                                :groups="$annualTouchAvailableFields"
                                class="mt-5"
                            />
                        </div>

                        <div class="mt-6 flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
                            <button type="button" @click="closeTemplateCreator()" class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 px-5 text-sm font-bold text-slate-800 hover:bg-slate-50">Cancel</button>
                            <button type="button" @click="createTemplate()" :disabled="templateCreator.saving" class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">
                                <span x-text="templateCreator.saving ? 'Creating…' : 'Create and use message'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-5 text-slate-500">
                        Choose a saved reusable message or create one here. New messages automatically receive the standalone annual-touch context and are selected in this row after creation.
                    </p>
                    <button
                        type="submit"
                        class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-bold text-white hover:bg-slate-800"
                    >
                        {{ $editing ? 'Save annual touches' : 'Create annual touches' }}
                    </button>
                </div>
            </form>
        </section>

        @if($programs->isNotEmpty())
            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                    <h2 class="text-lg font-semibold text-slate-950">Configured annual-touch programs</h2>
                </div>

                @foreach($programs as $program)
                    <article class="border-b border-slate-200 p-4 last:border-b-0 sm:p-6">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="break-words font-semibold text-slate-950">{{ $contactStatuses->firstWhere('key', $program->audience_key)?->name ?? $program->audience_key }}</h3>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $program->is_active ? 'bg-emerald-100 text-emerald-900' : 'bg-slate-100 text-slate-600' }}">
                                        {{ $program->is_active ? 'Enabled' : 'Off' }}
                                    </span>
                                </div>
                                <p class="mt-2 text-sm text-slate-600">
                                    Contact Status audience · {{ $program->repeat_years }} years
                                    · {{ $program->touchDates->where('is_active', true)->count() }} annual {{ \Illuminate\Support\Str::plural('date', $program->touchDates->where('is_active', true)->count()) }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row">
                                <a
                                    href="{{ route('crm.campaigns.annual-touches.index', ['edit' => $program->getKey()]) }}"
                                    class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 px-5 text-sm font-bold text-slate-800 hover:bg-slate-50"
                                >
                                    Edit
                                </a>
                                @if($program->is_active)
                                    <form method="POST" action="{{ route('crm.campaigns.annual-touches.destroy', $program) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            type="submit"
                                            class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-red-200 px-5 text-sm font-bold text-red-700 hover:bg-red-50 sm:w-auto"
                                        >
                                            Turn off
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </section>
        @endif
    </div>
</x-layouts.crm>