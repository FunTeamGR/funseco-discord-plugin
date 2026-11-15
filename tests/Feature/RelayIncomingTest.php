<?php
namespace Funseco\Discord\Tests\Feature;

use Mockery;
use Funseco\Discord\Tests\TestCase;
use Funseco\Core\Services\Chat\ChatService;
use Funseco\Discord\Relay;

class RelayIncomingTest extends TestCase
{
    private array $chat = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['discord.enabled' => true, 'discord.relay_channel' => 'R1', 'discord.relay.from_discord' => true]);
        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('sendMessage')->andReturnUsing(function ($m) {
            $this->chat[] = $m;
        });
        $this->app->instance(ChatService::class, $chat);
    }

    private function msg(array $o = []): array
    {
        return array_merge(['channel_id' => 'R1', 'content' => 'hello', 'author' => ['username' => 'bob']], $o);
    }

    public function test_relays_human_message(): void
    {
        app(Relay::class)->incoming($this->msg(['member' => ['nick' => 'Bobby']]));
        $this->assertCount(1, $this->chat);
        $this->assertStringContainsString('Bobby', $this->chat[0]);
        $this->assertStringContainsString('hello', $this->chat[0]);
    }

    public function test_ignores_bots_webhooks_other_channels_and_empty(): void
    {
        $r = app(Relay::class);
        $r->incoming($this->msg(['author' => ['username' => 'bot', 'bot' => true]]));
        $r->incoming($this->msg(['webhook_id' => '1']));
        $r->incoming($this->msg(['channel_id' => 'X']));
        $r->incoming($this->msg(['content' => '  <@123> ']));
        $this->assertSame([], $this->chat);
    }

    public function test_disabled_by_default_switch(): void
    {
        config(['discord.relay.from_discord' => false]);
        app(Relay::class)->incoming($this->msg());
        $this->assertSame([], $this->chat);
    }

    public function test_escapes_format_codes_and_mentions(): void
    {
        app(Relay::class)->incoming($this->msg(['content' => '$f00red <@9> <:pog:5>']));
        $this->assertStringContainsString('$$f00red :pog:', $this->chat[0]);
        $this->assertStringNotContainsString('<@', $this->chat[0]);
    }
}
