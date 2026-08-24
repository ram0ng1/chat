import app from "flarum/forum/app";
import FormModal from "flarum/common/components/FormModal";
import type { IFormModalAttrs } from "flarum/common/components/FormModal";
import Alert from "flarum/common/components/Alert";
import Button from "flarum/common/components/Button";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import Switch from "flarum/common/components/Switch";
import Stream from "flarum/common/utils/Stream";
import classList from "flarum/common/utils/classList";
import withAttr from "flarum/common/utils/withAttr";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import chatState from "../state/chat";
import afterModalClosed from "../utils/afterModalClosed";
import EmojiPicker from "./EmojiPicker";
import { resolveEmoji } from "../utils/emoji";
import { humanDuration } from "../utils/duration";
import { forumMaxMessageLength } from "../utils/messageLimit";

/**
 * Message-length presets, and the bounds the API clamps a custom value to.
 *
 * Kept in step with ChannelResource's `maxMessageLength` field by hand: the
 * server is the authority and clamps regardless, these exist so the form does
 * not offer a number the save would silently change.
 */
const LENGTH_STEPS = [500, 1000, 2000, 3000, 4000, 8000, 16000];
const MIN_LENGTH = 100;
const MAX_LENGTH = 50000;

/** Sentinel for the "custom" option — never a value that gets saved. */
const CUSTOM_LENGTH = "custom";

export interface ChannelFormModalAttrs extends IFormModalAttrs {
  /** Omit to create a channel; pass one to edit it. */
  channel?: Channel;
  /** Called with the saved channel. */
  onSaved?: (channel: Channel) => void;
}

/**
 * Creates or edits a category channel.
 *
 * One component for both because the field set is identical — Discourse shows the
 * same form for "New channel" and the channel's settings tab. Only the title, the
 * submit label and whether the record already exists differ.
 *
 * Extends FormModal, not Modal: `Modal.wrapper()` returns a bare fragment, so a
 * `type="submit"` button inside it has no form and onsubmit never fires.
 */
export default class ChannelFormModal extends FormModal<ChannelFormModalAttrs> {
  private name!: Stream<string>;
  private description!: Stream<string>;
  private emoji!: Stream<string>;
  private uploadingImage = false;

  /**
   * Which immediate action is running, or null.
   *
   * Distinct from `loading`, and both are needed. `loading` is core's form-wide
   * gate — it answers "is anything in flight", which is what every field and
   * every button reads to disable itself, and that part was right.
   *
   * What it cannot answer is *which* action is running, and a spinner is a claim
   * about one control rather than about the form. Driving four buttons from the
   * one flag meant pressing Save spun Close, Archive and Delete along with it,
   * as though the dialog had started four things at once.
   */
  private pending: "status" | "archive" | "delete" | null = null;

  /**
   * A picture chosen on the create form, held until the channel exists.
   *
   * The upload route is addressed to a channel id, so on create there is nothing
   * to send it to yet. Keeping the file here and attaching it right after the
   * save is what lets the picture be part of creating a channel rather than a
   * second trip through the settings modal.
   */
  private pendingImage: File | null = null;

  /**
   * `URL.createObjectURL` handle for the preview of {@link pendingImage}.
   *
   * Tracked separately because it has to be revoked — the browser keeps the blob
   * alive for the lifetime of the document otherwise, and picking three pictures
   * before saving would leak all three.
   */
  private pendingImageUrl: string | null = null;

  /**
   * Which of the two icon fields is in use.
   *
   * A channel has one mark, not two: `channelIcon` already resolves picture over
   * emoji, so a channel carrying both silently ignores one of them and the form
   * showed no sign of which. The switch makes that choice the thing being edited,
   * and {@link onsubmit} enforces it in the data — the losing field is cleared
   * rather than left behind to reappear if the winner is ever removed.
   */
  private useImage!: Stream<boolean>;
  private tagId!: Stream<string>;
  private threading!: Stream<boolean>;
  private slowMode!: Stream<string>;

  /** A string, like `slowMode`: it backs a <select>. "" means "follow the forum". */
  private maxMessageLength!: Stream<string>;

  /** Whether the custom number field is showing instead of a preset. */
  private customLength!: Stream<boolean>;
  private autoJoin!: Stream<boolean>;
  private allowChannelWide!: Stream<boolean>;
  private autoJoinOnReply!: Stream<boolean>;
  private isPrivate!: Stream<boolean>;
  private postPermission!: Stream<string>;
  private postDiscussions!: Stream<boolean>;

  oninit(vnode: Mithril.Vnode<ChannelFormModalAttrs>): void {
    super.oninit(vnode);

    const channel = this.attrs.channel;

    this.name = Stream(channel?.name() ?? "");
    this.description = Stream(channel?.description() ?? "");
    this.emoji = Stream(channel?.emoji() ?? "");

    // Whichever the channel already uses. A saved picture is the one that shows,
    // so opening the form on the emoji field would misreport the channel.
    this.useImage = Stream(Boolean(channel?.imageUrl()));

    this.tagId = Stream(channel?.tagId() ? String(channel.tagId()) : "");

    // A string, because it backs a <select> whose values are strings. Coerced
    // on submit rather than here, so an unchanged form round-trips exactly.
    this.slowMode = Stream(String(channel?.slowModeSeconds() ?? 0));

    // Empty string, not "0": the option that means "follow the forum" has to
    // round-trip as null, and 0 would read as a limit of zero characters.
    this.maxMessageLength = Stream(
      channel?.maxMessageLength() ? String(channel.maxMessageLength()) : "",
    );

    // A channel already set to something off the list opens on the custom
    // field rather than snapping to the nearest preset.
    const saved = channel?.maxMessageLength() ?? 0;
    this.customLength = Stream(saved > 0 && !LENGTH_STEPS.includes(saved));

    this.threading = Stream(
      channel
        ? Boolean(channel.threadingEnabled())
        : Boolean(app.forum.attribute("ramon-chat.threadingDefault")),
    );
    this.autoJoin = Stream(channel ? Boolean(channel.autoJoin()) : false);
    this.allowChannelWide = Stream(
      channel ? channel.allowChannelWideMentions() !== false : true,
    );
    this.autoJoinOnReply = Stream(
      channel ? Boolean(channel.autoJoinOnReply()) : false,
    );

    // Public by default: a channel nobody can find is the surprising outcome, so
    // it has to be chosen rather than fallen into.
    this.isPrivate = Stream(channel ? Boolean(channel.isPrivate()) : false);
    this.postPermission = Stream(channel?.postPermission() ?? "all");
    this.postDiscussions = Stream(
      channel ? Boolean(channel.postDiscussions()) : false,
    );
  }

