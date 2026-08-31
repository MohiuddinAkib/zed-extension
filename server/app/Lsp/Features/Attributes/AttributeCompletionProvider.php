<?php

declare(strict_types=1);

namespace App\Lsp\Features\Attributes;

use App\Lsp\Analysis\AttributeIntelligenceRegistry;
use App\Lsp\Analysis\DriverRegistry;
use App\Lsp\Analysis\SemanticIndex;
use App\Lsp\Contracts\CompletionProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\Position;
use App\Lsp\Support\Utf16Position;

class AttributeCompletionProvider implements CompletionProvider
{
    protected AttributeIntelligenceRegistry $attrRegistry;
    protected DriverRegistry $driverRegistry;
    protected ?SemanticIndex $semanticIndex = null;

    public function __construct(
        protected Project $project,
        ?AttributeIntelligenceRegistry $attrRegistry = null,
    ) {
        $this->attrRegistry = $attrRegistry ?? new AttributeIntelligenceRegistry($project);
        $this->driverRegistry = $this->attrRegistry->driverRegistry();
        $this->semanticIndex = new SemanticIndex($project);
    }

    public function get(Document $document, array $position): array
    {
        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return [];
        }

        $lines = explode("\n", $document->content);
        $line = $lines[$lineNumber] ?? '';
        $textBefore = Utf16Position::substr($line, 0, $character);

        $context = $this->parseContext($textBefore);
        if ($context === null) {
            return [];
        }

