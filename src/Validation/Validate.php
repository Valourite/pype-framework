<?php

declare(strict_types=1);

namespace Pype\Validation;

use Closure;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/**
 * Validation as a stage. Fails with 422, or puts ValidatedInput in the context.
 *
 * Inline:     $router->post('/users', [new Validate(['email' => 'required|email']), CreateUser::class]);
 * Reusable:   final class CreateUserRules extends Validate {
 *                 protected function rules(): array { return ['email' => 'required|email']; }
 *             }
 */
class Validate implements Stage
{
    /** @param array<string, string|list<string|Closure>> $rules */
    public function __construct(private readonly array $rules = [])
    {
    }

    /** Override in subclasses for reusable rule sets. */
    protected function rules(): array
    {
        return $this->rules;
    }

    /** Which input to validate: the body plus uploaded files. Override to validate query params instead. */
    protected function data(Request $request): array
    {
        return [...$request->input(), ...$request->files()];
    }

    public function handle(Request $request, Closure $next): mixed
    {
        [$validated, $errors] = Validator::validate($this->data($request), $this->rules());

        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        $request->context()->put(new ValidatedInput($validated));

        return $next($request);
    }
}
