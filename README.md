# Guild Chat Lobby

API-only Laravel 12 backend with Sanctum authentication.

## Stack

- Laravel 12
- Sanctum API token authentication
- Reverb WebSocket broadcasting
- SQLite by default
- MySQL supported through environment configuration

## Setup

Install dependencies:

```bash
composer install
```

Create the local environment file and app key:

```bash
cp .env.example .env
php artisan key:generate
```

For SQLite, create the database file:

```bash
touch database/database.sqlite
```

For MySQL, update these values in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=guild_chat_lobby
DB_USERNAME=root
DB_PASSWORD=
```

Run migrations:

```bash
php artisan migrate
```

Start the API server:

```bash
php artisan serve
```

The API will be available at `http://localhost:8000`.

## Broadcasting

Broadcasting uses Laravel Reverb locally:

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database
```

Run the API server, Reverb server, and queue worker in separate terminals:

```bash
php artisan serve
php artisan reverb:start --debug
php artisan queue:work
```

Queued broadcast events retry up to three times with a short backoff. Failed broadcast jobs are stored in `failed_jobs` and monitored by the scheduler:

```bash
php artisan schedule:work
php artisan chat:broadcast-failures
```

Set `BROADCAST_FAILED_JOB_ALERT_THRESHOLD` to control when the monitor logs a critical alert and exits with a failure status.

Composer script shortcuts are also available:

```bash
composer run dev
composer run reverb
composer run queue
```

Use this frontend environment shape in the consuming Vite app:

```dotenv
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

The generated `resources/js/echo.js` shows the matching Laravel Echo client setup. Since this repository is API-only, frontend packages are not installed here; install `laravel-echo` and `pusher-js` in the frontend app that connects to this API.

### Echo Presence Setup

Install frontend dependencies in the consuming Vite app:

```bash
npm install laravel-echo pusher-js
```

`resources/js/echo.js` exports:

- `createEcho({ token })` for Reverb + Sanctum bearer token auth.
- `subscribeToRoomPresence(...)` for presence channel subscriptions.
- `roomPresenceChannelName(guildId, roomId)` for `guild.{guildId}.room.{roomId}`.

Presence subscriptions support:

- `here(users)` for the current online room members.
- `joining(user)` when a member enters the room channel.
- `leaving(user)` when a member exits the room channel.

The room presence channel listens for:

- `message.sent`
- `message.edited`
- `message.deleted`
- `presence.updated`
- `reaction.added`
- `room.status.updated`
- `user.typing`

Plain JavaScript example:

```js
import { createEcho, subscribeToRoomPresence } from './echo';

const echo = createEcho({ token: localStorage.getItem('api_token') });

subscribeToRoomPresence({
    echo,
    guildId: 1,
    roomId: 1,
    onHere: (users) => console.log(users),
    onJoining: (user) => console.log('joining', user),
    onLeaving: (user) => console.log('leaving', user),
    onMessageSent: (message) => console.log('message.sent', message),
    onMessageEdited: (message) => console.log('message.edited', message),
    onMessageDeleted: (message) => console.log('message.deleted', message),
    onPresenceUpdated: (presence) => console.log('presence.updated', presence),
    onReactionAdded: (reaction) => console.log('reaction.added', reaction),
    onRoomStatusUpdated: (room) => console.log('room.status.updated', room),
    onUserTyping: (typing) => console.log('user.typing', typing),
});
```

Presence cache updates are pushed with `presence.updated` when a member first heartbeats online or explicitly clears their heartbeat with `DELETE /api/rooms/{room}/heartbeat`. The configured presence TTL remains a fallback for abandoned sessions.

Vue integration example:

```js
import { computed } from 'vue';
import { useRoomPresence } from './examples/useRoomPresence';

const guildId = computed(() => 1);
const roomId = computed(() => 1);

const { onlineUsers, messages, typingUsers } = useRoomPresence({
    guildId,
    roomId,
    token: localStorage.getItem('api_token'),
});
```

## Reverb Environment

`REVERB_APP_ID` identifies the Reverb application configured in `config/reverb.php`.

`REVERB_APP_KEY` is the public application key clients use when opening WebSocket connections.

`REVERB_APP_SECRET` is the private signing secret Laravel uses when publishing and authenticating broadcast requests.

`REVERB_HOST` is the hostname Laravel and clients use to reach the Reverb server.

`REVERB_PORT` is the public WebSocket port. Local development uses `8080`.

`REVERB_SCHEME` controls whether clients connect over `http/ws` or `https/wss`; local development uses `http`.

`VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, and `VITE_REVERB_SCHEME` expose the matching Reverb connection values to a Vite-powered frontend.

## API

The starter API route is protected with Sanctum:

```http
GET /api/user
Authorization: Bearer <token>
```
