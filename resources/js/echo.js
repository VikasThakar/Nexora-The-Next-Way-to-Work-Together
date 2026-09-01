import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/*
 * Realtime client.
 *
 * Only started when a Reverb key was compiled into the bundle. The application
 * is designed to work identically without it — boards and tickets simply do not
 * refresh by themselves — so a deployment with no websocket server must not
 * throw here, and must not leave a half-initialised window.Echo behind that
 * Livewire would then try to subscribe through.
 *
 * Every channel this application uses is private. Echo authorizes each
 * subscription against POST /broadcasting/auth using the session cookie, and
 * routes/channels.php decides. The browser cannot join a channel by guessing
 * its name, and nothing on the wire carries board content in any case: events
 * are bare signals telling the page to re-ask the server.
 */
const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
