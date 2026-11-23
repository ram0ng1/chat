import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import classList from 'flarum/common/utils/classList';
import withAttr from 'flarum/common/utils/withAttr';
import type Mithril from 'mithril';

import type { ChannelScheduleData } from '../../common/models/Channel';
import { browserTimeZone, instantToZoned, timeZones, zonedToInstant } from '../utils/schedule';

type Mode = 'always' | 'window' | 'weekly' | 'event';

/** Evento como o ListCalendarEventsController devolve. */
interface CalendarEvent {
  id: number;
  title: string;
  startAt: string;
  endAt: string | null;
  allDay: boolean;
  recurring: boolean;
}

const trans = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ramon-chat.forum.schedule.${key}`, params);
const text = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ramon-chat.forum.schedule.${key}`, params, true) as string;

/** Se o ernestdefoe/calendar está ligado neste fórum. */
export function calendarAvailable(): boolean {
  return 'ernestdefoe-calendar' in flarum.extensions;
}

/**
 * O que o formulário edita, em streams. Converte do formato guardado e de volta.
 * As horas da janela ficam no relógio do fuso escolhido, como num datetime-local.
 */
export class ScheduleDraft {
  mode: Stream<Mode>;
  timezone: Stream<string>;
  windowStart: Stream<string>;
  windowEnd: Stream<string>;
  days: Stream<number[]>;
  from: Stream<string>;
  to: Stream<string>;
  eventId: Stream<number | null>;
  eventTitle: Stream<string | null>;

  constructor(saved: ChannelScheduleData | null, eventTitle: string | null) {
    const zone = saved && 'timezone' in saved ? saved.timezone : browserTimeZone();

    // Janela padrão: da próxima hora cheia, por duas horas.
    const start = new Date();
    start.setMinutes(0, 0, 0);
    start.setHours(start.getHours() + 1);
    const end = new Date(start.getTime() + 2 * 3600 * 1000);

    this.mode = Stream<Mode>(saved?.mode ?? 'always');
    this.timezone = Stream(zone);
    this.windowStart = Stream(instantToZoned(saved?.mode === 'window' ? saved.start : start.toISOString(), zone));
    this.windowEnd = Stream(instantToZoned(saved?.mode === 'window' ? saved.end : end.toISOString(), zone));
    this.days = Stream(saved?.mode === 'weekly' ? saved.days : [1, 2, 3, 4, 5]);
    this.from = Stream(saved?.mode === 'weekly' ? saved.from : '09:00');
    this.to = Stream(saved?.mode === 'weekly' ? saved.to : '18:00');
    this.eventId = Stream<number | null>(saved?.mode === 'event' ? saved.eventId : null);
    this.eventTitle = Stream<string | null>(eventTitle);
  }

  /**
   * O que vai no save. Campos incompletos vão como estão: o servidor valida e o
   * modal mostra a mensagem dele.
   */
  toPayload(): Record<string, unknown> | null {
    const zone = this.timezone();

    switch (this.mode()) {
      case 'window':
        return {
          mode: 'window',
          start: zonedToInstant(this.windowStart(), zone)?.toISOString() ?? this.windowStart(),
          end: zonedToInstant(this.windowEnd(), zone)?.toISOString() ?? this.windowEnd(),
          timezone: zone,
        };
      case 'weekly':
        return { mode: 'weekly', days: this.days(), from: this.from(), to: this.to(), timezone: zone };
      case 'event':
        return { mode: 'event', eventId: this.eventId() };
      default:
        return null;
    }
  }
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
  private events: CalendarEvent[] = [];
  private searching = false;
  private loaded = false;
  private query = '';
  private timer: number | null = null;
  private sequence = 0;

  oninit(vnode: Mithril.Vnode<ScheduleFieldsAttrs, this>): void {
    super.oninit(vnode);

    if (this.attrs.draft.mode() === 'event') this.search('');
  }

  onremove(vnode: Mithril.VnodeDOM<ScheduleFieldsAttrs, this>): void {
    super.onremove(vnode);

    if (this.timer !== null) window.clearTimeout(this.timer);
  }

  view(): Mithril.Children {
    const { draft, disabled } = this.attrs;
    const mode = draft.mode();
    const modes: Mode[] = ['always', 'window', 'weekly'];

    // Já ligado a um evento, a opção fica mesmo sem o calendário, para o aviso fazer sentido.
    if (calendarAvailable() || mode === 'event') modes.push('event');

    return (
      <div className="ChatSchedule">
        <div className="Form-group">
          <label>{trans('mode_label')}</label>

          <select className="FormControl" value={mode} disabled={disabled} onchange={withAttr('value', (value: Mode) => this.chooseMode(value))}>
            {/* Nada de `m` como nome: o JSX compila para a função global `m` do Mithril. */}
            {modes.map((option) => (
              <option key={option} value={option}>
                {text(`mode_${option}`)}
              </option>
            ))}
          </select>

          <p className="helpText">{trans(`mode_${mode}_help`)}</p>
        </div>

        {this.attrs.unavailable && mode === 'event' ? (
          <p className="ChatSchedule-warning">
            <i className="fas fa-triangle-exclamation" aria-hidden="true" /> {trans('event_unavailable')}
          </p>
        ) : null}

        {mode === 'window' ? this.windowFields() : null}
        {mode === 'weekly' ? this.weeklyFields() : null}
        {mode === 'event' ? this.eventFields() : null}
        {mode === 'window' || mode === 'weekly' ? this.timezoneField() : null}
      </div>
    );
  }

