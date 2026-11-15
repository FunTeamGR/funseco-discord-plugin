<?php

namespace Funseco\Discord;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Events\TrackAddRequested;
use Funseco\Core\SharedKernel\Values\TrackAddResult;
use Illuminate\Support\Facades\Log;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * TMX map suggestions through a Discord channel (REST polling): a TMX link starts a pending
 * suggestion, a thumbs-up from an approver adds the map through the TMX plugin (TrackAddRequested).
 */
class Suggestions
{
    /** Thumbs-up in the five skin tones (and the plain one). */
    public const THUMBS = ["\u{1F44D}", "\u{1F44D}\u{1F3FB}", "\u{1F44D}\u{1F3FC}", "\u{1F44D}\u{1F3FD}", "\u{1F44D}\u{1F3FE}", "\u{1F44D}\u{1F3FF}"];

    private bool $busy = false;

    private int $ticks = 0;

    private ?string $lastId = null;

    /** @var array<int, true> tmx ids being added right now */
    private array $adding = [];

    /** @var array{0: int, 1: array}|null */
    private ?array $rolesCache = null;

    /** @var array<int, array{0: int, 1: int}> tmx id => [time of the failed add, thumbs count then] */
    private array $failed = [];

    public function __construct(private DiscordApi $api) {}

    public function active(): bool
    {
        return $this->api->configured() && (string) config('discord.suggest_channel') !== '';
    }

    /** Every second; polls every discord.poll_seconds, never two polls at once. */
    public function tick(): ?PromiseInterface
    {
        if (! $this->active() || ++$this->ticks < max(1, (int) config('discord.poll_seconds', 10)) || $this->busy) {
            return null;
        }
        $this->ticks = 0;
        $this->busy = true;

        try {
            $poll = $this->poll();
        } catch (\Throwable $e) {
            Log::warning('[Discord] poll failed: '.$e->getMessage());
            $this->busy = false;

            return null;
        }

        return $poll->then(
            fn () => $this->busy = false,
            function (\Throwable $e) {
                Log::warning('[Discord] poll failed: '.$e->getMessage());
                $this->busy = false;
            },
        );
    }

    public function poll(): PromiseInterface
    {
        $channel = (string) config('discord.suggest_channel');

        if ($this->lastId === null) {
            $known = Suggestion::whereNotNull('message_id')->pluck('message_id')->sortByDesc(fn ($id) => (int) $id)->first();
            if ($known) {
                $this->lastId = (string) $known;
            } else {
                // first run: start after the newest message instead of ingesting the history
                return $this->api->messages($channel, null, 1)->then(function (array $latest) {
                    $this->lastId = (string) ($latest[0]['id'] ?? '0');
                });
            }
        }

        return $this->api->messages($channel, $this->lastId)->then(function (array $messages) use ($channel) {
            usort($messages, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);

            return $this->each($messages, fn (array $m) => $this->handleMessage($channel, $m));
        })->then(fn () => $this->checkReactions($channel));
    }

    /** @param array{id: string, content?: string, author?: array} $m */
    public function handleMessage(string $channel, array $m): PromiseInterface
    {
        $this->lastId = (string) $m['id'];
        if ($m['author']['bot'] ?? false) {
            return resolve(null);
        }
        $link = TmxLink::parse((string) ($m['content'] ?? ''));
        if (! $link) {
            Log::debug('[Discord] suggestion channel message without TMX link');

            return resolve(null);
        }

        $row = Suggestion::where('tmx_id', $link['id'])->first();
        if ($row) {
            return $row->status === 'added'
                ? $this->safe($this->api->post($channel, "\u{2139}\u{FE0F} This track (TMX #{$link['id']}) has already been added to the server.", $m['id']))
                : $this->safe($this->api->react($channel, $m['id'], "\u{23F3}"));
        }

        Suggestion::create([
            'tmx_id' => $link['id'], 'tmx_section' => $link['section'], 'status' => 'pending',
            'discord_author' => (string) ($m['author']['username'] ?? 'unknown'), 'message_id' => (string) $m['id'],
        ]);

        return $this->safe($this->api->react($channel, $m['id'], "\u{1F440}"));
    }

    private function checkReactions(string $channel): PromiseInterface
    {
        $rows = Suggestion::where('status', 'pending')->whereNotNull('message_id')
            ->orderByDesc('id')->limit((int) config('discord.check_pending', 25))->get();

        return $this->each($rows->all(), function (Suggestion $row) use ($channel) {
            if (isset($this->adding[$row->tmx_id])) {
                return resolve(null);
            }

            return $this->api->message($channel, (string) $row->message_id)->then(function (array $message) use ($channel, $row) {
                $emojis = [];
                foreach ($message['reactions'] ?? [] as $reaction) {
                    $name = (string) ($reaction['emoji']['name'] ?? '');
                    if (in_array($name, self::THUMBS, true) && ($reaction['count'] ?? 0) > 0) {
                        $emojis[] = $name;
                    }
                }

                $total = 0;
                foreach ($message['reactions'] ?? [] as $reaction) {
                    if (in_array((string) ($reaction['emoji']['name'] ?? ''), self::THUMBS, true)) {
                        $total += (int) ($reaction['count'] ?? 0);
                    }
                }
                // a failed add is retried only after a backoff or when more thumbs-ups arrived
                if (($f = $this->failed[$row->tmx_id] ?? null) && $total <= $f[1]
                    && time() - $f[0] < (int) config('discord.retry_backoff_seconds', 600)) {
                    return null;
                }

                if ($emojis) {
                    Log::info("[Discord] thumbs found on TMX #{$row->tmx_id}: ".implode(',', $emojis));
                }

                return $this->firstApprover($channel, (string) $row->message_id, $emojis)->then(
                    fn (?array $approver) => $approver ? $this->approve($channel, $row, $approver, $total) : null
                );
            }, fn () => null);   // message deleted or unreachable: leave the row pending
        });
    }

