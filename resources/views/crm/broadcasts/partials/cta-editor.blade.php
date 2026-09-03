<section
    x-show="channel === 'email'"
    class="space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5"
    data-broadcast-cta-editor
>
    <input type="hidden" name="cta_present" value="1" x-bind:disabled="channel !== 'email'">

    <div>
        <h3 class="text-sm font-semibold text-slate-900">Call to action</h3>
        <p class="mt-1 text-xs leading-5 text-slate-500">
            Optional. Add one button to the email instead of pasting a long link into the message body. Messaging tracks delivered CTA clicks through its existing signed redirect.
        </p>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <x-ui.form.label for="cta_label">Button label</x-ui.form.label>
            <x-ui.form.input
                id="cta_label"
                name="cta[label]"
                x-model="ctaLabel"
                x-bind:disabled="channel !== 'email'"
                maxlength="255"
                placeholder="See the details"
            />
            <x-ui.form.error name="cta.label" />
        </div>

        <div>
            <x-ui.form.label for="cta_url">Destination URL</x-ui.form.label>
            <x-ui.form.input
                id="cta_url"
                name="cta[url]"
                type="url"
                x-model="ctaUrl"
                x-bind:disabled="channel !== 'email'"
                maxlength="2000"
                placeholder="https://example.com/details"
            />
            <x-ui.form.error name="cta.url" />
        </div>
    </div>

    <p class="text-xs leading-5 text-slate-500">
        Put <span class="font-mono">{cta}</span> on its own line in the email body to choose the button position. If omitted, the button is appended after the body.
    </p>
</section>