<?php
namespace Funseco\Discord\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use React\Http\Message\Response;
use Funseco\Discord\Tests\TestCase;
use Funseco\Core\Services\Http\AsyncHttpClient;
use Funseco\Core\SharedKernel\Events\TrackAddRequested;
use Funseco\Core\SharedKernel\Values\TrackAddResult;
use Funseco\Discord\Suggestion;
use Funseco\Discord\Suggestions;
use Funseco\Discord\TmxLink;
use function React\Promise\reject;
use function React\Promise\resolve;

class DiscordTest extends TestCase
{
    use RefreshDatabase;

    private const UP = "\u{1F44D}";

    /** @var array<int, array{0: string, 1: string, 2: ?array}> method, path, json body */
    private array $calls = [];
    /** @var array<string, mixed> "METHOD /path-prefix" => response (array or Closure) */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'discord.enabled' => true, 'discord.token' => 'secret', 'discord.guild' => 'G1',
            'discord.suggest_channel' => 'C1', 'discord.poll_seconds' => 1, 'discord.admin_roles' => ['Admin', 'Owner'],
            'server.login' => 'srv',
        ]);
        $http = Mockery::mock(AsyncHttpClient::class);
        $http->shouldReceive('request')->andReturnUsing(function (string $method, string $url, array $headers = [], string $body = '') {
            $path = substr($url, strlen('https://discord.com/api/v10'));
            $this->calls[] = [$method, $path, $body === '' ? null : json_decode($body, true), $headers];
            foreach ($this->routes as $key => $response) {
                [$m, $prefix] = explode(' ', $key, 2);
                if ($m === $method && str_starts_with($path, $prefix)) {
                    $r = $response instanceof \Closure ? $response($path) : $response;

                    return $r instanceof \Throwable ? reject($r) : resolve(new Response(200, [], json_encode($r)));
                }
            }

            return resolve(new Response(200, [], '{}'));
        });
        $this->app->instance(AsyncHttpClient::class, $http);
    }

    private function sent(string $method, string $prefix): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === $method && str_starts_with($c[1], $prefix)));
    }

    private function msg(string $id, string $text, string $author = 'carl', bool $bot = false): array
    {
        return ['id' => $id, 'content' => $text, 'author' => ['username' => $author, 'bot' => $bot]];
    }

    private function poll(): void
    {
        app(Suggestions::class)->poll();
    }

    public function test_link_parser_maps_prefixes_to_sections()
    {
        $this->assertSame(['id' => 123, 'section' => 'TMNF'], TmxLink::parse('look https://tmnforever.tm-exchange.com/trackshow/123 nice'));
        $this->assertSame(['id' => 5, 'section' => 'TMNF'], TmxLink::parse('HTTP://TMNF.EXCHANGE/trackshow/5'));
        $this->assertSame(['id' => 6, 'section' => 'TMU'], TmxLink::parse('https://united.tm-exchange.com/trackshow/6'));
        $this->assertSame(['id' => 7, 'section' => 'TMU'], TmxLink::parse('https://tmuf.exchange/trackshow/7'));
        $this->assertSame(['id' => 8, 'section' => 'TMN'], TmxLink::parse('https://nations.tmexchange.com/trackshow/8'));
        $this->assertSame(['id' => 9, 'section' => 'TMS'], TmxLink::parse('https://sunrise.tm-exchange.com/trackshow/9'));
        $this->assertSame(['id' => 10, 'section' => 'TMO'], TmxLink::parse('https://original.tm-exchange.com/trackshow/10'));
        $this->assertSame(['id' => 11, 'section' => 'TMNF'], TmxLink::parse('https://weird.tm-exchange.com/trackshow/11'));
        $this->assertSame(['id' => 1, 'section' => 'TMNF'], TmxLink::parse('https://tmnf.exchange/trackshow/1 https://tmuf.exchange/trackshow/2'));
        $this->assertNull(TmxLink::parse('no link https://example.com/trackshow/1'));
    }

    public function test_first_poll_starts_after_the_newest_message()
    {
        $this->routes['GET /channels/C1/messages'] = [$this->msg('500', 'https://tmnf.exchange/trackshow/1')];

        $this->poll();

        $this->assertSame(0, Suggestion::count());
        $this->assertSame([], $this->sent('PUT', '/channels'));
    }

    public function test_new_link_becomes_a_pending_suggestion_with_an_eyes_reaction()
    {
        Suggestion::create(['tmx_id' => 1, 'tmx_section' => 'TMNF', 'discord_author' => 'x', 'message_id' => '500']);
        $this->routes['GET /channels/C1/messages?'] = [
            $this->msg('503', 'bot talk https://tmnf.exchange/trackshow/9', 'botty', true),
            $this->msg('502', 'https://united.tm-exchange.com/trackshow/42', 'carl'),
            $this->msg('501', 'just chatting'),
        ];

        $this->poll();

        $row = Suggestion::where('tmx_id', 42)->first();
        $this->assertSame(['TMU', 'pending', 'carl', '502'], [$row->tmx_section, $row->status, $row->discord_author, $row->message_id]);
        $this->assertNull(Suggestion::where('tmx_id', 9)->first());
        $react = $this->sent('PUT', '/channels/C1/messages/502/reactions/');
        $this->assertCount(1, $react);
        $this->assertSame(rawurlencode("\u{1F440}"), explode('/', $react[0][1])[6]);
    }

    public function test_known_links_get_a_reply_or_an_hourglass()
    {
        Suggestion::create(['tmx_id' => 1, 'tmx_section' => 'TMNF', 'status' => 'added', 'discord_author' => 'x', 'message_id' => '500']);
        Suggestion::create(['tmx_id' => 2, 'tmx_section' => 'TMNF', 'status' => 'pending', 'discord_author' => 'x', 'message_id' => '499']);
        $this->routes['GET /channels/C1/messages?'] = [
            $this->msg('502', 'https://tmnf.exchange/trackshow/2'),
            $this->msg('501', 'https://tmnf.exchange/trackshow/1'),
        ];

        $this->poll();

        $post = $this->sent('POST', '/channels/C1/messages');
        $this->assertSame("\u{2139}\u{FE0F} This track (TMX #1) has already been added to the server.", $post[0][2]['content']);
        $this->assertSame('501', $post[0][2]['message_reference']['message_id']);
        $this->assertSame(rawurlencode("\u{23F3}"), explode('/', $this->sent('PUT', '/channels')[0][1])[6]);
        $this->assertSame(2, Suggestion::count());
    }

    private function approvalSetup(array $reactor): void
    {
        Suggestion::create(['tmx_id' => 42, 'tmx_section' => 'TMU', 'discord_author' => 'carl', 'message_id' => '502']);
        $this->routes['GET /channels/C1/messages?'] = [];
        $this->routes['GET /channels/C1/messages/502/reactions/'] = [$reactor];
        $this->routes['GET /channels/C1/messages/502'] = ['id' => '502', 'reactions' => [['emoji' => ['name' => self::UP], 'count' => 1]]];
        $this->routes['GET /guilds/G1/members/'] = ['roles' => ['r1']];
        $this->routes['GET /guilds/G1/roles'] = [['id' => 'r1', 'name' => 'Admin', 'permissions' => '0'], ['id' => 'r2', 'name' => 'Mod', 'permissions' => '8']];
        $this->routes['GET /guilds/G1'] = ['owner_id' => 'nobody'];
        $this->routes['GET /channels/C1/messages/502/reactions/'] = [$reactor];
        // /guilds/G1 prefix also matches members/roles: order matters, so the specific routes come first (above)
    }

    public function test_thumbs_up_from_an_approver_adds_the_map_and_announces_it()
    {
        $this->approvalSetup(['id' => 'u9', 'username' => 'boss']);
        $seen = null;
        Event::listen(TrackAddRequested::class, function (TrackAddRequested $e) use (&$seen) {
            $seen = $e;
            $e->result = resolve(new TrackAddResult(true, '$f00Speedy', 'bob'));
        });
        $this->routes['GET /channels/C1/messages?'] = [];
        app(Suggestions::class)->poll();   // sets the baseline from the table (message 502)
        app(Suggestions::class)->poll();

        $this->assertSame([42, 'tmu', 'srv', config('discord.bot_nickname'), true], [$seen->tmxId, $seen->section, $seen->requester->login, $seen->requester->nickname, $seen->silent]);
        $row = Suggestion::where('tmx_id', 42)->first();
        $this->assertSame(['added', 'boss'], [$row->status, $row->approved_by]);
        $this->assertSame([], $this->sent('POST', '/channels/C1/messages'));
        $this->assertNotEmpty($this->sent('PUT', '/channels/C1/messages/502/reactions/'.rawurlencode("\u{2705}")));
    }

    public function test_a_failed_add_is_reported_and_stays_pending()
    {
        $this->approvalSetup(['id' => 'u9', 'username' => 'boss']);
        Event::listen(TrackAddRequested::class, fn (TrackAddRequested $e) => $e->result = resolve(TrackAddResult::failed('Track 42 not found on tmu.')));

        app(Suggestions::class)->poll();
        app(Suggestions::class)->poll();

        $this->assertSame('pending', Suggestion::where('tmx_id', 42)->first()->status);
        $this->assertSame([], $this->sent('POST', '/channels/C1/messages'));
        $this->assertNotEmpty($this->sent('PUT', '/channels/C1/messages/502/reactions/'.rawurlencode("\u{274C}")));
    }

    public function test_a_reactor_without_an_approver_role_is_ignored()
    {
        $this->approvalSetup(['id' => 'u9', 'username' => 'rando']);
        $this->routes['GET /guilds/G1/members/'] = ['roles' => []];
        Event::listen(TrackAddRequested::class, function () {
            $this->fail('must not add');
        });

        app(Suggestions::class)->poll();
        app(Suggestions::class)->poll();

        $this->assertSame('pending', Suggestion::where('tmx_id', 42)->first()->status);
        $this->assertSame([], $this->sent('POST', '/channels/C1/messages'));
    }

    public function test_the_administrator_permission_counts_as_approver()
    {
        $this->approvalSetup(['id' => 'u9', 'username' => 'mod']);
        $this->routes['GET /guilds/G1/members/'] = ['roles' => ['r2']];
        Event::listen(TrackAddRequested::class, fn (TrackAddRequested $e) => $e->result = resolve(new TrackAddResult(true, 'N', 'A')));

        app(Suggestions::class)->poll();
        app(Suggestions::class)->poll();

        $this->assertSame('added', Suggestion::where('tmx_id', 42)->first()->status);
    }

    public function test_nothing_is_called_when_disabled()
    {
        config(['discord.enabled' => false]);
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull(app(Suggestions::class)->tick());
        }
        $this->assertSame([], $this->calls);
    }

    public function test_requests_carry_the_bot_token_only_in_the_header()
    {
        $this->routes['GET /channels/C1/messages'] = [];
        $this->poll();

        $this->assertSame('Bot secret', $this->calls[0][3]['Authorization']);
        $this->assertStringNotContainsString('secret', $this->calls[0][1]);
    }

    public function test_relay_posts_chat_to_the_channel_with_colours_stripped_and_no_mentions()
    {
        config(['discord.relay.chat' => true, 'discord.relay_channel' => 'R1']);
        $player = \Funseco\Core\Models\Player::factory()->make(['nickname' => '$f00Al$$ice']);

        event(new \Funseco\Core\SharedKernel\Events\PlayerChatted($player, 'hi @everyone'));

        $post = $this->sent('POST', '/channels/R1/messages');
        $this->assertSame('**Al$ice**: hi @everyone', $post[0][2]['content']);
        $this->assertSame([], $post[0][2]['allowed_mentions']['parse']);
    }

    public function test_relay_is_off_by_default()
    {
        event(new \Funseco\Core\SharedKernel\Events\PlayerChatted(\Funseco\Core\Models\Player::factory()->make(), 'x'));
        $this->assertSame([], $this->calls);
    }

    public function test_webhook_is_used_when_there_is_no_relay_channel()
    {
        config(['discord.relay.chat' => true, 'discord.relay_channel' => '', 'discord.webhook_url' => 'https://discord.com/api/webhooks/1/abc']);

        event(new \Funseco\Core\SharedKernel\Events\PlayerChatted(\Funseco\Core\Models\Player::factory()->make(['nickname' => 'Al']), 'yo'));

        $this->assertCount(1, $this->calls);
        $this->assertSame('**Al**: yo', $this->calls[0][2]['content']);
    }

    public function test_a_failed_add_is_not_retried_every_poll_but_after_new_thumbs_or_backoff()
    {
        $this->approvalSetup(['id' => 'u9', 'username' => 'boss']);
        $adds = 0;
        Event::listen(TrackAddRequested::class, function (TrackAddRequested $e) use (&$adds) {
            $adds++;
            $e->result = resolve(TrackAddResult::failed('boom'));
        });
        $fails = fn () => count($this->sent('PUT', '/channels/C1/messages/502/reactions/'.rawurlencode("\u{274C}")));

        foreach (range(1, 4) as $_) {
            app(Suggestions::class)->poll();
        }
        $this->assertSame([1, 1], [$adds, $fails()]);

        // one more thumbs-up: retried once
        $this->routes['GET /channels/C1/messages/502'] = ['id' => '502', 'reactions' => [['emoji' => ['name' => self::UP], 'count' => 2]]];
        foreach (range(1, 3) as $_) {
            app(Suggestions::class)->poll();
        }
        $this->assertSame([2, 2], [$adds, $fails()]);

        // backoff over: retried again
        config(['discord.retry_backoff_seconds' => 0]);
        app(Suggestions::class)->poll();
        $this->assertSame(3, $adds);
    }

    public function test_a_throwing_poll_does_not_leave_the_poller_busy()
    {
        $boom = true;
        $this->routes['GET /channels/C1/messages'] = function () use (&$boom) {
            if ($boom) {
                throw new \RuntimeException('db gone');
            }

            return [];
        };
        $s = app(Suggestions::class);
        $s->tick();
        $boom = false;
        $before = count($this->calls);
        $s->tick();

        $this->assertGreaterThan($before, count($this->calls));
    }
}
