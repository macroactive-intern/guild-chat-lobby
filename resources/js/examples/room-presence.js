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
        console.log('MessageSent:', message);
    },
    onUserTyping: (typing) => {
        console.log('UserTyping:', typing);
    },
});

export function stopListening(guildId = 1, roomId = 1) {
    roomChannel.stopListening('MessageSent');
    roomChannel.stopListening('.UserTyping');
    echo.leave(`guild.${guildId}.room.${roomId}`);
}
