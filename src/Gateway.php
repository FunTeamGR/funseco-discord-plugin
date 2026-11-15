<?php
namespace Funseco\Discord;

use Illuminate\Support\Facades\Log;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\Connector;
use React\Stream\DuplexStreamInterface;

/**
 * Discord gateway (websocket) session on the controller's event loop: hello/heartbeat, identify,
 * resume, reconnect with backoff. Dispatch events go to handlers registered with on(). Nothing here
 * throws into the loop: bad frames, handler errors and connection failures are logged.
 */
class Gateway
{
    /** Seconds from TCP connect to op 10 Hello before the connection is given up. */
    private const HELLO_TIMEOUT = 15;
    /** Close codes that a reconnect cannot fix: bad token, invalid or disallowed intents. */
    private const FATAL_CLOSE = [4004, 4010, 4011, 4012, 4013, 4014];

    private const INTENTS = (1 << 9) | (1 << 10) | (1 << 15); // guild messages, reactions, message content

    /** @var callable(string): \React\Promise\PromiseInterface */
    private $connector;
    private LoopInterface $loop;
    /** @var array<string, callable[]> */
    private array $handlers = [];
    private ?WebSocketClient $ws = null;
    private int $generation = 0;
    private bool $running = false;
    private bool $ready = false;
    private ?int $seq = null;
    private ?string $sessionId = null;
    private ?string $resumeUrl = null;
    private bool $acked = true;
    private int $attempts = 0;
    private ?TimerInterface $heartbeat = null;
    private ?TimerInterface $reconnectTimer = null;
    private ?TimerInterface $helloTimer = null;

    public function __construct(?callable $connector = null, ?LoopInterface $loop = null)
    {
        $this->loop = $loop ?? Loop::get();
        $this->connector = $connector ?? function (string $uri) {
            return (new Connector([], $this->loop))->connect($uri);
        };
    }

    public function on(string $event, callable $handler): void
    {
        $this->handlers[$event][] = $handler;
    }

    public function ready(): bool
    {
        return $this->ready;
    }

    public function enabled(): bool
    {
        return (bool) config('discord.enabled') && (bool) config('discord.gateway.enabled') && (string) config('discord.token') !== '';
    }

    public function start(): void
    {
        if ($this->running || !$this->enabled()) {
            return;
        }
        $this->running = true;
        $this->connect();
    }

    public function stop(): void
    {
        $this->running = false;
        $this->teardown();
        $this->reconnectTimer && $this->loop->cancelTimer($this->reconnectTimer);
        $this->reconnectTimer = null;
        $this->ws?->close();
    }

    private function connect(): void
    {
        $this->reconnectTimer = null;
        $gen = ++$this->generation;
        $url = $this->sessionId && $this->resumeUrl ? $this->resumeUrl : (string) config('discord.gateway.url');
        $url = $this->withQuery($url);
        $parts = parse_url($url) ?: [];
        $host = $parts['host'] ?? '';
        $tls = ($parts['scheme'] ?? 'wss') === 'wss';
        $uri = ($tls ? 'tls' : 'tcp').'://'.$host.':'.($parts['port'] ?? ($tls ? 443 : 80));

        try {
            $promise = ($this->connector)($uri);
        } catch (\Throwable $e) {
            $this->failed($gen, $e);

            return;
        }
        $promise->then(function (DuplexStreamInterface $stream) use ($gen, $url) {
            if ($gen !== $this->generation || !$this->running) {
                $stream->close();

                return;
            }
            $ws = $this->ws = new WebSocketClient($stream);
            $this->helloTimer = $this->loop->addTimer(self::HELLO_TIMEOUT, fn () => $this->failed($gen, new \RuntimeException('no handshake/hello within '.self::HELLO_TIMEOUT.'s')));
            $ws->onClose(fn () => $this->dropped($gen, $ws->closeCode()));
            $ws->onMessage(fn (string $m) => $this->frame($gen, $m));
            $ws->handshake($url)->then(null, fn (\Throwable $e) => $this->failed($gen, $e));
        }, fn (\Throwable $e) => $this->failed($gen, $e));
    }

    private function withQuery(string $url): string
    {
        return str_contains($url, '?') ? $url : rtrim($url, '/').'/?v=10&encoding=json';
    }