    /** @param string[] $emojis */
    private function firstApprover(string $channel, string $messageId, array $emojis): PromiseInterface
    {
        $found = null;

        return $this->each($emojis, function (string $emoji) use ($channel, $messageId, &$found) {
            if ($found) {
                return resolve(null);
            }

            return $this->api->reactors($channel, $messageId, $emoji)->then(function (array $users) use (&$found) {
                return $this->each($users, function (array $user) use (&$found) {
                    if ($found || ($user['bot'] ?? false)) {
                        return resolve(null);
                    }

                    return $this->isApprover($user)->then(function (bool $yes) use ($user, &$found) {
                        $yes && $found = $user;
                    });
                });
            }, function (\Throwable $e) {
                Log::warning('[Discord] reactors fetch failed: '.$e->getMessage());
            });
        })->then(function () use (&$found) {
            return $found;   // by reference: an arrow fn would capture the initial null
        });
    }

    public function approve(string $channel, Suggestion $row, array $approver, int $thumbs = 0): PromiseInterface
    {
        if ($row->status === 'added' || isset($this->adding[$row->tmx_id])) {
            return resolve(null);
        }
        $this->adding[$row->tmx_id] = true;
        Log::info("[Discord] approving TMX #{$row->tmx_id} (section {$row->tmx_section}) by ".($approver['username'] ?? '?'));

        $bot = new Player([
            'login' => (string) (config('discord.bot_login') ?: config('server.login')),
            'nickname' => (string) config('discord.bot_nickname'),
        ]);
        $request = new TrackAddRequested((int) $row->tmx_id, strtolower($row->tmx_section), $bot, true);
        event($request);
        $result = $request->result ?? resolve(TrackAddResult::failed('TMX plugin not available'));

        return $result->then(function (TrackAddResult $r) use ($channel, $row, $approver, $thumbs) {
            $by = (string) ($approver['username'] ?? 'unknown');
            if (! $r->ok) {
                $this->failed[$row->tmx_id] = [time(), $thumbs];

                Log::warning("[Discord] TMX #{$row->tmx_id} could not be added: {$r->error}");

                return $this->safe($this->api->react($channel, (string) $row->message_id, "\u{274C}"));
            }
            unset($this->failed[$row->tmx_id]);
            $row->update(['status' => 'added', 'approved_by' => $by]);

            return $this->safe($this->api->react($channel, (string) $row->message_id, "\u{2705}"));
        })->then(function () use ($row) {
            unset($this->adding[$row->tmx_id]);
        }, function (\Throwable $e) use ($row) {
            unset($this->adding[$row->tmx_id]);
            Log::warning('[Discord] approve failed: '.$e->getMessage());
        });
    }

    /** Holds an approver role (by name) or the Administrator permission, or owns the guild. */
    public function isApprover(array $user): PromiseInterface
    {
        $guild = (string) config('discord.guild');
        if ($guild === '' || ! isset($user['id'])) {
            Log::warning('[Discord] approver check skipped: DISCORD_GUILD is not set');

            return resolve(false);
        }

        return all([$this->api->member($guild, (string) $user['id']), $this->roles($guild), $this->api->guild($guild)])->then(
            function (array $r) use ($user) {
                [$member, $roles, $guildInfo] = $r;
                if (($guildInfo['owner_id'] ?? null) === (string) $user['id']) {
                    return true;
                }
                $names = array_map('strtolower', (array) config('discord.admin_roles', []));
                foreach ($member['roles'] ?? [] as $roleId) {
                    $role = $roles[$roleId] ?? null;
                    if ($role && (in_array(strtolower((string) $role['name']), $names, true) || ((int) ($role['permissions'] ?? 0) & 0x8))) {
                        return true;
                    }
                }

                return false;
            },
            function (\Throwable $e) use ($user) {
                Log::warning("[Discord] approver check failed for {$user['id']}: ".$e->getMessage());

                return false;
            },
        );
    }

    /** @return PromiseInterface<array<string, array>> roles by id, cached 5 minutes */
    private function roles(string $guild): PromiseInterface
    {
        if ($this->rolesCache && time() - $this->rolesCache[0] < 300) {
            return resolve($this->rolesCache[1]);
        }

        return $this->api->roles($guild)->then(function (array $list) {
            $byId = [];
            foreach ($list as $role) {
                $byId[(string) $role['id']] = $role;
            }
            $this->rolesCache = [time(), $byId];

            return $byId;
        });
    }

    /** Run $fn over the items one after another. */
    private function each(array $items, callable $fn): PromiseInterface
    {
        $p = resolve(null);
        foreach ($items as $item) {
            $p = $p->then(fn () => $fn($item));
        }

        return $p;
    }

    /** A failing side call (reaction, reply) must not stop the rest. */
    private function safe(PromiseInterface $p): PromiseInterface
    {
        return $p->then(fn () => null, function (\Throwable $e) {
            Log::warning('[Discord] request failed: '.$e->getMessage());
        });
    }

    public static function strip(string $text): string
    {
        $text = str_replace('$$', "\0", $text);
        $text = preg_replace('/\$(?:[0-9a-f][^$]{0,2}|[lh]\[[^\]]*\]|[^$]?)/i', '', $text);

        return str_replace("\0", '$', $text);
    }
}
