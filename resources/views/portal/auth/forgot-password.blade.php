<x-layouts.portal :presentation="$presentation">
    <div class="mx-auto max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight text-slate-950">Reset your password</h1>
        <p class="mt-2 text-sm text-slate-600">Enter the email address for your account.</p>

        @if(session('status'))
            <div class="mt-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('portal.password.request') }}" class="mt-6 space-y-5">
            @csrf

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Email</span>
                <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
                @error('email')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror
            </label>

            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-[var(--public-primary)] px-5 py-2.5 text-sm font-extrabold text-white hover:brightness-95">
                Send reset link
            </button>
        </form>

        <a href="{{ route('portal.login') }}" class="mt-5 inline-flex text-sm font-semibold text-slate-600 hover:text-slate-950">Back to sign in</a>
    </div>
</x-layouts.portal>