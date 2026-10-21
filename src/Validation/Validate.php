<?php

declare(strict_types=1);

namespace Pype\Validation;

use Closure;
use Psr\Container\ContainerInterface;
use Pype\Container\ContainerAware;
use Pype\Database\Connection;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Pipeline\Provides;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;

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
#[Provides(ValidatedInput::class)]
class Validate implements Stage, ContainerAware
{
    use WithArguments;

    private ?ContainerInterface $container = null;

    // Defaults, not promoted: subclasses with their own constructor don't have to call parent's.
    private array $rules = [];
    private array $messages = [];
    private int $status = 422;

    /**
     * @param array<string, string|list<string|Rule|Closure>> $rules
     * @param array<string, string>                            $messages "field.rule", "field" or "rule" => message
     * @param int                                              $status   of the error response (422, or e.g. 400 if your API says so)
     */
    public function __construct(array $rules = [], array $messages = [], int $status = 422)
    {
        $this->rules = $rules;
        $this->messages = $messages;
        $this->status = $status;
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /**
     * The rules for this request. Override it instead of rules() when they depend on the request or
     * the context, e.g. a scope from the signed-in user:
     *
     *   protected function rulesFor(Request $request): array
     *   {
     *       $user = $request->context()->get(User::class);
     *       return ['sku' => ['required', new Unique('items', 'sku', where: ['company_id' => $user->company_id], ignore: $request->param('id'))]];
     *   }
     *
     * In string rules, {name} is a route param and {user.prop} a property of the signed-in user
     * (Authenticate must run first): 'unique:items,sku,{id},id,company_id={user.company_id}'.
     */
    protected function rulesFor(Request $request): array
    {
        return $this->rules();
    }

    /**
     * {user.prop} placeholders used in string rules → values from the signed-in user.
     *
     * @return array<string, string>
     */
    private function userValues(Request $request, array $rules): array
    {
        preg_match_all('/\{user\.(\w+)\}/', json_encode($rules, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '', $m);

        if ($m[1] === []) {
            return [];
        }

        $class = $this->container?->has(\Pype\Auth\Auth::class) ? $this->container->get(\Pype\Auth\Auth::class)->userClass() : null;
        $user = $class === null ? null : $request->context()->find($class);

        if ($user === null) {
            throw new \LogicException('A rule uses {user.' . $m[1][0] . '}, but nobody is signed in on this route: put Authenticate (or \'auth\') before ' . static::class . '.');
        }

        $values = [];
        foreach (array_unique($m[1]) as $property) {
            $values["user.{$property}"] = (string) ($user->{$property} ?? '');
        }

        return $values;
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

    /** The error response's status. Override in subclasses (default 422). */
    protected function status(): int
    {
        return $this->status;
    }

    /** Which input to validate: the body plus uploaded files. Override to validate query params instead. */
    protected function data(Request $request): array
    {
        return [...$request->input(), ...$request->files()];
    }

    /**
     * Tidy the input before the rules run: drop the rows a form sent empty, normalise a value.
     * Keep the keys: errors then name the rows the browser shows (lines.2.quantity is still row 2),
     * and the form is refilled from the request as sent. The request itself is not changed.
     *
     *   protected function prepare(array $input): array
     *   {
     *       $input['lines'] = array_filter($input['lines'] ?? [], fn (array $row) => array_filter($row) !== []);
     *
     *       return $input;
     *   }
     *
     * After validation, a field that came in as a list (0, 1, 2 ...) and lost rows here is numbered
     * again for ValidatedInput (0, 2, 4 → 0, 1, 2); arrays keyed on purpose (prices[17]) keep their keys.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function prepare(array $input): array
    {
        return $input;
    }

    /**
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $input     as the request sent it
     * @param array<string, mixed> $prepared  after prepare()
     * @return array<string, mixed>
     */
    private static function renumber(array $validated, array $input, array $prepared): array
    {
        if ($prepared === $input) {
            return $validated;
        }

        foreach ($validated as $field => $value) {
            if (is_array($value) && !array_is_list($value) && is_array($input[$field] ?? null) && array_is_list($input[$field])
                && ($prepared[$field] ?? null) !== $input[$field]) {
                $validated[$field] = array_values($value);
            }
        }

        return $validated;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $rules = $this->rulesFor($request);
        $rules = self::withParams($rules, [...$request->params(), ...$this->userValues($request, $rules)]);

        $input = $this->data($request);
        $prepared = $this->prepare($input);

        [$validated, $errors] = Validator::validate($prepared, $rules, $this->messages(), $this->connection());

        if ($errors !== []) {
            throw (new HttpException($this->status(), 'Validation failed', ['errors' => $errors]))->withKey('validation');
        }

        $request->context()->put(new ValidatedInput(self::renumber($validated, $input, $prepared)));

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
