<div
    class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 px-4 py-8 backdrop-blur-sm"
    data-public-human-verification
    role="dialog"
    aria-modal="true"
    aria-labelledby="public-human-verification-title"
    aria-hidden="true"
>
    <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl ring-1 ring-slate-900/10 sm:p-8">
        <div class="space-y-2">
            <h2
                id="public-human-verification-title"
                class="text-xl font-extrabold tracking-tight text-slate-950"
            >
                Security check
            </h2>
            <p class="text-sm leading-6 text-slate-600">
                Please complete this quick check before continuing.
            </p>
        </div>

        <div class="mt-6 min-h-16" data-public-human-verification-widget></div>

        <p
            class="mt-4 hidden text-sm font-semibold text-red-700"
            data-public-human-verification-error
            role="alert"
        ></p>

        @error('human_verification')
            <p class="mt-4 text-sm font-semibold text-red-700" role="alert">
                {{ $message }}
            </p>
        @enderror

        <div class="mt-6 flex justify-end">
            <button
                type="button"
                class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2"
                data-public-human-verification-cancel
            >
                Cancel
            </button>
        </div>
    </div>
</div>

<script type="application/json" data-public-human-verification-config>@json($humanVerificationConfig)</script>

<script>
    (() => {
        if (window.__publicHumanVerificationBootstrap?.patched) {
            return;
        }

        const state = {
            nativeSubmit: HTMLFormElement.prototype.submit,
            pendingForms: [],
            ready: false,
            patched: true,
        };

        HTMLFormElement.prototype.submit = function publicHumanVerificationBootstrapSubmit() {
            if (!state.ready) {
                state.pendingForms.push(this);

                return;
            }

            state.nativeSubmit.call(this);
        };

        window.__publicHumanVerificationBootstrap = state;
    })();
</script>