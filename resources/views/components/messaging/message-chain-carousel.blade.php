@props([
    'presentation' => [],
    'editable' => false,
    'emptyMessage' => 'No messages are configured for this sequence.',
    'initialMessageId' => null,
    'formContext' => [],
])

<x-messaging.message-editor-carousel
    :presentation="$presentation"
    :editable="$editable"
    :empty-message="$emptyMessage"
    :initial-message-id="$initialMessageId"
    :form-context="$formContext"
/>