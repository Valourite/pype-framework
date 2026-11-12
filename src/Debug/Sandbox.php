<?php

declare(strict_types=1);

namespace Pype\Debug;

use Pype\Application;
use Pype\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * The development sandbox (/_pype/sandbox): a throw-away SQLite database built from the app's
 * own migrations and seeder, and requests run against a copy of it.
 *
 *   base.sqlite   as built (`bin/pype sandbox:build`): migrated and seeded, never written to again
 *   run.sqlite    the copy a request runs against; kept when the next run is to carry on from it
 *   meta.json     when it was built, from which migrations
 *
 * Both building and running happen in a `bin/pype` process of their own, started with
 * PYPE_SANDBOX set (see Application::sandboxed): that whole process uses the sandbox file and
 * in-memory mail, queue, cache and sessions, so nothing in the app that is serving the screen is
 * swapped, and nothing reaches the app's real database.
 */
final class Sandbox
{
    public const MARK = "\n---PYPE-SANDBOX---\n";

    public function __construct(private readonly Application $app, private readonly string $directory)
    {
    }

    public function base(): string
    {
        return $this->directory . '/base.sqlite';
    }

    public function runFile(): string
    {
        return $this->directory . '/run.sqlite';
    }

    /**
     * @return array{built: bool, at: ?string, stale: bool, seeder: ?string, tables: array<string, int>, carrying: bool}
     */
    public function status(): array
    {
        $meta = is_file($this->directory . '/meta.json') ? json_decode((string) file_get_contents($this->directory . '/meta.json'), true) : null;
        $built = is_array($meta) && is_file($this->base());

        return [
            'built' => $built,
            'at' => $built ? ($meta['at'] ?? null) : null,
            'stale' => $built && ($meta['migrations'] ?? '') !== $this->migrationsHash(),
            'seeder' => $built ? ($meta['seeder'] ?? null) : null,
            'tables' => $built ? ($meta['tables'] ?? []) : [],
            'carrying' => $built && is_file($this->runFile()),
        ];
    }

