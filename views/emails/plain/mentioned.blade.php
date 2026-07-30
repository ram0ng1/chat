<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ramon-chat.email.mentioned.plain.body', [
'{author}'  => $blueprint->message->user?->display_name ?? $translator->trans('ramon-chat.email.mentioned.unknown_author'),
'{channel}' => $blueprint->message->channel?->name ?? $translator->trans('ramon-chat.email.mentioned.direct_channel'),
'{content}' => strip_tags($blueprint->message->formatContent()),
'{url}'     => $url->to('forum')->route('chat.channel', ['id' => $blueprint->message->channel_id]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
