<?php

echo json_encode([
    'php_version'     => phpversion(),
    'laravel_version' => app()->version(),
]);