        return $this->resolveCompletionsForDomain(
            $context['domain'],
            $context['prefix'],
            $lineNumber,
            $character,
            $context['quoteChar'],
        );
    }

    /**
     * Parse the active attribute, helper, or facade completion context.
     *
     * @return array{domain: string, prefix: string, quoteChar: string}|null
     */
    public function parseContext(string $textBefore): ?array
    {
        // 1. Attribute argument context: #[AttributeName('prefix or #[AttributeName("prefix or #[AttributeName(prefix
        if (preg_match('/#\[\s*\\\\?([a-zA-Z0-9_\\\\]+)\s*\((?:[^,)]*,\s*)*([\'"]?)([^\'")]*)$/', $textBefore, $m)) {
            $attrName = $m[1];
            $quoteChar = $m[2];
            $prefix = $m[3];

            // Count existing comma-separated arguments before cursor
            $argsSection = substr($textBefore, strrpos($textBefore, '(') + 1);
            $argIndex = substr_count($argsSection, ',');

            $domain = $this->attrRegistry->getAttributeArgumentDomain($attrName, $argIndex);
            if ($domain !== null) {
                return [
                    'domain'    => $domain,
                    'prefix'    => $prefix,
                    'quoteChar' => $quoteChar,
                ];
            }
        }

        // 2. Facade / Static method driver context:
        // Auth::guard('prefix or Storage::disk('prefix or DB::connection('prefix or Cache::store('prefix
        // Queue::connection('prefix or Mail::mailer('prefix or Broadcast::connection('prefix or Redis::connection('prefix
        if (preg_match('/(?:\\\\?[a-zA-Z0-9_\\\\]*\\\\)?(Auth|Storage|DB|Database|Cache|Queue|Mail|Broadcast|Redis|Route|Gate)::([a-zA-Z0-9_]+)\s*\(\s*([\'"]?)([^\'")]*)$/', $textBefore, $fm)) {
            $facade = $fm[1];
            $method = $fm[2];
            $quoteChar = $fm[3];
            $prefix = $fm[4];

            $domain = $this->resolveFacadeMethodDomain($facade, $method);
            if ($domain !== null) {
                return [
                    'domain'    => $domain,
                    'prefix'    => $prefix,
                    'quoteChar' => $quoteChar,
                ];
            }
        }

        // 3. Global helper driver context:
        // auth('prefix or cache('prefix or storage('prefix or db('prefix or queue('prefix
        if (preg_match('/(?:\b|\()(auth|cache|storage|db)\s*\(\s*([\'"]?)([^\'")]*)$/', $textBefore, $hm)) {
            $helper = $hm[1];
            $quoteChar = $hm[2];
            $prefix = $hm[3];

            $domain = match ($helper) {
                'auth' => 'driver:auth_guards',
                'cache' => 'driver:cache_stores',
                'storage' => 'driver:filesystem_disks',
                'db' => 'driver:database_connections',
                default => null,
            };

            if ($domain !== null) {
                return [
                    'domain'    => $domain,
                    'prefix'    => $prefix,
                    'quoteChar' => $quoteChar,
                ];
            }
        }

        return null;
    }

    protected function resolveFacadeMethodDomain(string $facade, string $method): ?string
    {
        return match ($facade) {
            'Auth' => match ($method) {
                'guard' => 'driver:auth_guards',
                'user' => 'driver:auth_guards',
                default => null,
            },
            'Storage' => match ($method) {
                'disk', 'fake', 'persistentFake', 'forgetDisk' => 'driver:filesystem_disks',
                default => null,
            },
            'DB', 'Database' => match ($method) {
                'connection' => 'driver:database_connections',
                default => null,
            },
            'Cache' => match ($method) {
                'store', 'driver' => 'driver:cache_stores',
                default => null,
            },
            'Queue' => match ($method) {
                'connection' => 'driver:queue_connections',
                default => null,
            },
            'Mail' => match ($method) {
                'mailer' => 'driver:mailers',
                default => null,
            },
            'Broadcast' => match ($method) {
                'connection' => 'driver:broadcasters',
                default => null,
            },
            'Redis' => match ($method) {
                'connection' => 'driver:redis_connections',
                default => null,
            },
            'Route' => match ($method) {
                'middleware' => 'middleware',
                default => null,
            },
            'Gate' => match ($method) {
                'allows', 'denies', 'check', 'authorize', 'inspect' => 'policies',
                default => null,
            },
            default => null,
        };
    }

    /**
     * Resolve completion items for a specific semantic domain.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function resolveCompletionsForDomain(string $domain, string $prefix, int $line, int $character, string $quoteChar): array
    {
        $startChar = $character - strlen($prefix);
        $replacementRange = [
            'start' => ['line' => $line, 'character' => $startChar],
            'end'   => ['line' => $line, 'character' => $character],
        ];

        // 1. Driver domains
        if (str_starts_with($domain, 'driver:')) {
            $driverKind = substr($domain, 7);
            $drivers = $this->driverRegistry->getDrivers($driverKind);

            $items = [];
            foreach ($drivers as $name => $info) {
                if ($prefix !== '' && stripos($name, $prefix) === false) {
                    continue;
                }

                $configured = $info['configuredDriver'] ?? 'default';
                $resolvedType = $info['resolvedType'] ?? '';
                $items[] = [
                    'label'            => $name,
                    'kind'             => 12,
                    'detail'           => "({$configured} driver) -> {$resolvedType}",
                    'documentation'    => "**Laravel Driver**: `{$name}`\n\n- Kind: `{$driverKind}`\n- Driver: `{$configured}`\n- Type: `{$resolvedType}`",
                    'insertText'       => $name,
                    'textEdit'         => [
                        'range'   => $replacementRange,
                        'newText' => $name,
                    ],
                ];
            }

            return $items;
        }

        // 2. Config keys domain
        if ($domain === 'config_keys') {
            try {
                $configs = $this->project->index->configs()['configs'] ?? collect([]);
                $items = [];
                foreach ($configs as $config) {
                    $name = (string) ($config['name'] ?? '');
                    if ($name === '' || ($prefix !== '' && stripos($name, $prefix) === false)) {
                        continue;
                    }
                    $items[] = [
                        'label'      => $name,
                        'kind'       => 14,
                        'detail'     => 'Laravel Config',
                        'insertText' => $name,
                        'textEdit'   => [
                            'range'   => $replacementRange,
                            'newText' => $name,
                        ],
                    ];
                }

                return $items;
            } catch (\Throwable) {
                return [];
            }
        }

        // 3. Middleware domain
        if ($domain === 'middleware') {
            try {
                $middlewareList = $this->project->index->middleware() ?? collect([]);
                $items = [];
                foreach ($middlewareList as $m) {
                    $name = (string) ($m['name'] ?? '');
                    if ($name === '' || ($prefix !== '' && stripos($name, $prefix) === false)) {
                        continue;
                    }
                    $class = (string) ($m['class'] ?? '');
                    $items[] = [
                        'label'      => $name,
                        'kind'       => 12,
                        'detail'     => $class,
                        'insertText' => $name,
                        'textEdit'   => [
                            'range'   => $replacementRange,
                            'newText' => $name,
                        ],
                    ];
                }

                return $items;
            } catch (\Throwable) {
                return [];
            }
        }

        // 4. Routes domain
        if ($domain === 'routes') {
            try {
                $routes = $this->project->index->routes() ?? collect([]);
                $items = [];
                foreach ($routes as $r) {
                    $name = (string) ($r['name'] ?? '');
                    if ($name === '' || ($prefix !== '' && stripos($name, $prefix) === false)) {
                        continue;
                    }
                    $action = (string) ($r['action'] ?? '');
                    $items[] = [
                        'label'      => $name,
                        'kind'       => 12,
                        'detail'     => $action,
                        'insertText' => $name,
                        'textEdit'   => [
                            'range'   => $replacementRange,
                            'newText' => $name,
                        ],
                    ];
                }

                return $items;
            } catch (\Throwable) {
                return [];
            }
        }

        // 5. Views domain
        if ($domain === 'views') {
            try {
                $views = $this->project->index->views() ?? collect([]);
                $items = [];
                foreach ($views as $v) {
                    $key = (string) ($v['key'] ?? '');
                    if ($key === '' || ($prefix !== '' && stripos($key, $prefix) === false)) {
                        continue;
                    }
                    $items[] = [
                        'label'      => $key,
                        'kind'       => 17,
                        'detail'     => (string) ($v['path'] ?? ''),
                        'insertText' => $key,
                        'textEdit'   => [
                            'range'   => $replacementRange,
                            'newText' => $key,
                        ],
                    ];
                }

                return $items;
            } catch (\Throwable) {
                return [];
            }
        }

        // 6. Policies domain
        if ($domain === 'policies') {
            try {
                $auth = $this->project->index->auth()['policies'] ?? [];
                $items = [];
                foreach ($auth as $ability => $policies) {
                    if ($prefix !== '' && stripos((string) $ability, $prefix) === false) {
                        continue;
                    }
                    $policyClass = $policies[0]['policy'] ?? '';
                    $items[] = [
                        'label'      => (string) $ability,
                        'kind'       => 12,
                        'detail'     => $policyClass,
                        'insertText' => (string) $ability,
                        'textEdit'   => [
                            'range'   => $replacementRange,
                            'newText' => (string) $ability,
                        ],
                    ];
                }

                return $items;
            } catch (\Throwable) {
                return [];
            }
        }

        // 7. Bindings domain
        if ($domain === 'bindings' && $this->semanticIndex !== null) {
            $bindings = $this->semanticIndex->containerBindings();
            $items = [];
            foreach ($bindings as $key => $type) {
                if ($prefix !== '' && stripos($key, $prefix) === false) {
                    continue;
                }
                $items[] = [
                    'label'      => $key,
                    'kind'       => 12,
                    'detail'     => $type,
                    'insertText' => $key,
                    'textEdit'   => [
                        'range'   => $replacementRange,
                        'newText' => $key,
                    ],
                ];
            }

            return $items;
        }

        return [];
    }
}
