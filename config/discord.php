<?php
/*
 * Discord bridge and TMX map suggestions. Everything is OFF until DISCORD_ENABLED=true and a bot
 * token is set. REST polling is the baseline (suggestion channel read every poll_seconds); the optional
 * gateway websocket adds real-time message events.
 *
 * UNKNOWN in the owner's setup (the Discord client/settings files were unreadable): which of the
 * relay events were ever announced. The relay switches below are placeholders, all off.
 */
return [
    'enabled' => (bool) env('DISCORD_ENABLED', false),

    // Secrets: environment only.
    'token'       => env('DISCORD_TOKEN'),
    'webhook_url' => env('DISCORD_WEBHOOK_URL'),

    // Gateway websocket (real-time message events). Off by default.
    'gateway' => [
        'enabled' => (bool) env('DISCORD_GATEWAY', false),
        'url'     => 'wss://gateway.discord.gg/?v=10&encoding=json',
    ],

    // Line-JSON command server for outside processes. Off by default; needs a secret; localhost only by default.
    'command_server' => [
        'enabled' => (bool) env('DISCORD_COMMAND_SERVER', false),
        'host'    => env('DISCORD_COMMAND_HOST', '127.0.0.1'),
        'port'    => (int) env('DISCORD_COMMAND_PORT', 9999),
        'secret'  => env('DISCORD_COMMAND_SECRET'),
    ],

    'api_url' => 'https://discord.com/api/v10',
    'guild'   => env('DISCORD_GUILD'),

    // Relay (in-game -> Discord). Posts through the bot to relay_channel, or the webhook when no channel is set.
    'relay_channel' => env('DISCORD_RELAY_CHANNEL'),
    'relay' => [
        'chat'       => (bool) env('DISCORD_RELAY_CHAT', false),
        'join_leave' => (bool) env('DISCORD_RELAY_JOIN_LEAVE', false),
        'new_map'    => (bool) env('DISCORD_RELAY_NEW_MAP', false),
        // Discord -> in-game: messages in relay_channel (from humans) are shown in server chat.
        'from_discord' => (bool) env('DISCORD_RELAY_FROM_DISCORD', false),
        'max_length'   => 200,
    ],

    // Map suggestions: a TMX link posted in this channel is queued; a thumbs-up from an approver adds the map.
    'suggest_channel' => env('DISCORD_SUGGEST_CHANNEL', '1048625856651726890'),
    'admin_roles'     => array_filter(array_map('trim', explode(',', env('DISCORD_ADMIN_ROLES', 'Admin,Owner')))),
    'poll_seconds'    => 10,
    // Pending suggestions whose reactions are checked per poll (newest first).
    'check_pending'   => 25,
    // After a failed add, retry only after this many seconds or when more thumbs-ups arrive.
    'retry_backoff_seconds' => 600,
    // Nickname/login of the synthetic requester of an approved add (login empty = server.login).
    'bot_nickname' => '$s$o$0DDFunBot',
    'bot_login'    => '',
];
