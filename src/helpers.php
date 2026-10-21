<?php

declare(strict_types=1);

use Pype\Http\Response;
use Pype\Pipeline\StageSpec;
use Pype\Routing\Urls;
use Pype\Support\Env;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('e')) {
    /** Escape a value for HTML output. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * Link to a named route: url('expenses.edit', ['id' => 3]) → /expenses/3/edit.
     * Params fill {placeholders}; the rest become the query string. $absolute prefixes APP_URL.
     */
    function url(string $name, array $params = [], bool $absolute = false): string
    {
        return Urls::current()->to($name, $params, $absolute);
    }
}

if (!function_exists('signed_url')) {
    /**
     * A tamper-proof (and optionally expiring) absolute link to a named route, for emails:
     * signed_url('password.reset', ['user' => 5], expiresIn: 3600). Guard the route with ValidateSignature.
     */
    function signed_url(string $name, array $params = [], ?int $expiresIn = null): string
    {
        return Urls::current()->signed($name, $params, $expiresIn);
    }
}

if (!function_exists('redirect')) {
    /**
     * A redirect a stage can return to stop the pipeline there:
     *
     *   return redirect('quotes.index', flash: 'That quote no longer exists.');
     *   return redirect('quotes.show', ['id' => $quote->id], flash: ['status' => 'Sent.']);
     *   return redirect('/login');
     *
     * $to is a route name, or a path/URL (starts with / or has ://). A string $flash is the
     * 'status' message; it reaches the session through the response (StartSession applies it),
     * so routes without 'web' simply drop it. 303: the browser follows up with a GET.
     *
     * @param array<string, mixed> $params
     * @param string|array<string, mixed> $flash
     */
    function redirect(string $to, array $params = [], string|array $flash = [], int $status = 303): Response
    {
        $response = Response::redirect(Urls::current()->target($to, $params), $status);

        return $flash === [] ? $response : $response->withFlash(is_string($flash) ? ['status' => $flash] : $flash);
    }
}

if (!function_exists('stage')) {
    /**
     * A stage built when a request reaches it, with these arguments and its services autowired:
     *
     *   $router->get('/quotes', [ResolveListing::class, stage(LoadDocumentsPage::class, kind: 'quote'), QuotesView::class]);
     *
     * Unlike `new LoadDocumentsPage('quote')`, nothing is loaded while the routes file runs, the
     * constructor can ask for services, and each request gets a fresh instance.
     *
     * @param class-string $class
     */
    function stage(string $class, mixed ...$args): StageSpec
    {
        return new StageSpec($class, $args);
    }
}