  /**
   * Releases the staged picture's object URL.
   *
   * Closing the modal without saving is the common path — the file was never
   * uploaded, so nothing on the server needs cleaning up, but the blob handle
   * would outlive the component.
   */
  onremove(vnode: Mithril.VnodeDOM<ChannelFormModalAttrs, this>): void {
    super.onremove(vnode);
    this.discardPendingImage();
  }

  protected isEditing(): boolean {
    return Boolean(this.attrs.channel);
  }

  className(): string {
    return "ChatModal ChannelFormModal Modal--large";
  }

  title(): Mithril.Children {
    return app.translator.trans(
      this.isEditing()
        ? "ramon-chat.forum.edit_channel.title"
        : "ramon-chat.forum.browse.new_channel",
    );
  }

  /**
   * Replaces core's centred header + body pair with a header / body / footer
   * dialog.
   *
   * The form was a single 375px column of eighteen stacked fields, which is the
   * shape core's `inner()` produces and the reason it read as a questionnaire:
   * the one required field, the name, sat at the same weight and in the same
   * rhythm as an admin-only toggle six scrolls below it. Everything else here —
   * the two columns, the grouping, the pinned submit — depends on owning this
   * wrapper, so it is overridden rather than worked around from `content()`.
   */
  protected inner(): Mithril.Children {
    return (
      <>
        {this.header()}

        {!!this.alertAttrs && (
          <div className="Modal-alert">
            <Alert {...this.alertAttrs} />
          </div>
        )}

        {this.content()}
        {this.footer()}
      </>
    );
  }

