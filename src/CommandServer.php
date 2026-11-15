<?php
namespace Funseco\Discord;

use Illuminate\Support\Facades\Log;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use React\Stream\DuplexStreamInterface;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Chat\ChatService;

/**
 * Line-based JSON command server for outside processes (default 127.0.0.1:9999). Off by default,
 * refuses to start without a shared secret. One JSON object per line:
 * {"action": ping|status|chat|discord_message, "message": "...", "secret": "..."}.
 * Bad input gets an error reply; nothing here throws into the event loop.
 */
class CommandServer
{
    private const MAX_LINE = 65536;
    private const MAX_MESSAGE = 200;

    private ?SocketServer $server = null;

    public function __construct(private Relay $relay, private Gateway $gateway)
    {
    }

    /** Bind the listener. Returns false (and logs why) when it did not start. */
    public function listen(): bool
    {
        if ($this->server) {
            return true;
        }
        if (!config('discord.enabled') || !config('discord.command_server.enabled')) {
            return false;
        }
        if ((string) config('discord.command_server.secret') === '') {
            Log::warning('[Discord] command server not started: DISCORD_COMMAND_SECRET is empty');

            return false;
        }
        $addr = config('discord.command_server.host', '127.0.0.1').':'.config('discord.command_server.port', 9999);
        try {
            $this->server = new SocketServer($addr);
            $this->server->on('connection', fn (ConnectionInterface $c) => $this->accept($c));
            $this->server->on('error', fn (\Throwable $e) => Log::warning('[Discord] command server error: '.$e->getMessage()));
        } catch (\Throwable $e) {
            Log::warning("[Discord] command server cannot listen on $addr: ".$e->getMessage());
            $this->server = null;

            return false;
        }
        Log::info("[Discord] command server listening on $addr");

        return true;
    }

    public function stop(): void
    {
        $this->server?->close();
        $this->server = null;
    }

    public function accept(DuplexStreamInterface $conn): void
    {
        $buffer = '';
        $conn->on('error', fn (\Throwable $e) => Log::warning('[Discord] command connection error: '.$e->getMessage()));
        $conn->on('data', function (string $data) use (&$buffer, $conn) {
            try {
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line !== '') {
                        $conn->write(json_encode($this->handle($line))."\n");
                    }
                }
                if (strlen($buffer) > self::MAX_LINE) {
                    Log::warning('[Discord] command line too long, closing connection');
                    $buffer = '';
                    $conn->close();
                }
            } catch (\Throwable $e) {
                Log::warning('[Discord] command connection failed: '.$e->getMessage());
            }
        });
    }

    private function handle(string $line): array
    {
        $req = json_decode($line, true);
        if (!is_array($req) || array_is_list($req) || !isset($req['action'])) {
            Log::warning('[Discord] malformed command rejected');

            return ['ok' => false, 'error' => 'invalid request'];
        }
        $secret = (string) config('discord.command_server.secret');
        if ($secret === '' || !is_string($req['secret'] ?? null) || !hash_equals($secret, $req['secret'])) {
            Log::warning('[Discord] command with bad secret rejected');

            return ['ok' => false, 'error' => 'unauthorized'];
        }
        $message = is_string($req['message'] ?? null) ? $this->clean($req['message']) : '';

        try {
            switch ($req['action']) {
                case 'ping':
                    return ['ok' => true, 'pong' => true];
                case 'status':
                    return ['ok' => true, 'players' => CurrentPlayers::all()->count(), 'gateway' => $this->gateway->ready()];
                case 'chat':
                    if ($message === '') {
                        return ['ok' => false, 'error' => 'empty message'];
                    }
                    // double `$` so colour/format/link codes in the text are shown literally
                    app(ChatService::class)->sendMessage(str_replace('$', '$$', $message));

                    return ['ok' => true];
                case 'discord_message':
                    if ($message === '') {
                        return ['ok' => false, 'error' => 'empty message'];
                    }
                    $this->relay->send($message);

                    return ['ok' => true];
            }
        } catch (\Throwable $e) {
            Log::warning('[Discord] command failed: '.$e->getMessage());

            return ['ok' => false, 'error' => 'failed'];
        }

        return ['ok' => false, 'error' => 'unknown action'];
    }

    private function clean(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';

        return trim(mb_substr($text, 0, self::MAX_MESSAGE));
    }
}
