<?php
/*
 *  Copyright (c) 2014-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

spl_autoload_register(function ($class_name){
	include $class_name . '.php';
});

final class PerfRunner {
  public static function RunWithArgv($argv) {
    $options = new PerfOptions($argv);
    return self::RunWithOptions($options);
  }

  public static function RunWithOptions(PerfOptions $options) {
    // If we exit cleanly, Process::__destruct() gets called, but it doesn't
    // if we're killed by Ctrl-C. This tends to leak php-cgi or hhvm processes -
    // trap the signal so we can clean them up.
    pcntl_signal(
      SIGINT,
      function($signal) {
        Process::cleanupAll();
        exit();
      }
    );

    $php_engine = null;

    if ($options->php5) {
      $php_engine = new PHP5Daemon($options);
    }
    if ($options->hhvm) {
      $php_engine = new HHVMDaemon($options);
    }
    assert($php_engine != null, 'failed to initialize a PHP engine');

    return self::RunWithOptionsAndEngine($options, $php_engine);
  }

  private static function RunWithOptionsAndEngine(
    PerfOptions $options,
    PHPEngine $php_engine
  ) {
    $options->validate();
    $target = $options->getTarget();

    self::PrintProgress('Configuration: '.$target.' on '.$php_engine);
    self::PrintProgress('Installing framework');

    $target->install();
    if ($options->applyPatches) {
      self::PrintProgress('Applying patches');
      $target->applyPatches();
    }
    $target->postInstall();

    if ($options->setUpTest != null) {
      $command =
        "OSS_PERF_PHASE=".
        "setUp".
        " ".
        "OSS_PERF_TARGET=".
        (string) $target." ".$options->setUpTest;
      self::PrintProgress('Starting setUpTest '.$command);
      shell_exec($command);
      self::PrintProgress('Finished setUpTest '.$command);
    } else {
      self::PrintProgress('There is no setUpTest');
    }

    self::PrintProgress('Starting Nginx');
    $nginx = new NginxDaemon($options, $target);
    $nginx->start();
    Process::sleepSeconds($options->delayNginxStartup);
    assert($nginx->isRunning(), 'Failed to start nginx');

    if ($options->useMemcached && $target->supportsMemcached()) {
      $memcached = new MemcachedDaemon($options, $target);
      self::PrintProgress('Starting Memcached ('.
                          $memcached->getNumThreads().' threads)');
      Process::sleepSeconds($options->delayMemcachedStartup);
      $memcached->start();
    }

    self::PrintProgress('Starting PHP Engine');
    $php_engine->start();
    Process::sleepSeconds($options->delayPhpStartup);
    assert(
      $php_engine->isRunning(),
      'Failed to start '.get_class($php_engine)
    );

    if ($target->needsUnfreeze()) {
      self::PrintProgress('Unfreezing framework');
      $target->unfreeze($options);
    }

    if ($options->skipSanityCheck) {
      self::PrintProgress('Skipping sanity check');
    } else {
      self::PrintProgress('Running sanity check');
      $target->sanityCheck();
    }

    if ($options->scriptBeforeWarmup !== null) {
      self::PrintProgress('Starting execution of command: '.$options->scriptBeforeWarmup);
      exec($options->scriptBeforeWarmup);
    }

    if (!$options->skipWarmUp) {
      self::PrintProgress('Starting Siege for single request warmup');
      $siege = new Siege($options, $target, RequestModes::WARMUP);
      $siege->start();
      assert($siege->isRunning(), 'Failed to start siege');
      $siege->wait();

      assert(!$siege->isRunning(), 'Siege is still running :/');
      assert(
        $php_engine->isRunning(),
        get_class($php_engine).' crashed'
      );
    } else {
      self::PrintProgress('Skipping single request warmup');
    }

    if (!$options->skipWarmUp) {
      self::PrintProgress('Starting Siege for multi request warmup');
      $siege = new Siege($options, $target, RequestModes::WARMUP_MULTI);
      $siege->start();
      assert($siege->isRunning(), 'Failed to start siege');
      $siege->wait();

      assert(!$siege->isRunning(), 'Siege is still running :/');
      assert(
        $php_engine->isRunning(),
        'php_engine crashed'
      );
    } else {
      self::PrintProgress('Skipping multi request warmup');
    }
    
    if($options->clientThreads == 0){
		self::PrintProgress('Starting client sweep');    
		$clientSweepObj = new ClientSweepAutomation();
		$options->clientThreads = $clientSweepObj->GetOptimalThreads($options, $target);
    } else {
		self::PrintProgress('Skipping client sweep');
    }

    while (!$options->skipWarmUp && $php_engine->needsRetranslatePause()) {
      self::PrintProgress('Extending warmup, server is not done warming up.');
      sleep(3);
      $siege = new Siege($options, $target, RequestModes::WARMUP_MULTI, 10);
      $siege->start();
      assert($siege->isRunning(), 'Failed to start siege');
      $siege->wait();

      assert(!$siege->isRunning(), 'Siege is still running :/');
      assert(
        $php_engine->isRunning(),
        'php_engine crashed'
      );
    }

    self::PrintProgress('Server warmed, checking queue status.');
    while (!$options->skipWarmUp && !$php_engine->queueEmpty()) {
      self::PrintProgress('Server warmed, waiting for queue to drain.');
      sleep(10);
    }

    self::PrintProgress('Clearing nginx access.log');
    $nginx->clearAccessLog();

    if ($options->waitAfterWarmup) {
      self::PrintProgress('Finished warmup. Press Enter to continue the benchmark');
      fread(STDIN, 1);
    }

    if ($options->scriptAfterWarmup !== null) {
      self::PrintProgress('Starting execution of command: '.$options->scriptAfterWarmup);
      exec($options->scriptAfterWarmup);
    }

    self::PrintProgress('Starting Siege for benchmark');
    $siege = new Siege($options, $target, RequestModes::BENCHMARK);
    $siege->start();
    assert($siege->isRunning(), 'Siege failed to start');
    $siege->wait();

    if ($options->scriptAfterBenchmark !== null) {
      self::PrintProgress('Starting execution of command: '.$options->scriptAfterBenchmark);
      exec($options->scriptAfterBenchmark);
    }

    self::PrintProgress('Collecting results');
    if ($options->remoteSiege) {
      exec((' scp ' .
        $options->remoteSiege . ':' . $options->siegeTmpDir . '/* '.
        $options->tempDir));
    }

    $combined_stats = [];
    $siege_stats = $siege->collectStats();
    foreach ($siege_stats as $page => $stats) {
      if (array_key_exists($page, $combined_stats)) {
	//$combined_stats[$page] = $stats;
        $combined_stats[$page] = array_replace($combined_stats[$page], $stats);
      } else {
        $combined_stats[$page] = $stats;
      }
    }

    $nginx_stats = $nginx->collectStats();
    foreach ($nginx_stats as $page => $stats) {
      if (array_key_exists($page, $combined_stats)) {
        $combined_stats[$page] = array_replace($combined_stats[$page], $stats);
        //$combined_stats[$page] = $stats;
      } else {
          $combined_stats[$page] = $stats;
      }
    }

    $jit_stats = $php_engine->collectStats();
    foreach ($jit_stats as $page => $stats) {
      if ($combined_stats->containsKey($page)) {
        $combined_stats[$page]->setAll($stats);
      } else {
        $combined_stats[$page] = $stats;
      }
    }

    if (!$options->verbose) {
      $combined_stats  =
        array_filter($combined_stats, function ($k) {
          return $k === 'Combined';
        }, ARRAY_FILTER_USE_KEY);
    } else {
      ksort($combined_stats);
    }
    $combined_stats['Combined']['canonical'] =
      (int) !$options->notBenchmarking;

    self::PrintProgress('Collecting TC/PCRE data');
    $php_engine->writeStats();

    if ($options->waitAtEnd) {
      self::PrintProgress('Press Enter to shutdown the server');
      fread(STDIN, 1);
    }
    $php_engine->stop();

    if ($options->tearDownTest != null) {
      $command =
        "OSS_PERF_PHASE=".
        "tearDown".
        " ".
        "OSS_PERF_TARGET=".
        (string) $target." ".$options->tearDownTest;
      self::PrintProgress('Starting tearDownTest '.$command);
      shell_exec($command);
      self::PrintProgress('Finished tearDownTest '.$command);
    } else {
      self::PrintProgress('There is no tearDownTest');
    }

    return $combined_stats;
  }

  private static function PrintProgress($out): void {
    $timestamp = strftime('%Y-%m-%d %H:%M:%S %Z');
    $len = max(strlen($out), strlen($timestamp));
    fprintf(
      STDERR,
      "\n%s\n** %s\n** %s\n",
      str_repeat('*', $len + 3), // +3 for '** '
      $timestamp,
      $out
    );
  }
}
