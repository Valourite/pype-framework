<?php

declare(strict_types=1);

namespace Pype\Debug;

use Pype\Database\Connection;
use Throwable;

/**
 * What a request changed in the sandbox's SQLite database, row by row.
 *
 * watch() puts temporary triggers on every table: each insert, update and delete notes the row
 * (as JSON, before and after) in a temporary table. Temporary means this connection only and
 * nothing in the file, and it is exact whatever the size of the tables: nothing is read whole.
 * changes() then folds what was noted into one entry per row: new, changed (which columns) or
 * deleted; a row made and removed again within the request is nothing.
 */
final class TableDiff
{
    private const MAX_SHOWN = 50;
    private const HIDDEN = '•••• hidden';
    private const LOG = 'pype_sandbox_changes';

    /** @return list<string> the app's tables (not SQLite's own, not the migrations list) */
    public static function tables(Connection $db): array
    {
        return array_values(array_filter(
            array_column($db->select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"), 'name'),
            static fn (string $name) => !str_starts_with($name, 'sqlite_') && $name !== 'pype_migrations',
        ));
    }

    /** @return list<string> the primary key's columns; empty when the table has none (rowid is used) */
    public static function key(Connection $db, string $table): array
    {
        $columns = array_filter($db->select('PRAGMA table_info(' . $db->quote($table) . ')'), static fn (array $c) => (int) $c['pk'] > 0);
        usort($columns, static fn (array $a, array $b) => $a['pk'] <=> $b['pk']);

        return array_column($columns, 'name');
    }

    /**
     * Start noting changes, and count every table's rows.
     *
     * @return array{counts: array<string, int>, unwatched: list<string>} unwatched: tables no trigger could be put on (WITHOUT ROWID): only counted
     */
    public static function watch(Connection $db): array
    {
        $log = self::LOG;
        $db->statement("CREATE TEMP TABLE IF NOT EXISTS {$log} (n INTEGER PRIMARY KEY, tbl TEXT, op TEXT, row TEXT, was TEXT, now TEXT)");
        $db->statement("DELETE FROM temp.{$log}");
        $unwatched = [];

        foreach (self::tables($db) as $table) {
            $columns = array_column($db->select('PRAGMA table_info(' . $db->quote($table) . ')'), 'name');
            $name = str_replace("'", "''", $table);
            $quoted = $db->quote($table);
            $old = self::json($db, $columns, 'OLD');
            $new = self::json($db, $columns, 'NEW');
            $id = substr(hash('xxh128', $table), 0, 12);

            try {
                $db->statement("CREATE TEMP TRIGGER IF NOT EXISTS pype_sbx_i_{$id} AFTER INSERT ON main.{$quoted} BEGIN INSERT INTO {$log} (tbl, op, row, was, now) VALUES ('{$name}', 'I', NEW.rowid, NULL, {$new}); END");
                $db->statement("CREATE TEMP TRIGGER IF NOT EXISTS pype_sbx_u_{$id} AFTER UPDATE ON main.{$quoted} BEGIN INSERT INTO {$log} (tbl, op, row, was, now) VALUES ('{$name}', 'U', OLD.rowid, {$old}, {$new}); END");
                $db->statement("CREATE TEMP TRIGGER IF NOT EXISTS pype_sbx_d_{$id} AFTER DELETE ON main.{$quoted} BEGIN INSERT INTO {$log} (tbl, op, row, was, now) VALUES ('{$name}', 'D', OLD.rowid, {$old}, NULL); END");
            } catch (Throwable) {
                $unwatched[] = $table;
            }
        }

        return ['counts' => self::counts($db), 'unwatched' => $unwatched];
    }

    /**
     * What changed since watch(): only the tables that did.
     *
     * @param array{counts: array<string, int>, unwatched: list<string>} $watch what watch() returned
     * @return array<string, array{before: int, after: int, inserted: list<array<string, mixed>>, deleted: list<array<string, mixed>>, changed: list<array{key: string, columns: array<string, array{0: mixed, 1: mixed}>}>, more: int, counted: bool}>
     */
    public static function changes(Connection $db, array $watch): array
    {
        $after = self::counts($db);
        $rows = [];      // table => rowid => [how it was first, how it is now, made by this request?]
        $deleted = [];   // table => rows that were there before and are gone

        // In the order it happened: SQLite hands a deleted row's id to the next insert, so a
        // delete closes the row's story and an insert after it begins another.
        foreach ($db->select('SELECT tbl, op, row, was, now FROM temp.' . self::LOG . ' ORDER BY n') as $note) {
            $entry = $rows[$note['tbl']][$note['row']] ?? null;

            if ($note['op'] === 'D') {
                if ($entry === null || !$entry['made']) {
                    $deleted[$note['tbl']][] = $entry['was'] ?? $note['was'];
                }
                unset($rows[$note['tbl']][$note['row']]);
            } elseif ($note['op'] === 'I') {
                $rows[$note['tbl']][$note['row']] = ['was' => null, 'made' => true, 'now' => $note['now']];
            } else {
                $rows[$note['tbl']][$note['row']] = ['was' => $entry['was'] ?? $note['was'], 'made' => $entry['made'] ?? false, 'now' => $note['now']];
            }
        }

        $out = [];

        foreach ($after + $watch['counts'] as $table => $_) {
            $entry = ['before' => $watch['counts'][$table] ?? 0, 'after' => $after[$table] ?? 0, 'inserted' => [], 'deleted' => [], 'changed' => [], 'more' => 0, 'counted' => in_array($table, $watch['unwatched'], true)];
            $key = isset($after[$table]) ? self::key($db, $table) : [];

            foreach ($rows[$table] ?? [] as $rowid => $row) {
                $was = $row['was'] === null ? null : (array) json_decode($row['was'], true);
                $now = $row['now'] === null ? null : (array) json_decode($row['now'], true);

                if ($row['made']) {
                    $entry['inserted'][] = self::shown((array) $now);
                } elseif ($was !== null && $now !== null && $was !== $now) {
                    $columns = [];
                    foreach ($now as $column => $value) {
                        if (($was[$column] ?? null) !== $value) {
                            $columns[$column] = self::secret((string) $column) ? [self::HIDDEN, self::HIDDEN] : [self::cut($was[$column] ?? null), self::cut($value)];
                        }
                    }
                    $entry['changed'][] = ['key' => $key === [] ? "rowid {$rowid}" : implode(', ', array_map(static fn (string $k) => "{$k} " . ($now[$k] ?? '?'), $key)), 'columns' => $columns];
                }
            }

            foreach ($deleted[$table] ?? [] as $was) {
                $entry['deleted'][] = self::shown((array) json_decode((string) $was, true));
            }

            if ($entry['inserted'] === [] && $entry['deleted'] === [] && $entry['changed'] === [] && !($entry['counted'] && $entry['before'] !== $entry['after'])) {
                continue;
            }

            foreach (['inserted', 'deleted', 'changed'] as $kind) {
                $entry['more'] += max(0, count($entry[$kind]) - self::MAX_SHOWN);
                $entry[$kind] = array_slice($entry[$kind], 0, self::MAX_SHOWN);
            }

            $out[$table] = $entry;
        }

        ksort($out);

        return $out;
    }

    /** @return array<string, int> */
    private static function counts(Connection $db): array
    {
        $counts = [];

        foreach (self::tables($db) as $table) {
            $counts[$table] = (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->quote($table));
        }

        return $counts;
    }

    /**
     * A row as a JSON object, in SQL: json_object('a', NEW."a", ...). A blob can't go into JSON, so
     * it is noted as its size; SQLite takes a limited number of arguments, so wide tables are
     * built in parts.
     *
     * @param list<string> $columns
     */
    private static function json(Connection $db, array $columns, string $row): string
    {
        $parts = [];

        foreach (array_chunk($columns, 40) as $chunk) {
            $pairs = [];
            foreach ($chunk as $column) {
                $value = $row . '.' . $db->quote($column);
                $pairs[] = "'" . str_replace("'", "''", $column) . "', CASE WHEN typeof({$value}) = 'blob' THEN '(' || length({$value}) || ' bytes)' ELSE {$value} END";
            }
            $parts[] = 'json_object(' . implode(', ', $pairs) . ')';
        }

        return array_reduce(array_slice($parts, 1), static fn (string $all, string $part) => "json_patch({$all}, {$part})", $parts[0] ?? "'{}'");
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shown(array $row): array
    {
        foreach ($row as $column => $value) {
            $row[$column] = self::secret((string) $column) ? self::HIDDEN : self::cut($value);
        }

        return $row;
    }

    private static function secret(string $column): bool
    {
        return preg_match(ErrorPage::SECRET_FIELDS, $column) === 1;
    }

    private static function cut(mixed $value): mixed
    {
        return is_string($value) && mb_strlen($value) > 200 ? mb_substr($value, 0, 199) . '…' : $value;
    }
}
