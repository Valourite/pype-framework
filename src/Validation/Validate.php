<?php

declare(strict_types=1);

namespace Pype\Validation;

use Closure;
use Psr\Container\ContainerInterface;
use Pype\Container\ContainerAware;
use Pype\Database\Connection;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/**
 * Validation as a stage. Fails with 422, or puts ValidatedInput in the context.
 *
 * Inline:     $router->post('/users', [new Validate(['email' => 'required|email|unique:users']), CreateUser::class]);
 * Messages:   new Validate($rules, messages: ['email.unique' => 'That email is already registered.'])
 * Reusable:   final class CreateUserRules extends Validate {
 *                 protected function rules(): array { return ['email' => 'required|email']; }
 *             }
 *
 * {name} in a string rule is replaced by that route param, so an edit form can skip its own row:
 *   $router->patch('/users/{id}', [new Validate(['email' => 'required|email|unique:users,email,{id}']), ...]);
 */
class Validate implements Stage, ContainerAware
{
    private ?ContainerInterface $container = null;

    // Defaults, not promoted: subclasses with their own constructor don't have to call parent's.
    private array $rules = [];
    private array $messages = [];

    /**
     * @param array<string, string|list<string|Rule|Closure>> $rules
     * @param array<string, string>                            $messages "field.rule", "field" or "rule" => message
     */
    public function __construct(array $rules = [], array $messages = [])
    {
        $this->rules = $rules;
        $this->messages = $messages;
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /** Override in subclasses for reusable rule sets. */
    protected function rules(): array
    {
        return $this->rules;
    }

    /** Override in subclasses for their own messages. */
    protected function messages(): array
    {
        return $this->messages;
    }

    /** Which input to validate: the body plus uploaded files. Override to validate query params instead. */
    protected function data(Request $request): array
    {
        return [...$request->input(), ...$request->files()];
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $rules = self::withParams($this->rules(), $request->params());

        [$validated, $errors] = Validator::validate($this->data($request), $rules, $this->messages(), $this->connection());

        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        $request->context()->put(new ValidatedInput($validated));

        return $next($request);
    }

    /** {id} in string rules → the route param's value (only for rules that mention one). */
    private static function withParams(array $rules, array $params): array
    {
        if ($params === []) {
            return $rules;
        }

        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }

        foreach ($rules as $field => $fieldRules) {
            if (is_string($fieldRules)) {
                $rules[$field] = str_contains($fieldRules, '{') ? strtr($fieldRules, $replace) : $fieldRules;
                continue;
            }
            foreach ($fieldRules as $i => $rule) {
                if (is_string($rule) && str_contains($rule, '{')) {
                    $rules[$field][$i] = strtr($rule, $replace);
                }
            }
        }

        return $rules;
    }

    /** The app's database, if it has one (Connection is lazy: nothing connects unless a rule queries). */
    private function connection(): ?Connection
    {
        try {
            return $this->container?->get(Connection::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
