<x-layouts.crm
    :title="$event->title"
    heading="Edit Event draft"
    subheading="Adjust the occurrence while it is still a draft. Lifecycle changes after promotion use explicit Event actions."
    module="events"
>
    <div class="mx-auto max-w-5xl">
        <form method="POST" action="{{ route('crm.events.update', $event) }}">
            @csrf
            @method('PATCH')
            @include('crm.events.partials.form', [
                'submitLabel' => 'Save draft',
                'cancelUrl' => route('crm.events.show', $event),
            ])
        </form>
    </div>
</x-layouts.crm>