    /**
     * Build the base database in a process of its own. Returns what that process printed.
     *
     * @throws RuntimeException when it could not be built (the message says why)
     */
    public function build(): string
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Cannot create {$this->directory}.");
        }

        $building = $this->directory . '/building.sqlite';
        $this->remove($building);

        [$code, $out] = $this->pype(['sandbox:build', '--inside'], $building);

        if ($code !== 0 || !is_file($building)) {
            $this->remove($building);

            throw new RuntimeException(trim($out) !== '' ? trim($out) : "The sandbox could not be built (bin/pype exited with {$code}).");
        }

        $this->remove($this->base());
        $this->remove($this->runFile());
        rename($building, $this->base());

        return trim($out);
    }

    /**
     * The inside of build(): this process is already in sandbox mode, so the app's Connection is
     * the new file. Migrate it, seed it, note what it was built from.
     */
    public function buildInside(Connection $db, callable $migrate, callable $seed, ?string $seeder): void
    {
        $migrate();

        if ($seeder !== null) {
            $seed();
        }

        $tables = [];
        foreach (TableDiff::tables($db) as $table) {
            $tables[$table] = (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->quote($table));
        }

        // Everything in one file: the copy for each run is then a plain file copy.
        $db->statement('PRAGMA wal_checkpoint(TRUNCATE)');
        $db->disconnect();

        file_put_contents($this->directory . '/meta.json', json_encode([
            'at' => date(DATE_ATOM),
            'migrations' => $this->migrationsHash(),
            'seeder' => $seeder,
            'tables' => $tables,
        ], JSON_PRETTY_PRINT));
    }

    /** Throw the kept run away: the next request starts from the base again. */
    public function reset(): void
    {
        $this->remove($this->runFile());
    }

    /**
     * Run one request against the sandbox, in a process of its own.
     *
     * @param array{method: string, uri: string, body?: array<string, mixed>, headers?: array<string, string>, user?: ?int, json?: bool} $spec
     * @param bool $carryOn run against what the last run left, instead of a fresh copy of the base
     * @return array<string, mixed> what SandboxRun::run() made of it
     * @throws RuntimeException when there is no sandbox, or the process died without an answer
     */
    public function run(array $spec, bool $carryOn = false): array
    {
        if (!is_file($this->base())) {
            throw new RuntimeException('There is no sandbox yet: build it first.');
        }

        if (!$carryOn || !is_file($this->runFile())) {
            $this->remove($this->runFile());
            copy($this->base(), $this->runFile());
        }

        [$code, $out] = $this->pype(['sandbox:run'], $this->runFile(), (string) json_encode($spec));
        $at = strrpos($out, self::MARK);
        $result = $at === false ? null : json_decode(substr($out, $at + strlen(self::MARK)), true);

        if (!is_array($result)) {
            throw new RuntimeException("The request did not come back (bin/pype exited with {$code}):\n" . trim(mb_strcut($out, 0, 4000)));
        }

        $result['printed'] = trim(mb_strcut(substr($out, 0, (int) $at), 0, 4000));   // anything the app echoed

        return $result;
    }

    /** A connection for looking into the sandbox from the screen (the run that is being carried on, else the base). */
    public function peek(): ?Connection
    {
        $file = is_file($this->runFile()) ? $this->runFile() : $this->base();

        return is_file($file) ? new Connection(['driver' => 'sqlite', 'database' => $file]) : null;
    }

    /**
     * The first ids of $table, for the form's suggestions; empty when there is no such table.
     *
     * @return list<string>
     */
    public function ids(string $table, int $limit = 20): array
    {
        try {
            $db = $this->peek();

            if ($db === null || !in_array($table, TableDiff::tables($db), true)) {
                return [];
            }

            $key = TableDiff::key($db, $table);

            return count($key) !== 1 ? [] : array_map('strval', array_column(
                $db->select('SELECT ' . $db->quote($key[0]) . ' AS k FROM ' . $db->quote($table) . ' ORDER BY 1 LIMIT ' . $limit),
                'k',
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Who a request can be sent as: the first rows of the auth table, with something to tell them apart.
     *
     * @return list<array{id: int, label: string}>
     */
    public function users(int $limit = 50): array
    {
        try {
            $db = $this->peek();
            $table = (string) $this->app->config('auth.table', 'users');

            if ($db === null || !in_array($table, TableDiff::tables($db), true)) {
                return [];
            }

            $users = [];
            foreach ($db->select('SELECT * FROM ' . $db->quote($table) . ' ORDER BY id LIMIT ' . $limit) as $row) {
                $about = array_filter([$row['name'] ?? null, $row['email'] ?? null, $row['role'] ?? null], static fn ($v) => is_string($v) && $v !== '');
                $users[] = ['id' => (int) $row['id'], 'label' => '#' . $row['id'] . ($about === [] ? '' : ' ' . implode(' · ', $about))];
            }

            return $users;
        } catch (Throwable) {
            return [];
        }
    }

    /** Changes whenever a migration is added, removed or edited. */
    private function migrationsHash(): string
    {
        $directory = $this->app->path((string) $this->app->config('database.migrations', 'database/migrations'));
        $files = glob($directory . '/*.{php,sql}', GLOB_BRACE) ?: [];
        sort($files);

        return hash('xxh128', implode("\n", array_map(static fn (string $f) => basename($f) . ':' . hash_file('xxh128', $f), $files)));
    }

    /**
     * Run `bin/pype ...` in sandbox mode against $file.
     *
     * @param list<string> $arguments
     * @return array{0: int, 1: string} exit code, and what it printed (stdout and stderr)
     */
    private function pype(array $arguments, string $file, string $stdin = ''): array
    {
        $php = (string) $this->app->config('debug.sandbox.php', in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? PHP_BINARY : 'php');
        $bin = $this->app->path((string) $this->app->config('debug.sandbox.bin', 'bin/pype'));

        if (!is_file($bin)) {
            throw new RuntimeException("The sandbox runs the app's console in a process of its own, and there is no {$bin}.");
        }

        if (!function_exists('proc_open')) {
            throw new RuntimeException('The sandbox needs proc_open(), which this PHP has switched off.');
        }

        $env = ['PYPE_SANDBOX' => $file, 'APP_DEBUG' => 'true'] + array_filter(getenv(), 'is_string');
        $process = proc_open([$php, $bin, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->app->basePath, $env);

        if (!is_resource($process)) {
            throw new RuntimeException("Could not start {$php} {$bin}.");
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $out];
    }

    private function remove(string $file): void
    {
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            if (is_file($file . $suffix)) {
                @unlink($file . $suffix);
            }
        }
    }
}
