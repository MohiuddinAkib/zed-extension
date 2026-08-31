<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

class EloquentBuilderRegistry
{
    /**
     * Common Eloquent and Database Query Builder methods.
     *
     * @var array<string, array{signature: string, return: string, snippet: string, requiredParams: int, doc: string}>
     */
    public const BUILDER_METHODS = [
        'query' => [
            'signature' => '(): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'query()',
            'requiredParams' => 0,
            'doc' => 'Begin querying the model.',
        ],
        'where' => [
            'signature' => '(string|array|\Closure $column, mixed $operator = null, mixed $value = null, string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'where(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a basic where clause to the query.',
        ],
        'orWhere' => [
            'signature' => '(string|array|\Closure $column, mixed $operator = null, mixed $value = null): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'orWhere(${1})',
            'requiredParams' => 1,
            'doc' => 'Add an "or where" clause to the query.',
        ],
        'whereIn' => [
            'signature' => '(string $column, mixed $values, string $boolean = \'and\', bool $not = false): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereIn(${1})',
            'requiredParams' => 2,
            'doc' => 'Add a "where in" clause to the query.',
        ],
        'whereNotIn' => [
            'signature' => '(string $column, mixed $values, string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereNotIn(${1})',
            'requiredParams' => 2,
            'doc' => 'Add a "where not in" clause to the query.',
        ],
        'orWhereIn' => [
            'signature' => '(string $column, mixed $values): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'orWhereIn(${1})',
            'requiredParams' => 2,
            'doc' => 'Add an "or where in" clause to the query.',
        ],
        'orWhereNotIn' => [
            'signature' => '(string $column, mixed $values): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'orWhereNotIn(${1})',
            'requiredParams' => 2,
            'doc' => 'Add an "or where not in" clause to the query.',
        ],
        'whereNull' => [
            'signature' => '(string|array $columns, string $boolean = \'and\', bool $not = false): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereNull(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a "where null" clause to the query.',
        ],
        'whereNotNull' => [
            'signature' => '(string|array $columns, string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereNotNull(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a "where not null" clause to the query.',
        ],
        'orWhereNull' => [
            'signature' => '(string|array $columns): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'orWhereNull(${1})',
            'requiredParams' => 1,
            'doc' => 'Add an "or where null" clause to the query.',
        ],
        'orWhereNotNull' => [
            'signature' => '(string|array $columns): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'orWhereNotNull(${1})',
            'requiredParams' => 1,
            'doc' => 'Add an "or where not null" clause to the query.',
        ],
        'whereBetween' => [
            'signature' => '(string $column, iterable $values, string $boolean = \'and\', bool $not = false): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereBetween(${1})',
            'requiredParams' => 2,
            'doc' => 'Add a "where between" clause to the query.',
        ],
        'whereNotBetween' => [
            'signature' => '(string $column, iterable $values, string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereNotBetween(${1})',
            'requiredParams' => 2,
            'doc' => 'Add a "where not between" clause to the query.',
        ],
        'whereDate' => [
            'signature' => '(string $column, string $operator, mixed $value = null, string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereDate(${1})',
            'requiredParams' => 2,
            'doc' => 'Add a "where date" statement to the query.',
        ],
        'whereMonth' => [
            'signature' => '(string $column, string $operator, mixed $value = null, string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereMonth(${1})',
            'requiredParams' => 2,
            'doc' => 'Add a "where month" statement to the query.',
        ],
        'whereDay' => [
            'signature' => '(string $column, string $operator, mixed $value = null, string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereDay(${1})',
            'requiredParams' => 2,
            'doc' => 'Add a "where day" statement to the query.',
        ],
        'whereYear' => [
            'signature' => '(string $column, string $operator, mixed $value = null, string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereYear(${1})',
            'requiredParams' => 2,
            'doc' => 'Add a "where year" statement to the query.',
        ],
        'whereTime' => [
            'signature' => '(string $column, string $operator, mixed $value = null, string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereTime(${1})',
            'requiredParams' => 2,
            'doc' => 'Add a "where time" statement to the query.',
        ],
        'whereColumn' => [
            'signature' => '(string|array $first, ?string $operator = null, ?string $second = null, string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereColumn(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a "where" clause comparing two columns to the query.',
        ],
        'whereHas' => [
            'signature' => '(string $relation, ?\Closure $callback = null, string $operator = \'>=\', int $count = 1): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereHas(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a relationship count / exists condition to the query with where clauses.',
        ],
        'whereDoesntHave' => [
            'signature' => '(string $relation, ?\Closure $callback = null): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereDoesntHave(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a relationship count / exists condition to the query with where clauses (does not have).',
        ],
        'orWhereHas' => [
            'signature' => '(string $relation, ?\Closure $callback = null, string $operator = \'>=\', int $count = 1): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'orWhereHas(${1})',
            'requiredParams' => 1,
            'doc' => 'Add an "or where has" relationship condition to the query.',
        ],
        'whereRaw' => [
            'signature' => '(string $sql, array $bindings = [], string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'whereRaw(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a raw where clause to the query.',
        ],
        'orWhereRaw' => [
            'signature' => '(string $sql, array $bindings = []): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'orWhereRaw(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a raw "or where" clause to the query.',
        ],
        'find' => [
            'signature' => '(mixed $id, array $columns = [\'*\']): ?Model',
            'return' => '?Model',
            'snippet' => 'find(${1})',
            'requiredParams' => 1,
            'doc' => 'Find a model by its primary key.',
        ],
        'findOrFail' => [
            'signature' => '(mixed $id, array $columns = [\'*\']): Model',
            'return' => 'Model',
            'snippet' => 'findOrFail(${1})',
            'requiredParams' => 1,
            'doc' => 'Find a model by its primary key or throw an exception.',
        ],
        'findMany' => [
            'signature' => '(iterable $ids, array $columns = [\'*\']): \Illuminate\Database\Eloquent\Collection<int, Model>',
            'return' => '\Illuminate\Database\Eloquent\Collection<int, Model>',
            'snippet' => 'findMany(${1})',
            'requiredParams' => 1,
            'doc' => 'Find multiple models by their primary keys.',
        ],
        'findOrNew' => [
            'signature' => '(mixed $id, array $columns = [\'*\']): Model',
            'return' => 'Model',
            'snippet' => 'findOrNew(${1})',
            'requiredParams' => 1,
            'doc' => 'Find a model by its primary key or return fresh model instance.',
        ],
        'first' => [
            'signature' => '(array $columns = [\'*\']): ?Model',
            'return' => '?Model',
            'snippet' => 'first()',
            'requiredParams' => 0,
            'doc' => 'Execute the query and get the first result.',
        ],
        'firstOrFail' => [
            'signature' => '(array $columns = [\'*\']): Model',
            'return' => 'Model',
            'snippet' => 'firstOrFail()',
            'requiredParams' => 0,
            'doc' => 'Execute the query and get the first result or throw an exception.',
        ],
        'firstOrNew' => [
            'signature' => '(array $attributes = [], array $values = []): Model',
            'return' => 'Model',
            'snippet' => 'firstOrNew()',
            'requiredParams' => 0,
            'doc' => 'Get the first record matching the attributes or instantiate it.',
        ],
        'firstOrCreate' => [
            'signature' => '(array $attributes = [], array $values = []): Model',
            'return' => 'Model',
            'snippet' => 'firstOrCreate(${1})',
            'requiredParams' => 1,
            'doc' => 'Get the first record matching the attributes or create it.',
        ],
        'updateOrCreate' => [
            'signature' => '(array $attributes, array $values = []): Model',
            'return' => 'Model',
            'snippet' => 'updateOrCreate(${1})',
            'requiredParams' => 1,
            'doc' => 'Create or update a record matching the attributes, and fill it with values.',
        ],
        'sole' => [
            'signature' => '(array $columns = [\'*\']): Model',
            'return' => 'Model',
            'snippet' => 'sole()',
            'requiredParams' => 0,
            'doc' => 'Execute the query and get the first result or throw if not exactly one.',
        ],
        'value' => [
            'signature' => '(string $column): mixed',
            'return' => 'mixed',
            'snippet' => 'value(${1})',
            'requiredParams' => 1,
            'doc' => 'Get a single column\'s value from the first result of a query.',
        ],
        'get' => [
            'signature' => '(array $columns = [\'*\']): \Illuminate\Database\Eloquent\Collection<int, Model>',
            'return' => '\Illuminate\Database\Eloquent\Collection<int, Model>',
            'snippet' => 'get()',
            'requiredParams' => 0,
            'doc' => 'Execute the query as a "select" statement and get the collection of results.',
        ],
        'all' => [
            'signature' => '(array $columns = [\'*\']): \Illuminate\Database\Eloquent\Collection<int, Model>',
            'return' => '\Illuminate\Database\Eloquent\Collection<int, Model>',
            'snippet' => 'all()',
            'requiredParams' => 0,
            'doc' => 'Get all of the models from the database.',
        ],
        'cursor' => [
            'signature' => '(): \Illuminate\Support\LazyCollection<int, Model>',
            'return' => '\Illuminate\Support\LazyCollection<int, Model>',
            'snippet' => 'cursor()',
            'requiredParams' => 0,
            'doc' => 'Get a generator for the given query using cursor pagination.',
        ],
        'lazy' => [
            'signature' => '(int $chunkSize = 1000): \Illuminate\Support\LazyCollection<int, Model>',
            'return' => '\Illuminate\Support\LazyCollection<int, Model>',
            'snippet' => 'lazy()',
            'requiredParams' => 0,
            'doc' => 'Query lazily, retrieving records in chunks.',
        ],
        'lazyById' => [
            'signature' => '(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): \Illuminate\Support\LazyCollection<int, Model>',
            'return' => '\Illuminate\Support\LazyCollection<int, Model>',
            'snippet' => 'lazyById()',
            'requiredParams' => 0,
            'doc' => 'Query lazily by ID, retrieving records in chunks.',
        ],
        'chunk' => [
            'signature' => '(int $count, callable $callback): bool',
            'return' => 'bool',
            'snippet' => 'chunk(${1})',
            'requiredParams' => 2,
            'doc' => 'Chunk the results of the query.',
        ],
        'chunkById' => [
            'signature' => '(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool',
            'return' => 'bool',
            'snippet' => 'chunkById(${1})',
            'requiredParams' => 2,
            'doc' => 'Chunk the results of a query by comparing IDs.',
        ],
        'pluck' => [
            'signature' => '(string $column, ?string $key = null): \Illuminate\Support\Collection',
            'return' => '\Illuminate\Support\Collection',
            'snippet' => 'pluck(${1})',
            'requiredParams' => 1,
            'doc' => 'Get an array with the values of a given column.',
        ],
        'with' => [
            'signature' => '(string|array $relations): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'with(${1})',
            'requiredParams' => 1,
            'doc' => 'Set the relationships that should be eager loaded.',
        ],
        'without' => [
            'signature' => '(string|array $relations): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'without(${1})',
            'requiredParams' => 1,
            'doc' => 'Prevent the specified relationships from being eager loaded.',
        ],
        'withCount' => [
            'signature' => '(string|array $relations): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'withCount(${1})',
            'requiredParams' => 1,
            'doc' => 'Add relationship count queries to the query.',
        ],
        'withSum' => [
            'signature' => '(string|array $relation, string $column): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'withSum(${1})',
            'requiredParams' => 2,
            'doc' => 'Add relationship sum queries to the query.',
        ],
        'withAvg' => [
            'signature' => '(string|array $relation, string $column): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'withAvg(${1})',
            'requiredParams' => 2,
            'doc' => 'Add relationship average queries to the query.',
        ],
        'withMin' => [
            'signature' => '(string|array $relation, string $column): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'withMin(${1})',
            'requiredParams' => 2,
            'doc' => 'Add relationship min queries to the query.',
        ],
        'withMax' => [
            'signature' => '(string|array $relation, string $column): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'withMax(${1})',
            'requiredParams' => 2,
            'doc' => 'Add relationship max queries to the query.',
        ],
        'withExists' => [
            'signature' => '(string|array $relation): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'withExists(${1})',
            'requiredParams' => 1,
            'doc' => 'Add relationship exists queries to the query.',
        ],
        'has' => [
            'signature' => '(string $relation, string $operator = \'>=\', int $count = 1, string $boolean = \'and\', ?\Closure $callback = null): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'has(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a relationship count / exists condition to the query.',
        ],
        'doesntHave' => [
            'signature' => '(string $relation, string $boolean = \'and\', ?\Closure $callback = null): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'doesntHave(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a relationship count / exists condition to the query (does not have).',
        ],
        'select' => [
            'signature' => '(array|mixed $columns = [\'*\']): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'select(${1})',
            'requiredParams' => 0,
            'doc' => 'Set the columns to be selected.',
        ],
        'addSelect' => [
            'signature' => '(array|mixed $column): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'addSelect(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a new select column to the query.',
        ],
        'selectRaw' => [
            'signature' => '(string $expression, array $bindings = []): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'selectRaw(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a new raw select expression to the query.',
        ],
        'distinct' => [
            'signature' => '(): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'distinct()',
            'requiredParams' => 0,
            'doc' => 'Force the query to only return distinct results.',
        ],
        'orderBy' => [
            'signature' => '(string $column, string $direction = \'asc\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'orderBy(${1})',
            'requiredParams' => 1,
            'doc' => 'Add an "order by" clause to the query.',
        ],
        'orderByDesc' => [
            'signature' => '(string $column): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'orderByDesc(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a descending "order by" clause to the query.',
        ],
        'orderByRaw' => [
            'signature' => '(string $sql, array $bindings = []): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'orderByRaw(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a raw "order by" clause to the query.',
        ],
        'latest' => [
            'signature' => '(?string $column = null): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'latest()',
            'requiredParams' => 0,
            'doc' => 'Add an "order by" clause for a timestamp to the query (latest first).',
        ],
        'oldest' => [
            'signature' => '(?string $column = null): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'oldest()',
            'requiredParams' => 0,
            'doc' => 'Add an "order by" clause for a timestamp to the query (oldest first).',
        ],
        'inRandomOrder' => [
            'signature' => '(string $seed = \'\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'inRandomOrder()',
            'requiredParams' => 0,
            'doc' => 'Put the query\'s results in random order.',
        ],
        'reorder' => [
            'signature' => '(?string $column = null, string $direction = \'asc\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'reorder()',
            'requiredParams' => 0,
            'doc' => 'Remove an existing order and optionally add a new order.',
        ],
        'groupBy' => [
            'signature' => '(...$groups): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'groupBy(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a "group by" clause to the query.',
        ],
        'having' => [
            'signature' => '(string $column, ?string $operator = null, ?string $value = null, string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'having(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a "having" clause to the query.',
        ],
        'havingRaw' => [
            'signature' => '(string $sql, array $bindings = [], string $boolean = \'and\'): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'havingRaw(${1})',
            'requiredParams' => 1,
            'doc' => 'Add a raw "having" clause to the query.',
        ],
        'offset' => [
            'signature' => '(int $value): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'offset(${1})',
            'requiredParams' => 1,
            'doc' => 'Set the "offset" value of the query.',
        ],
        'limit' => [
            'signature' => '(int $value): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'limit(${1})',
            'requiredParams' => 1,
            'doc' => 'Set the "limit" value of the query.',
        ],
        'take' => [
            'signature' => '(int $value): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'take(${1})',
            'requiredParams' => 1,
            'doc' => 'Alias to set the "limit" value of the query.',
        ],
        'skip' => [
            'signature' => '(int $value): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'skip(${1})',
            'requiredParams' => 1,
            'doc' => 'Alias to set the "offset" value of the query.',
        ],
        'paginate' => [
            'signature' => '(?int $perPage = null, array $columns = [\'*\'], string $pageName = \'page\', ?int $page = null): \Illuminate\Pagination\LengthAwarePaginator<int, Model>',
            'return' => '\Illuminate\Pagination\LengthAwarePaginator<int, Model>',
            'snippet' => 'paginate()',
            'requiredParams' => 0,
            'doc' => 'Paginate the given query into a simple paginator.',
        ],
        'simplePaginate' => [
            'signature' => '(?int $perPage = null, array $columns = [\'*\'], string $pageName = \'page\', ?int $page = null): \Illuminate\Pagination\Paginator<int, Model>',
            'return' => '\Illuminate\Pagination\Paginator<int, Model>',
            'snippet' => 'simplePaginate()',
            'requiredParams' => 0,
            'doc' => 'Paginate the given query into a simple paginator without counting total pages.',
        ],
        'cursorPaginate' => [
            'signature' => '(?int $perPage = null, array $columns = [\'*\'], string $cursorName = \'cursor\', ?\Illuminate\Pagination\Cursor $cursor = null): \Illuminate\Pagination\CursorPaginator<int, Model>',
            'return' => '\Illuminate\Pagination\CursorPaginator<int, Model>',
            'snippet' => 'cursorPaginate()',
            'requiredParams' => 0,
            'doc' => 'Paginate the given query using cursor pagination.',
        ],
        'count' => [
            'signature' => '(string $columns = \'*\'): int',
            'return' => 'int',
            'snippet' => 'count()',
            'requiredParams' => 0,
            'doc' => 'Retrieve the "count" result of the query.',
        ],
        'min' => [
            'signature' => '(string $column): mixed',
            'return' => 'mixed',
            'snippet' => 'min(${1})',
            'requiredParams' => 1,
            'doc' => 'Retrieve the minimum value of a given column.',
        ],
        'max' => [
            'signature' => '(string $column): mixed',
            'return' => 'mixed',
            'snippet' => 'max(${1})',
            'requiredParams' => 1,
            'doc' => 'Retrieve the maximum value of a given column.',
        ],
        'sum' => [
            'signature' => '(string $column): int|float',
            'return' => 'int|float',
            'snippet' => 'sum(${1})',
            'requiredParams' => 1,
            'doc' => 'Retrieve the sum of the values of a given column.',
        ],
        'avg' => [
            'signature' => '(string $column): ?float',
            'return' => '?float',
            'snippet' => 'avg(${1})',
            'requiredParams' => 1,
            'doc' => 'Retrieve the average of the values of a given column.',
        ],
        'average' => [
            'signature' => '(string $column): ?float',
            'return' => '?float',
            'snippet' => 'average(${1})',
            'requiredParams' => 1,
            'doc' => 'Alias for avg().',
        ],
        'exists' => [
            'signature' => '(): bool',
            'return' => 'bool',
            'snippet' => 'exists()',
            'requiredParams' => 0,
            'doc' => 'Determine if any rows exist for the current query.',
        ],
        'doesntExist' => [
            'signature' => '(): bool',
            'return' => 'bool',
            'snippet' => 'doesntExist()',
            'requiredParams' => 0,
            'doc' => 'Determine if no rows exist for the current query.',
        ],
        'create' => [
            'signature' => '(array $attributes = []): Model',
            'return' => 'Model',
            'snippet' => 'create(${1})',
            'requiredParams' => 0,
            'doc' => 'Save a new model and return the instance.',
        ],
        'forceCreate' => [
            'signature' => '(array $attributes): Model',
            'return' => 'Model',
            'snippet' => 'forceCreate(${1})',
            'requiredParams' => 1,
            'doc' => 'Save a new model and return the instance without mass assignment checks.',
        ],
        'createMany' => [
            'signature' => '(iterable $records): \Illuminate\Database\Eloquent\Collection<int, Model>',
            'return' => '\Illuminate\Database\Eloquent\Collection<int, Model>',
            'snippet' => 'createMany(${1})',
            'requiredParams' => 1,
            'doc' => 'Create a collection of models.',
        ],
        'make' => [
            'signature' => '(array $attributes = []): Model',
            'return' => 'Model',
            'snippet' => 'make()',
            'requiredParams' => 0,
            'doc' => 'Create a new instance of the given model (without saving).',
        ],
        'update' => [
            'signature' => '(array $values): int',
            'return' => 'int',
            'snippet' => 'update(${1})',
            'requiredParams' => 1,
            'doc' => 'Update records in the database.',
        ],
        'delete' => [
            'signature' => '(): int|bool|null',
            'return' => 'int|bool|null',
            'snippet' => 'delete()',
            'requiredParams' => 0,
            'doc' => 'Delete records from the database.',
        ],
        'forceDelete' => [
            'signature' => '(): int|bool|null',
            'return' => 'int|bool|null',
            'snippet' => 'forceDelete()',
            'requiredParams' => 0,
            'doc' => 'Run a force delete on the model (even when SoftDeletes are enabled).',
        ],
        'truncate' => [
            'signature' => '(): void',
            'return' => 'void',
            'snippet' => 'truncate()',
            'requiredParams' => 0,
            'doc' => 'Truncate the database table.',
        ],
        'upsert' => [
            'signature' => '(array $values, array|string $uniqueBy, ?array $update = null): int',
            'return' => 'int',
            'snippet' => 'upsert(${1})',
            'requiredParams' => 2,
            'doc' => 'Insert new records or update the existing ones.',
        ],
        'insert' => [
            'signature' => '(array $values): bool',
            'return' => 'bool',
            'snippet' => 'insert(${1})',
            'requiredParams' => 1,
            'doc' => 'Insert new records into the database.',
        ],
        'insertGetId' => [
            'signature' => '(array $values, ?string $sequence = null): int',
            'return' => 'int',
            'snippet' => 'insertGetId(${1})',
            'requiredParams' => 1,
            'doc' => 'Insert a new record and get the value of the primary key.',
        ],
        'insertOrIgnore' => [
            'signature' => '(array $values): int',
            'return' => 'int',
            'snippet' => 'insertOrIgnore(${1})',
            'requiredParams' => 1,
            'doc' => 'Insert new records into the database while ignoring errors.',
        ],
        'destroy' => [
            'signature' => '(mixed $ids): int',
            'return' => 'int',
            'snippet' => 'destroy(${1})',
            'requiredParams' => 1,
            'doc' => 'Destroy the models for the given IDs.',
        ],
        'withTrashed' => [
            'signature' => '(bool $withTrashed = true): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'withTrashed()',
            'requiredParams' => 0,
            'doc' => 'Consider all soft deleted models in the query.',
        ],
        'onlyTrashed' => [
            'signature' => '(): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'onlyTrashed()',
            'requiredParams' => 0,
            'doc' => 'Consider only soft deleted models in the query.',
        ],
        'withoutTrashed' => [
            'signature' => '(): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'withoutTrashed()',
            'requiredParams' => 0,
            'doc' => 'Prevent soft deleted models from being included in query.',
        ],
        'restore' => [
            'signature' => '(): int|bool|null',
            'return' => 'int|bool|null',
            'snippet' => 'restore()',
            'requiredParams' => 0,
            'doc' => 'Restore a soft-deleted model.',
        ],
        'when' => [
            'signature' => '(mixed $value = null, ?callable $callback = null, ?callable $default = null): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'when(${1})',
            'requiredParams' => 1,
            'doc' => 'Apply the callback if the given "value" is truthy.',
        ],
        'unless' => [
            'signature' => '(mixed $value = null, ?callable $callback = null, ?callable $default = null): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'unless(${1})',
            'requiredParams' => 1,
            'doc' => 'Apply the callback if the given "value" is falsy.',
        ],
        'tap' => [
            'signature' => '(?callable $callback = null): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'tap(${1})',
            'requiredParams' => 1,
            'doc' => 'Pass the query to a given callback.',
        ],
        'lockForUpdate' => [
            'signature' => '(): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'lockForUpdate()',
            'requiredParams' => 0,
            'doc' => 'Lock the selected rows in the table for updating.',
        ],
        'sharedLock' => [
            'signature' => '(): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'sharedLock()',
            'requiredParams' => 0,
            'doc' => 'Share lock the selected rows in the table.',
        ],
        'toSql' => [
            'signature' => '(): string',
            'return' => 'string',
            'snippet' => 'toSql()',
            'requiredParams' => 0,
            'doc' => 'Execute the query and get the SQL representation.',
        ],
        'dump' => [
            'signature' => '(): \Illuminate\Database\Eloquent\Builder<static>',
            'return' => '\Illuminate\Database\Eloquent\Builder<Model>',
            'snippet' => 'dump()',
            'requiredParams' => 0,
            'doc' => 'Dump the current query and return the builder.',
        ],
        'dd' => [
            'signature' => '(): never',
            'return' => 'never',
            'snippet' => 'dd()',
            'requiredParams' => 0,
            'doc' => 'Die and dump the current query.',
        ],
    ];

    /**
     * Check if the given class name looks like an Eloquent Model.
     */
    public static function isModel(string $className, ?array $knownModels = null): bool
    {
        $clean = ltrim($className, '\\');
        $base = class_basename($clean);

        if ($clean === 'Illuminate\Database\Eloquent\Model' || $clean === 'Model') {
            return true;
        }

        if (str_starts_with($clean, 'App\Models\\') || str_starts_with($clean, 'Models\\')) {
            return true;
        }

        if ($knownModels !== null) {
            if (isset($knownModels[$clean]) || isset($knownModels['\\' . $clean]) || in_array($clean, $knownModels, true)) {
                return true;
            }
            // Check by short basename in knownModels keys (e.g. 'User' -> 'App\Models\User')
            foreach (array_keys($knownModels) as $km) {
                if (class_basename((string) $km) === $clean || class_basename((string) $km) === $base) {
                    return true;
                }
            }
        }

        if (class_exists($clean) && is_subclass_of($clean, 'Illuminate\Database\Eloquent\Model')) {
            return true;
        }

        // Framework classes outside Eloquent/Auth are not models
        if (str_starts_with($clean, 'Illuminate\\')) {
            return $clean === 'Illuminate\Foundation\Auth\User';
        }

        // Generic fallback for simple short class names without namespace (e.g. 'User', 'Post', 'Order')
        if (!str_contains($clean, '\\') && ctype_upper($base[0] ?? '')) {
            $nonModelClasses = [
                'Factory', 'Application', 'Request', 'Response', 'Session', 'Config',
                'View', 'DB', 'Cache', 'Log', 'Route', 'Auth', 'Gate', 'Blade',
                'Event', 'Bus', 'Queue', 'Storage', 'Http', 'Validator', 'Mail',
                'Notification', 'Str', 'Arr', 'Carbon', 'Collection', 'Date',
                'Schema', 'Artisan', 'URL', 'Redirect', 'Cookie', 'Crypt', 'Hash',
                'Lang', 'Password', 'RateLimiter', 'Process', 'Benchmark', 'Number',
                'Uri', 'Vite', 'File', 'ParallelTesting',
            ];
            if (!in_array($base, $nonModelClasses, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a type is an Eloquent or Query Builder.
     */
    public static function isBuilder(string $type): bool
    {
        $clean = ltrim($type, '\\');
        return str_starts_with($clean, 'Illuminate\Database\Eloquent\Builder')
            || str_starts_with($clean, 'Illuminate\Database\Query\Builder')
            || str_starts_with($clean, 'Builder')
            || str_contains($clean, '\Builder<')
            || str_contains($clean, 'Builder<');
    }

    /**
     * Extract the model class from a Builder type (e.g. Builder<App\Models\User> -> App\Models\User).
     */
    public static function extractModelFromBuilder(string $type): ?string
    {
        if (preg_match('/(?:Builder)<(?:[^,]+,\s*)?([^>]+)>/i', $type, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Get all builder members formatted for LSP completion.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getMembersForModel(?string $modelClass = null, bool $isStatic = false): array
    {
        $modelDisplay = $modelClass !== null ? class_basename($modelClass) : 'Model';
        $fullModel = $modelClass !== null ? '\\' . ltrim($modelClass, '\\') : 'Model';

        $members = [];

        foreach (self::BUILDER_METHODS as $name => $info) {
            $ret = str_replace('Model', $fullModel, $info['return']);
            $sig = str_replace('Model', $modelDisplay, $info['signature']);

            $members[$name] = [
                'name' => $name,
                'kind' => 2, // Method
                'detail' => $sig,
                'paramSignature' => preg_match('/\((.*?)\)/', $sig, $sm) ? '(' . $sm[1] . ')' : '()',
                'returnType' => $ret,
                'requiredParams' => $info['requiredParams'],
                'snippet' => $info['snippet'],
                'isMethod' => true,
                'documentation' => "**" . ($isStatic ? "{$modelDisplay}::{$name}" : "\${$name}") . "**\n\n```php\npublic " . ($isStatic ? 'static ' : '') . "function {$name}{$sig};\n```\n\n{$info['doc']}\n\n*Origin:* `Eloquent Builder`",
            ];
        }

        return $members;
    }
}
