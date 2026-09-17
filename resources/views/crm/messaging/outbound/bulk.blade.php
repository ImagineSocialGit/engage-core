@if($embedded)
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <title>Bulk message edit</title>
    </head>
    <body class="bg-slate-50 p-3 sm:p-5">
        @include('crm.messaging.outbound.bulk-form')
    </body>
    </html>
@else
    <x-layouts.crm title="Bulk message edit" heading="Bulk message edit" module="messaging">
        @include('crm.messaging.outbound.bulk-form')
    </x-layouts.crm>
@endif