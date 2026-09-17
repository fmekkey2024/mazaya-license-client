<?php

declare(strict_types=1);

namespace Mazaya\License\Console;

use Illuminate\Console\Command;
use Mazaya\License\Storage\StateStore;
use Throwable;

/**
 * Holds an SSE connection to the licence server for instant updates.
 *
 * A long-running daemon (run it under supervisor/systemd). It subscribes to this
 * installation's topic on the Mercure hub and, the moment the panel changes
 * anything, receives a "wake" and runs an ordinary heartbeat — so a suspend,
 * revoke, approval or renewal takes effect in seconds instead of at the next
 * poll.
 *
 * It carries no authority of its own: the wake is only a nudge, and the real,
 * signed verdict still comes from the heartbeat it triggers. If the hub is
 * unreachable it simply keeps retrying, and the scheduled heartbeat remains the
 * backstop — so this is a latency improvement, never a dependency.
 */
class ListenCommand extends Command
{
    protected $signature = 'license:listen
                            {--once : Connect once and exit when the stream closes (for testing)}';

    protected $description = 'Hold an SSE connection to the licence server for instant updates';

    private float $lastBeat = 0.0;

    private float $lastTouch = 0.0;

    private ?StateStore $store = null;

    public function handle(StateStore $store): int
    {
        $this->store = $store;
        if (config('license.mode') !== 'onprem') {
            $this->info('Not an on-prem installation; nothing to listen for.');

            return self::SUCCESS;
        }

        do {
            $cfg = $store->mercureConfig();

            // No hub details yet: a heartbeat fetches them, then we can connect.
            if (! $this->usable($cfg)) {
                $this->beat();
                $cfg = $store->mercureConfig();
            }

            if ($this->usable($cfg)) {
                $this->info('Listening on '.$cfg['topic'].' …');
                $this->stream($cfg);              // blocks until the stream closes
            } else {
                $this->warn('No hub configured yet; will retry.');
            }

            if (! $this->option('once')) {
                sleep(5);                          // reconnect backoff
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /** @param array<string,mixed>|null $cfg */
    private function usable(?array $cfg): bool
    {
        return is_array($cfg) && filled($cfg['url'] ?? null) && filled($cfg['token'] ?? null) && filled($cfg['topic'] ?? null);
    }

    /** @param array<string,string> $cfg */
    private function stream(array $cfg): void
    {
        $url = $cfg['url'].'?topic='.rawurlencode($cfg['topic']);
        $buffer = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$cfg['token'], 'Accept: text/event-stream'],
            CURLOPT_TIMEOUT        => 0,          // long-lived
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$buffer): int {
                $this->touch();   // any hub activity (event or keep-alive) proves reachability
                $buffer .= $chunk;

                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line   = substr($buffer, 0, $nl);
                    $buffer = substr($buffer, $nl + 1);

                    // Any data line is a nudge to check in. The comment keep-
                    // alives the hub sends (":\n") are ignored.
                    if (str_starts_with($line, 'data:')) {
                        $this->onWake();
                    }
                }

                return strlen($chunk);
            },
        ]);

        try {
            curl_exec($ch);                        // returns when the stream closes
        } catch (Throwable) {
            // fall through to reconnect
        }

        curl_close($ch);
    }

    /** Record reachability for the offline leash, at most once a minute. */
    private function touch(): void
    {
        $now = microtime(true);
        if ($now - $this->lastTouch < 60.0) {
            return;
        }
        $this->lastTouch = $now;
        $this->store?->touchSse();
    }

    private function onWake(): void
    {
        // Debounce a burst of events into a single heartbeat.
        $now = microtime(true);
        if ($now - $this->lastBeat < 0.5) {
            return;
        }
        $this->lastBeat = $now;

        $this->line('  wake → heartbeat');
        $this->beat();
    }

    /** A fresh subprocess: a clean state each time, and no leak in a daemon that runs for weeks. */
    private function beat(): void
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' license:heartbeat --quiet-fail';
        $redirect = stripos(PHP_OS, 'WIN') === 0 ? ' > NUL 2>&1' : ' > /dev/null 2>&1';
        @exec($cmd.$redirect);
    }
}
