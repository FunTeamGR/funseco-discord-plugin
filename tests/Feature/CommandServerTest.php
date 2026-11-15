<?php
namespace Funseco\Discord\Tests\Feature;

use Mockery;
use Funseco\Discord\Tests\TestCase;
use Funseco\Core\Services\Chat\ChatService;
use Funseco\Discord\CommandServer;
use Funseco\Discord\Relay;

class CmdConn extends FakeDuplex
{
}

class CommandServerTest extends TestCase
{
    private array $chat = [];
    private array $relayed = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['discord.enabled' => true, 'discord.command_server.enabled' => true, 'discord.command_server.secret' => 's3cret']);
        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('sendMessage')->andReturnUsing(function ($m) {
            $this->chat[] = $m;
        });
        $this->app->instance(ChatService::class, $chat);
        $relay = Mockery::mock(Relay::class);
        $relay->shouldReceive('send')->andReturnUsing(function ($m) {
            $this->relayed[] = $m;
        });
        $this->app->instance(Relay::class, $relay);
    }

    private function conn(): CmdConn
    {
        $c = new CmdConn();
        app(CommandServer::class)->accept($c);

        return $c;
    }

    private static function line(array $a): string
    {
        return json_encode($a)."\n";
    }

    public function test_ping_with_secret()
    {
        $c = $this->conn();
        $c->emit('data', [self::line(['action' => 'ping', 'secret' => 's3cret'])]);
        $this->assertSame(['ok' => true, 'pong' => true], $c->replies()[0]);
    }

    public function test_wrong_or_missing_secret_is_refused_and_does_nothing()
    {
        $c = $this->conn();
        $c->emit('data', [self::line(['action' => 'chat', 'message' => 'hi', 'secret' => 'nope'])]);
        $c->emit('data', [self::line(['action' => 'chat', 'message' => 'hi'])]);
        $this->assertFalse($c->replies()[0]['ok']);
        $this->assertFalse($c->replies()[1]['ok']);
        $this->assertSame([], $this->chat);
    }

    public function test_chat_and_discord_message()
    {
        $c = $this->conn();
        $c->emit('data', [self::line(['action' => 'chat', 'message' => "hello\x07 there", 'secret' => 's3cret'])]);
        $c->emit('data', [self::line(['action' => 'discord_message', 'message' => 'to discord', 'secret' => 's3cret'])]);
        $this->assertSame(['hello there'], $this->chat);
        $this->assertSame(['to discord'], $this->relayed);
        $this->assertTrue($c->replies()[1]['ok']);
    }

    public function test_chat_doubles_dollar_codes()
    {
        $c = $this->conn();
        $c->emit('data', [self::line(['action' => 'chat', 'message' => '$f00red $l[http://x]link', 'secret' => 's3cret'])]);
        $this->assertSame(['$$f00red $$l[http://x]link'], $this->chat);
    }

    public function test_status()
    {
        $c = $this->conn();
        $c->emit('data', [self::line(['action' => 'status', 'secret' => 's3cret'])]);
        $r = $c->replies()[0];
        $this->assertTrue($r['ok']);
        $this->assertArrayHasKey('players', $r);
    }

    public function test_malformed_json_unknown_action_and_split_lines_do_not_kill()
    {
        $c = $this->conn();
        $c->emit('data', ["{not json\n"]);
        $c->emit('data', ["[1,2]\n"]);
        $c->emit('data', [self::line(['action' => 'nope', 'secret' => 's3cret'])]);
        $half = self::line(['action' => 'ping', 'secret' => 's3cret']);
        $c->emit('data', [substr($half, 0, 10)]);
        $c->emit('data', [substr($half, 10)]);
        $r = $c->replies();
        $this->assertFalse($r[0]['ok']);
        $this->assertFalse($r[1]['ok']);
        $this->assertFalse($r[2]['ok']);
        $this->assertTrue($r[3]['ok']);
    }

    public function test_oversized_line_closes_connection()
    {
        $c = $this->conn();
        $c->emit('data', [str_repeat('a', 70000)]);
        $this->assertTrue($c->closedByServer);
    }

    public function test_handler_error_is_contained()
    {
        $relay = Mockery::mock(Relay::class);
        $relay->shouldReceive('send')->andThrow(new \RuntimeException('x'));
        $this->app->instance(Relay::class, $relay);
        $this->app->forgetInstance(CommandServer::class);
        $c = $this->conn();
        $c->emit('data', [self::line(['action' => 'discord_message', 'message' => 'm', 'secret' => 's3cret'])]);
        $this->assertFalse($c->replies()[0]['ok']);
    }

    public function test_listen_refuses_without_secret_and_when_disabled()
    {
        config(['discord.command_server.secret' => '']);
        $this->assertFalse(app(CommandServer::class)->listen());
        config(['discord.command_server.secret' => 'x', 'discord.command_server.enabled' => false]);
        $this->assertFalse(app(CommandServer::class)->listen());
    }

    public function test_listen_bind_failure_is_logged_not_thrown()
    {
        config(['discord.command_server.host' => '999.999.999.999', 'discord.command_server.port' => 1]);
        $this->assertFalse(app(CommandServer::class)->listen());
    }
}
