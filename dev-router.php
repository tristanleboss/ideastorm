<?php

/*
 * Router for PHP's built-in web server, used by `composer dev`.
 *
 * Without it the built-in server hands requests for .css and .js files to public/index.php,
 * which tries to execute them as PHP. Returning false for anything that exists on disk under
 * public/ lets the server stream those files itself and sends everything else through Symfony.
 *
 * This file is for local development only — a real deployment points a web server at public/
 * and never loads it. See README.md.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?: '/';
$candidate = __DIR__.'/public'.urldecode($path);

if ('/' !== substr($candidate, -1) && is_file($candidate)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/public/index.php';

require __DIR__.'/public/index.php';
