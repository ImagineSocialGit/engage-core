<x-layouts.public :title="$series->title">
    <section class="mx-auto max-w-4xl px-6 py-16 text-center">
        <h1 class="text-4xl font-bold tracking-tight text-slate-900">
            No upcoming sessions are currently scheduled for {{ $series->title }}
        </h1>

        <p class="mx-auto mt-4 max-w-2xl text-lg text-slate-600">
            This webinar series exists, but there are no upcoming dates available right now.
        </p>

        @if($otherUpcomingSeries->isNotEmpty())
            <div class="mt-10">
                <h2 class="text-xl font-semibold text-slate-900">Other upcoming webinars</h2>

                <div class="mt-6 space-y-3">
                    @foreach($otherUpcomingSeries as $otherSeries)
                        <div>
                            <a
                                href="{{ route('webinar.show', $otherSeries->slug) }}"
                                class="text-base font-medium text-slate-900 underline hover:text-slate-700"
                            >
                                {{ $otherSeries->title }}
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
</x-layouts.public>