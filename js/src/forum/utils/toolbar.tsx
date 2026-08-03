import app from "flarum/forum/app";
import Button from "flarum/common/components/Button";
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

/**
 * The way out of a chat section on a phone, in the toolbar's right-hand slot.
 *
 * The left slot is not available: core mounts its own `Navigation` into
 * `#app-navigation` with `App-backControl`, and on `/chat/search` — reached from
 * the channel list, with no page before it in this tab — that renders the drawer
 * toggle rather than a back arrow. So the section had a hamburger, a title, and
 * nothing that returned to the list the user came from.
 *
 * `App-primaryControl` is the remaining slot, and core positions whatever carries
 * it at the right of the bar (`less/common/App.less`). It is the same slot core's
 * own `IndexSidebar` puts "Start a Discussion" in, so a control sitting there on a
 * chat page is not an arrangement invented here.
 *
 * `fa-xmark`, not a left chevron: an arrow pointing left, drawn on the right-hand
 * edge, points away from where tapping it takes you. The cross reads as "close
 * this section", which is exactly what it does — the channel list is underneath.
 */
export function mobileBackControl(onclick: () => void): Mithril.Children {
  const label = app.translator.trans(
    "ramon-chat.forum.sidebar.back_to_channels",
    {},
    true,
  );

  // A wrapper element, because core styles `.App-primaryControl > .Button` — the
  // class has to be on the parent for the button inside it to be sized and
  // coloured like the drawer toggle opposite.
  return (
    <div className="App-primaryControl ChatToolbarBack">
      <Button
        className="Button Button--icon"
        icon="fas fa-xmark"
        title={label}
        aria-label={label}
        onclick={onclick}
      />
    </div>
  );
}
