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

$f3 = \Base::instance();
$f3->set('NAMESPACE', __NAMESPACE__);

// Fat-Free escalates every reported error to a 500. PHP 8 reclassified undefined var/array-key
// E_NOTICE -> E_WARNING (silent on 7.2), so the app's 7.2-era code now fatals; drop E_WARNING
// (+ E_DEPRECATED from held deps) to restore 7.2 behavior. Real errors (E_ERROR, TypeError,
// uncaught exceptions) still surface.
// TODO(php8-cleanup): fix the underlying warnings and revert this relaxation to strict.
error_reporting(error_reporting() & ~(E_DEPRECATED | E_WARNING));

// load main config
$f3->config('app/config.ini', true);

// load environment dependent config
Lib\Config::instance($f3);

// initiate cron-jobs
Lib\Cron::instance();

$f3->run();