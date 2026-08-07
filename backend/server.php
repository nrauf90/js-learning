<?php

/**
 * Router for PHP's built-in web server, emulating Apache's mod_rewrite.
 *
 * This is a copy of the shim Laravel ships with `artisan serve`, lifted into
 * the project because `npm run dev:api` no longer goes through `artisan serve`
 * — it invokes `php -S` directly so it can pass `-d opcache.*` flags, which
 * `artisan serve` does not forward to the server process it spawns. Without
 * OPcache the built-in server recompiles ~8,000 vendor files on every request
 * (~750ms of pure boot); with it, the same request lands in ~60ms.
 *
 * `artisan serve` picks this file up automatically when it exists, so both
 * entry points behave identically apart from the OPcache flags.
 */

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Serve static files straight off disk; everything else goes to Laravel.
if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

$formattedDateTime = date('D M j H:i:s Y');

$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];

file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

require_once $publicPath.'/index.php';
