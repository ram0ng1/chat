import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Upload from '../../common/models/Upload';
import type Channel from '../../common/models/Channel';
import type ChatState from '../state/ChatState';
import type { Suggestion } from './ChatAutocomplete';
export interface ChatComposerAttrs extends ComponentAttrs {
    channel: Channel;
    state: ChatState;
    threadId?: number | null;
    /** Chamado após enviar, para o stream rolar até o fim. */
    onSent?: () => void;
}
/**
 * Campo de mensagem: cresce até um limite, guarda rascunho, limita os avisos de
 * digitação e envia com Enter (Shift+Enter quebra linha).
 */
export default class ChatComposer extends Component<ChatComposerAttrs> {
    private textarea;
    private joining;
    private unarchiving;
    private sending;
    /** Segundos até o canal aceitar outra mensagem deste usuário. */
    private cooldown;
    private cooldownTimer;
    /** Último `slowModeRemaining` visto, para distinguir mudança de redraw. */
    private lastServerCooldown;
    /** Último `slowModeSeconds` visto. -1 para a primeira sincronização sempre rodar. */
    private lastSlowModeWindow;
    private uploading;
    /** Alvo de resposta/edição para o qual o cursor foi movido por último. */
    private focusedContext;
    private suggestions;
    private activeSuggestion;
    /** Trecho `@foo` / `:smi` sendo completado, como [início, fim) no valor. */
    private trigger;
    private mentionTimer;
    /** Descarta uma busca de usuários antiga que chegou fora de ordem. */
    private mentionSequence;
    onremove(): void;
    oninit(vnode: Mithril.Vnode<ChatComposerAttrs>): void;
    /**
     * Alinha a contagem local com o modo lento do canal.
     *
     * Ligar: adota `slowModeRemaining`, mas só quando muda; é um retrato da última
     * leitura, e reler a cada draw reiniciaria a contagem sem nunca chegar a zero.
     *
     * Desligar ou reduzir: libera ou limita a espera ao novo intervalo. Limita em vez
     * de recalcular porque o broadcast chega antes do refetch; nunca fica mais
     * permissivo que o servidor, e o próximo envio ressincroniza.
     */
    protected syncSlowMode(): void;
    oncreate(vnode: Mithril.VnodeDOM<ChatComposerAttrs>): void;
    onupdate(vnode: Mithril.VnodeDOM<ChatComposerAttrs>): void;
    /**
     * Foca o campo quando uma resposta ou edição é preparada. Só quando o alvo muda:
     * o `onupdate` roda a cada redraw, e focar sempre roubaria o cursor ou abriria o
     * teclado do celular.
     */
    protected focusOnNewContext(): void;
    view(): Mithril.Children;
    /**
     * Substitui o composer durante a espera do modo lento. Ampulheta, não cadeado:
     * nada está bloqueado, a espera acaba sozinha.
     */
    protected slowed(): Mithril.Children;
    /**
     * Por que não dá para escrever aqui. Motivos do mais específico ao mais geral,
     * para um canal de avisos aberto não aparecer como "fechado".
     */
    protected frozen(channel: Channel): Mithril.Children;
    /**
     * Quem modera escreve fora do horário; a linha lembra que os membros não podem.
     */
    protected offHoursHint(channel: Channel): Mithril.Children;
    /**
     * Aviso de arquivado: onde ficou a transcrição e, para quem pode, como desarquivar.
     */
    protected archivedNotice(channel: Channel): Mithril.Children;
    /**
     * Desarquiva o canal. Ele volta fechado, então o composer só aparece ao reabrir.
     */
    protected unarchive(channel: Channel): Promise<void>;
    /**
     * Entra no canal para o composer aparecer. A resposta traz o canal, e
     * `canPostMessage` vem do servidor.
     */
    protected join(channel: Channel): Promise<void>;
    /**
     * Responde ao convite da barra. Aceitar devolve o canal com `canPostMessage`, e
     * o composer assume no próximo draw.
     */
    protected answerInvitation(channel: Channel, accept: boolean): Promise<void>;
    /**
     * Alvo de resposta/edição deste composer. Canal e thread têm composers sobre o
     * mesmo state, então o contexto é buscado pelo escopo.
     */
    protected replyingTo(): import("..").Message | null;
    /** Se a resposta preparada veio de "responder em thread". */
    protected branching(): boolean;
    protected editing(): import("..").Message | null;
    protected placeholder(channel: Channel): string;
    /** Tooltip do botão de envio, com a tecla que envia. */
    protected sendHint(): string;
    /** Faixa de contexto de resposta/edição acima do campo. */
    protected contextBar(): Mithril.Children;
    protected pendingAttachments(): Mithril.Children;
    protected onInput(e: Event): void;
    /**
     * Procura um trecho `@nome` ou `:emoji` logo antes do cursor. Exige início de
     * palavra, para e-mail ou `http://` não abrirem a lista.
     */
    protected detectTrigger(el: HTMLTextAreaElement): void;
    protected suggestUsers(term: string): void;
    protected applySuggestion(suggestion: Suggestion): void;
    protected closeAutocomplete(): void;
    /** Se a lista está aberta e captura setas, Enter e Escape. */
    protected autocompleteOpen(): boolean;
    protected onKeyDown(e: KeyboardEvent): void;
    /** Cresce o textarea com o conteúdo, até o max-height do CSS. */
    protected resize(): void;
    protected startEditing(message: any): void;
    protected cancelContext(): void;
    /**
     * Abre ou fecha o seletor de figurinhas. O shortcode entra no cursor como texto
     * comum; o formatter o converte no envio.
     */
    protected toggleStickers(e: Event): void;
    /**
     * Insere texto no cursor. Atualiza o textarea (posição do cursor) e o rascunho
     * (o que é enviado).
     */
    protected insertAtCursor(text: string): void;
    /**
     * Inicia ou renova a contagem do modo lento, num intervalo de um segundo: nada
     * mais redesenharia durante a espera. Só espelha o que o servidor informou.
     */
    protected startCooldown(seconds: number): void;
    protected stopCooldown(): void;
    protected submit(): Promise<void>;
    protected pendingUploads(): Upload[];
    protected pickFiles(): void;
    protected onFilesPicked(e: Event): Promise<void>;
    protected removeUpload(id: number): void;
}
