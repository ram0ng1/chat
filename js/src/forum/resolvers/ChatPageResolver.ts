import DefaultResolver from 'flarum/common/resolvers/DefaultResolver';

/**
 * Keeps every chat route on one mounted page.
 *
 * Core's DefaultResolver keys a page by `routeName + JSON.stringify(params)`, and a
 * changed key makes Mithril throw the component away and build a new one. Every
 * chat route is a different *view* of the same page — opening a thread only adds a
 * `threadId`, picking a channel only changes `id` — so that default meant each of
 * those navigations tore down the sidebar, the channel and the scroll position and
 * rebuilt them from scratch. It looked exactly like a page reload, because
 * structurally it was one.
 *
 * Returning a constant collapses the whole family onto a single key, so navigation
 * between them is an ordinary redraw. The route still changes, the URL is still
 * shareable, and `routeName` is still handed to the component through makeAttrs —
 * ChatPage reads it to decide which pane to show, and syncs the channel and thread
 * from the route on update, since oninit no longer runs per navigation.
 *
 * BrowseChannelsPage keeps the default resolver: it is a genuinely different page,
 * not another view of this one.
 */
export default class ChatPageResolver<
  Attrs extends Record<string, unknown> = Record<string, unknown>,
  Comp = any,
  RouteArgs extends Record<string, unknown> = {}
> extends DefaultResolver<Attrs, any, RouteArgs> {
  makeKey(): string {
    return 'ramon-chat';
  }
}
