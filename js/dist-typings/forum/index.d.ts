import type User from "flarum/common/models/User";
import Channel from "../common/models/Channel";
import Message from "../common/models/Message";
import Thread from "../common/models/Thread";
import Upload from "../common/models/Upload";
import MessageFlag from "../common/models/MessageFlag";
import chatState from "./state/chat";
import ChatState from "./state/ChatState";
import ChatNavButton from "./components/ChatNavButton";
import ChatDrawer from "./components/ChatDrawer";
import ChannelInviteNotification from "./components/ChannelInviteNotification";
import ChannelInviteDeclinedNotification from "./components/ChannelInviteDeclinedNotification";
import MessageFlaggedNotification from "./components/MessageFlaggedNotification";
import ModeratorPromotedNotification from "./components/ModeratorPromotedNotification";
import OwnershipTransferNotification from "./components/OwnershipTransferNotification";
import OwnershipTransferDeclinedNotification from "./components/OwnershipTransferDeclinedNotification";
import OwnershipInheritedNotification from "./components/OwnershipInheritedNotification";
import { realtimeBound, realtimeDelivered, realtimeLive } from "./realtime";
export { Channel, Message, Thread, Upload, MessageFlag, ChatState, chatState, ChatNavButton, ChatDrawer, ChannelInviteNotification, ChannelInviteDeclinedNotification, MessageFlaggedNotification, ModeratorPromotedNotification, OwnershipTransferNotification, OwnershipTransferDeclinedNotification, OwnershipInheritedNotification, realtimeBound, realtimeDelivered, realtimeLive, };
/**
 * Opens (or creates) a direct channel with a user and shows it.
 *
 * The endpoint reuses an existing conversation only while every participant is
 * still in it. Once someone leaves, a fresh start opens a new channel rather than
 * dragging them back into the history they walked away from.
 */
export declare function startDirectMessage(user: User): Promise<void>;
