<x-layouts.crm
    title="Create Event"
    heading="Create Event"
    subheading="Create one concrete occurrence as a draft, then move it to upcoming when the required details are ready."
    module="events"
>
    <div class="mx-auto max-w-5xl">
        <form method="POST" action="{{ route('crm.events.store') }}">
            @csrf
            @include('crm.events.partials.form', [
                'submitLabel' => 'Create draft',
                'cancelUrl' => route('crm.events.index'),
            ])
        </form>
    </div>
</x-layouts.crm>