<x-layouts.portal :presentation="$presentation">
    <div class="mx-auto max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight text-slate-950">Choose a new password</h1>

        <form method="POST" action="{{ route('portal.password.update', ['token' => $token]) }}" class="mt-6 space-y-5">
            @csrf

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Email</span>
                <input type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
                @error('email')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">New password</span>
                <input type="password" name="password" autocomplete="new-password" required class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
                @error('password')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Confirm new password</span>
                <input type="password" name="password_confirmation" autocomplete="new-password" required class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
            </label>

            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-[var(--public-primary)] px-5 py-2.5 text-sm font-extrabold text-white hover:brightness-95">
                Update password
            </button>
        </form>
    </div>
</x-layouts.portal>