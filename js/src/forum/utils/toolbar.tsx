import type Mithril from "mithril";

/**
 * The page's name in Flarum's phone toolbar.
 *
 * Core reserves three slots in that bar and fills them from anywhere in the page:
 * whatever carries `App-backControl` goes left, `App-titleControl` centre, and
 * `App-primaryControl` right — see `less/common/App.less`, "Somewhere on the page
 * there will be…". Nothing needs to be nested in the header; core positions the
 * elements absolutely out of wherever they were rendered.
 *
 * `flarum/messages` never writes this itself: `MessagesSidebar` extends core's
 * `IndexSidebar`, and the `nav` item it inherits is a `SelectDropdown` classed
 * `App-titleControl`. Our sidebar is a channel list rather than an `IndexSidebar`,
 * so no one ever claimed the slot and the bar held nothing but the drawer toggle.
 *
 * `--text` rather than the switcher dropdown core uses: the sections it would
 * switch between are already one tap away behind the back arrow, and a second
 * navigation for the same list competing with the first is worse than none. The
 * name shown is the section's, not the open channel's — the channel already names
 * itself in `ChatChannel-header`, and messages draws the same line, keeping the
 * dialog's name in the section header and the page's name in the bar.
 */
export function mobileTitleControl(title: string): Mithril.Children {
  // A `span`, not a heading. `BrowseChannelsPage` already carries an `h1`, and a
  // second one naming the same page would be a competing document outline for no
  // gain: assistive technology reads the page's name from `document.title`, which
  // every chat page sets through `app.setTitle`. This is chrome.
  return (
    <span className="App-titleControl App-titleControl--text ChatToolbarTitle">
      {title}
    </span>
  );
}
