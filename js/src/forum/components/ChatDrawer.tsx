import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import chatState from "../state/chat";
import { loadChatUi } from "../utils/lazy";
import { isNarrowViewport } from "../utils/surface";
import { morphIn } from "../utils/morph";

type Panel = Awaited<ReturnType<typeof loadChatUi>>["ChatDrawerPanel"];

let panel: Panel | null = null;
let pending: Promise<void> | null = null;

/**
 * Busca o painel do drawer, que vive no chunk preguiçoso da interface. Chamado
 * no hover do botão do cabeçalho, ao abrir e ao restaurar um drawer deixado
 * aberto; os pedidos se juntam num só.
 */
export function loadDrawerPanel(): Promise<void> {
  pending ??= loadChatUi().then(
    (ui) => {
      panel = ui.ChatDrawerPanel;
      m.redraw();
    },
    (error) => {
      pending = null;
      throw error;
    },
  );

  return pending;
}

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
  private placeholderShown = false;

  oncreate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void {
    super.oncreate(vnode);

    this.morphPlaceholder(vnode.dom);
  }

  onupdate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void {
    super.onupdate(vnode);

    this.morphPlaceholder(vnode.dom);
  }

  /**
   * Quando o chunk ainda não chegou, é a caixa de espera que cresce a partir do
   * botão; ela consome a origem, e o painel que a substitui entra sem repetir a
   * animação.
   */
  protected morphPlaceholder(dom: Element | null): void {
    const box =
      dom instanceof HTMLElement &&
      dom.classList.contains("ChatDrawer--loading")
        ? dom
        : null;

    if (box && !this.placeholderShown) morphIn(box, "drawer");

    this.placeholderShown = box !== null;
  }

  view(): Mithril.Children {
    if (!chatState.drawerOpen) return null;

    if (panel) {
      const Panel = panel;

      return <Panel />;
    }

    loadDrawerPanel().catch(() => {});

    if (isNarrowViewport()) return null;

    return (
      <div
        className={classList("ChatDrawer ChatDrawer--loading", {
          "ChatDrawer--collapsed": chatState.drawerCollapsed,
        })}
        role="complementary"
        aria-busy="true"
      >
        <LoadingIndicator />
      </div>
    );
  }

  /**
   * Abre o drawer, carregando a lista de canais na primeira abertura.
   *
   * O redraw vem antes do carregamento: esperar a lista deixava o drawer
   * invisível por uma ida e volta inteira, o que parece botão lento. A barra
   * lateral desenha o esqueleto enquanto isso.
   */
  static async open(): Promise<void> {
    chatState.setDrawerOpen(true);
    chatState.setDrawerCollapsed(false);

    loadDrawerPanel().catch(() => {});

    m.redraw();

    if (chatState.channelsLoaded) return;

    await Promise.all([chatState.loadChannels(), chatState.loadDrafts()]).catch(
      () => {},
    );
  }
}
