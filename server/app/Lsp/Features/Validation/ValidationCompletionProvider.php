<?php

declare(strict_types=1);

namespace App\Lsp\Features\Validation;

use App\Lsp\Contracts\CompletionProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\Utf16Position;
use Throwable;

class ValidationCompletionProvider implements CompletionProvider
{
    /**
     * Fallback snippets for common Laravel validation rules.
     *
     * @var array<string, string>
     */
    protected array $rules = [
        'accepted'             => 'accepted',
        'active_url'           => 'active_url',
        'after_or_equal'       => 'after_or_equal:${1:date}',
        'after'                => 'after:${1:date}',
        'alpha_dash'           => 'alpha_dash',
        'alpha_num'            => 'alpha_num',
        'alpha'                => 'alpha',
        'array'                => 'array',
        'ascii'                => 'ascii',
        'bail'                 => 'bail',
        'before_or_equal'      => 'before_or_equal:${1:date}',
        'before'               => 'before:${1:date}',
        'between'              => 'between:${1:min},${2:max}',
        'boolean'              => 'boolean',
        'confirmed'            => 'confirmed',
        'current_password'     => 'current_password:${1:api}',
        'date_equals'          => 'date_equals:${1:date}',
        'date_format'          => 'date_format:${1:format}',
        'date'                 => 'date',
        'decimal'              => 'decimal:${1:min},${2:max}',
        'declined_if'          => 'declined_if:${1:anotherfield},${2:value}',
        'declined'             => 'declined',
        'different'            => 'different:${1:field}',
        'digits_between'       => 'digits_between:${1:min},${2:max}',
        'digits'               => 'digits:${1:value}',
        'dimensions'           => 'dimensions',
        'distinct'             => 'distinct',
        'doesnt_end_with'      => 'doesnt_end_with:${1:foo},${2:bar}',
        'doesnt_start_with'    => 'doesnt_start_with:${1:foo},${2:bar}',
        'email'                => 'email',
        'ends_with'            => 'ends_with:${1}',
        'exists'               => 'exists:${2:table},${3:column}',
        'file'                 => 'file',
        'filled'               => 'filled',
        'gt'                   => 'gt:${1:field}',
        'gte'                  => 'gte:${1:field}',
        'image'                => 'image',
        'in_array'             => 'in_array:${1:anotherfield.*}',
        'in'                   => 'in:${1:something},${2:something else}',
        'integer'              => 'integer',
        'ip'                   => 'ip',
        'ipv4'                 => 'ipv4',
        'ipv6'                 => 'ipv6',
        'json'                 => 'json',
        'lowercase'            => 'lowercase',
        'lt'                   => 'lt:${1:field}',
        'lte'                  => 'lte:${1:field}',
        'mac_address'          => 'mac_address',
        'max_digits'           => 'max_digits:${1:value}',
        'max'                  => 'max:${1:value}',
        'mimes'                => 'mimes:${1:png,jpg}',
        'mimetypes'            => 'mimetypes:${1:text/plain}',
        'min_digits'           => 'min_digits:${1:value}',
        'min'                  => 'min:${1:value}',
        'multiple_of'          => 'multiple_of:${1:value}',
        'not_in'               => 'not_in:${1:something},${2:something else}',
        'not_regex'            => 'not_regex:${1:pattern}',
        'nullable'             => 'nullable',
        'numeric'              => 'numeric',
        'password'             => 'password',
        'present'              => 'present',
        'prohibited_if'        => 'prohibited_if:${1:anotherfield},${2:value}',
        'prohibited_unless'    => 'prohibited_unless:${1:anotherfield},${2:value}',
        'prohibited'           => 'prohibited',
        'regex'                => 'regex:${1:pattern}',
        'required_array_keys'  => 'required_array_keys:${1:foo},${2:bar}',
        'required_if'          => 'required_if:${1:anotherfield},${2:value}',
        'required_unless'      => 'required_unless:${1:anotherfield},${2:value}',
        'required_with_all'    => 'required_with_all:${1:anotherfield},${2:anotherfield}',
        'required_with'        => 'required_with:${1:anotherfield}',
        'required_without_all' => 'required_without_all:${1:anotherfield},${2:anotherfield}',
        'required_without'     => 'required_without:${1:anotherfield}',
        'required'             => 'required',
        'same'                 => 'same:${1:field}',
        'size'                 => 'size:${1:value}',
        'sometimes'            => 'sometimes',
        'starts_with'          => 'starts_with:${1:foo},${2:bar}',
        'string'               => 'string',
        'timezone'             => 'timezone',
        'unique'               => 'unique:${1:table},${2:column},${3:except},${4:id}',
        'uppercase'            => 'uppercase',
        'url'                  => 'url',
        'uuid'                 => 'uuid',
    ];

