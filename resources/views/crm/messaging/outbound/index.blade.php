@if($embedded)
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <title>Outbound messages</title>
    </head>
    <body class="bg-slate-50 p-3 sm:p-5">
        @include('crm.messaging.outbound.workspace')
    </body>
    </html>
@else
    <x-layouts.crm
        title="Outbound messages"
        heading="Outbound messages"
        subheading="See when messages will send, review recent results, and adjust messages that have not sent."
        module="messaging"
    >
        @include('crm.messaging.outbound.workspace')
    </x-layouts.crm>
@endif