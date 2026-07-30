<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ramon-chat.email.mentioned.html.body', [
            '{author}'  => $blueprint->message->user?->display_name ?? $translator->trans('ramon-chat.email.mentioned.unknown_author'),
            '{channel}' => $blueprint->message->channel?->name ?? $translator->trans('ramon-chat.email.mentioned.direct_channel'),
            '{url}'     => $url->to('forum')->route('chat.channel', ['id' => $blueprint->message->channel_id]),
        ])) !!}
    </x-slot:body>

    <x-slot:preview>
        {!! $blueprint->message->formatContent() !!}
    </x-slot:preview>
</x-mail::html.notification>
