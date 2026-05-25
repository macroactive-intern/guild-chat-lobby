import { createEcho, subscribeToRoomPresence } from '../echo';

const echo = createEcho({
    token: window.localStorage.getItem('api_token'),
});

const roomChannel = subscribeToRoomPresence({
    echo,
    guildId: 1,
    roomId: 1,
    onHere: (users) => {
        console.log('Online users:', users);
    },
    onJoining: (user) => {
        console.log('User joined:', user);
    },
    onLeaving: (user) => {
        console.log('User left:', user);
    },
    onMessageSent: (message) => {
        console.log('message.sent:', message);
    },
    onMessageEdited: (message) => {
        console.log('message.edited:', message);
    },
    onMessageDeleted: (message) => {
        console.log('message.deleted:', message);
    },
    onReactionAdded: (reaction) => {
        console.log('reaction.added:', reaction);
    },
    onRoomStatusUpdated: (room) => {
        console.log('room.status.updated:', room);
    },
    onUserTyping: (typing) => {
        console.log('user.typing:', typing);
    },
});

export function stopListening(guildId = 1, roomId = 1) {
    roomChannel.stopListening('.message.sent');
    roomChannel.stopListening('.message.edited');
    roomChannel.stopListening('.message.deleted');
    roomChannel.stopListening('.reaction.added');
    roomChannel.stopListening('.room.status.updated');
    roomChannel.stopListening('.user.typing');
    echo.leave(`guild.${guildId}.room.${roomId}`);
}
