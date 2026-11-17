<?php
namespace Funseco\Discord;

use Illuminate\Support\Facades\Log;
use Funseco\Core\Services\Chat\ChatService;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\PlayerChatted;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerDisconnected;

/** In-game events to Discord (each switch in discord.relay, all off by default). */
class Relay
{
    public function __construct(private DiscordApi $api)
    {
    }

    public function chat(PlayerChatted $e): void
    {
        config('discord.relay.chat') && $this->send('**'.self::clean($e->player->nickname).'**: '.self::clean($e->text));
    }

    public function joined(PlayerConnected $e): void
    {
        config('discord.relay.join_leave') && $this->send(self::clean($e->player->nickname).' joined the server');
    }

    public function left(PlayerDisconnected $e): void
    {
        config('discord.relay.join_leave') && $this->send(self::clean($e->player->nickname).' left the server');
    }

    public function mapStarted(MapStarted $e): void
    {
        config('discord.relay.new_map') && $this->send('New map: **'.self::clean($e->map->name).'** by '.self::clean($e->map->author));
    }

    /** Discord MESSAGE_CREATE in the relay channel -> in-game chat. */
    public function incoming(array $m): void
    {
        $channel = (string) config('discord.relay_channel');
        if (!config('discord.enabled') || !config('discord.relay.from_discord') || $channel === ''
            || ($m['channel_id'] ?? null) !== $channel) {
            return;
        }
        // bots and webhooks include our own relayed chat: skip them to avoid echo loops
        if (($m['author']['bot'] ?? false) || isset($m['webhook_id'])) {
            return;
        }
        $text = (string) ($m['content'] ?? '');
        $text = preg_replace('/<a?:(\w+):\d+>/', ':$1:', $text);
        $text = preg_replace('/<@[!&]?\d+>|<#\d+>/', '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return;
        }
        $max = (int) config('discord.relay.max_length', 200);
        $text = mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
        $name = $m['member']['nick'] ?? $m['author']['global_name'] ?? $m['author']['username'] ?? 'discord';

        // double `$` so colour/format/link codes are shown literally
        $line = '{#grey}[{#message}Discord{#grey}] {#highlite}'.str_replace('$', '$$', $name)
            .'{#grey}: {#server}'.str_replace('$', '$$', $text);
        app(ChatService::class)->sendMessage(format_colors($line));
    }

    public function send(string $text): void
    {
        if (!config('discord.enabled')) {
            return;
        }
        $channel = (string) config('discord.relay_channel');
        $promise = $channel !== '' && $this->api->configured()
            ? $this->api->post($channel, $text)
            : ((string) config('discord.webhook_url') !== '' ? $this->api->webhook($text) : null);

        $promise?->then(null, fn (\Throwable $e) => Log::warning('[Discord] relay failed: '.$e->getMessage()));
    }

    private static function clean(?string $text): string
    {
        return Suggestions::strip((string) $text);
    }
}
