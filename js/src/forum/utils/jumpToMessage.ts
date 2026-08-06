/**
 * Quanto tempo a linha fica destacada depois do salto. Espelha
 * `@chat-jump-highlight` no LESS, que governa a animação em si — os dois
 * precisam andar juntos, senão a classe sai antes de a animação terminar (o
 * destaque some no meio) ou fica depois dela (a linha volta ao normal e a barra
 * lateral permanece acesa).
 */
const HIGHLIGHT_MS = 3000;

/**
 * Centra uma linha do stream e a destaca por alguns segundos.
 *
 * O alvo é procurado dentro do scroller que contém `from` — o painel de tópico e
 * o canal desenham a mesma `ChatMessage`, e uma busca no documento levaria a
 * rolar o stream errado quando os dois estão abertos.
 *
 * Devolve `false` quando a linha não está na janela carregada, para quem chamou
 * poder avisar em vez de engolir o clique.
 */
export function jumpToMessage(
  id: number | string,
  from?: HTMLElement | null,
): boolean {
  const scroller =
    from?.closest<HTMLElement>(".ChatChannel-stream, .ChatThreadPanel-stream") ??
    document.querySelector<HTMLElement>(".ChatChannel-stream");

  const node = scroller?.querySelector<HTMLElement>(
    `.ChatMessage[data-id="${id}"]`,
  );

  if (!scroller || !node) return false;

  // Deliberadamente não é `scrollIntoView`: ele rola *todos* os ancestrais
  // roláveis do nó, o documento incluído. No celular isso arrastava a página
  // inteira para baixo só para mover uma linha dentro de um contêiner que já
  // estava na tela.
  //
  // Medido pelos rects e não por `offsetTop`, que é relativo ao ancestral
  // posicionado mais próximo e só coincide com o espaço de coordenadas do
  // scroller por acidente do CSS atual.
  const nodeRect = node.getBoundingClientRect();
  const scrollerRect = scroller.getBoundingClientRect();
  const centred =
    nodeRect.top -
    scrollerRect.top -
    (scroller.clientHeight - nodeRect.height) / 2;

  scroller.scrollTo({
    top: Math.max(0, scroller.scrollTop + centred),
    behavior: "smooth",
  });

  highlight(node);

  return true;
}

/**
 * Timers em voo, por linha. Sem isso, saltar duas vezes para a mesma mensagem
 * deixaria o primeiro timer apagar o destaque no meio do segundo.
 */
const timers = new WeakMap<HTMLElement, number>();

function highlight(node: HTMLElement): void {
  window.clearTimeout(timers.get(node));

  // Reaplicar a classe que já está lá não reinicia a animação. Tirar, forçar o
  // reflow lendo `offsetWidth` e pôr de volta é o que faz o segundo salto para a
  // mesma linha acender de novo em vez de não fazer nada visível.
  node.classList.remove("ChatMessage--flash");
  void node.offsetWidth;
  node.classList.add("ChatMessage--flash");

  timers.set(
    node,
    window.setTimeout(() => {
      node.classList.remove("ChatMessage--flash");
      timers.delete(node);
    }, HIGHLIGHT_MS),
  );
}
