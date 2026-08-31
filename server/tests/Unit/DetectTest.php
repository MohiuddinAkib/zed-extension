<?php

use App\Parser\DetectWalker;

function detect($values)
{
    return json_encode($values, JSON_PRETTY_PRINT);
}

function detectFromArray($values)
{
    return array_map(fn ($v) => array_merge([
        'class'  => null,
        'method' => null,
        'params' => [],
    ], $v), $values);
}

function normalizeDetectItem($item)
{
    if (!is_array($item)) {
        return $item;
    }

    if (($item['type'] ?? '') === 'methodCall') {
        $params = [];
        if (isset($item['arguments']['children']) && is_array($item['arguments']['children'])) {
            foreach ($item['arguments']['children'] as $arg) {
                if (isset($arg['children'][0])) {
                    $valNode = $arg['children'][0];
                    if (($valNode['type'] ?? '') === 'array') {
                        $arrVal = [];
                        foreach ($valNode['children'] ?? [] as $ac) {
                            if (($ac['type'] ?? '') === 'array_item') {
                                if (($ac['value']['type'] ?? '') === 'string') {
                                    $val = [
                                        'type' => 'string',
                                        'value' => $ac['value']['value'],
                                        'start' => $ac['value']['start'] ?? null,
                                        'end' => $ac['value']['end'] ?? null,
                                    ];
                                } else {
                                    $val = [
                                        'type' => 'unknown',
                                        'value' => ($ac['value']['className'] ?? null) ? basename(str_replace('\\', '/', $ac['value']['className'])) . '::class' : '',
                                    ];
                                }
                                $arrVal[] = [
                                    'key' => ['type' => 'null', 'value' => null],
                                    'value' => $val,
                                ];
                            }
                        }
                        $params[] = ['type' => 'array', 'value' => $arrVal];
                    } elseif (($valNode['type'] ?? '') === 'string') {
                        $p = [
                            'type' => 'string',
                            'value' => $valNode['value'],
                        ];
                        if (isset($valNode['start'])) $p['start'] = $valNode['start'];
                        if (isset($valNode['end'])) $p['end'] = $valNode['end'];
                        $params[] = $p;
                    } elseif (($valNode['type'] ?? '') === 'closure') {
                        $params[] = ['type' => 'closure', 'arguments' => []];
                    } else {
                        $params[] = [
                            'type' => 'unknown',
                            'value' => ($valNode['className'] ?? null) ? basename(str_replace('\\', '/', $valNode['className'])) . '::class' : '',
                        ];
                    }
                }
            }
        }

        return [
            'method' => $item['methodName'] ?? null,
            'class' => $item['className'] ?? null,
            'params' => $params,
        ];
    }

    return $item;
}

function result($file)
{
    $code = fromFile($file);
    $walker = new DetectWalker($code);

    $context = $walker->walk();
    $normalized = [ $context->map(fn ($item) => normalizeDetectItem($item))->all() ];

    return json_encode($normalized, JSON_PRETTY_PRINT);
}

test('extract functions and string params', function () {
    $res = json_decode(result('detect/routes'), true);
    expect($res)->toBeArray();
    expect($res[0])->not->toBeEmpty();
    $methods = array_column($res[0], 'method');
    expect($methods)->toContain('basicFunc');
    expect($methods)->toContain('name');
    expect($methods)->toContain('get');
    expect($methods)->toContain('middleware');
});
