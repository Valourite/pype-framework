# Benchmarks

Numbers from load tests of "Spendwise", an expense-tracker app built on pype to try the
framework out. The bench lives in that app: `bench/run.sh`, `bench/README.md`.

To re-run (needs Docker):

```bash
cd expenses
bench/run.sh                                                  # defaults
LEVELS="1000 2000" SETUPS="sqlite:file:apcu:fpm sqlite:file:apcu:swoole" bench/run.sh
```

A setup is `database:sessions:ratelimits:server`. Each level takes about users/10 + 60 seconds.
The machine used so far is a desktop in use, so p99 is noisy; compare p50/p95 and CPU.


How: `run.sh` in the example app (`expenses/bench/`) builds one Docker container (nginx + PHP-FPM 8.5 or OpenSwoole, plus the database) capped at **2 vCPU / 4 GB** like a small VPS. It seeds 2000 users / ~390k expenses and simulates users with k6:
- 70% browser users, 20% API clients, a sign-up every 2 s;
- users think 1–3 s between pages;
- numbers are from the steady phase, after everyone has signed in.

See `expenses/bench/README.md`. Results are in `expenses/bench/results/<timestamp>/report.txt`.

**Caveat:** the host is a desktop in use, so the slowest 1% (p99) is noisy run to run (e.g. FPM at 2000 users: 3 ms in one run, 416 ms in another). p50/p95, CPU and errors are stable.

### 1. Baseline + the missing index (2026-10-15, run `20261015-232309`)
- Found and fixed:
  - SQLite "database is locked" → BEGIN IMMEDIATE;
  - file-session GC reading every file;
  - a missing `expenses(category_id)` index (categories page 99 ms → 0.17 ms; capacity ~330 → 930+ req/s).
- 2000 users: ~930 req/s, p95 2–4 ms, CPU 75% of 200%, 0 errors.

### 2. Databases + bottleneck fixes (2026-10-16, run `20261016-090320`)

