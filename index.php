<?php
namespace Exodus4D\Pathfinder;

use Exodus4D\Pathfinder\Lib;

session_name('pathfinder_session');

$composerAutoloader = 'vendor/autoload.php';
if(file_exists($composerAutoloader)){
    require_once($composerAutoloader);
}else{
    die("Couldn't find '$composerAutoloader'. Did you run `composer install`?");
}

// F3's Base::instance() fatals on ANY error_get_last() lingering from autoload, even one
// masked by error_reporting (e.g. guzzle 6.5's PHP-8.4+ implicit-nullable-param deprecation,
// compiled once per opcache cold-start). Clear it so that masked/suppressed errors don't
// reach F3's mask-blind startup check (base.php's `$check && error_get_last()`).
// TODO: cleanup after dependencies update to resume error reporting.
error_clear_last();

$f3 = \Base::instance();
$f3->set('NAMESPACE', __NAMESPACE__);

// Fat-Free escalates every reported error to a 500. PHP 8 reclassified undefined var/array-key
// E_NOTICE -> E_WARNING (silent on 7.2), so the app's 7.2-era code now fatals; drop E_WARNING
// (+ E_DEPRECATED from held deps) to restore 7.2 behavior. Real errors (E_ERROR, TypeError,
// uncaught exceptions) still surface. NOTE: guzzle 6.5's compile-time deprecation is NOT caught
// by this (it bypasses error_reporting) -> one cold-start 500 until guzzle goes 7.x.
// TODO(php8-cleanup): fix the underlying warnings and revert this relaxation to strict.
error_reporting(error_reporting() & ~(E_DEPRECATED | E_WARNING));

// load main config
$f3->config('app/config.ini', true);

// load environment dependent config
Lib\Config::instance($f3);

// initiate cron-jobs
Lib\Cron::instance();

$f3->run();