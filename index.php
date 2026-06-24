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

// Fat-Free's error handler escalates every *reported* error to a 500 (see base.php). PHP 8
// reclassified undefined var/array-key E_NOTICE -> E_WARNING (silent on 7.2), so the app's
// 7.2-era code would now fatal. We don't want a stray warning/deprecation to crash a request,
// but we do want it visible: this handler logs each unique E_WARNING/E_DEPRECATED site once and
// then *swallows* it (returns true) so it never reaches F3's escalate-to-500 handler. Every
// other level chains to F3 unchanged, so real errors (E_ERROR, TypeError, uncaught exceptions)
// still surface as 500s. Inspect the log with: `podman compose logs pf | grep PF_WARN`.
$f3ErrorHandler = set_error_handler(static function(int $level, string $text, string $file, int $line) use (&$f3ErrorHandler){
    if($level & (E_DEPRECATED | E_WARNING)){
        // respect the @-operator: error_reporting() drops $level when the call site used @,
        // so an intentionally-suppressed warning is swallowed silently (no log, still no 500).
        if(error_reporting() & $level){
            static $seen = [];
            $key = $level.'|'.$text.'|'.$file.'|'.$line;
            if(!isset($seen[$key])){
                $seen[$key] = true;
                error_log(sprintf('PF_WARN [%s] %s in %s:%d',
                    $level === E_DEPRECATED ? 'DEPRECATED' : 'WARNING', $text, $file, $line));
            }
        }
        return true; // handled: log but never escalate a warning/deprecation to a 500
    }
    return $f3ErrorHandler ? ($f3ErrorHandler)($level, $text, $file, $line) : false;
});

// load main config
$f3->config('app/config.ini', true);

// load environment dependent config
Lib\Config::instance($f3);

// initiate cron-jobs
Lib\Cron::instance();

$f3->run();