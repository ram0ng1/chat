import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import type { ChannelScheduleData } from '../../common/models/Channel';
type Mode = 'always' | 'window' | 'weekly' | 'event';
/** Se o ernestdefoe/calendar está ligado neste fórum. */
export declare function calendarAvailable(): boolean;
/**
 * O que o formulário edita, em streams. Converte do formato guardado e de volta.
 * As horas da janela ficam no relógio do fuso escolhido, como num datetime-local.
 */
export declare class ScheduleDraft {
    mode: Stream<Mode>;
    timezone: Stream<string>;
    windowStart: Stream<string>;
    windowEnd: Stream<string>;
    days: Stream<number[]>;
    from: Stream<string>;
    to: Stream<string>;
    eventId: Stream<number | null>;
    eventTitle: Stream<string | null>;
    constructor(saved: ChannelScheduleData | null, eventTitle: string | null);
    /**
     * O que vai no save. Campos incompletos vão como estão: o servidor valida e o
     * modal mostra a mensagem dele.
     */
    toPayload(): Record<string, unknown> | null;
}
export interface ScheduleFieldsAttrs extends ComponentAttrs {
    draft: ScheduleDraft;
    disabled?: boolean;
    /** O evento ligado sumiu: o canal está fechado até trocarem o horário. */
    unavailable?: boolean;
}
/**
 * Campos do horário de funcionamento no formulário do canal: sempre aberto, uma
 * janela, dias da semana ou um evento do calendário (só com o ernestdefoe/calendar).
 */
export default class ScheduleFields extends Component<ScheduleFieldsAttrs> {
    private events;
    private searching;
    private loaded;
    private query;
    private timer;
    private sequence;
    oninit(vnode: Mithril.Vnode<ScheduleFieldsAttrs, this>): void;
    onremove(vnode: Mithril.VnodeDOM<ScheduleFieldsAttrs, this>): void;
    view(): Mithril.Children;
    protected chooseMode(mode: Mode): void;
    protected windowFields(): Mithril.Children;
    protected weeklyFields(): Mithril.Children;
    protected timezoneField(): Mithril.Children;
    /** Mantém as horas escritas e muda só o fuso, como quem corrige o fuso. */
    protected changeZone(zone: string): void;
    protected eventFields(): Mithril.Children;
    protected search(value: string): void;
}
export {};
