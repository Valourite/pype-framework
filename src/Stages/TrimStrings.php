<?php

declare(strict_types=1);

namespace Pype\Stages;

use Closure;
use Pype\Http\Request;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;

/**
 * Trims whitespace from every string in the query and body input (nested arrays too), so
 * "  ada@x.test " validates and saves as "ada@x.test". Passwords are left as typed.
 *
 *   $app->through(new TrimStrings());                       // global, before Validate
 *   new TrimStrings(except: ['bio'], emptyToNull: true)     // '' (after trimming) becomes null
 */
final class TrimStrings implements Stage
{
    use WithArguments;

    /** @param list<string> $except  top-level field names left alone */
    public function __construct(
        private readonly array $except = ['password', 'password_confirmation', 'current_password'],
        private readonly bool $emptyToNull = false,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $body = $request->input();
        $query = $request->query();

        if ($body === [] && $query === []) {
            return $next($request);
        }

        return $next($request->withInput($this->clean($body, true), $this->clean($query, true)));
    }

    private function clean(array $values, bool $top): array
    {
        foreach ($values as $key => $value) {
            if ($top && in_array($key, $this->except, true)) {
                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->clean($value, false);
            } elseif (is_string($value)) {
                $value = trim($value);
                $values[$key] = $value === '' && $this->emptyToNull ? null : $value;
            }
        }

        return $values;
    }
}
