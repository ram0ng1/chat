import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
/**
 * Busca o painel do drawer, que vive no chunk preguiçoso da interface. Chamado
 * no hover do botão do cabeçalho, ao abrir e ao restaurar um drawer deixado
 * aberto; os pedidos se juntam num só.
 */
export declare function loadDrawerPanel(): Promise<void>;
/**
 * A casca do drawer que fica no bundle inicial.
 *
 * Montada uma vez na raiz do app, como antes, mas sem carregar a conversa:
 * fechada não desenha nada, e aberta antes do chunk chegar mostra a caixa do
 * drawer com um indicador. O painel de verdade (ChatDrawerPanel) entra assim
 * que o chunk resolve; a metamorfose a partir do botão fica com quem aparecer
 * primeiro.
 */
export default class ChatDrawer extends Component<ComponentAttrs> {
    /** Se a caixa de espera estava na tela no último render. */
    private placeholderShown;
    oncreate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    onupdate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    /**
     * Quando o chunk ainda não chegou, é a caixa de espera que cresce a partir do
     * botão; ela consome a origem, e o painel que a substitui entra sem repetir a
     * animação.
     */
    protected morphPlaceholder(dom: Element | null): void;
    view(): Mithril.Children;
    /**
     * Abre o drawer, carregando a lista de canais na primeira abertura.
     *
     * O redraw vem antes do carregamento: esperar a lista deixava o drawer
     * invisível por uma ida e volta inteira, o que parece botão lento. A barra
     * lateral desenha o esqueleto enquanto isso.
     */
    static open(): Promise<void>;
}
