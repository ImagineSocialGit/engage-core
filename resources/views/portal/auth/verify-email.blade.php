<x-layouts.portal :presentation="$presentation" :portal-user="$portalUser">
    <div class="mx-auto max-w-xl rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight text-slate-950">Verify your email</h1>
        <p class="mt-2 text-sm text-slate-600">Your account email is {{ $portalUser->email }}.</p>

        @if(session('status'))
            <div class="mt-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @if($deliveryAvailable)
            <form method="POST" action="{{ route('portal.verification.send') }}" class="mt-6">
                @csrf
                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-full bg-[var(--public-primary)] px-5 py-2.5 text-sm font-extrabold text-white hover:brightness-95">
                    Send verification link
                </button>
            </form>
        @endif
    </div>
</x-layouts.portal>