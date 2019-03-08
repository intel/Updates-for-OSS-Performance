<?php
/*
 *  Copyright (c) 2014-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

final class PerfOptions {
  public $help;
  public $verbose;

  //
  // Exactly one of php5 or hhvm must be set with the path
  // to the corresponding executable.  The one that is set
  // determines what kind of cgi server is run.
  //
  public $php5;
  public $hhvm;

  // When running with php, this enables fpm cgi.
  public $fpm = false;

  //
  // setUpTest and tearDownTest are called before and after each
  // individual invocation of the $php5 or $hhvm
  //
  public $setUpTest;
  public $tearDownTest;

  public $hhvmExtraArguments;
  public $phpExtraArguments;

  public $phpFCGIChildren;

  public $siege;
  public $nginx;
  public $memcached;

  public $dbUsername;
  public $dbPassword;

  public $cpuBind = false;
  public $daemonProcessors;
  public $helperProcessors;

  public $fetchResources = false;
  public $forceInnodb = false;
  public $skipSanityCheck = false;
  public $skipWarmUp = false;
  public $skipVersionChecks = false;
  public $skipDatabaseInstall = false;
  public $dumpIsCompressed = true;
  public $traceSubProcess = false;
  public $noTimeLimit = false;

  // Pause once benchmarking is complete to allow for manual inspection of the
  // HHVM or PHP process.
  public $waitAtEnd = false;
  // Pause after the warmup is completed to get relevant data when profiling
  public $waitAfterWarmup = false;

  //
  // HHVM specific options to enable RepoAuthoritative mode and the static
  // content cache, as well as selecting the PCRE caching mode.
  //
  public $precompile = false;
  public $filecache = false;
  public $pcreCache;
  public $pcreSize;
  public $pcreExpire;
  public $allVolatile = false;
  public $interpPseudomains = false;
  public $proxygen = false;
  public $jit = false;
  public $statCache = false;

  //
  // HHVM specific options for generating performance data and profiling
  // information.
  //
  public $tcprint = false;
  public $pcredump = false;
  public $profBC = false;
  public $warmupStats = false;

  public $applyPatches = false;

  public $useMemcached = true;

  //
  // All times are given in seconds, stored in a float.
  // For PHP code, the usleep timer is used, so fractional seconds work fine.
  //
  // For times that go into configuration files for 3rd party software,
  // such as nginx, times may be truncated to the nearest integer value,
  // in order to accomodate inflexibility in the 3rd party software.
  //
  public $delayNginxStartup;
  public $delayPhpStartup;
  public $delayMemcachedStartup;
  public $delayProcessLaunch; // secs to wait after start process
  public $delayCheckHealth; // secs to wait before hit /check-health
  // in secs, store as int
  public $benchmarkTime;

  //
  // Maximum wait times, as for example given to file_get_contents
  // or the configuration file for nginx.  These times may be truncated
  // to the nearest integral second to accomodate the specific server.
  //
  public $maxdelayUnfreeze;
  public $maxdelayAdminRequest;
  public $maxdelayNginxKeepAlive;
  public $maxdelayNginxFastCGI;

  public $daemonOutputToFile = false;
  public $tempDir;
  public $srcDir;

  public $scriptBeforeWarmup;
  public $scriptAfterWarmup;
  public $scriptAfterBenchmark;
  public $serverThreads = '100';
  public $clientThreads = '200';

  public $notBenchmarking = false;

  public $dbHost = '127.0.0.1'; //The hostname/IP of server which hosts the database.
  public $memcachedPort; //The hostname/IP of server which hosts memcached.
  public $memcachedThreads; // Number of memcached threads

  private $args;
  private $notBenchmarkingArgs = [];

  public $remoteSiege;
  public $siegeTmpDir;

  //create a vector
  public function __construct($argv) {
    $def = array(
      'help',
      'verbose',
      'php:', // Uses FPM by default (see no-fpm).
      'php5:', // Uses CGI.  Legacy option.
      'hhvm:',
      'no-fpm',
      'siege:',
      'nginx:',
      'memcached:',
      'wait-at-end',
      'wait-after-warmup',
      'no-proxygen',
      'no-repo-auth',
      'no-jit',
      'no-file-cache',
      'stat-cache',
      'pcre-cache:',
      'pcre-cache-expire:',
      'pcre-cache-size:',
      'all-volatile',
      'interp-pseudomains',
      'apply-patches',
      'force-innodb',
      'fbcode::',
      'tcprint',
      'dump-pcre-cache',
      'profBC',
      'warmup-stats',
      'setUpTest:',
      'db-username:',
      'db-password:',
      'cpu-fraction:',
      'tearDownTest:',
      'i-am-not-benchmarking',
      'hhvm-extra-arguments:',
      'php-extra-arguments:',
      'php-fcgi-children:',
      'no-time-limit',
      'benchmark-time:',
      'fetch-resources',
      'skip-sanity-check',
      'skip-warmup',
      'skip-version-checks',
      'skip-database-install',
      'trace',
      'delay-nginx-startup:',
      'delay-php-startup:',
      'delay-memcached-startup:',
      'delay-process-launch:',
      'delay-check-health:',
      'max-delay-unfreeze:',
      'max-delay-admin-request:',
      'max-delay-nginx-keepalive:',
      'max-delay-nginx-fastcgi:',
      'exec-before-warmup:',
      'exec-after-warmup:',
      'exec-after-benchmark:',
      'daemon-files', // daemon output goes to files in the temp directory
      'temp-dir:', // temp directory to use; if absent one in /tmp is made
      'src-dir:', // location for source to copy into tmp dir instead of ZIP
      'db-host:',
      'server-threads:',
      'client-threads:',
      'remote-siege:',
      'memcached-port:',
      'memcached-threads:',
      'no-memcached' // do not use memcached (even if target supports it)
    );
    $targets = array_keys($this->getTargetDefinitions());
    $def=array_merge($def,$targets);

    $original_argv = $GLOBALS['argv'];
    $GLOBALS['argv'] = $argv;
    $o = getopt('', $def);
    $GLOBALS['argv'] = $original_argv;

    $this->help = array_key_exists('help', $o);
    if ($this->help) {
      fprintf(
        STDERR,
        "Usage: %s \\\n".
        "  --<php5=/path/to/php-cgi|php=/path/to/php-fpm|".
        "hhvm=/path/to/hhvm>\\\n".
        "  --<".
        implode('|', $targets).
        ">\n".
        "\n".
        "Options:\n%s",
	$argv[0],
	implode('',
	        array_map(function($x) { return '  --'.$x."\n"; },
		$def))
      );
      exit(1);
    }

    $this->verbose = array_key_exists('verbose', $o);

    $php5 = $this->hphp_array_idx($o, 'php5', null);  // Will only use cgi.
    $php = $this->hphp_array_idx($o, 'php', null);  // Will use fpm by default.
    if ($php5 !== null) {
      $this->php5 = $php5;
    } else {
      $this->php5 = $php;
    }
    $this->hhvm = $this->hphp_array_idx($o, 'hhvm', null);

    $this->setUpTest = $this->hphp_array_idx($o, 'setUpTest', null);
    $this->tearDownTest = $this->hphp_array_idx($o, 'tearDownTest', null);

    $this->dbUsername = $this->hphp_array_idx($o, 'db-username', null);
    $this->dbPassword = $this->hphp_array_idx($o, 'db-password', null);

    $this->siege = $this->hphp_array_idx($o, 'siege', 'siege');
    $this->nginx = $this->hphp_array_idx($o, 'nginx', 'nginx');
    $this->memcached = $this->hphp_array_idx($o, 'memcached', 'memcached');
    $this->memcachedPort = (int) $this->hphp_array_idx($o, 'memcached-port', 11888);
    $this->memcachedThreads = (int) $this->hphp_array_idx($o, 'memcached-threads', 0);

    $isFacebook = array_key_exists('fbcode', $o);
    $fbcode = "";
    if ($isFacebook) {
      $val = $this->hphp_array_idx($o, 'fbcode', false);
      if (is_string($val) && $val !== '') {
        $fbcode = $val;
      } else {
        $fbcode = getenv('HOME').'/fbcode';
      }
      $this->forceInnodb = true;
    }

    $this->notBenchmarking = array_key_exists('i-am-not-benchmarking', $o);

    // If any arguments below here are given, then the "standard
    // semantics" have changed, and any results are potentially not
    // consistent with the benchmark standards for HHVM. You can only
    // use these arguments if you also give the -i-am-not-benchmarking
    // argument too.
    $this->args = $o;

    if ($php5 === null) {
      $this->fpm = !$this->getBool('no-fpm');
    }

    $this->fetchResources = $this->getBool('fetch-resources');
    $this->skipSanityCheck = $this->getBool('skip-sanity-check');
    $this->skipWarmUp = $this->getBool('skip-warmup');
    $this->waitAfterWarmup = $this->getBool('wait-after-warmup');
    $this->skipVersionChecks = $this->getBool('skip-version-checks');
    $this->skipDatabaseInstall = $this->getBool('skip-database-install');
    $this->noTimeLimit = $this->getBool('no-time-limit');
    $this->waitAtEnd = $this->getBool('wait-at-end');
    $this->proxygen = !$this->getBool('no-proxygen');
    $this->statCache = $this->getBool('stat-cache');
    $this->jit = !$this->getBool('no-jit');
    $this->applyPatches = $this->getBool('apply-patches');
    $this->useMemcached = !$this->getBool('no-memcached');

    $fraction = $this->getFloat('cpu-fraction', 1.0);
    if ($fraction !== 1.0) {
      $this->cpuBind = true;
      $output = [];
      exec('nproc', $output);
      $numProcessors = (int)($output[0]);
      $numDaemonProcessors = (int)($numProcessors * $fraction);
      $this->helperProcessors = "$numDaemonProcessors-$numProcessors";
      $this->daemonProcessors = "0-$numDaemonProcessors";
    }

    $this->precompile = !$this->getBool('no-repo-auth');
    $this->filecache = $this->precompile && !$this->getBool('no-file-cache');
    $this->pcreCache = $this->getNullableString('pcre-cache');
    $this->pcreSize = $this->getNullableInt('pcre-cache-size');
    $this->pcreExpire = $this->getNullableInt('pcre-cache-expire');
    $this->allVolatile = $this->getBool('all-volatile');
    $this->interpPseudomains = $this->getBool('interp-pseudomains');

    $this->scriptBeforeWarmup = $this->getNullableString('exec-before-warmup');
    $this->scriptAfterWarmup = $this->getNullableString('exec-after-warmup');
    $this->scriptAfterBenchmark = $this->getNullableString('exec-after-benchmark');

    $this->tcprint = $this->getBool('tcprint');

    $this->pcredump = $this->getBool('dump-pcre-cache');
    $this->profBC = $this->getBool('profBC');
    $this->warmupStats = $this->getBool('warmup-stats');
    $this->forceInnodb = $isFacebook || $this->getBool('force-innodb');

    if ($isFacebook && $this->php5 === null && $this->hhvm === null) {
      $this->hhvm = $fbcode.'/buck-out/gen/hphp/hhvm/hhvm/hhvm';
    }

    $this->traceSubProcess = $this->getBool('trace');

    $this->hhvmExtraArguments = $this->getArray('hhvm-extra-arguments');
    $this->phpExtraArguments = $this->getArray('php-extra-arguments');

    $this->phpFCGIChildren = $this->getInt('php-fcgi-children', 100);
    $this->delayNginxStartup = $this->getFloat('delay-nginx-startup', 0.1);
    $this->delayPhpStartup = $this->getFloat('delay-php-startup', 1.0);
    $this->delayMemcachedStartup = $this->getFloat('delay-memcached-startup', 1.0);
    $this->delayProcessLaunch = $this->getFloat('delay-process-launch', 0.0);
    $this->delayCheckHealth = $this->getFloat('delay-check-health', 1.0);
    $this->benchmarkTime = $this->getInt('benchmark-time', 60);
    $this->maxdelayUnfreeze = $this->getFloat('max-delay-unfreeze', 60.0);
    $this->maxdelayAdminRequest =
      $this->getFloat('max-delay-admin-request', 3.0);
    $this->maxdelayNginxKeepAlive =
      $this->getFloat('max-delay-nginx-keep-alive', 60.0);
    $this->maxdelayNginxFastCGI =
      $this->getFloat('max-delay-nginx-fastcgi', 60.0);

    $this->daemonOutputToFile = $this->getBool('daemon-files');

    $argTempDir = $this->getNullableString('temp-dir');

    $host = $this->getNullableString('db-host');
    if ($host) {
      $this->dbHost = $host;
    }

    if (array_key_exists('server-threads', $o)) {
      $this->serverThreads = $this->args['server-threads'];
    }

    if (array_key_exists('client-threads', $o)) {
      $this->clientThreads = $this->args['client-threads'];
    }

    if ($argTempDir === null) {
      $this->tempDir = tempnam('/tmp', 'hhvm-nginx');
      // Currently a file - change to a dir
      unlink($this->tempDir);
      mkdir($this->tempDir);
    } else {
      $this->tempDir = $argTempDir;
    }

    $this->srcDir = $this->getNullableString('src-dir');

    $this->remoteSiege = $this->getNullableString('remote-siege');
  }

  public function validate() {
    if ($this->php5) {
      $this->precompile = false;
      $this->proxygen = false;
      $this->jit = false;
      $this->filecache = false;
    }
    if ($this->hhvm) {
      $this->fpm = false;
    }
    if ($this->notBenchmarkingArgs && !$this->notBenchmarking) {
      $message = sprintf(
        "These arguments are invalid without --i-am-not-benchmarking: %s",
        implode(' ', $this->notBenchmarkingArgs)
      );
      if (getenv("HHVM_OSS_PERF_BE_LENIENT")) {
        fprintf(STDERR, "*** WARNING ***\n%s\n", $message);
        $this->notBenchmarking = true;
      } else {
        echo "$message\n";
        exit(1);
      }
    }
    if ($this->remoteSiege) {
      if (preg_match('*@*',$this->remoteSiege) === 0){
       echo "Please provide Siege remote host in the form of <user>@<host>\n";
        exit(1);
      }
      $ret = 0;
      $output = "";
      $this->siegeTmpDir = exec('ssh ' .
        $this->remoteSiege . ' mktemp -d ', $output, $ret);
      if ($ret) {
        echo "Invalid ssh credentials:  . $this->remoteSiege\n";
      }
    }
    if ($this->php5 === null && $this->hhvm === null) {
      echo  "Either --php5=/path/to/php-cgi or --php=/path/to/php-fpm or '.
        '--hhvm=/path/to/hhvm must be specified'\n";
    }
    $engine = $this->php5 !== null ? $this->php5 : $this->hhvm;
    assert(
      shell_exec('which '.escapeshellarg($engine)) !== null ||
      is_executable($engine),
      'Invalid engine: '.$engine
    );
    assert(
      shell_exec('which '.escapeshellarg($this->siege)) !== null ||
      is_executable($this->siege),
      'Could not find siege'
    );

    $tcprint = $this->tcprint;
    if ($tcprint) {
      assert(
        $this->hhvm !== null,
        'tcprint is only valid for hhvm'
      );
    }

    if ($this->pcreCache !== null || $this->pcreSize || $this->pcreExpire) {
      assert(
        $this->hhvm !== null,
        'The PCRE caching scheme can only be tuned for hhvm'
      );
    }

    if ($this->precompile) {
      assert(
        $this->hhvm !== null,
        'Only hhvm can be used with --repo-auth'
      );
    }

    SystemChecks::CheckAll($this);

    // Validates that one was defined
    $this->getTarget();
  }

  private function getBool(string $name): bool {
    $value = array_key_exists($name, $this->args);
    if ($value) {
      $this->notBenchmarkingArgs[] = '--'.$name;
    }
    return $value;
  }

  private function hphp_array_idx($search, $key, $default) {
    if(array_key_exists($key,$search))
        return $search[$key];
    else return $default;
  }

  private function getNullableString(string $name): ?string {
    if (!array_key_exists($name, $this->args)) {
      return null;
    }
    $this->notBenchmarkingArgs[] = '--'.$name;
    return $this->args[$name];
  }

  // getopt allows multiple instances of the same argument,
  // in which case $options[$index] is an array.
  // If only one instance is given, then getopt just uses a string.
  private function getArray(string $name) {
    if (array_key_exists($name, $this->args)) {
      $this->notBenchmarkingArgs[] = '--'.$name;
    } else {
      return array();
    }
    $option_value = $this->hphp_array_idx($this->args, $name, array());
    if (is_array($option_value)) {
      return $option_value;
    } else {
      return array($option_value);
    }
  }

  private function getInt(string $index, int $the_default): int {
    if (array_key_exists($index, $this->args)) {
      $this->notBenchmarkingArgs[] = '--'.$index;
    }
    return (int) $this->hphp_array_idx($this->args, $index, $the_default);
  }

  private function getNullableInt(string $name): ?int {
    if (!array_key_exists($name, $this->args)) {
      return null;
    }
    $this->notBenchmarkingArgs[] = '--'.$name;
    return $this->args[$name];
  }

  private function getFloat(string $index, float $the_default): float {
    if (array_key_exists($index, $this->args)) {
      $this->notBenchmarkingArgs[] = '--'.$index;
    }
    return (float) $this->hphp_array_idx($this->args, $index, $the_default);
  }

  //
  // Return the name of a file that should collect stdout
  // from daemon executions.  Returning null means that
  // the daemon stdout should go to a pipe attached to this process.
  //
  public function daemonOutputFileName(string $daemonName): ?string {
    if ($this->daemonOutputToFile) {
      return
        (($this->tempDir === null) ? '/tmp' : $this->tempDir).
        '/'.
        $daemonName.
        '.out';
    } else {
      return null;
    }
  }

  public function getTarget(): PerfTarget {
    $multiple = false;
    $target = null;
    $def = $this->getTargetDefinitions();
    foreach ($def as $flag => $factory) {
      if (array_key_exists($flag, $this->args)) {
        if ($target === null) {
          $target = $factory();
        } else {
          $multiple = true;
        }
      }
    }
    if ($multiple || ($target === null)) {
      fprintf(
        STDERR,
        "You must specify a target with exactly one of the following:\n".
	implode('',
	       array_map(function($arg) { return '  --'.$arg."\n"; },
	                                  array_keys($def)))
      );
      exit(1);
    }
    return $target;
  }

  private function getTargetDefinitions() {
    return array(
      'codeigniter-hello-world' => function() { return new CodeIgniterTarget($this); },
      'drupal7' => function() { return new Drupal7Target($this); },
      'drupal8-page-cache' => function() { return new Drupal8PageCacheTarget($this); },
      'drupal8-no-cache' =>  function() { return new Drupal8NoCacheTarget($this); },
      'mediawiki' => function() { return new MediaWikiTarget($this); },
      'laravel4-hello-world' => function() { return new Laravel4Target($this); } ,
      'laravel5-hello-world' =>  function() { return new Laravel5Target($this); },
      'sugarcrm-login-page' => function() { return new SugarCRMLoginPageTarget($this); },
      'sugarcrm-home-page' => function() { return new SugarCRMHomePageTarget($this); },
      'toys-fibonacci' => function() { return new FibonacciTarget(); },
      'toys-hello-world' => function() { return new HelloWorldTarget(); },
      'wordpress' => function()  {return new WordpressTarget($this);},
      'magento1' => function() { return new Magento1Target($this);}
    );
  }
}
