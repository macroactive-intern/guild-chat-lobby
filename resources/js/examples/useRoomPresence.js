import { onBeforeUnmount, ref, watch } from 'vue';
import { createEcho, roomPresenceChannelName, subscribeToRoomPresence } from '../echo';

export function useRoomPresence({ guildId, roomId, token }) {
    const echo = createEcho({ token });
    const onlineUsers = ref([]);
    const messages = ref([]);
    const typingUsers = ref(new Map());

    let channel = null;

    const stop = () => {
        if (!channel) {
            return;
        }

        channel.stopListening('.message.sent');
        channel.stopListening('.message.edited');
        channel.stopListening('.message.deleted');
        channel.stopListening('.presence.updated');
        channel.stopListening('.reaction.added');
        channel.stopListening('.room.status.updated');
        channel.stopListening('.user.typing');
        echo.leave(roomPresenceChannelName(guildId.value, roomId.value));
        channel = null;
    };

    watch(
        [guildId, roomId],
        ([currentGuildId, currentRoomId]) => {
            stop();

            channel = subscribeToRoomPresence({
                echo,
                guildId: currentGuildId,
                roomId: currentRoomId,
                onHere: (users) => {
                    onlineUsers.value = users;
                },
                onJoining: (user) => {
                    onlineUsers.value = [...onlineUsers.value, user];
                },
                onLeaving: (user) => {
                    onlineUsers.value = onlineUsers.value.filter((onlineUser) => onlineUser.id !== user.id);
                    typingUsers.value.delete(user.id);
                },
                onMessageSent: (message) => {
                    messages.value = [message, ...messages.value];
                },
                onMessageEdited: (message) => {
                    messages.value = messages.value.map((currentMessage) =>
                        currentMessage.id === message.id ? { ...currentMessage, ...message } : currentMessage,
                    );
                },
                onMessageDeleted: (message) => {
                    messages.value = messages.value.map((currentMessage) =>
                        currentMessage.id === message.id ? { ...currentMessage, ...message, is_deleted: true } : currentMessage,
                    );
                },
                onPresenceUpdated: (presence) => {
                    onlineUsers.value = presence.online_members;
                },
                onReactionAdded: (reaction) => {
                    messages.value = messages.value.map((currentMessage) =>
                        currentMessage.id === reaction.message_id
                            ? { ...currentMessage, reactions: [...(currentMessage.reactions ?? []), reaction] }
                            : currentMessage,
                    );
                },
                onUserTyping: (typing) => {
                    typingUsers.value.set(typing.user_id, typing);
                    setTimeout(() => typingUsers.value.delete(typing.user_id), 3000);
                },
            });
        },
        { immediate: true },
    );

    onBeforeUnmount(stop);

    return {
        onlineUsers,
        messages,
        typingUsers,
        stop,
    };
}