    /**
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $indexedRulesCache = null;

    public function __construct(
        protected ?Project $project = null,
    ) {}

    /**
     * Provide validation rule completions for the given document and position.
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, array $position): array
    {
        $item = $document->autocomplete($position);

        if ($item === [] || !$this->shouldComplete($item)) {
            return [];
        }

        return $this->completionItems($document, $position);
    }

    /**
     * Determine if validation completions apply to the autocomplete item.
     *
     * @param  array<string, mixed>  $item
     */
    protected function shouldComplete(array $item): bool
    {
        if ($this->isValidationMethodCall($item)) {
            return true;
        }

        return $this->isRulesMethodArray($item);
    }

    /**
     * Determine if the item is a validation method call completion context.
     *
     * @param  array<string, mixed>  $item
     */
    protected function isValidationMethodCall(array $item): bool
    {
        if (($item['type'] ?? null) !== 'methodCall') {
            return false;
        }

        $argumentIndex = $item['arguments']['autocompletingIndex'] ?? null;
        $rulesArgumentIndex = $this->rulesArgumentIndex($item);

        if (!is_int($argumentIndex) || $rulesArgumentIndex === null || $argumentIndex !== $rulesArgumentIndex) {
            return false;
        }

        return !$this->isCompletingArrayKeyInArgument($item, $rulesArgumentIndex);
    }

    /**
     * Get the validation rules argument index for the method call.
     *
     * @param  array<string, mixed>  $item
     */
    protected function rulesArgumentIndex(array $item): ?int
    {
        $method = $item['methodName'] ?? null;
        $class = $item['className'] ?? null;

        if (!is_string($method) || !is_string($class)) {
            return null;
        }

        if ($method === 'validate' && $this->isRequestClass($class)) {
            return 0;
        }

        if ($method === 'validate' && $this->isValidatorClass($class)) {
            return 1;
        }

        if ($method === 'make' && $this->isValidatorFactoryClass($class)) {
            return 1;
        }

        if ($method === 'sometimes' && $this->isValidatorClass($class)) {
            return 1;
        }

        return null;
    }

    /**
     * Determine if the class is a request validation target.
     */
    protected function isRequestClass(string $class): bool
    {
        return in_array($class, [
            'Illuminate\\Http\\Request',
            'request',
        ], true);
    }

    /**
     * Determine if the class is a validator target.
     */
    protected function isValidatorClass(string $class): bool
    {
        return in_array($class, [
            'Validator',
            'Illuminate\\Support\\Facades\\Validator',
            'Illuminate\\Contracts\\Validation\\Factory',
            'Illuminate\\Contracts\\Validation\\Validator',
        ], true);
    }

    /**
     * Determine if the class is a validator factory target.
     */
    protected function isValidatorFactoryClass(string $class): bool
    {
        return in_array($class, [
            'Validator',
            'Illuminate\\Support\\Facades\\Validator',
            'Illuminate\\Contracts\\Validation\\Factory',
        ], true);
    }

    /**
     * Determine if the current argument is completing an array key.
     *
     * @param  array<string, mixed>  $item
     */
    protected function isCompletingArrayKeyInArgument(array $item, int $argumentIndex): bool
    {
        $argument = $item['arguments']['children'][$argumentIndex]['children'][0] ?? null;

        return is_array($argument)
            && ($argument['type'] ?? null) === 'array'
            && ($argument['autocompletingKey'] ?? false) === true;
    }

    /**
     * Determine if the item is inside a FormRequest or Livewire Form rules method.
     *
     * @param  array<string, mixed>  $item
     */
    protected function isRulesMethodArray(array $item): bool
    {
        if (!$this->isInsideRulesMethod($item) || !$this->isInsideValidationRulesClass($item)) {
            return false;
        }

        return !$this->isCompletingTopLevelArrayKey($item);
    }

