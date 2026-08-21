@if(($reusableMessageTemplates ?? []) !== [])
    <div
        x-show="availableReusableMessages().length > 0"
        x-cloak
        class="rounded-xl border border-slate-200 bg-slate-50 p-4"
    >
        <x-ui.form.label for="reusable_message_template">
            Start from a saved message
        </x-ui.form.label>

        <select
            id="reusable_message_template"
            x-model="selectedReusableMessageId"
            x-on:change="applyReusableMessage()"
            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
        >
            <option value="">Start with blank/current copy</option>
            <template x-for="template in availableReusableMessages()" :key="template.id">
                <option :value="String(template.id)" x-text="template.name"></option>
            </template>
        </select>

        <p class="mt-2 text-xs text-slate-500">
            This loads a copy into the Broadcast. Editing this Broadcast does not change the saved Message Template.
        </p>
    </div>
@endif