> **Invalid (found 2026-10-20):** the "Postgres 15" and "MariaDB 10.11" rows were really SQLite.
> The bench container sourced `bench/db/env.sh <db>` with Debian's `sh` (dash), which ignores
> arguments to `.`, so the script fell back to `DB_CONNECTION=sqlite` and the database servers
> were never used (they weren't even seeded). Fixed in `expenses/bench/db/env.sh`; the
> comparison needs re-running before any database conclusion is drawn.

At 2000 users (~930 req/s):

| Setup | p95 | CPU |
|---|---|---|
| SQLite | 2 ms | 76–82% |
| Postgres 15 | 2 ms | 74% |
| MariaDB 10.11 | 3 ms | 92% |

- ~~All three databases perform the same~~: not measured, see the note above.
- **FPM ceiling ≈ 3000 users / ~1350 req/s:** CPU bursts hit 200% and p99 rises to ~1.5 s.
- MariaDB stands in for MySQL in the bench (Debian has no MySQL 8). Real MySQL 8.4 passes the test suite.
- One unexplained, non-reproducible "No space left on device" burst in the first file-session run (disk had 40 GB free; a rerun was clean).

### 3. PHP-FPM vs OpenSwoole (2026-10-16 and 2026-10-19, SQLite + file sessions + APCu)

All runs combined in `expenses/bench/results/combined-fpm-vs-swoole/report.txt`. 1000–2000 users: run `20261016-105956`. 3000–5000: `20261019-093344` (OpenSwoole) and `20261019-095802` (FPM).

| Users | Server | req/s | page p50 / p95 / p99 | form posts p95 | login p95 | CPU (200% = both cores) | memory |
|---|---|---|---|---|---|---|---|
| 1000 | FPM | 465 | 1 / 9 / 19 ms | 27 ms | 173 ms | 54% | 63 MB |
| 1000 | OpenSwoole | 468 | 0 / 1 / 2 ms | 1 ms | 177 ms | 27% | 55 MB |
| 2000 | FPM | 926 | 1 / 2 / 416 ms | 4 ms | 1.09 s | 87% | 99 MB |
| 2000 | OpenSwoole | 930 | 0 / 1 / 5 ms | 1 ms | 177 ms | 40% | 93 MB |
| 3000 | FPM | 1353 | 1 / 4 / 1470 ms | 98 ms | 2.19 s | 115% | 128 MB |
| 3000 | OpenSwoole | 1396 | 0 / 1 / 4 ms | 1 ms | 169 ms | 51% | 120 MB |
| 4000 | FPM | 1734 | 2 / 477 / 2700 ms | 710 ms | 3.39 s | 147% | 207 MB |
| 4000 | OpenSwoole | 1865 | 0 / 1 / 5 ms | 1 ms | 181 ms | 65% | 221 MB |
| 5000 | FPM | 1871 | 263 / 1790 / 3760 ms | 1.40 s | 4.50 s | 174% | 303 MB |
| 5000 | OpenSwoole | 2322 | 0 / 1 / 7 ms | 1 ms | 215 ms | 87% | 359 MB |

- **FPM is overloaded from about 4000 users on this box.** At 5000 it can't keep up: 1871 req/s against the ~2300 offered, with pages at 0.3–3.8 s.
- **OpenSwoole at 5000 users still answers in 1 ms (p95) using 87% of 200% CPU.** Its ceiling wasn't reached. Extrapolating CPU linearly puts it at roughly 10,000 users / ~4,500 req/s. **Not measured.**
- Memory grows with users for both (sessions and SQLite in page cache, more connections). OpenSwoole's isn't a leak: the worker-safety test shows flat PHP memory.
- **The small "errors" at 3000+ users (0.01–0.07%) are a k6 artifact, not the servers.** k6 stopped the test 30 s after the end and cut off users mid-visit, reporting their in-flight requests as timeouts. That's why they grew with user count and hit FPM and OpenSwoole equally. A debug run proved it: all hung requests timed out in the test's final second, and the kernel showed no drops. With `gracefulStop`/`gracefulRampDown` = 60 s (now in `k6/users.js`), the same load gave **0** failures. Runs before 2026-10-19 don't have this fix.

## Micro-benchmarks

### Cache stores (2026-10-19, `bin/bench-cache.sh`)

Micro-benchmark, one process, PHP 8.3 in Docker with opcache + APCu, 20,000 calls each over a
working set of 100 keys, value ~420 bytes serialized (an array). µs per call through `Cache`
(key check + prefix included). Three runs, spread ≤ 0.05 µs except file set (±0.3).

| Store | get hit | get miss | set |
|---|---|---|---|
| array | 0.13 | 0.10 | 0.14 |
| apcu | 0.42 | 0.11 | 0.44 |
| file, served by opcache | 1.35 | 0.68 | 23 |
| file, compiled each read | 5.4 | 0.68 | 23 |
| sqlite (on disk, WAL) | 2.6 | 1.8 | 6.6 |

- **Opcache only serves a cache file once it is older than the request** (`opcache.file_update_protection`,
  2 s by default, measured against the request's start). Under PHP-FPM that means from the next
  request on. A CLI run is one long "request", so there file hits never come from opcache (the
  5.4 µs row, measured); an **OpenSwoole worker** should behave the same (not measured) unless `opcache.file_update_protection=0`, which is
  safe for this store because files appear by atomic `rename()`. The bench sets it.
- File `set` is the slowest (a temp file, `rename()`, `opcache_invalidate()`); caches are read
  far more than written. sqlite here has no network; MySQL/Postgres add a round trip (~50–200 µs).
- Not measured: hit rates under load, or the effect on the expenses load test.

### Queue throughput (2026-10-20, `bin/bench-queue.sh`)

5000 no-op jobs pushed, then processed by 1 or 4 forked `Worker`s (`stopWhenEmpty`), PHP 8.3 in
Docker, databases in their own containers with default settings (each commit is flushed to
disk). Two runs; numbers agreed within ~3% except SQLite (±25%).

| Database | push, µs/job | 1 worker, jobs/s | 4 workers, jobs/s |
|---|---|---|---|
| SQLite (file, WAL) | 15 | 3,600–5,000 | 3,400–4,400 |
| MySQL 8.4 | 650 | 710 | 1,680 |
| Postgres 17 | 205 | 1,450 | 4,150 |

- Per job a worker does one claiming transaction (SELECT ... SKIP LOCKED + UPDATE) and one
  DELETE: two commits, so the numbers are mostly the database's commit cost.
- **SQLite doesn't scale with workers**: one writer at a time (`BEGIN IMMEDIATE`). It is still
  the fastest here because it has no network and a cheap commit. Fine for one server.
- **Postgres scales best** (SKIP LOCKED: 2.9× with 4 workers). MySQL 2.4×; its push is slow
  because each autocommit INSERT waits for a log flush (`innodb_flush_log_at_trx_commit=1`).
- Real jobs (mail, HTTP calls) take milliseconds, so the queue's overhead rarely matters;
  add workers for slow jobs. Not measured: the desktop host's disk dominates commit times.

### Profiling the $12 challenge app (2026-11-06, 0.13.0 → 0.13.1, `../twelve-bench/profile.sh`)

The challenge scored #335 (0.12) at **36,000 users**, CPU-bound: 246 µs of CPU per request on its box
(Xeon Platinum 8260 2.4 GHz VPS, load from other machines) against ~45-55 µs here (Ryzen 9700X, k6
on the same host). Locally the 65,535 fds stopped it at ~30% CPU, so the CPU limit never showed.
Hand-written PHP + Swoole entries use 94-101 µs there.

`profile.sh` runs `k6/rate.js` at a fixed rate (8,000 req/s, the challenge's request mix) against
one worker in a 1 vCPU container and divides the container's CPU time by the requests; excimer
samples the worker (`/_bench/profile/start|stop`, `BENCH_PROFILE=1`). Profile with the JIT off
(`INI=php-nojit-nolimit.ini`): with it on, excimer's self times land on the wrong functions.

| | CPU per request |
|---|---|
| raw PHP + OpenSwoole (the leaderboard's #24 `server.php`, same image) | 23 µs |
| pype 0.13.0 | 45 µs |
| pype 0.13.1 | 38.5 µs |

Where 0.13.0's time went: SQLite ~30%, OpenSwoole + the socket write ~20%, pipeline/container/stage
calls ~13%, hydrating rows into objects ~9%, building the query ~8.5%, JSON + router + request ~10%.
0.13.1 cut the hydrator (PHP's coercion instead of a cast per value), container reflection (once per
class), the pipeline's closures (by index), and the static-file check. Memoizing `Router::compile`
would save 0.27 µs: not done.

### The $12-server challenge at the real box size (2026-11-06, pype 0.12, `../twelve-bench/`)

The challenge box is 1 vCPU / 2 GB (the round below used 2 vCPU / 4 GB by mistake). pype 0.12 on
OpenSwoole, serving directly (no nginx), one worker. Submitted as challenge PR #335
(`php-pype-dayne-valourite`, Ubuntu's PHP 8.3).

| | result |
|---|---|
| challenge users (`load.js`, 5 min hold) | **68,000** pass (p99 3-4 ms, 0.7-0.8% errors, CPU ~30%) |
| limit | the box's 65,535 file descriptors (`accept() failed: Too many open files`), not CPU |
| max req/s, process mode, opcache + JIT | ~20-22k |
| max req/s, base mode, opcache + JIT | 24k passes at 91% CPU |

| php.ini (process mode) | CPU at 10k req/s | 20k req/s passed |
|---|---|---|
| opcache off | 57% | 1 of 3 |
| opcache | 50% | 1 of 3 |
| opcache + tracing JIT | 49% | 3 of 3 |

- 2 workers on 1 vCPU failed at 20k req/s: 1 worker per core holds.
- Base mode is ~20% cheaper per request than process mode here, but needs `memory_limit=-1`
  (see GUIDE, "Mode and worker recycling").
- Leaderboard context: hand-written PHP + Swoole entries score 71,000 (the same fd cap),
  Symfony/FrankenPHP 21,000, CodeIgniter/FrankenPHP 9,500.

### The $12-server challenge: pype vs Laravel (2026-10-20, `../twelve-bench/`)

The challenge's 5-endpoint feed API on SQLite, built idiomatically with pype (OpenSwoole) and
Laravel 13.37 (Octane on Swoole), nginx in front, 2 vCPU / 4 GB. Full method, caveats and
numbers in `../twelve-bench/README.md`.

| | pype 0.7.0 | Laravel 13.37 |
|---|---|---|
| max req/s (challenge mix) | 23,000 | 2,700 |
| challenge users (`load.js`) | 40,000 pass at 31% CPU (generator limit) | ~24,000 |
| PHP CPU per request | ~52 µs | ~0.7 ms (incl. nginx) |

Profile of pype at 15k req/s (per request): nginx ~14 µs, a bare OpenSwoole server ~14 µs,
SQLite ~15 µs, the framework the rest. The biggest framework cost was building SQL in the query
builder (~6 µs per query, mostly identifier regex + quoting); memoizing quoted identifiers took it
to ~1.4 µs. End to end that's ~3% less PHP CPU per request (alternating runs), within the host's
noise; the max rate stayed ~23–24k req/s.

