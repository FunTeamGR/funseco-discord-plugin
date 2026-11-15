<?php
namespace Funseco\Discord;

use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use Funseco\Core\Services\Http\AsyncHttpClient;
use function React\Promise\reject;

/** Discord REST calls on the controller's event loop (AsyncHttp). Promises reject on failure. */
class DiscordApi
{
    public function __construct(private AsyncHttpClient $http)
    {
    }

    public function configured(): bool
    {
        return (bool) config('discord.enabled') && (string) config('discord.token') !== '';
    }

    /** Newest first, at most 50. */
    public function messages(string $channel, ?string $after = null, int $limit = 50): PromiseInterface
    {
        return $this->call('GET', "/channels/$channel/messages?limit=$limit".($after ? "&after=$after" : ''));
    }

    public function message(string $channel, string $id): PromiseInterface
    {
        return $this->call('GET', "/channels/$channel/messages/$id");
    }

    /** Users who reacted with $emoji. */
    public function reactors(string $channel, string $id, string $emoji): PromiseInterface
    {
        return $this->call('GET', "/channels/$channel/messages/$id/reactions/".rawurlencode($emoji).'?limit=100');
    }

    public function react(string $channel, string $id, string $emoji): PromiseInterface
    {
        return $this->call('PUT', "/channels/$channel/messages/$id/reactions/".rawurlencode($emoji).'/@me');
    }

    public function post(string $channel, string $text, ?string $replyTo = null): PromiseInterface
    {
        $body = ['content' => $text, 'allowed_mentions' => ['parse' => []]];
        if ($replyTo) {
            $body['message_reference'] = ['message_id' => $replyTo, 'fail_if_not_exists' => false];
        }

        return $this->call('POST', "/channels/$channel/messages", $body);
    }

    public function member(string $guild, string $user): PromiseInterface
    {
        return $this->call('GET', "/guilds/$guild/members/$user");
    }

    public function roles(string $guild): PromiseInterface
    {
        return $this->call('GET', "/guilds/$guild/roles");
    }

    public function guild(string $guild): PromiseInterface
    {
        return $this->call('GET', "/guilds/$guild");
    }

    /** Post through the webhook (no bot token needed). */
    public function webhook(string $text, ?string $username = null): PromiseInterface
    {
        $url = (string) config('discord.webhook_url');
        if ($url === '') {
            return reject(new \RuntimeException('No Discord webhook configured'));
        }
        $body = ['content' => $text, 'allowed_mentions' => ['parse' => []]];
        $username && $body['username'] = $username;

        return $this->http->request('POST', $url, ['Content-Type' => 'application/json'], json_encode($body))->then(fn () => null);
    }

    private function call(string $method, string $path, ?array $json = null): PromiseInterface
    {
        $headers = [
            'Authorization' => 'Bot '.config('discord.token'),
            'User-Agent' => 'DiscordBot (https://github.com/funseco, 1.0)',
        ];
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->http->request($method, rtrim((string) config('discord.api_url'), '/').$path, $headers, $json === null ? '' : json_encode($json))
            ->then(function (ResponseInterface $response) {
                $decoded = json_decode((string) $response->getBody(), true);

                return is_array($decoded) ? $decoded : [];
            });
    }
}
