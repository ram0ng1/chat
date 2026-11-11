import type Channel from '../../common/models/Channel';
/**
 * Horário de funcionamento no cliente: fusos para o formulário, o texto do aviso
 * e a atualização quando o canal abre ou fecha.
 *
 * Os fusos usam só `Intl`, sem plugin de timezone do dayjs: o core não traz um.
 */
/** O fuso do aparelho, ponto de partida do formulário. */
export declare function browserTimeZone(): string;
/** Fusos que o navegador conhece; o do aparelho entra mesmo se a lista faltar. */
export declare function timeZones(): string[];
/**
 * "2026-11-15T19:00" no relógio do fuso → instante. Duas passadas: o desvio pode
 * mudar entre o palpite e o resultado num dia de horário de verão.
 */
export declare function zonedToInstant(local: string, zone: string): Date | null;
/** Instante → "2026-11-15T19:00" no relógio do fuso, para o campo datetime-local. */
export declare function instantToZoned(iso: string, zone: string): string;
/** "em 3 horas · sex., 15 de nov. 19:00", no idioma e no fuso de quem lê. */
export declare function describeChange(date: Date): string;
/**
 * Aviso de quem lê fora do horário: quando abre, ou por que não abre.
 */
export declare function closedNotice(channel: Channel): string;
/**
 * Busca o canal de novo quando o horário vira, para o composer abrir ou fechar
 * sem recarregar a página. Idempotente: chamado a cada render, só agenda uma vez
 * por mudança. O servidor continua decidindo; isto só atualiza a tela.
 */
export declare function watchSchedule(channel: Channel): void;
