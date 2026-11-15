<?php
namespace Funseco\Discord;

use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;
use Funseco\Core\SharedKernel\Events\EverySecondEvent;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\PlayerChatted;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerDisconnected;

class DiscordServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    /** StartupEvent fires after every XMLRPC reconnect; the gateway handler must be added only once. */
    private bool $messageHandlerAdded = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/discord.php', 'discord');
        $this->publishPluginConfig(__DIR__.'/..');
        $this->app->singleton(DiscordApi::class);
        $this->app->singleton(Suggestions::class);
        $this->app->singleton(Relay::class);
        $this->app->singleton(Gateway::class);
        $this->app->singleton(CommandServer::class);

        // restart: the gateway and command server start once at StartupEvent. The relay, webhook, guild,
        // channels, roles and the command server's secret (checked per connection) are read lazily and apply live.
        $this->app->make(SettingsRegistry::class)->declare('discord', 'discord', [
            Setting::bool('enabled', 'Discord enabled', restart: true),
            Setting::string('token', 'Bot token', secret: true, restart: true),
            Setting::url('webhook_url', 'Relay webhook URL', secret: true),
            Setting::bool('gateway.enabled', 'Gateway connection', restart: true),
            Setting::bool('command_server.enabled', 'Command server', restart: true),
            Setting::string('command_server.host', 'Command server host', restart: true),
            Setting::int('command_server.port', 'Command server port', min: 1, max: 65535, restart: true),
            Setting::string('command_server.secret', 'Command server secret', secret: true),
            Setting::string('guild', 'Guild id'),
            Setting::string('relay_channel', 'Relay channel id'),
            Setting::bool('relay.chat', 'Relay chat'),
            Setting::bool('relay.join_leave', 'Relay joins and leaves'),
            Setting::bool('relay.new_map', 'Relay new map'),
            Setting::bool('relay.from_discord', 'Relay Discord messages in game'),
            Setting::string('suggest_channel', 'Map suggestion channel id'),
            Setting::list('admin_roles', 'Admin role names'),
        ]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Event::listen(StartupEvent::class, function () {
            $gateway = app(Gateway::class);
            if (!$this->messageHandlerAdded) {
                $this->messageHandlerAdded = true;
                $gateway->on('MESSAGE_CREATE', function (array $m) {
                    app(Relay::class)->incoming($m);
                    $channel = (string) config('discord.suggest_channel');
                    if ($channel !== '' && ($m['channel_id'] ?? null) === $channel && isset($m['id'])) {
                        app(Suggestions::class)->handleMessage($channel, $m);
                    }
                });
            }
            $gateway->start();
            app(CommandServer::class)->listen();
        });
        Event::listen(EverySecondEvent::class, fn () => app(Suggestions::class)->tick());
        Event::listen(PlayerChatted::class, [Relay::class, 'chat']);
        Event::listen(PlayerConnected::class, [Relay::class, 'joined']);
        Event::listen(PlayerDisconnected::class, [Relay::class, 'left']);
        Event::listen(MapStarted::class, [Relay::class, 'mapStarted']);
    }
}
