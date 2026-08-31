<?php

use Illuminate\Support\Facades\View;

$shared = [];

try {
    if (class_exists(View::class) && method_exists(View::class, 'getShared')) {
        foreach (View::getShared() as $key => $value) {
            $type = is_object($value) ? '\\' . get_class($value) : gettype($value);
            $shared[] = [
                'name' => $key,
                'type' => $type,
                'detail' => 'Globally shared via View::share()',
                'origin' => 'View::share()',
            ];
        }
    }
} catch (Throwable) {
    // Fail silently if the View facade is not initialized.
}

echo json_encode([
    'shared' => $shared,
]);
