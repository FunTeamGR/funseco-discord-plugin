<?php
namespace Funseco\Discord\Tests\Feature;

use Mockery;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Funseco\Discord\Tests\TestCase;
use Funseco\Discord\Gateway;
use Funseco\Discord\WebSocketClient;
use function React\Promise\reject;
use function React\Promise\resolve;

class FakeConn extends FakeDuplex
{
}

class GatewayTest extends TestCase
{
    /** @var FakeConn[] */
    private array $conns = [];
    /** @var array<int, array{0: float, 1: callable, 2: bool}> seconds, callback, periodic */
    private array $timers = [];
    private bool $connectFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['discord.enabled' => true, 'discord.token' => 'tok', 'discord.gateway.enabled' => true,
            'discord.gateway.url' => 'wss://gw.example/?v=10&encoding=json']);
    }

    private function loop(): LoopInterface
    {
        $loop = Mockery::mock(LoopInterface::class);
        $loop->shouldReceive('addTimer')->andReturnUsing(function ($s, $cb) {
            $this->timers[] = [$s, $cb, false];

            return Mockery::mock(TimerInterface::class);
        });
        $loop->shouldReceive('addPeriodicTimer')->andReturnUsing(function ($s, $cb) {
            $this->timers[] = [$s, $cb, true];

            return Mockery::mock(TimerInterface::class);
        });
        $loop->shouldReceive('cancelTimer')->andReturnNull();

        return $loop;
    }

    private function gateway(): Gateway
    {
        $connector = function (string $uri) {
            if ($this->connectFails) {
                return reject(new \RuntimeException('refused'));
            }
            $c = new FakeConn();
            $this->conns[] = $c;

            return resolve($c);
        };

        return new Gateway($connector, $this->loop());
    }

    private function handshake(FakeConn $c): void
    {
        preg_match('/Sec-WebSocket-Key: (\S+)/i', $c->written[0], $m);
        $accept = base64_encode(sha1($m[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $c->emit('data', ["HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n"]);
    }

    private static function frame(array $payload): string
    {
        $json = json_encode($payload);
        $len = strlen($json);

        return chr(0x81).($len < 126 ? chr($len) : chr(126).pack('n', $len)).$json;
    }

    /** Decodes the client (masked) text frames written after the handshake request. */
    private function sentFrames(FakeConn $c): array
    {
        $out = [];
        foreach (array_slice($c->written, 1) as $raw) {
            $len = ord($raw[1]) & 0x7F;
            $off = 2;
            if ($len === 126) {
                $len = unpack('n', substr($raw, 2, 2))[1];
                $off = 4;
            }
            $mask = substr($raw, $off, 4);
            $data = substr($raw, $off + 4, $len);
            for ($i = 0; $i < $len; $i++) {
                $data[$i] = $data[$i] ^ $mask[$i % 4];
            }
            $out[] = json_decode($data, true);
        }

        return $out;
    }

    private function fireLastTimer(): void
    {
        $once = array_values(array_filter($this->timers, fn ($t) => !$t[2]));
        $this->assertNotEmpty($once);
        ($once[count($once) - 1][1])();
    }

    public function test_hello_starts_heartbeat_and_identifies()
    {
        $g = $this->gateway();
        $g->start();
        $this->assertCount(1, $this->conns);
        $this->handshake($this->conns[0]);
        $this->conns[0]->emit('data', [self::frame(['op' => 10, 'd' => ['heartbeat_interval' => 41250]])]);

        $sent = $this->sentFrames($this->conns[0]);
        $this->assertSame(2, $sent[0]['op']);
        $this->assertSame('tok', $sent[0]['d']['token']);
        $this->assertNotEmpty(array_filter($this->timers, fn ($t) => $t[2] && abs($t[0] - 41.25) < 0.01));
    }

    public function test_dispatch_reaches_handlers_and_ready_marks_gateway_ready()
    {
        $g = $this->gateway();
        $seen = [];
        $g->on('MESSAGE_CREATE', function (array $d) use (&$seen) {
            $seen[] = $d;
        });
        $g->start();
        $this->handshake($this->conns[0]);
        $this->conns[0]->emit('data', [self::frame(['op' => 0, 's' => 1, 't' => 'READY', 'd' => ['session_id' => 'S']])]);
        $this->assertTrue($g->ready());
        $this->conns[0]->emit('data', [self::frame(['op' => 0, 's' => 2, 't' => 'MESSAGE_CREATE', 'd' => ['id' => '9']])]);
        $this->assertSame([['id' => '9']], $seen);
    }

    public function test_handler_exception_does_not_kill_gateway()
    {
        $g = $this->gateway();
        $g->on('MESSAGE_CREATE', fn () => throw new \RuntimeException('boom'));
        $ok = false;
        $g->on('MESSAGE_CREATE', function () use (&$ok) {
            $ok = true;
        });
        $g->start();
        $this->handshake($this->conns[0]);
        $this->conns[0]->emit('data', [self::frame(['op' => 0, 's' => 1, 't' => 'MESSAGE_CREATE', 'd' => []])]);
        $this->assertTrue($ok);
    }

    public function test_malformed_frames_are_logged_and_ignored()
    {
        $g = $this->gateway();
        $g->start();
        $this->handshake($this->conns[0]);
        $this->conns[0]->emit('data', [chr(0x81).chr(5).'{nope']);
        $this->conns[0]->emit('data', [self::frame(['op' => 0, 's' => 1, 't' => 'READY', 'd' => ['session_id' => 'S']])]);
        $this->assertTrue($g->ready());
    }

    public function test_drop_schedules_reconnect_with_resume()
    {
        $g = $this->gateway();
        $g->start();
        $this->handshake($this->conns[0]);
        $this->conns[0]->emit('data', [self::frame(['op' => 0, 's' => 7, 't' => 'READY', 'd' => ['session_id' => 'S1']])]);
        $this->conns[0]->emit('close');
        $this->assertFalse($g->ready());

        $this->fireLastTimer();
        $this->assertCount(2, $this->conns);
        $this->handshake($this->conns[1]);
        $this->conns[1]->emit('data', [self::frame(['op' => 10, 'd' => ['heartbeat_interval' => 1000]])]);
        $sent = $this->sentFrames($this->conns[1]);
        $this->assertSame(6, $sent[0]['op']);
        $this->assertSame('S1', $sent[0]['d']['session_id']);
        $this->assertSame(7, $sent[0]['d']['seq']);
    }

    public function test_connect_failure_backs_off_and_does_not_throw()
    {
        $this->connectFails = true;
        $g = $this->gateway();
        $g->start();
        $this->assertNotEmpty(array_filter($this->timers, fn ($t) => !$t[2]));
    }

    public function test_invalid_session_not_resumable_reidentifies()
    {
        $g = $this->gateway();
        $g->start();
        $this->handshake($this->conns[0]);
        $this->conns[0]->emit('data', [self::frame(['op' => 0, 's' => 7, 't' => 'READY', 'd' => ['session_id' => 'S1']])]);
        $this->conns[0]->emit('data', [self::frame(['op' => 9, 'd' => false])]);
        $this->conns[0]->emit('close');
        $this->fireLastTimer();
        $this->handshake($this->conns[1]);
        $this->conns[1]->emit('data', [self::frame(['op' => 10, 'd' => ['heartbeat_interval' => 1000]])]);
        $this->assertSame(2, $this->sentFrames($this->conns[1])[0]['op']);
    }

    public function test_bad_handshake_reconnects()
    {
        $g = $this->gateway();
        $g->start();
        $this->conns[0]->emit('data', ["HTTP/1.1 403 Forbidden\r\n\r\n"]);
        $this->assertNotEmpty(array_filter($this->timers, fn ($t) => !$t[2]));
    }

    public function test_no_handshake_response_times_out_and_reconnects()
    {
        $g = $this->gateway();
        $g->start();
        $timeout = array_values(array_filter($this->timers, fn ($t) => !$t[2] && $t[0] === 15));
        $this->assertCount(1, $timeout);
        $this->timers = [];
        $timeout[0][1]();
        $this->assertNotEmpty(array_filter($this->timers, fn ($t) => !$t[2]), 'backoff reconnect scheduled');
        $this->fireLastTimer();
        $this->assertCount(2, $this->conns);
    }

    public function test_handshake_without_hello_times_out_and_reconnects()
    {
        $g = $this->gateway();
        $g->start();
        $this->handshake($this->conns[0]);
        $timeout = array_values(array_filter($this->timers, fn ($t) => !$t[2] && $t[0] === 15));
        $this->timers = [];
        $timeout[0][1]();
        $this->fireLastTimer();
        $this->assertCount(2, $this->conns);
    }

    /** @dataProvider closeCodes */
    public function test_close_code_decides_whether_to_reconnect(int $code, bool $reconnects)
    {
        $g = $this->gateway();
        $g->start();
        $this->handshake($this->conns[0]);
        $this->timers = [];
        $this->conns[0]->emit('data', [chr(0x88).chr(2).pack('n', $code)]);
        $this->assertSame($reconnects, (bool) array_filter($this->timers, fn ($t) => !$t[2]));
        if ($reconnects) {
            $this->fireLastTimer();
            $this->assertCount(2, $this->conns);
        } else {
            $this->assertCount(1, $this->conns);
        }
    }

    public static function closeCodes(): array
    {
        return [[4004, false], [4010, false], [4011, false], [4012, false], [4013, false], [4014, false], [4000, true], [4009, true], [1000, true]];
    }

    public function test_fatal_close_code_logs_an_error()
    {
        \Illuminate\Support\Facades\Log::spy();
        $g = $this->gateway();
        $g->start();
        $this->handshake($this->conns[0]);
        $this->conns[0]->emit('data', [chr(0x88).chr(2).pack('n', 4004)]);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once();
    }

    public function test_message_handler_is_registered_once_across_startup_events()
    {
        $gateway = new Gateway(fn () => reject(new \RuntimeException('x')), $this->loop());
        $this->app->instance(Gateway::class, $gateway);
        config(['discord.enabled' => false]);
        \Illuminate\Support\Facades\Event::forget(\Funseco\Core\SharedKernel\Events\StartupEvent::class);   // only this plugin's listener
        (new \Funseco\Discord\DiscordServiceProvider($this->app))->boot();
        event(new \Funseco\Core\SharedKernel\Events\StartupEvent());
        event(new \Funseco\Core\SharedKernel\Events\StartupEvent());
        $handlers = (new \ReflectionProperty(Gateway::class, 'handlers'))->getValue($gateway);
        $this->assertCount(1, $handlers['MESSAGE_CREATE']);
    }

    public function test_websocket_client_joins_fragments_and_split_reads()
    {
        $c = new FakeConn();
        $ws = new WebSocketClient($c);
        $got = [];
        $ws->onMessage(function (string $m) use (&$got) {
            $got[] = $m;
        });
        $ws->beginFrames();
        $a = chr(0x01).chr(3).'abc';
        $b = chr(0x80).chr(2).'de';
        $c->emit('data', [$a.substr($b, 0, 1)]);
        $c->emit('data', [substr($b, 1)]);
        $this->assertSame(['abcde'], $got);
    }
}
