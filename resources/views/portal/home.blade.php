<x-layouts.portal :presentation="$presentation" :navigation="$navigation" :portal-user="$portalUser">
    <div class="space-y-6">
        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-950">Your account</h1>
                    <p class="mt-2 text-sm text-slate-600">{{ $portalUser->email }}</p>
                </div>

                @if(!$portalUser->hasVerifiedEmail())
                    <span class="inline-flex w-fit rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Email verification pending</span>
                @endif
            </div>
        </section>

        @foreach($panels as $panel)
            @include($panel->view, ['portalUser' => $portalUser, 'panelData' => $panel->data])
        @endforeach
    </div>
</x-layouts.portal>