  /**
   * Icon, title and one line of orientation.
   *
   * The icon mirrors the emoji or picture currently chosen, so the field that is
   * furthest from the header still shows its effect without scrolling back.
   */
  protected header(): Mithril.Children {
    // Follows the switch rather than the stored values: while the form is open
    // the icon shows what saving would produce, which is the whole point of a
    // preview. A channel with a picture whose owner has just chosen an emoji
    // must preview the emoji.
    const image = this.useImage() ? this.iconImageUrl() : null;
    const emoji = this.useImage() ? null : resolveEmoji(this.emoji());
    const name = this.name().trim();

    return (
      <div className="Modal-header ChannelFormModal-header">
        <div className="ChannelFormModal-headerIcon" aria-hidden="true">
          {image ? (
            <img src={image} alt="" />
          ) : emoji ? (
            <span>{emoji}</span>
          ) : (
            <i className="fas fa-hashtag" />
          )}
        </div>

        <div className="ChannelFormModal-headerText">
          <h3 className="App-titleControl App-titleControl--text">
            {this.title()}
          </h3>
          <p className="ChannelFormModal-headerSubtitle">
            {name ||
              app.translator.trans(
                this.isEditing()
                  ? "ramon-chat.forum.edit_channel.subtitle"
                  : "ramon-chat.forum.new_channel.subtitle",
              )}
          </p>
        </div>
      </div>
    );
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body ChannelFormModal-body">
        {/* A flat grid, not two column wrappers: identity and lifecycle span
            both tracks, and a wrapper per column cannot express that without
            duplicating one of them. */}
        <div className="Form ChannelFormModal-grid">
          {this.identitySection()}
          {this.accessSection()}
          {this.behaviourSection()}
          {this.lifecycle()}
        </div>
      </div>
    );
  }

  /** Name, description and the channel's icon. */
  protected identitySection(): Mithril.Children {
    return this.section(
      "ramon-chat.forum.new_channel.section_identity",
      [
        // Icon and name on one line: the emoji is a property of the name, not a
        // field of its own, and stacking them put an empty picker row between
        // the two things the reader is actually comparing. This works because
        // the section spans both columns — in one track the picker's search
        // field would have about 90px to live in.
        <div className="Form-group ChannelFormModal-nameRow">
          <div>
            <label>
              {app.translator.trans("ramon-chat.forum.new_channel.name")}
            </label>
            <input
              className="FormControl"
              type="text"
              maxlength={100}
              bidi={this.name}
              placeholder={app.translator.trans(
                "ramon-chat.forum.new_channel.name_placeholder",
                {},
                true,
              )}
              disabled={this.loading}
            />
          </div>

          {this.iconField()}
        </div>,

        <div className="Form-group">
          <label>
            {app.translator.trans("ramon-chat.forum.new_channel.description")}
          </label>
          <textarea
            className="FormControl"
            rows={2}
            maxlength={1000}
            bidi={this.description}
            disabled={this.loading}
          />
        </div>,
      ],
      "ChannelFormModal-section--wide",
    );
  }

  /**
   * The channel's mark: an emoji, or a picture instead of one.
   *
   * One slot holding whichever the switch selects, rather than both fields on
   * screen at once. Two always-visible fields for a value that can only be one
   * of them invites setting both and then wondering why only the picture shows.
   */
  protected iconField(): Mithril.Children {
    const picture = this.useImage();

    return (
      <div className="ChannelFormModal-iconField">
        <div className="ChannelFormModal-iconField-head">
          {/* The label names the field that is showing, not the pair. */}
          <label>
            {app.translator.trans(
              picture
                ? "ramon-chat.forum.new_channel.image"
                : "ramon-chat.forum.new_channel.emoji",
            )}
          </label>

          {/* One button carrying both sides, not two buttons — a click
              anywhere on it flips to the other, the way a switch behaves.
              As two radios, pressing the lit half did nothing, which is
              correct for radios and wrong for something shaped like this.

              `role="switch"` with an explicit "true"/"false": Mithril renders
              a boolean attribute as an empty string, and `aria-checked=""` is
              not a value a screen reader can read. */}
          <button
            type="button"
            className="ChannelFormModal-iconKind"
            role="switch"
            aria-checked={picture ? "true" : "false"}
            aria-label={app.translator.trans(
              "ramon-chat.forum.new_channel.icon_kind",
              {},
              true,
            )}
            title={app.translator.trans(
              picture
                ? "ramon-chat.forum.new_channel.icon_kind_to_emoji"
                : "ramon-chat.forum.new_channel.icon_kind_to_image",
              {},
              true,
            )}
            disabled={this.loading || this.uploadingImage}
            onclick={() => this.chooseIconKind(!picture)}
          >
            {this.iconKindSide(false, "far fa-face-smile")}
            {this.iconKindSide(true, "fas fa-image")}
          </button>
        </div>

        {/* One wrapper for either side, so the two can be held to the same
            height. Both also carry a line of help: without it the emoji side
            was shorter, and flipping the switch shoved everything below the
            section up or down. */}
        <div className="ChannelFormModal-iconField-body">
          {picture ? (
            this.image()
          ) : (
            <>
              <EmojiPicker
                value={this.emoji()}
                onchange={(value: string | null) => this.emoji(value ?? "")}
                disabled={this.loading}
              />

              <div className="helpText">
                {app.translator.trans(
                  "ramon-chat.forum.new_channel.emoji_help",
                )}
              </div>
            </>
          )}
        </div>
      </div>
    );
  }

  /**
   * One half of the switch. A span, not a button: the whole control is the
   * button, so a click on either side flips it.
   */
  protected iconKindSide(picture: boolean, icon: string): Mithril.Children {
    return (
      <span
        className={classList("ChannelFormModal-iconKind-option", {
          "ChannelFormModal-iconKind-option--active":
            this.useImage() === picture,
        })}
        aria-hidden="true"
      >
        <i className={icon} />
      </span>
    );
  }

  /**
   * Switches between the two icon fields.
   *
   * Turning the picture off drops a file staged for a channel that does not
   * exist yet: it is never going to be uploaded now, and holding it would
   * attach it anyway if the switch were flipped back on after the save. A
   * picture already on the server is left alone until the form is submitted, so
   * the change stays cancellable — see {@link onsubmit}.
   */
  protected chooseIconKind(picture: boolean): void {
    this.useImage(picture);

    if (!picture) this.discardPendingImage();
  }

  /** Who can see the channel, who can write in it, and what it inherits. */
  protected accessSection(): Mithril.Children {
    return this.section("ramon-chat.forum.new_channel.section_access", [
      this.visibility(),
      this.posting(),
      this.tagOptions(),
    ]);
  }

  /** How the channel behaves once it exists. */
  protected behaviourSection(): Mithril.Children {
    const tag = this.selectedTag();

    return this.section("ramon-chat.forum.new_channel.section_behaviour", [
      this.slowModeOptions(),
      this.messageLengthOptions(),

      <div className="Form-group ChannelFormModal-toggles">
        {this.toggle(
          this.threading,
          "ramon-chat.forum.info.threading",
          app.translator.trans("ramon-chat.forum.new_channel.threading_help"),
        )}

        {this.toggle(
          this.allowChannelWide,
          "ramon-chat.forum.settings.channel_wide_mentions",
          app.translator.trans(
            "ramon-chat.forum.new_channel.channel_wide_mentions_help",
          ),
        )}

        {/* Only meaningful for a tag-bound channel: it keys off replies in
            that category. Shown regardless so the intent is discoverable — but
            with the category named once there is one, because "requires a
            category above" describes a state the reader may have already left. */}
        {this.toggle(
          this.autoJoinOnReply,
          "ramon-chat.forum.info.auto_join_on_reply",
          tag
            ? app.translator.trans(
                "ramon-chat.forum.info.auto_join_on_reply_help_bound",
                { category: tag.name() },
              )
            : app.translator.trans(
                "ramon-chat.forum.info.auto_join_on_reply_help_none",
              ),
        )}

        {/* Only with a category chosen. The switch announces discussions *from
            that category*, so without one it is a control whose label describes
            something that does not exist — and flipping it would do nothing.
            Choosing a category above brings it in; clearing the category takes it
            away and turns it off (see `chooseTag`), so a hidden switch is never
            left holding a value the reader cannot see. */}
        {tag
          ? this.toggle(
              this.postDiscussions,
              "ramon-chat.forum.info.post_discussions",
              app.translator.trans(
                "ramon-chat.forum.info.post_discussions_help_bound",
                { category: tag.name() },
              ),
            )
          : null}

        {/* Auto-join is admin-only: it can add every account on the forum. */}
        {app.session.user?.attribute<boolean>("isAdmin") !== false
          ? this.toggle(
              this.autoJoin,
              "ramon-chat.forum.info.auto_join",
              app.translator.trans("ramon-chat.forum.info.auto_join_help"),
            )
          : null}
      </div>,
    ]);
  }

  /**
   * A titled group of fields, dropped entirely when it has nothing to show —
   * an empty bordered box with a heading is worse than no box.
   */
  protected section(
    titleKey: string,
    children: Mithril.Children[],
    className?: string,
  ): Mithril.Children {
    const content = children.filter(Boolean);

    if (content.length === 0) return null;

    return (
      <section className={classList("ChannelFormModal-section", className)}>
        <h4 className="ChannelFormModal-sectionTitle">
          {app.translator.trans(titleKey)}
        </h4>
        {content}
      </section>
    );
  }

  /**
   * One switch row: control, label and the sentence explaining what it changes.
   *
   * A `Switch` rather than a bare checkbox because these are settings that take
   * effect on save, not items being ticked off a list — and because the help
   * text needs to sit under the label, which a checkbox's inline layout cannot
   * do without a hardcoded indent matching the box's width.
   */
  protected toggle(
    stream: Stream<boolean>,
    labelKey: string,
    // Rendered children rather than a translator key: two of these rows change
    // their sentence with the category picked above, and a key alone cannot
    // carry the interpolated category name.
    help?: Mithril.Children,
  ): Mithril.Children {
    return (
      <div className="ChannelFormModal-toggle">
        <Switch
          state={stream()}
          onchange={(checked: boolean) => stream(checked)}
          disabled={this.loading}
        >
          {app.translator.trans(labelKey)}
        </Switch>

        {help ? <div className="helpText">{help}</div> : null}
      </div>
    );
  }

  /**
   * The submit row, pinned below the scrolling body.
   *
   * It used to be the last of eighteen stacked fields, which meant that on a
   * short viewport the only way to find out the form could be submitted was to
   * scroll past every optional setting.
   */
  protected footer(): Mithril.Children {
    return (
      <div className="Modal-footer ChannelFormModal-footer">
        <Button
          className="Button Button--link"
          type="button"
          disabled={this.loading}
          onclick={() => this.hide()}
        >
          {app.translator.trans("ramon-chat.forum.new_channel.cancel")}
        </Button>

        <Button
          className="Button Button--primary"
          type="submit"
          loading={this.loading && this.pending === null}
          disabled={this.loading || this.name().trim() === ""}
        >
          {app.translator.trans(
            this.isEditing()
              ? "ramon-chat.forum.edit_channel.submit"
              : "ramon-chat.forum.new_channel.submit",
          )}
        </Button>
      </div>
    );
  }

  /**
   * A picture for the channel, instead of its emoji.
   *
   * Offered on both forms, but the two behave differently underneath. Editing
   * uploads immediately, because the channel id the route needs already exists.
   * Creating cannot: the id is only minted by the save. There the file is held
   * in {@link pendingImage} and attached once the record comes back — see
   * {@link attachPendingImage}.
   *
   * The alternative, hiding the field until the channel exists, is what this
   * replaced: it read as "channels cannot have pictures" and sent people back
   * through the settings modal for something they had already decided.
   *
   * No label of its own: it fills the icon slot, which {@link iconField} has
   * already labelled.
   */
  protected image(): Mithril.Children {
    const url = this.iconImageUrl();

    return (
      <div className="ChatChannelForm-image">
        <div className="ChatChannelForm-imageRow">
          <div className="ChatChannelForm-imagePreview">
            {url ? (
              <img src={url} alt="" />
            ) : (
              <i className="fas fa-hashtag" aria-hidden="true" />
            )}
          </div>

          {this.uploadingImage ? (
            <LoadingIndicator display="inline" size="small" />
          ) : (
            <>
              <label className="Button">
                {app.translator.trans(
                  "ramon-chat.forum.new_channel.image_upload",
                )}
                <input
                  type="file"
                  accept="image/*"
                  style={{ display: "none" }}
                  onchange={(e: Event) => this.chooseImage(e)}
                />
              </label>

              {url ? (
                <Button className="Button" onclick={() => this.removeImage()}>
                  {app.translator.trans(
                    "ramon-chat.forum.new_channel.image_remove",
                  )}
                </Button>
              ) : null}
            </>
          )}
        </div>

        <div className="helpText">
          {app.translator.trans("ramon-chat.forum.new_channel.image_help")}
        </div>
      </div>
    );
  }

  /** The picture standing in for the channel: staged on create, saved on edit. */
  protected iconImageUrl(): string | null {
    return this.attrs.channel
      ? this.attrs.channel.imageUrl()
      : this.pendingImageUrl;
  }

  /**
   * Routes the picked file: straight to the server when the channel exists,
   * into {@link pendingImage} when it does not.
   */
  protected chooseImage(e: Event): void {
    if (this.isEditing()) {
      void this.uploadImage(e);
      return;
    }

    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];

    // Cleared so re-picking the same file still fires a change event.
    input.value = "";

    if (!file) return;

    this.discardPendingImage();
    this.pendingImage = file;
    this.pendingImageUrl = URL.createObjectURL(file);
    m.redraw();
  }

  /** Drops the picture: a DELETE when saved, a local discard when staged. */
  protected removeImage(): void {
    if (this.isEditing()) {
      void this.clearImage();
      return;
    }

    this.discardPendingImage();
    m.redraw();
  }

  private discardPendingImage(): void {
    if (this.pendingImageUrl) URL.revokeObjectURL(this.pendingImageUrl);

    this.pendingImage = null;
    this.pendingImageUrl = null;
  }

  /**
   * Sends the staged picture to the channel that was just created.
   *
   * A failure here does NOT undo the channel — it exists, it is in the sidebar,
   * and the only thing missing is the picture. Saying so and letting the user
   * add it from the settings modal is honest; rolling back a channel they asked
   * for because a JPEG was too large would not be.
   */
  private async attachPendingImage(channel: Channel): Promise<void> {
    const file = this.pendingImage;

    if (!file) return;

    const body = new FormData();
    body.append("image", file);

    try {
      const response = await app.request<any>({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat/channels/${channel.id()}/image`,
        serialize: (raw: any) => raw,
        body,
      });

      channel.pushAttributes({ imageUrl: response?.data?.imageUrl ?? null });
    } catch (err: any) {
      app.alerts.show(
        { type: "error" },
        err?.response?.errors?.[0]?.detail ??
          app.translator.trans(
            "ramon-chat.forum.new_channel.image_failed_after_create",
          ),
      );
    } finally {
      this.discardPendingImage();
    }
  }

  protected async uploadImage(e: Event): Promise<void> {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    const channel = this.attrs.channel;

    if (!file || !channel) return;

    this.uploadingImage = true;
    m.redraw();

    const body = new FormData();
    body.append("image", file);

    try {
      const response = await app.request<any>({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat/channels/${channel.id()}/image`,
        // The default serializer would JSON-encode the FormData into "[object
        // FormData]" and the request would arrive with no file at all.
        serialize: (raw: any) => raw,
        body,
      });

      channel.pushAttributes({ imageUrl: response?.data?.imageUrl ?? null });
    } catch (err: any) {
      app.alerts.show(
        { type: "error" },
        err?.response?.errors?.[0]?.detail ??
          app.translator.trans("ramon-chat.forum.new_channel.image_failed"),
      );
    } finally {
      this.uploadingImage = false;
      // Cleared so re-picking the same file still fires a change event.
      input.value = "";
      m.redraw();
    }
  }

  protected async clearImage(): Promise<void> {
    const channel = this.attrs.channel;

    if (!channel) return;

    this.uploadingImage = true;
    m.redraw();

    try {
      await app.request({
        method: "DELETE",
        url: `${app.forum.attribute("apiUrl")}/chat/channels/${channel.id()}/image`,
      });

      channel.pushAttributes({ imageUrl: null });
    } catch {
      app.alerts.show(
        { type: "error" },
        app.translator.trans("ramon-chat.forum.new_channel.image_failed"),
      );
    } finally {
      this.uploadingImage = false;
      m.redraw();
    }
  }

  protected visibility(): Mithril.Children {
    return (
      <div className="Form-group">
        <label>
          {app.translator.trans("ramon-chat.forum.new_channel.visibility")}
        </label>

        {/* Two cards side by side rather than two stacked radio rows. The
            choice is between named alternatives whose consequences differ, so
            both descriptions have to be readable at once — stacked, the second
            option's help text sat below the fold of the group and the reader
            chose "public" without ever seeing what "private" meant. */}
        <div className="ChannelFormModal-choices">
          {this.choice(
            false,
            "fas fa-globe",
            "ramon-chat.forum.new_channel.public",
            "ramon-chat.forum.new_channel.public_help",
          )}
          {this.choice(
            true,
            "fas fa-lock",
            "ramon-chat.forum.new_channel.private",
            "ramon-chat.forum.new_channel.private_help",
          )}
        </div>
      </div>
    );
  }

  /** One visibility card. Still a radio underneath — keyboard and screen readers get the group semantics for free. */
  protected choice(
    value: boolean,
    icon: string,
    labelKey: string,
    helpKey: string,
  ): Mithril.Children {
    const selected = this.isPrivate() === value;

    return (
      <label
        className={classList("ChannelFormModal-choice", {
          "ChannelFormModal-choice--selected": selected,
        })}
      >
        <input
          type="radio"
          name="ramon-chat-visibility"
          checked={selected}
          onchange={() => this.isPrivate(value)}
          disabled={this.loading}
        />

        <span className="ChannelFormModal-choice-title">
          <i className={icon} aria-hidden="true" />
          {app.translator.trans(labelKey)}
        </span>

        <span className="ChannelFormModal-choice-help">
          {app.translator.trans(helpKey)}
        </span>
      </label>
    );
  }

  /**
   * Who may post.
   *
   * A separate question from visibility: "who can see this" and "who can write in
   * it" are independent, and a private channel only moderators post in is a
   * perfectly ordinary announcement channel for an invited audience.
   */
  protected posting(): Mithril.Children {
    return (
      <div className="Form-group">
        <label>
          {app.translator.trans("ramon-chat.forum.new_channel.post_permission")}
        </label>

        <select
          className="FormControl"
          value={this.postPermission()}
          onchange={withAttr("value", this.postPermission)}
          disabled={this.loading}
        >
          <option value="all">
            {app.translator.trans(
              "ramon-chat.forum.new_channel.post_all",
              {},
              true,
            )}
          </option>
          <option value="moderators">
            {app.translator.trans(
              "ramon-chat.forum.new_channel.post_moderators",
              {},
              true,
            )}
          </option>
        </select>

        {/* The sentence describes the option that is selected, not the one that
            is not. A single line explaining what "moderators only" does, shown
            while "everyone" is selected, reads as a description of the current
            setting and says the opposite of the truth. */}
        <div className="helpText">
          {app.translator.trans(
            this.postPermission() === "moderators"
              ? "ramon-chat.forum.new_channel.post_moderators_help"
              : "ramon-chat.forum.new_channel.post_all_help",
          )}
        </div>
      </div>
    );
  }

  /**
   * Close and archive, on an existing channel only.
   *
   * Neither means anything for a channel that does not exist yet, and both act
   * immediately rather than on submit — they are not settings being edited, they
   * are state changes, and mixing them into the form's save would make an
   * unsaved-and-abandoned form able to archive something.
   */
  protected lifecycle(): Mithril.Children {
    const channel = this.attrs.channel;

    if (!channel) return null;

    const closed = channel.status() === "closed";
    const archived = Boolean(channel.archivedAt());
    const items: Mithril.Children[] = [];

    if (channel.canClose() && !archived) {
      items.push(
        <Button
          className="Button"
          icon={closed ? "fas fa-lock-open" : "fas fa-lock"}
          loading={this.pending === "status"}
          disabled={this.loading}
          onclick={() => this.setStatus(closed ? "open" : "closed")}
        >
          {app.translator.trans(
            closed
              ? "ramon-chat.forum.info.reopen_channel"
              : "ramon-chat.forum.info.close_channel",
          )}
        </Button>,
      );
    }

    if (channel.canArchive() && !archived) {
      items.push(
        <Button
          className="Button"
          icon="fas fa-box-archive"
          loading={this.pending === "archive"}
          disabled={this.loading}
          onclick={() => this.archive()}
        >
          {app.translator.trans("ramon-chat.forum.info.archive_channel")}
        </Button>,
      );
    }

    // Deleting is offered from here as well as from the channel's info panel:
    // this dialog is where "the channel's settings" live, and an irreversible
    // action that exists only behind another surface reads as missing.
    const canDelete = channel.canDelete();

    if (items.length === 0 && !canDelete) return null;

    return (
      <div className="ChannelFormModal-lifecycle ChannelFormModal-section ChannelFormModal-section--wide">
        <label className="ChannelFormModal-sectionTitle">
          {app.translator.trans("ramon-chat.forum.edit_channel.lifecycle")}
        </label>

        {items.length > 0 ? (
          <div className="ChannelFormModal-lifecycleActions">{items}</div>
        ) : null}

        <div className="helpText">
          {app.translator.trans("ramon-chat.forum.edit_channel.lifecycle_help")}
        </div>

        {canDelete ? (
          <div className="ChannelFormModal-lifecycleDanger">
            <Button
              className="Button ChannelFormModal-deleteButton"
              icon="fas fa-trash"
              loading={this.pending === "delete"}
              disabled={this.loading}
              onclick={() => this.destroy()}
            >
              {app.translator.trans("ramon-chat.forum.info.delete_channel")}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  /**
   * Deletes the channel and closes the dialog.
   *
   * Its own path rather than `act()`: that one posts to an endpoint and then
   * hands the saved channel to `onSaved`, and neither makes sense once the
   * record is gone. The callers that pass `onSaved` on the *edit* path use it to
   * refresh a list, which is exactly what should happen here too — the create
   * path cannot reach this code, because a channel that does not exist yet has
   * no `canDelete()` to be true.
   */
  protected async destroy(): Promise<void> {
    const channel = this.attrs.channel;

    if (!channel) return;

    if (
      !confirm(
        app.translator.trans("ramon-chat.forum.info.delete_confirm", {}, true),
      )
    )
      return;

    this.pending = "delete";
    this.loading = true;
    m.redraw();

    try {
      await channel.delete();

      chatState.channels = chatState.channels.filter(
        (c) => c.id() !== channel.id(),
      );

      // Leaving the active channel pointing at a deleted record strands the
      // stream on something that can no longer be fetched.
      if (chatState.activeChannelId === Number(channel.id())) {
        chatState.setActiveChannel(null);
      }

      this.hide();

      afterModalClosed(() => this.attrs.onSaved?.(channel));
    } catch (e: any) {
      app.alerts.show(
        { type: "error" },
        e?.response?.errors?.[0]?.detail ??
          app.translator.trans("ramon-chat.forum.edit_channel.failed"),
      );
    } finally {
      this.pending = null;
      this.loading = false;
      m.redraw();
    }
  }

  protected async setStatus(status: "open" | "closed"): Promise<void> {
    await this.act(
      "status",
      `/chat-channels/${this.attrs.channel!.id()}/status`,
      {
        status,
      },
    );
  }

  protected async archive(): Promise<void> {
    if (
      !confirm(
        app.translator.trans(
          "ramon-chat.forum.edit_channel.archive_confirm",
          {},
          true,
        ),
      )
    )
      return;

    await this.act(
      "archive",
      `/chat-channels/${this.attrs.channel!.id()}/archive`,
      {},
    );
  }

  /**
   * Runs one immediate state change.
   *
   * `action` names the button that owns the spinner for the duration; `loading`
   * still gates the rest of the form, so nothing else can be started meanwhile.
   */
  protected async act(
    action: "status" | "archive",
    path: string,
    attributes: Record<string, unknown>,
  ): Promise<void> {
    this.pending = action;
    this.loading = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}${path}`,
        body: { data: { attributes } },
      });

      if (payload?.data) app.store.pushPayload(payload);

      this.hide();

      // Same reason as the save path above: whatever the caller does next must not
      // race the close animation.
      afterModalClosed(() => this.attrs.onSaved?.(this.attrs.channel!));
    } catch (e: any) {
      app.alerts.show(
        { type: "error" },
        e?.response?.errors?.[0]?.detail ??
          app.translator.trans("ramon-chat.forum.edit_channel.failed"),
      );
    } finally {
      this.pending = null;
      this.loading = false;
      m.redraw();
    }
  }

  /**
   * Category picker, rendered only when flarum/tags is present. A tag-bound
   * channel inherits that tag's permissions, which is how a restricted category
   * yields a restricted channel.
   */
  protected tagOptions(): Mithril.Children {
    const tags = app.store.all("tags");

    if (!("flarum-tags" in (flarum.extensions ?? {})) || tags.length === 0)
      return null;

    const selected = this.selectedTag();

    return (
      <div className="Form-group">
        <label>
          {app.translator.trans("ramon-chat.forum.new_channel.category")}
        </label>

        {/* Deliberately not `bidi`. For a <select>, bidi walks node.children and
            reads `option.attrs.value` on each — so it requires flat, literal
            children. A mapped list arrives as a nested array whose entries have no
            `.attrs`, which throws. value + onchange is equivalent and safe. */}
        <select
          className="FormControl"
          value={this.tagId()}
          onchange={withAttr("value", (id: string) => this.chooseTag(id))}
          disabled={this.loading}
        >
          <option value="">
            {app.translator.trans("ramon-chat.forum.new_channel.no_category")}
          </option>
          {tags
            .filter((tag: any) => !tag.isChild?.())
            .map((tag: any) => (
              <option key={tag.id()} value={String(tag.id())}>
                {tag.name()}
              </option>
            ))}
        </select>

        {/* Named, not described in the abstract: "inherits this category's
            permissions" leaves the reader to work out which category that is,
            and the two states — bound and forum-wide — have nothing in common
            worth saying in one sentence. */}
        <div className="helpText">
          {selected
            ? app.translator.trans(
                "ramon-chat.forum.new_channel.category_help_bound",
                { category: selected.name() },
              )
            : app.translator.trans(
                "ramon-chat.forum.new_channel.category_help_none",
              )}
        </div>
      </div>
    );
  }

  /**
   * Picks the bound category, or clears it.
   *
   * Clearing also switches off "announce new discussions": that toggle is only
   * rendered while a category is chosen, so leaving it set would submit a value
   * the reader can no longer see or reach — and the channel would start
   * announcing the moment someone bound a category to it later.
   */
  protected chooseTag(id: string): void {
    this.tagId(id);

    if (!id) this.postDiscussions(false);
  }

  /** The category currently chosen in the picker, if any. */
  protected selectedTag(): any | null {
    if (!this.tagId()) return null;

    return (
      app.store
        .all("tags")
        .find((tag: any) => String(tag.id()) === this.tagId()) ?? null
    );
  }

  /**
   * Nothing awaits this method, so a rejection here would surface as an unhandled
   * promise rejection rather than as feedback. Every failure path is handled inline.
   */
  /**
   * Slow mode: how long each person waits between messages here.
   *
   * A fixed list rather than a free number field. The useful values are few and
   * far apart — five seconds calms a room, five minutes changes what the room is
   * for — and a text box invites 7s, which is nobody's intention.
   *
   * Moderators are exempt, and the help text says so: someone enabling this needs
   * to know it will not throttle them out of their own moderation. That sentence
   * only appears once a wait is actually set — with slow mode off there is no
   * exemption to explain, and describing one implies a limit that is not there.
   */
  protected slowModeOptions(): Mithril.Children {
    const steps = [0, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600, 21600];
    const seconds = Number(this.slowMode()) || 0;

    return (
      <div className="Form-group">
        <label>
          {app.translator.trans("ramon-chat.forum.new_channel.slow_mode")}
        </label>

        <select
          className="FormControl"
          value={this.slowMode()}
          onchange={withAttr("value", this.slowMode)}
          disabled={this.loading}
        >
          {steps.map((seconds) => (
            <option key={seconds} value={String(seconds)}>
              {seconds === 0
                ? app.translator.trans(
                    "ramon-chat.forum.new_channel.slow_mode_off",
                    {},
                    true,
                  )
                : humanDuration(seconds)}
            </option>
          ))}
        </select>

        <p className="helpText">
          {seconds === 0
            ? app.translator.trans(
                "ramon-chat.forum.new_channel.slow_mode_help_off",
              )
            : app.translator.trans(
                "ramon-chat.forum.new_channel.slow_mode_help_on",
                { duration: humanDuration(seconds) },
              )}
        </p>
      </div>
    );
  }

  /**
   * How long a message may be here.
   *
   * Presets plus a way out of them. The list covers what most channels want and
   * keeps the common case to one click; "Custom" opens a number field for the
   * rooms that need something the list does not have. The first option is not a
   * number at all — it keeps the channel following the forum-wide setting, so
   * raising that later still raises this channel with it.
   */
  protected messageLengthOptions(): Mithril.Children {
    const forumDefault = forumMaxMessageLength();
    const value = this.maxMessageLength();
    const custom = this.customLength();

    return (
      <div className="Form-group">
        <label>
          {app.translator.trans("ramon-chat.forum.new_channel.message_length")}
        </label>

        <select
          className="FormControl"
          value={custom ? CUSTOM_LENGTH : value}
          onchange={withAttr("value", (picked: string) =>
            this.chooseMessageLength(picked),
          )}
          disabled={this.loading}
        >
          <option value="">
            {app.translator.trans(
              "ramon-chat.forum.new_channel.message_length_inherit",
              { count: forumDefault },
              true,
            )}
          </option>

          {LENGTH_STEPS.map((chars) => (
            <option key={chars} value={String(chars)}>
              {app.translator.trans(
                "ramon-chat.forum.new_channel.message_length_chars",
                { count: chars },
                true,
              )}
            </option>
          ))}

          <option value={CUSTOM_LENGTH}>
            {app.translator.trans(
              "ramon-chat.forum.new_channel.message_length_custom",
              {},
              true,
            )}
          </option>
        </select>

        {custom ? (
          <input
            className="FormControl ChannelFormModal-customLength"
            type="number"
            min={MIN_LENGTH}
            max={MAX_LENGTH}
            step={100}
            // Not `bidi`: the value has to survive being briefly empty while it
            // is retyped, and clamping on every keystroke would fight the
            // typist — "5" becomes "100" before the "00" arrives. It is clamped
            // on submit instead, where the server clamps too.
            value={value}
            placeholder={String(forumDefault)}
            disabled={this.loading}
            oninput={withAttr("value", this.maxMessageLength)}
            oncreate={(vnode: Mithril.VnodeDOM) =>
              (vnode.dom as HTMLInputElement).focus()
            }
          />
        ) : null}

        <p className="helpText">{this.messageLengthHelp(forumDefault)}</p>
      </div>
    );
  }

  /** The sentence under the field, describing the option actually selected. */
  protected messageLengthHelp(forumDefault: number): Mithril.Children {
    const raw = this.maxMessageLength().trim();

    if (raw === "") {
      return app.translator.trans(
        this.customLength()
          ? "ramon-chat.forum.new_channel.message_length_help_empty"
          : "ramon-chat.forum.new_channel.message_length_help_inherit",
        { count: forumDefault },
      );
    }

    const chars = Number(raw);

    // Says what saving would actually store, not what was typed: the server
    // clamps, and a help text promising 40 characters under a field the API
    // will raise to 100 is a lie the reader only discovers afterwards.
    if (chars < MIN_LENGTH || chars > MAX_LENGTH) {
      return app.translator.trans(
        "ramon-chat.forum.new_channel.message_length_help_clamped",
        { count: this.clampedMessageLength() ?? forumDefault },
      );
    }

    return app.translator.trans(
      "ramon-chat.forum.new_channel.message_length_help_own",
      { count: chars },
    );
  }

  /** Switches between a preset, the forum default, and the custom field. */
  protected chooseMessageLength(picked: string): void {
    if (picked === CUSTOM_LENGTH) {
      this.customLength(true);

      // Seeded with the preset that was showing, so the field opens on a
      // sensible number instead of empty.
      if (this.maxMessageLength() === "") {
        this.maxMessageLength(String(forumMaxMessageLength()));
      }

      return;
    }

    this.customLength(false);
    this.maxMessageLength(picked);
  }

  /**
   * The custom value as it will be stored: clamped to the range the API
   * accepts, or null when the field is empty or not a number.
   */
  protected clampedMessageLength(): number | null {
    const chars = Number(this.maxMessageLength().trim());

    if (!Number.isFinite(chars) || chars <= 0) return null;

    return Math.max(MIN_LENGTH, Math.min(MAX_LENGTH, Math.round(chars)));
  }

  onsubmit(e: SubmitEvent): void {
    e.preventDefault();

    if (this.loading) return;

    this.loading = true;

    const editing = this.isEditing();

    const attributes: Record<string, unknown> = {
      name: this.name().trim(),
      description: this.description().trim() || null,
      // Null when the channel is using a picture. The emoji stream keeps its
      // value so flipping the switch back restores the picker, but a channel
      // that stores both would show the picture and silently carry an emoji
      // that reappears the day the picture is deleted.
      emoji: this.useImage() ? null : this.emoji().trim() || null,
      threadingEnabled: this.threading(),
      slowModeSeconds: Number(this.slowMode()) || 0,
      maxMessageLength: this.clampedMessageLength(),
      allowChannelWideMentions: this.allowChannelWide(),
      autoJoin: this.autoJoin(),
      autoJoinOnReply: this.autoJoinOnReply(),
      isPrivate: this.isPrivate(),
      postPermission: this.postPermission(),
      postDiscussions: this.postDiscussions(),
      tagId: this.tagId() ? Number(this.tagId()) : null,
    };

    // `type` is writable only on create; sending it on update would be rejected.
    if (!editing) {
      attributes.type = "category";
    }

    const record =
      this.attrs.channel ?? app.store.createRecord<Channel>("chat-channels");

    record
      .save(attributes)
      .then(async (channel) => {
        // Before the sidebar and `onSaved` see it: both read `imageUrl()`, and a
        // channel that flashes a hash for a second and then swaps to the picture
        // looks like a bug in a list the user is already scanning.
        if (!editing && this.useImage() && this.pendingImage) {
          await this.attachPendingImage(channel as Channel);
        }

        // The other half of the exclusivity: the emoji was written by the save
        // above, so the picture it replaces has to go. Deferred to here rather
        // than done when the switch was flipped, so abandoning the form leaves
        // the channel exactly as it was found.
        if (editing && !this.useImage() && (channel as Channel).imageUrl()) {
          await this.clearImage();
        }

        if (
          !editing &&
          !chatState.channels.some((c) => c.id() === channel.id())
        ) {
          // The server subscribes the creator, so the channel belongs in the
          // sidebar straight away rather than after the next poll.
          chatState.channels.unshift(channel as Channel);
        }

        this.hide();

        if (editing) {
          app.alerts.show(
            { type: "success" },
            app.translator.trans("ramon-chat.forum.edit_channel.saved"),
          );
        }

        // Deferred until the modal has actually gone.
        //
        // Core's ModalManager.animateHide() only clears its `modalClosing` latch
        // from a `transitionend` handler. A caller that changes the route in this
        // same tick can interrupt that transition, the event never fires, and the
        // latch stays set — after which every later animateHide() returns
        // immediately and no modal can be closed again until the page is reloaded.
        // That is why creating a channel left the next modal stuck with a dead X.
        afterModalClosed(() => this.attrs.onSaved?.(channel as Channel));
      })
      .catch((error: any) => {
        this.loading = false;

        // FormModal.onerror renders validation errors above the form and refocuses
        // the offending field; anything else gets an alert so it is never silent.
        if (error?.alert) {
          this.onerror(error);
        } else {
          app.alerts.show(
            { type: "error" },
            error?.response?.errors?.[0]?.detail ??
              app.translator.trans(
                editing
                  ? "ramon-chat.forum.edit_channel.failed"
                  : "ramon-chat.forum.new_channel.failed",
              ),
          );

          m.redraw();
        }
      });
  }
}
