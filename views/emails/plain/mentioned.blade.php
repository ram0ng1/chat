<x-mail::plain.notification>
<x-slot:body>
{{--
    Substitution is done here rather than by the translator, for the reason set
    out in the HTML view: core gives email views a MailTranslator that swaps each
    parameter for a marker, restored by the MailFormatter only for a body that
    goes through `$formatter->convert()`. This one does not, so the markers
    reached the inbox verbatim.

    The restoring half would be wrong here even if it ran — it escapes for HTML,
    and this is the plain-text alternative, where `&lt;` is the literal text the
    reader sees. Nothing is escaped in this template, and nothing should be.
--}}
{!! strtr($translator->trans('ramon-chat.email.mentioned.plain.body'), [
'{author}'  => $blueprint->message->user?->display_name ?? $translator->trans('ramon-chat.email.mentioned.unknown_author'),
'{channel}' => $blueprint->message->channel?->name ?? $translator->trans('ramon-chat.email.mentioned.direct_channel'),
'{content}' => strip_tags($blueprint->message->formatContent()),
'{url}'     => $url->to('forum')->route('chat.channel', ['id' => $blueprint->message->channel_id]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
