import app from 'flarum/forum/app';

import type Channel from '../../common/models/Channel';

/**
 * Horário de funcionamento no cliente: fusos para o formulário, o texto do aviso
 * e a atualização quando o canal abre ou fecha.
 *
 * Os fusos usam só `Intl`, sem plugin de timezone do dayjs: o core não traz um.
 */

/** O fuso do aparelho, ponto de partida do formulário. */
export function browserTimeZone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  } catch {
    return 'UTC';
  }
}

/** Fusos que o navegador conhece; o do aparelho entra mesmo se a lista faltar. */
export function timeZones(): string[] {
  let zones: string[] = [];

  try {
    zones = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone') ?? [];
  } catch {
    zones = [];
  }

  const list = new Set<string>(zones);
  list.add(browserTimeZone());
  list.add('UTC');

  return [...list].sort();
}

/** Partes de data e hora de um instante, no relógio do fuso. */
function partsIn(date: Date, zone: string): Record<string, number> {
  const formatter = new Intl.DateTimeFormat('en-US', {
    timeZone: zone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });

  const parts: Record<string, number> = {};

  for (const part of formatter.formatToParts(date)) {
    if (part.type !== 'literal') parts[part.type] = Number(part.value);
  }

  return parts;
}

/** Quanto o fuso está à frente de UTC nesse instante, em milissegundos. */
function offsetAt(date: Date, zone: string): number {
  const p = partsIn(date, zone);

  return Date.UTC(p.year, p.month - 1, p.day, p.hour % 24, p.minute, p.second) - date.getTime();
}

/**
 * "2026-11-15T19:00" no relógio do fuso → instante. Duas passadas: o desvio pode
 * mudar entre o palpite e o resultado num dia de horário de verão.
 */
export function zonedToInstant(local: string, zone: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(local);

  if (!match) return null;

  const [, y, mo, d, h, mi] = match.map(Number);
  const guess = Date.UTC(y, mo - 1, d, h, mi);

  let instant = guess - offsetAt(new Date(guess), zone);
  instant = guess - offsetAt(new Date(instant), zone);

  return new Date(instant);
}

/** Instante → "2026-11-15T19:00" no relógio do fuso, para o campo datetime-local. */
export function instantToZoned(iso: string, zone: string): string {
  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '';

  const p = partsIn(date, zone);
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${p.year}-${pad(p.month)}-${pad(p.day)}T${pad(p.hour % 24)}:${pad(p.minute)}`;
}

/** "em 3 horas · sex., 15 de nov. 19:00", no idioma e no fuso de quem lê. */
export function describeChange(date: Date): string {
  return `${dayjs(date).fromNow()} · ${dayjs(date).format('llll')}`;
}

/**
 * Aviso de quem lê fora do horário: quando abre, ou por que não abre.
 */
export function closedNotice(channel: Channel): string {
  if (channel.scheduleUnavailable()) {
    return app.translator.trans('ramon-chat.forum.schedule.closed_unavailable', {}, true) as string;
  }

  const next = channel.scheduleNextChange();

  return next
    ? (app.translator.trans('ramon-chat.forum.schedule.closed_until', { when: describeChange(next) }, true) as string)
    : (app.translator.trans('ramon-chat.forum.schedule.closed_ended', {}, true) as string);
}

/** Maior espera que o setTimeout aceita sem estourar (~24,8 dias). */
const MAX_TIMEOUT = 2 ** 31 - 1;

const timers = new Map<string, { at: number; timer: number }>();

/**
 * Busca o canal de novo quando o horário vira, para o composer abrir ou fechar
 * sem recarregar a página. Idempotente: chamado a cada render, só agenda uma vez
 * por mudança. O servidor continua decidindo; isto só atualiza a tela.
 */
export function watchSchedule(channel: Channel): void {
  const id = channel.id();
  const next = channel.scheduleNextChange();

  if (!id || !next) return;

  const at = next.getTime();
  const current = timers.get(id);

  if (current?.at === at) return;

  if (current) window.clearTimeout(current.timer);

  // Um segundo de folga para o relógio do servidor já ter virado.
  const wait = Math.min(Math.max(at - Date.now() + 1000, 1000), MAX_TIMEOUT);

  const timer = window.setTimeout(() => {
    timers.delete(id);

    app.store
      .find('chat-channels', id)
      .then(() => m.redraw())
      .catch(() => {});
  }, wait);

  timers.set(id, { at, timer });
}