    /**
     * Determine if the item is inside a rules method definition.
     *
     * @param  array<string, mixed>  $item
     */
    protected function isInsideRulesMethod(array $item): bool
    {
        $method = $this->ancestorOfType($item, 'methodDefinition');

        return is_array($method) && ($method['methodName'] ?? null) === 'rules';
    }

    /**
     * Determine if the item is inside a validation rules class.
     *
     * @param  array<string, mixed>  $item
     */
    protected function isInsideValidationRulesClass(array $item): bool
    {
        $class = $this->ancestorOfType($item, 'classDefinition');
        $extends = is_array($class) ? ($class['extends'] ?? null) : null;

        return is_string($extends) && in_array($extends, [
            'Illuminate\\Foundation\\Http\\FormRequest',
            'Livewire\\Form',
        ], true);
    }

    /**
     * Determine if the current array completion is a top-level field key.
     *
     * @param  array<string, mixed>  $item
     */
    protected function isCompletingTopLevelArrayKey(array $item): bool
    {
        if (($item['type'] ?? null) !== 'array' || ($item['autocompletingKey'] ?? false) !== true) {
            return false;
        }

        $parent = $item['parent'] ?? null;

        return !is_array($parent) || ($parent['type'] ?? null) !== 'array_item';
    }

    /**
     * Get the nearest ancestor of the given parser type.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    protected function ancestorOfType(array $item, string $type): ?array
    {
        $parent = $item['parent'] ?? null;

        while (is_array($parent)) {
            if (($parent['type'] ?? null) === $type) {
                return $parent;
            }

            $parent = $parent['parent'] ?? null;
        }

        return null;
    }

    /**
     * Create validation rule completion items.
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    protected function completionItems(Document $document, array $position): array
    {
        $range = $this->replacementRange($document, $position);

        return collect($this->rules())
            ->map(fn (string $insertText, string $label): array => [
                'label'    => $label,
                'kind'     => 13,
                'textEdit' => [
                    'range'   => $range,
                    'newText' => $insertText,
                ],
                'insertTextFormat' => 2,
            ])
            ->values()
            ->all();
    }

    /**
     * Get the range that should be replaced by the completion.
     *
     * @param  array<string, mixed>  $position
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     */
    protected function replacementRange(Document $document, array $position): array
    {
        $line = (int) $position['line'];
        $character = (int) $position['character'];
        $lines = explode("\n", $document->content);
        $text = Utf16Position::substr($lines[$line] ?? '', 0, $character);
        $start = $character - Utf16Position::length($this->fragment($text));

        return [
            'start' => [
                'line'      => $line,
                'character' => max(0, $start),
            ],
            'end' => [
                'line'      => $line,
                'character' => $character,
            ],
        ];
    }

    /**
     * Get the current word fragment.
     */
    protected function fragment(string $text): string
    {
        preg_match('/[\\w\\d\\-_\\.\\:\\\\\/@]+$/', $text, $matches);

        return $matches[0] ?? '';
    }

    /**
     * Return indexed validation rules with fallback snippets filled in.
     *
     * @return array<string, string>
     */
    protected function rules(): array
    {
        $rules = [];

        foreach ($this->indexedRules() as $name => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $ruleName = strtolower((string) ($rule['name'] ?? $name));
            if ($ruleName === '') {
                continue;
            }

            $rules[$ruleName] = $this->rules[$ruleName] ?? ((bool) ($rule['hasParams'] ?? false) ? sprintf('%s:${1}', $ruleName) : $ruleName);
        }

        foreach ($this->rules as $name => $snippet) {
            if (!isset($rules[$name])) {
                $rules[$name] = $snippet;
            }
        }

        return $rules;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function indexedRules(): array
    {
        if ($this->indexedRulesCache !== null) {
            return $this->indexedRulesCache;
        }

        $this->indexedRulesCache = [];

        if ($this->project === null) {
            return $this->indexedRulesCache;
        }

        try {
            if (!method_exists($this->project->index, 'validationRules')) {
                return $this->indexedRulesCache;
            }

            $rules = $this->project->index->validationRules();
            $this->indexedRulesCache = is_array($rules) ? $rules : [];
        } catch (Throwable) {
            return $this->indexedRulesCache;
        }

        return $this->indexedRulesCache;
    }
}