  protected chooseMode(mode: Mode): void {
    this.attrs.draft.mode(mode);

    if (mode === 'event' && !this.loaded) this.search('');
  }

  protected windowFields(): Mithril.Children {
    const { draft, disabled } = this.attrs;

    return (
      <div className="ChatSchedule-row">
        <div className="Form-group">
          <label>{trans('window_start')}</label>
          <input
            className="FormControl"
            type="datetime-local"
            value={draft.windowStart()}
            disabled={disabled}
            oninput={withAttr('value', draft.windowStart)}
          />
        </div>

        <div className="Form-group">
          <label>{trans('window_end')}</label>
          <input
            className="FormControl"
            type="datetime-local"
            value={draft.windowEnd()}
            disabled={disabled}
            oninput={withAttr('value', draft.windowEnd)}
          />
        </div>
      </div>
    );
  }

  protected weeklyFields(): Mithril.Children {
    const { draft, disabled } = this.attrs;
    const selected = draft.days();

    return (
      <>
        <div className="Form-group">
          <label>{trans('weekly_days')}</label>

          <div className="ChatSchedule-days" role="group">
            {[1, 2, 3, 4, 5, 6, 7].map((day) => {
              const on = selected.includes(day);

              return (
                <button
                  type="button"
                  key={day}
                  className={classList('ChatSchedule-day', { 'ChatSchedule-day--on': on })}
                  aria-pressed={on}
                  disabled={disabled}
                  onclick={() => draft.days(on ? selected.filter((d: number) => d !== day) : [...selected, day].sort())}
                >
                  {/* ISO: 1 = segunda … 7 = domingo; o dayjs conta domingo como 0. */}
                  {dayjs()
                    .day(day % 7)
                    .format('ddd')}
                </button>
              );
            })}
          </div>
        </div>

        <div className="ChatSchedule-row">
          <div className="Form-group">
            <label>{trans('weekly_from')}</label>
            <input className="FormControl" type="time" value={draft.from()} disabled={disabled} oninput={withAttr('value', draft.from)} />
          </div>

          <div className="Form-group">
            <label>{trans('weekly_to')}</label>
            <input className="FormControl" type="time" value={draft.to()} disabled={disabled} oninput={withAttr('value', draft.to)} />
          </div>
        </div>

        {draft.to() && draft.from() && draft.to() < draft.from() ? <p className="helpText">{trans('weekly_overnight')}</p> : null}
      </>
    );
  }

  protected timezoneField(): Mithril.Children {
    const { draft, disabled } = this.attrs;

    return (
      <div className="Form-group">
        <label>{trans('timezone')}</label>

        <select
          className="FormControl"
          value={draft.timezone()}
          disabled={disabled}
          onchange={withAttr('value', (zone: string) => this.changeZone(zone))}
        >
          {timeZones().map((zone) => (
            <option key={zone} value={zone}>
              {zone.replace(/_/g, ' ')}
            </option>
          ))}
        </select>

        <p className="helpText">{trans('timezone_help')}</p>
      </div>
    );
  }

  /** Mantém as horas escritas e muda só o fuso, como quem corrige o fuso. */
  protected changeZone(zone: string): void {
    this.attrs.draft.timezone(zone);
  }

  protected eventFields(): Mithril.Children {
    const { draft, disabled } = this.attrs;
    const chosen = draft.eventId();

    return (
      <div className="Form-group">
        <label>{trans('event_label')}</label>

        {chosen ? (
          <div className="ChatSchedule-chosen">
            <i className="fas fa-calendar-days" aria-hidden="true" />
            <span>{draft.eventTitle() ?? `#${chosen}`}</span>
          </div>
        ) : null}

        <input
          className="FormControl"
          type="search"
          placeholder={text('event_search')}
          value={this.query}
          disabled={disabled}
          oninput={withAttr('value', (value: string) => this.search(value))}
        />

        <div className="ChatSchedule-events">
          {this.searching ? (
            <LoadingIndicator display="inline" size="small" />
          ) : this.events.length === 0 ? (
            <p className="helpText">{trans(this.loaded ? 'event_none' : 'event_hint')}</p>
          ) : (
            this.events.map((event) => (
              <button
                type="button"
                key={event.id}
                className={classList('ChatSchedule-event', { 'ChatSchedule-event--on': event.id === chosen })}
                disabled={disabled}
                onclick={() => {
                  draft.eventId(event.id);
                  draft.eventTitle(event.title);
                }}
              >
                <span className="ChatSchedule-event-title">{event.title}</span>
                <span className="ChatSchedule-event-when">
                  {dayjs(event.startAt).format(event.allDay ? 'll' : 'llll')}
                  {event.recurring ? ` · ${text('event_recurring')}` : ''}
                </span>
              </button>
            ))
          )}
        </div>
      </div>
    );
  }

  protected search(value: string): void {
    this.query = value;

    if (this.timer !== null) window.clearTimeout(this.timer);

    const mine = ++this.sequence;
    this.searching = true;

    this.timer = window.setTimeout(() => {
      app
        .request<{ data: CalendarEvent[] }>({
          method: 'GET',
          url: `${app.forum.attribute('apiUrl')}/chat/calendar-events`,
          params: { q: value.trim() },
        })
        .then((payload) => {
          if (mine !== this.sequence) return;

          this.events = payload?.data ?? [];
        })
        .catch(() => {
          if (mine !== this.sequence) return;

          this.events = [];
        })
        .finally(() => {
          if (mine !== this.sequence) return;

          this.searching = false;
          this.loaded = true;
          m.redraw();
        });
    }, 250);
  }
}
