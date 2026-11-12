<?php

declare(strict_types=1);

namespace Pype\Log;

use Psr\Log\LoggerInterface;
use Pype\Application;
use Pype\Http\Request;
use Pype\Outlet\HtmlOutlet;
use Pype\Outlet\JsonOutlet;
use Pype\Provider;
use Pype\Routing\Router;
use Pype\Stages\RequestIdValue;

/**
 * Logging from config/log.php: binds the Logger (as Psr\Log\LoggerInterface too), forwards
 * entries to log.forward (stderr, syslog, any PSR-3 logger), sends unexpected errors to it,
 * optionally logs every request, and registers the log viewer.
 *
 * The viewer is on by default only when APP_DEBUG=true. In production it is registered only if
 * enabled AND given stages to guard it (log.viewer.stages), since logs can hold personal data.
 */
final class LogProvider implements Provider
{
    /**
     * log.forward: 'stderr' / 'stdout' (JSON lines for Docker and platform collectors), 'syslog',
     * or the class name of any PSR-3 logger (Monolog with a Sentry handler, your own...).
     *
     * @return list<LoggerInterface>
     */
    private static function forwarders(Application $app): array
    {
        $loggers = [];

        foreach ((array) $app->config('log.forward', []) as $target) {
            $loggers[] = match ($target) {
                'stderr' => new StreamLogger('php://stderr'),
                'stdout' => new StreamLogger('php://stdout'),
                'syslog' => new SyslogLogger((string) $app->config('app.name', 'pype')),
                default => $target instanceof LoggerInterface ? $target : $app->container->get($target),
            };

            if (!end($loggers) instanceof LoggerInterface) {
                throw new \InvalidArgumentException('log.forward entries must be stderr, stdout, syslog or a Psr\Log\LoggerInterface class.');
            }
        }

        return $loggers;
    }

    /**
     * log.viewer.sources: other log folders the viewer offers beside the app's own, for an app
     * that writes more than one log (a folder per tenant, a worker's own). Paths are relative to
     * the app; 'label' => 'path' is one folder, and a path with * is one source per folder that
     * matches, named after the folder:
     *
     *   'sources' => ['Worker' => 'storage/logs/worker', 'storage/logs/tenants/*']
     *
     * @return array<string, string> label => directory
     */
    private static function sources(Application $app): array
    {
        $sources = [];

        foreach ((array) $app->config('log.viewer.sources', []) as $label => $path) {
            $path = $app->path((string) $path);

            if (!str_contains($path, '*')) {
                $sources[is_string($label) ? $label : basename($path)] = $path;
                continue;
            }

            foreach (glob($path, GLOB_ONLYDIR) ?: [] as $directory) {
                $sources[basename($directory)] = $directory;
            }
        }

        return $sources;
    }

    public function register(Application $app): void
    {
        $container = $app->container;
        $directory = $app->path($app->config('log.path', 'storage/logs'));

        $container->singleton(Logger::class, fn () => new Logger(
            $directory,
            (string) $app->config('log.level', 'debug'),
            (int) $app->config('log.days', 14),
            // One request at a time per process (PHP-FPM, OpenSwoole without coroutines), so the
            // request bound in the container is the one being handled.
            static fn (): ?string => $container->resolved(Request::class) ? RequestIdValue::for($container->get(Request::class))->value : null,
            self::forwarders($app),
            (string) $app->config('log.forward_level', 'debug'),
        ));
        $container->bind(LoggerInterface::class, fn ($c) => $c->get(Logger::class));
        $container->singleton(LogReader::class, fn () => new LogReader($directory));
        $container->singleton(LogViewer::class, fn ($c) => new LogViewer($c->get(LogReader::class), self::sources($app)));

        $app->reportUsing(LogReporter::class);

        $viewer = rtrim((string) $app->config('log.viewer.path', '/_pype/logs'), '/');

        if ($app->config('log.requests', false)) {
            $app->through(new LogRequests(except: [$viewer]));
        }

        $stages = $app->config('log.viewer.stages', []);
        if ($app->config('log.viewer.enabled', false) && ($app->config('app.debug') || $stages !== [])) {
            $app->router->group($viewer, $stages, function (Router $r) {
                $r->get('/', [ShowLogs::class, HtmlOutlet::class])->name('logs');
                $r->get('/entries', [ShowLogEntries::class, JsonOutlet::class])->name('logs.entries');
            }, name: 'pype.');
        }
    }
}
