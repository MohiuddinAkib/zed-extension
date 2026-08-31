<?php

declare(strict_types=1);

namespace App\Lsp\Detection;

class Pattern
{
    /**
     * Create a new detected pattern instance.
     *
     * @param  array<int, string>  $classes
     * @param  array<int, string>  $methods
     * @param  array<int, int>  $arguments
     */
    protected function __construct(
        protected string $type,
        protected array $classes,
        protected array $methods,
        protected array $arguments,
    ) {}

    /**
     * Create a method call pattern.
     */
    public static function method(string|array|null $method = null, string|array|null $class = null, int|array $argument = 0): self
    {
        return new self(
            type: 'methodCall',
            classes: self::strings($class),
            methods: self::strings($method),
            arguments: self::integers($argument),
        );
    }

    /**
     * Create an attribute/object pattern.
     */
    public static function attribute(string|array $class, int|array $argument = 0): self
    {
        return new self(
            type: 'object',
            classes: self::strings($class),
            methods: [],
            arguments: self::integers($argument),
        );
    }

    /**
     * Get a container attribute class.
     */
    public static function containerAttribute(string $class): string
    {
        return "Illuminate\\Container\\Attributes\\{$class}";
    }

    /**
     * Get a contract class.
     */
    public static function contract(string $class): string
    {
        return "Illuminate\\Contracts\\{$class}";
    }

    /**
     * Get a support class.
     */
    public static function support(string $class): string
    {
        return "Illuminate\\Support\\{$class}";
    }

    /**
     * Get facade aliases for a class.
     *
     * @return array<int, string>
     */
    public static function facade(string $class): array
    {
        return [$class, self::support("Facades\\{$class}")];
    }

    /**
     * Determine if the detected item matches this pattern.
     *
     * @param  array<string, mixed>  $item
     */
    public function matches(array $item): bool
    {
        if ($item['type'] !== $this->type) {
            return false;
        }

        if (!$this->matchesMethod($item)) {
            return false;
        }

        return $this->matchesClass($item);
    }

    /**
     * Get the argument indexes matched by this pattern.
     *
     * @return array<int, int>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * Determine if the detected item method matches this pattern.
     *
     * @param  array<string, mixed>  $item
     */
    protected function matchesMethod(array $item): bool
    {
        if ($this->type !== 'methodCall' || $this->methods === []) {
            return true;
        }

        $method = $item['methodName'];

        return is_string($method) && in_array($method, $this->methods, true);
    }

    /**
     * Determine if the detected item class matches this pattern.
     *
     * @param  array<string, mixed>  $item
     */
    protected function matchesClass(array $item): bool
    {
        $class = $item['className'];

        if ($this->classes === []) {
            return $class === null;
        }

        return is_string($class) && in_array($class, $this->classes, true);
    }

    /**
     * Normalize a string or string list.
     *
     * @return array<int, string>
     */
    protected static function strings(string|array|null $values): array
    {
        if ($values === null) {
            return [];
        }

        return array_values(array_filter(
            is_array($values) ? $values : [$values],
            fn (mixed $value): bool => is_string($value),
        ));
    }

    /**
     * Normalize an integer or integer list.
     *
     * @return array<int, int>
     */
    protected static function integers(int|array $values): array
    {
        return array_values(array_filter(
            is_array($values) ? $values : [$values],
            fn (mixed $value): bool => is_int($value),
        ));
    }
}
