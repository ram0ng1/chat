import app from "flarum/forum/app";
import Modal from "flarum/common/components/Modal";
import type { IInternalModalAttrs } from "flarum/common/components/Modal";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import humanTime from "flarum/common/helpers/humanTime";
import type Mithril from "mithril";

import type Message from "../../common/models/Message";
import { RevisionsSkeleton } from "./Skeletons";

interface Revision {
  id: number;
  content: string | null;
  createdAt: string | null;
  editedBy: {
    id: number;
    username: string;
    displayName: string;
    avatarUrl: string | null;
  } | null;
}

export interface RevisionsModalAttrs extends IInternalModalAttrs {
  message: Message;
}

/**
 * A message's edit history.
 *
 * Each row is the text *before* one edit, oldest first, with the current content
 * last so the list reads as a chronology rather than as a pile of diffs. Plain text
 * throughout: a revision is a record of what was written, and re-rendering old
 * markup would let a since-removed mention or embed fire again.
 */
export default class RevisionsModal extends Modal<RevisionsModalAttrs> {
  private revisions: Revision[] = [];
  private loadingRevisions = true;
  private failed = false;

  oninit(vnode: Mithril.Vnode<RevisionsModalAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  className(): string {
    return "ChatModal ChatRevisions Modal--medium";
  }

  title(): Mithril.Children {
    return app.translator.trans("ramon-chat.forum.revisions.title");
  }

  content(): Mithril.Children {
    if (this.loadingRevisions) {
      return <div className="Modal-body">{RevisionsSkeleton()}</div>;
    }

    if (this.failed) {
      return (
        <div className="Modal-body">
          <div className="ChatBrowse-empty">
            {app.translator.trans("ramon-chat.forum.revisions.failed")}
          </div>
        </div>
      );
    }

    return (
      <div className="Modal-body">
        <div className="ChatRevisions-list">
          {this.revisions.map((revision) => (
            <div className="ChatRevisions-entry" key={revision.id}>
              <div className="ChatRevisions-meta">
                <span className="ChatRevisions-author">
                  {revision.editedBy?.displayName ??
                    app.translator.trans(
                      "ramon-chat.forum.revisions.unknown_editor",
                    )}
                </span>
                {revision.createdAt ? (
                  <span>{humanTime(new Date(revision.createdAt))}</span>
                ) : null}
              </div>

              <div className="ChatRevisions-content">
                {revision.content ?? ""}
              </div>
            </div>
          ))}

          <div className="ChatRevisions-entry ChatRevisions-entry--current">
            <div className="ChatRevisions-meta">
              <span className="ChatRevisions-author">
                {app.translator.trans("ramon-chat.forum.revisions.current")}
              </span>
            </div>

            <div className="ChatRevisions-content">
              {this.attrs.message.content() ?? ""}
            </div>
          </div>
        </div>
      </div>
    );
  }

  protected async load(): Promise<void> {
    try {
      const payload = await app.request<{
        data: { attributes: { revisions: Revision[] } };
      }>({
        method: "GET",
        url: `${app.forum.attribute("apiUrl")}/chat-messages/${this.attrs.message.id()}/revisions`,
      });

      this.revisions = payload.data?.attributes?.revisions ?? [];
    } catch {
      this.failed = true;
    } finally {
      this.loadingRevisions = false;
      m.redraw();
    }
  }
}