    private function failed(int $gen, \Throwable $e): void
    {
        if ($gen !== $this->generation) {
            return;
        }
        Log::warning('[Discord] gateway connection failed: '.$e->getMessage());
        $this->ws?->close();
        $this->ws = null;
        $this->dropped($gen);
    }

    private function dropped(int $gen, ?int $code = null): void
    {
        if ($gen !== $this->generation) {
            return;
        }
        $this->generation++; // later events of the dead connection are stale
        $this->ready = false;
        $this->ws = null;
        $this->teardown();
        if ($code !== null && in_array($code, self::FATAL_CLOSE, true)) {
            $this->running = false;
            Log::error("[Discord] gateway closed with fatal code {$code} (bad token or intents), not reconnecting");

            return;
        }
        if (!$this->running || $this->reconnectTimer) {
            return;
        }
        $delay = min(60, 2 ** min($this->attempts++, 6));
        Log::warning("[Discord] gateway down, reconnecting in {$delay}s");
        $this->reconnectTimer = $this->loop->addTimer($delay, fn () => $this->running && $this->connect());
    }

    private function teardown(): void
    {
        $this->heartbeat && $this->loop->cancelTimer($this->heartbeat);
        $this->heartbeat = null;
        $this->helloTimer && $this->loop->cancelTimer($this->helloTimer);
        $this->helloTimer = null;
    }

    private function frame(int $gen, string $raw): void
    {
        if ($gen !== $this->generation) {
            return;
        }
        $msg = json_decode($raw, true);
        if (!is_array($msg) || !isset($msg['op'])) {
            Log::warning('[Discord] malformed gateway frame ignored');

            return;
        }
        if (isset($msg['s']) && is_int($msg['s'])) {
            $this->seq = $msg['s'];
        }

        switch ($msg['op']) {
            case 10:
                $this->hello($gen, (int) ($msg['d']['heartbeat_interval'] ?? 41250));
                break;
            case 11:
                $this->acked = true;
                break;
            case 1:
                $this->beat();
                break;
            case 7:
                $this->ws?->close();
                break;
            case 9:
                if (!($msg['d'] ?? false)) {
                    $this->sessionId = null;
                    $this->resumeUrl = null;
                    $this->seq = null;
                }
                $this->ws?->close();
                break;
            case 0:
                $this->dispatch((string) ($msg['t'] ?? ''), is_array($msg['d'] ?? null) ? $msg['d'] : []);
                break;
        }
    }

    private function hello(int $gen, int $intervalMs): void
    {
        $this->teardown();
        $this->acked = true;
        $seconds = max(1, $intervalMs) / 1000;
        $this->loop->addTimer($seconds * mt_rand(0, 1000) / 1000, fn () => $gen === $this->generation && $this->beat());
        $this->heartbeat = $this->loop->addPeriodicTimer($seconds, fn () => $gen === $this->generation && $this->beat());

        if ($this->sessionId !== null && $this->seq !== null) {
            $this->sendOp(6, ['token' => (string) config('discord.token'), 'session_id' => $this->sessionId, 'seq' => $this->seq]);

            return;
        }
        $this->sendOp(2, [
            'token' => (string) config('discord.token'),
            'intents' => self::INTENTS,
            'properties' => ['os' => PHP_OS_FAMILY, 'browser' => 'funseco', 'device' => 'funseco'],
        ]);
    }

    private function beat(): void
    {
        if (!$this->acked) {
            Log::warning('[Discord] gateway heartbeat not acknowledged, reconnecting');
            $this->ws?->close();

            return;
        }
        $this->acked = false;
        $this->sendOp(1, $this->seq);
    }

    private function sendOp(int $op, mixed $d): void
    {
        $this->ws?->send(json_encode(['op' => $op, 'd' => $d]));
    }

    private function dispatch(string $event, array $data): void
    {
        if ($event === 'READY') {
            $this->sessionId = (string) ($data['session_id'] ?? '') ?: null;
            $this->resumeUrl = (string) ($data['resume_gateway_url'] ?? '') ?: null;
            $this->ready = true;
            $this->attempts = 0;
        } elseif ($event === 'RESUMED') {
            $this->ready = true;
            $this->attempts = 0;
        }
        foreach ($this->handlers[$event] ?? [] as $handler) {
            try {
                $handler($data);
            } catch (\Throwable $e) {
                Log::warning("[Discord] gateway handler for $event failed: ".$e->getMessage());
            }
        }
    }
}
