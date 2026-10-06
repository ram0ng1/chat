import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
import type ChatState from "../state/ChatState";
export interface ChatSelectionBarAttrs extends ComponentAttrs {
    channel: Channel;
    state: ChatState;
}
interface TranscriptResponse {
    data: {
        attributes: {
            format: string;
            content: string;
            count: number;
            omitted: number;
        };
    };
}
/**
 * The bar shown while messages are selected: quote into a discussion, copy, move.
 *
 * Rendering the transcript is the server's job — TranscriptController re-checks
 * every id against `whereVisibleTo`, so a selection that names a message the actor
 * cannot read comes back without it, and the count of what was dropped is reported
 * rather than silently swallowed.
 */
export default class ChatSelectionBar extends Component<ChatSelectionBarAttrs> {
    private working;
    view(): Mithril.Children;
    /**
     * Moving needs `ramon-chat.moderate`, and the destination has to be somewhere the
     * actor may post — a closed or archived channel is refused server-side, so those
     * are not offered.
     */
    protected moveControls(count: number): Mithril.Children;
    protected transcript(format: "markup" | "plain"): Promise<TranscriptResponse["data"]["attributes"] | null>;
    protected quote(): Promise<void>;
    protected copy(): Promise<void>;
    protected move(target: string): Promise<void>;
    protected cancel(): void;
}
export {};
