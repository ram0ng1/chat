@php
    $author = $blueprint->message->user?->display_name
        ?? $translator->trans('ramon-chat.email.mentioned.unknown_author');
    $channel = $blueprint->message->channel?->name
        ?? $translator->trans('ramon-chat.email.mentioned.direct_channel');
    $channelUrl = $url->to('forum')->route('chat.channel', ['id' => $blueprint->message->channel_id]);
@endphp

<x-mail::html.notification>
    <x-slot:body>
        {{--
            The locale string carries the markup and is interpolated raw; the two
            values substituted into it are escaped first, because a display name is
            user-controlled and this is an HTML context — CVE-2026-30913 is the same
            shape. The post formatter is deliberately not involved: it is the
            pipeline for user content, and pointing it at our own template made the
            output depend on which formatting extensions the admin had enabled.
        --}}
        <p>{!! $translator->trans('ramon-chat.email.mentioned.html.intro', [
            '{author}'  => e($author),
            '{channel}' => e($channel),
        ]) !!}</p>

        <p><a href="{{ $channelUrl }}">{{ $translator->trans('ramon-chat.email.mentioned.html.action') }}</a></p>
    </x-slot:body>

    <x-slot:preview>
        {!! $blueprint->message->formatContent() !!}
    </x-slot:preview>
</x-mail::html.notification>
