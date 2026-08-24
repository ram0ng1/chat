<x-mail::plain.notification>
<x-slot:body>
{{--
    Substitution is done here rather than by the translator, for the reason set
    out in the HTML view: core gives email views a MailTranslator that swaps each
    parameter for a marker, restored by the MailFormatter only for a body that
    goes through `$formatter->convert()`. This one does not, so the markers
    reached the inbox verbatim.

    The restoring half would be wrong here even if it ran: it escapes for HTML,
    and core's plain layout escapes the slot again on the way out, so a name
    holding `<` would reach the reader as the literal text `&lt;`.

    Values are handed over raw for the same reason. Neutralising them is the
    layout's job and it already does it — `x-mail::plain` runs the slot through
    `strip_tags()` before Blade's braces escape what is left, so markup arrives
    as its text alone. Escaping here as well would only add the entities that
    layout is there to avoid.
--}}
{!! strtr($translator->trans('ramon-chat.email.mentioned.plain.body'), [
'{author}'  => $blueprint->message->user?->display_name ?? $translator->trans('ramon-chat.email.mentioned.unknown_author'),
'{channel}' => $blueprint->message->channel?->name ?? $translator->trans('ramon-chat.email.mentioned.direct_channel'),
'{content}' => strip_tags($blueprint->message->formatContent()),
'{url}'     => $url->to('forum')->route('chat.channel', ['id' => $blueprint->message->channel_id]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
