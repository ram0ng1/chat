import FormModal from 'flarum/common/components/FormModal';
import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
export interface ChannelFormModalAttrs extends IFormModalAttrs {
    /** Sem canal, cria; com canal, edita. */
    channel?: Channel;
    /** Recebe o canal salvo. */
    onSaved?: (channel: Channel) => void;
}
/**
 * Cria ou edita um canal de categoria; os campos são os mesmos nos dois casos.
 *
 * Estende FormModal, não Modal: `Modal.wrapper()` devolve um fragment sem form, e
 * o onsubmit nunca dispararia.
 */
export default class ChannelFormModal extends FormModal<ChannelFormModalAttrs> {
    private name;
    private description;
    private emoji;
    private uploadingImage;
    /**
     * Ação imediata em andamento, ou null. `loading` (do core) desabilita o form
     * inteiro; isto decide qual botão mostra o spinner.
     */
    private pending;
    /**
     * Imagem escolhida ao criar, guardada até o canal existir: a rota de upload
     * precisa do id do canal.
     */
    private pendingImage;
    /**
     * URL de `URL.createObjectURL` da prévia de {@link pendingImage}. Precisa ser
     * revogada, senão o blob vive até o documento fechar.
     */
    private pendingImageUrl;
    /**
     * Qual dos dois campos de ícone está em uso. O canal tem um só (`channelIcon`
     * prefere a imagem); {@link onsubmit} limpa o outro campo.
     */
    private useImage;
    private tagId;
    private threading;
    private slowMode;
    private schedule;
    /** String, como `slowMode`, porque alimenta um <select>. "" segue o fórum. */
    private maxMessageLength;
    /** Se o campo numérico próprio aparece no lugar das opções. */
    private customLength;
    private autoJoin;
    private allowChannelWide;
    private autoJoinOnReply;
    private isPrivate;
    private postPermission;
    private postDiscussions;
    oninit(vnode: Mithril.Vnode<ChannelFormModalAttrs>): void;
    /**
     * Libera a object URL da imagem pendente; sem isso o blob sobreviveria ao
     * componente.
     */
    onremove(vnode: Mithril.VnodeDOM<ChannelFormModalAttrs, this>): void;
    protected isEditing(): boolean;
    className(): string;
    title(): Mithril.Children;
    /**
     * Troca o cabeçalho + corpo do core por cabeçalho / corpo / rodapé. As duas
     * colunas, os grupos e o botão fixo dependem deste wrapper.
     */
    protected inner(): Mithril.Children;
    /** Ícone, título e uma linha de orientação. O ícone reflete a escolha atual. */
    protected header(): Mithril.Children;
    content(): Mithril.Children;
    /** Nome, descrição e ícone do canal. */
    protected identitySection(): Mithril.Children;
    /**
     * Ícone do canal: emoji ou imagem. Um só espaço, com o campo escolhido no switch.
     */
    protected iconField(): Mithril.Children;
    /** Metade do switch. `span`, porque o botão é o controle inteiro. */
    protected iconKindSide(picture: boolean, icon: string): Mithril.Children;
    /**
     * Alterna entre os campos de ícone. Desligar a imagem descarta o arquivo
     * pendente; imagem já no servidor só sai no envio ({@link onsubmit}), para
     * a mudança poder ser cancelada.
     */
    protected chooseIconKind(picture: boolean): void;
    /** Quem vê, quem escreve e o que o canal herda. */
    protected accessSection(): Mithril.Children;
    /** Comportamento do canal. */
    protected behaviourSection(): Mithril.Children;
    /**
     * Horário de funcionamento: fora dele, só a moderação escreve. Ocupa as duas
     * colunas, como a identidade, porque os campos mudam com o modo.
     */
    protected scheduleSection(): Mithril.Children;
    /** Grupo de campos com título; some quando não há nada a mostrar. */
    protected section(titleKey: string, children: Mithril.Children[], className?: string): Mithril.Children;
    /**
     * Linha de switch: controle, label e ajuda. `Switch` em vez de checkbox para a
     * ajuda ficar sob o label sem recuo fixo.
     */
    protected toggle(stream: Stream<boolean>, labelKey: string, help?: Mithril.Children): Mithril.Children;
    /** Linha de envio, fixa abaixo do corpo rolável. */
    protected footer(): Mithril.Children;
    /**
     * Imagem do canal no lugar do emoji. Na edição o upload é imediato; na criação
     * o arquivo fica em {@link pendingImage} até o canal existir
     * ({@link attachPendingImage}). O label vem de {@link iconField}.
     */
    protected image(): Mithril.Children;
    /** Imagem do canal: pendente na criação, salva na edição. */
    protected iconImageUrl(): string | null;
    /**
     * Envia o arquivo direto se o canal existe; senão guarda em {@link pendingImage}.
     */
    protected chooseImage(e: Event): void;
    /** Remove a imagem: DELETE se salva, descarte local se pendente. */
    protected removeImage(): void;
    private discardPendingImage;
    /**
     * Envia a imagem pendente ao canal recém-criado. Uma falha aqui não desfaz o
     * canal; o usuário é avisado e pode enviar pelas configurações.
     */
    private attachPendingImage;
    protected uploadImage(e: Event): Promise<void>;
    protected clearImage(): Promise<void>;
    protected visibility(): Mithril.Children;
    /** Card de visibilidade. Por baixo é um radio, com a semântica de grupo. */
    protected choice(value: boolean, icon: string, labelKey: string, helpKey: string): Mithril.Children;
    /**
     * Quem pode escrever, independente de quem pode ver.
     */
    protected posting(): Mithril.Children;
    /**
     * Fechar e arquivar, só em canal existente. Agem na hora, não no envio: são
     * mudanças de estado, não configurações.
     */
    protected lifecycle(): Mithril.Children;
    /**
     * Exclui o canal e fecha o diálogo. Não usa `act()`, que passa o canal salvo ao
     * `onSaved`; aqui o registro já não existe.
     */
    protected destroy(): Promise<void>;
    protected setStatus(status: 'open' | 'closed'): Promise<void>;
    protected archive(): Promise<void>;
    protected unarchive(): Promise<void>;
    /**
     * Executa uma mudança de estado imediata. `action` diz qual botão mostra o
     * spinner; `loading` bloqueia o resto do form.
     */
    protected act(action: 'status' | 'archive' | 'unarchive', path: string, attributes: Record<string, unknown>): Promise<void>;
    /**
     * Seletor de categoria, só com flarum/tags. O canal herda as permissões da tag.
     */
    protected tagOptions(): Mithril.Children;
    /**
     * Escolhe ou limpa a categoria. Limpar desliga "anunciar novas discussões", que
     * só aparece com categoria, para não enviar um valor invisível.
     */
    protected chooseTag(id: string): void;
    /** Categoria escolhida no seletor, se houver. */
    protected selectedTag(): any | null;
    /**
     * Modo lento: espera entre mensagens de cada pessoa. Lista fixa, não campo livre.
     * A ajuda diz que moderadores são isentos, só quando há espera definida.
     */
    protected slowModeOptions(): Mithril.Children;
    /**
     * Tamanho máximo da mensagem: opções prontas mais "personalizado". A primeira
     * opção segue a configuração do fórum.
     */
    protected messageLengthOptions(): Mithril.Children;
    /** Frase sob o campo, sobre a opção selecionada. */
    protected messageLengthHelp(forumDefault: number): Mithril.Children;
    /** Alterna entre opção pronta, padrão do fórum e campo próprio. */
    protected chooseMessageLength(picked: string): void;
    /**
     * Valor próprio como será gravado: limitado ao intervalo da API, ou null se
     * vazio ou inválido.
     */
    protected clampedMessageLength(): number | null;
    /**
     * Ninguém aguarda este método; toda falha é tratada aqui para não virar
     * unhandled rejection.
     */
    onsubmit(e: SubmitEvent): void;
}
