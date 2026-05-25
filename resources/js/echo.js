import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

export function createEcho({ token, authEndpoint = '/broadcasting/auth' } = {}) {
    return new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint,
        auth: {
            headers: {
                Accept: 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
        },
    });
}

export function roomPresenceChannelName(guildId, roomId) {
    return `guild.${guildId}.room.${roomId}`;
}

export function subscribeToRoomPresence({
    echo = window.Echo,
    guildId,
    roomId,
    onHere = () => {},
    onJoining = () => {},
    onLeaving = () => {},
    onMessageDeleted = () => {},
    onMessageEdited = () => {},
    onMessageSent = () => {},
    onReactionAdded = () => {},
    onRoomStatusUpdated = () => {},
    onUserTyping = () => {},
}) {
    return echo
        .join(roomPresenceChannelName(guildId, roomId))
        .here(onHere)
        .joining(onJoining)
        .leaving(onLeaving)
        .listen('.message.sent', onMessageSent)
        .listen('.message.edited', onMessageEdited)
        .listen('.message.deleted', onMessageDeleted)
        .listen('.reaction.added', onReactionAdded)
        .listen('.room.status.updated', onRoomStatusUpdated)
        .listen('.user.typing', onUserTyping);
}

window.Echo = createEcho();
