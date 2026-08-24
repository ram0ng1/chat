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

            Substitution is done here rather than by the translator, and that is
            load bearing. Core gives email views a MailTranslator that replaces
            every parameter with an opaque marker, to be put back — escaped — by
            the MailFormatter afterwards. That pairing only completes for a body
            rendered through `$formatter->convert()`, and this one deliberately is
            not: the markers had nothing to restore them and reached the inbox as
            `flarumsafevalue…` in place of the author's name.

            Passing no parameters leaves the placeholders in the string for
            `strtr` to fill, which keeps this template's own escaping as the whole
            of its defence — the same guarantee it always had, and one that does
            not depend on how core happens to hand values to a translator.
        --}}
        <p>{!! strtr($translator->trans('ramon-chat.email.mentioned.html.intro'), [
            '{author}'  => e($author),
            '{channel}' => e($channel),
        ]) !!}</p>

        <p><a href="{{ $channelUrl }}">{{ $translator->trans('ramon-chat.email.mentioned.html.action') }}</a></p>
    </x-slot:body>

    {{-- `formatContent()` is s9e/TextFormatter output, the same pipeline core
         renders a post body with — and core's own notification.blade.php emits
         it unescaped for exactly this reason. Escaping it here would mail the
         markup as visible text. Every other value in this template goes through
         `e()` or the escaping braces. --}}
    <x-slot:preview>
        {!! $blueprint->message->formatContent() !!}
    </x-slot:preview>
</x-mail::html.notification>
