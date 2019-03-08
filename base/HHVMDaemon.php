<?php
/*
 *  Copyright (c) 2014-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

final class HHVMDaemon extends PHPEngine {
  private $target;
  private $serverType;
  private $options;

  public function __construct(PerfOptions $options) {
    $this->target = $options->getTarget();
    $this->options = $options;
    parent::__construct((string) $options->hhvm);

    $this->serverType = $options->proxygen ? 'proxygen' : 'fastcgi';

    $output = [];
    $check_command = implode(
      ' ',
      array_map(function($x) { return escapeshellarg($x); },
                                      array(
				      $options->hhvm,
				      '-v',
				      'Eval.Jit=1',
				      __DIR__.'/hhvm_config_check.php')));
    if ($options->traceSubProcess) {
      fprintf(STDERR, "%s\n", $check_command);
    }
    exec($check_command, $output);
    $checks = json_decode(implode("\n", $output), /* as array = */ true);
    if (array_key_exists('HHVM_VERSION', $checks)) {
      $version = $checks['HHVM_VERSION'];
      if (version_compare($version, '3.4.0') === -1) {
        fprintf(
          STDERR,
          'WARNING: Unable to confirm HHVM is built correctly. This is '.
          'supported in 3.4.0-dev or later - detected %s. Please make sure '.
          'that your HHVM build is a release build, and is built against '.
          "libpcre with JIT support.\n",
          $version
        );
        sleep(2);
        return;
      }
    }
    BuildChecker::Check(
      $options,
      (string) $options->hhvm,
      $checks,
      array ('HHVM_VERSION')
    );
  }

  protected function getTarget(): PerfTarget {
    return $this->target;
  }

  //<<__Override>>
  public function needsRetranslatePause(): bool {
    $status = $this->adminRequest('/warmup-status');
    return $status !== '' && $status !== 'failure';
  }

  //<<__Override>>
  public function queueEmpty(): bool {
    $status = $this->adminRequest('/check-queued');
    if ($status === 'failure') {
      return true;
    }
    return $status !== '' && $status === '0';
  }

  //<<__Override>>
  protected function getArguments() {
    if ($this->options->cpuBind) {
      $this->cpuRange = $this->options->daemonProcessors;
    }
    $args = array(
      '-m',
      'server',
      '-p',
      (string) PerfSettings::BackendPort(),
      '-v',
      'AdminServer.Port='.PerfSettings::BackendAdminPort(),
      '-v',
      'Server.Type='.$this->serverType,
      '-v',
      'Server.DefaultDocument=index.php',
      '-v',
      'Server.ErrorDocument404=index.php',
      '-v',
      'Server.SourceRoot='.$this->target->getSourceRoot(),
      '-v',
      'Log.File='.$this->options->tempDir.'/hhvm_error.log',
      '-v',
      'PidFile='.escapeshellarg($this->getPidFilePath()),
      '-c',
      OSS_PERFORMANCE_ROOT.'/conf/php.ini'
    );
    if ($this->options->jit) {
      $args = array_merge($args,array('-v', 'Eval.Jit=1'));
    } else {
      $args = array_merge($args,array('-v', 'Eval.Jit=0'));
    }
    if ($this->options->statCache) {
      $args = array_merge($args,array('-v', 'Server.StatCache=1'));
    }
    if ($this->options->pcreCache) {
      $args = array_merge($args, array('-v', 'Eval.PCRECacheType='.$this->options->pcreCache));
    }
    if ($this->options->pcreSize) {
      $args = array_merge($args,array('-v', 'Eval.PCRETableSize='.$this->options->pcreSize));
    }
    if ($this->options->pcreExpire) {
      $args = array_merge($args, array(
          '-v',
          'Eval.PCREExpireInterval='.$this->options->pcreExpire,
          )
      );
    }
    if (count($this->options->hhvmExtraArguments) > 0) {
      $args = array_merge($args,$this->options->hhvmExtraArguments);
    }
    //$args->add('-vServer.ThreadCount='.$this->options->serverThreads);
    array_push($args,'-vServer.ThreadCount='.$this->options->serverThreads);
    if ($this->options->precompile) {
      $bcRepo = $this->options->tempDir.'/hhvm.hhbc';
      array_push($args,'-v');
      array_push($args,'Repo.Authoritative=true');
      array_push($args,'-v');
      array_push($args,'Repo.Central.Path='.$bcRepo);
    }
    if ($this->options->filecache) {
      $sourceRoot = $this->getTarget()->getSourceRoot();
      $staticContent = $this->options->tempDir.'/static.content';
      array_push($args,'-v');
      array_push($args,'Server.FileCache='.$staticContent);
      array_push($args,'-v');
      array_push($args,'Server.SourceRoot='.$sourceRoot);
    }
    if ($this->options->tcprint !== null) {
      array_push($args,'-v');
      array_push($args,'Eval.DumpTC=true');
    }
    if ($this->options->profBC) {
      array_push($args,'-v');
      array_push($args,'Eval.ProfileBC=true');
    }
    if ($this->options->interpPseudomains) {
      array_push($args,'-v');
      array_push($args,'Eval.JitPseudomain=false');
    }
    if ($this->options->allVolatile) {
      array_push($args,'-v');
      array_push($args,'Eval.AllVolatile=true');
    }
    return $args;
  }

  protected function getPidFilePath(): string {
    return $this->options->tempDir.'/hhvm.pid';
  }

  public function start(): void {
    if ($this->options->precompile) {
      $sourceRoot = $this->getTarget()->getSourceRoot();
      $hhvm = $this->options->hhvm;
      assert(!is_null($hhvm), "Must have hhvm path");
      $args = array (
        $hhvm,
        '--hphp',
        '--target',
        'hhbc',
        '--output-dir',
        $this->options->tempDir,
        '--input-dir',
        $sourceRoot,
        '--module',
        '/',
        '--cmodule',
        '/',
        '-l3',
        '-k1',
       );

      if ($this->options->allVolatile) {
        $args->add('-v');
        $args->add('AllVolatile=true');
      }

      assert(is_dir($sourceRoot), 'Could not find valid source root');

      $dir_iter = new RecursiveDirectoryIterator($sourceRoot);
      $iter = new RecursiveIteratorIterator($dir_iter);
      foreach ($iter as $info) {
        $path = $info->getPathname();
        // Source files not ending in .php need to be specifically included
        if (is_file($path) && substr($path, -4) !== '.php') {
          $contents = file_get_contents($path);
          if (strpos($contents, '<?php') !== false) {
            $arg =
              "--ffile=".ltrim(substr($path, strlen($sourceRoot)), '/');
            //$args->add($arg);
            array_push($args,$arg);
          }
        }
      }

      $bcRepo = $this->options->tempDir.'/hhvm.hhbc';
      if (file_exists($bcRepo)) {
        unlink($bcRepo);
      }

      $staticContent = $this->options->tempDir.'/static.content';
      if ($this->options->filecache) {
        if (file_exists($staticContent)) {
          unlink($staticContent);
	}
	array_push($args,'--file-cache');
	array_push($args,$staticContent);
      }

      Utils::RunCommand($args);

      assert(file_exists($bcRepo), 'Failed to create bytecode repo');
      assert(
        !$this->options->filecache || file_exists($staticContent),
        'Failed to create static content cache'
      );
    }

    if ($this->options->pcredump) {
      if (file_exists('/tmp/pcre_cache')) {
        unlink('/tmp/pcre_cache');
      }
    }

    parent::startWorker(
      $this->options->daemonOutputFileName('hhvm'),
      $this->options->delayProcessLaunch,
      $this->options->traceSubProcess
    );
    assert($this->isRunning(), 'Failed to start HHVM');
    for ($i = 0; $i < 10; ++$i) {
      Process::sleepSeconds($this->options->delayCheckHealth);
      $health = $this->adminRequest('/check-health', true);
      if ($health) {
        if ($health === "failure") {
          continue;
        }
        $health = json_decode($health, /* assoc array = */ true);
        if (array_key_exists('tc-size', $health) &&
            ($health['tc-size'] > 0 || $health['tc-hotsize'] > 0)) {
          return;
        }
      }
    }
    // Whoops...
    $this->stop();
  }

  public function stop(): void {
    if (!$this->isRunning()) {
      return;
    }

    try {
      $health = $this->adminRequest('/check-health');
      if (!($health && json_decode($health))) {
        parent::stop();
        return;
      }
      $time = microtime(true);
      $this->adminRequest('/stop');
      $this->waitForStop(10, 0.1);
    } catch (Exception $e) {
    }

    $pid = $this->getPid();
    if ($this->isRunning() && $pid !== null) {
      posix_kill($pid, SIGKILL);
    }
    assert($this->waitForStop(1, 0.1), "HHVM is unstoppable!");
  }


  public function collectStats() {
    if (!$this->options->warmupStats || !$this->options->jit) {
        return [];
    }
    $jit_timers = [];
    $counts = [];
    $entry_marker = "JIT timers for";
    $log = file_get_contents($this->options->tempDir.'/wrmpstats.log');
    $lines = explode("\n", trim($log));
    $cur_url = null;

    foreach ($lines as $line) {
        if (strlen($line) == 0) {
            $cur_url = null;
            continue;
        }
        if (substr($line, 0, strlen($entry_marker)) === $entry_marker) {
            $cur_url = explode($entry_marker, $line)[1];
            if (!(array_key_exists($cur_url, $jit_timers))) {
                $jit_timers[$cur_url] = [];
                $counts[$cur_url] = [];
            }
            continue;
        }
        assert(
            $cur_url != null,
            'Unrecognized warmup stats file format!');
        if (substr($line, 0, 4) === "name" || $line[0] === '-') {
            continue;
        }
        #name   |   count   total(in us)   average(in ns)     max(in ns)
        $parts = explode(' ', preg_replace('/\s+/', ' ', $line));
        list($stat_name, $count, $total) =
            [$parts[0],
            (int)filter_var($parts[2], FILTER_SANITIZE_NUMBER_INT),
            (float)filter_var($parts[3], FILTER_SANITIZE_NUMBER_FLOAT)];

	if (!array_key_exists($stat_name,$jit_timers[$cur_url])) {
            $jit_timers[$cur_url][$stat_name] = 0;
            $counts[$cur_url][$stat_name] = 0;
        }

        $jit_timers[$cur_url][$stat_name] += $total;
        $counts[$cur_url][$stat_name] += $count;
    }
    $combined = [];
    foreach ($jit_timers as $url => $entries) {
        foreach ($entries as $stat_name => $avg) {
		#$jit_timers[$url][$stat_name] /= $counts[$url][$stat_name];
	    if(!array_key_exists($stat_name,$combined)) {
                $combined[$stat_name] = (float)0;
            }
            #show 'Combined' in seconds instead of us
            $combined[$stat_name] += $jit_timers[$url][$stat_name] / 1000;
        }
    }
    $jit_timers['Combined'] = $combined;
    return $jit_timers;
  }

  public function writeStats(): void {
    $tcprint = $this->options->tcprint;
    $conf = $this->options->tempDir.'/conf.hdf';
    $args = [];
    $hdf = false;
    foreach ($this->getArguments() as $arg) {
	if ($hdf)
          array_push($args,$arg);
        //$args->add($arg);
      $hdf = $arg === '-v';
    }
    $confData = implode("\n", $args);

    file_put_contents($conf, $confData);
    if ($tcprint) {
      $result = $this->adminRequest('/vm-dump-tc');
      assert(
        $result === 'Done' && file_exists('/tmp/tc_dump_a'),
        'Failed to dump TC'
      );
    }

    if ($this->options->pcredump) {
      $result = $this->adminRequest('/dump-pcre-cache');
      assert(
        $result === "OK\n" && file_exists('/tmp/pcre_cache'),
        'Failed to dump PCRE cache'
      );

      // move dump to CWD
      rename('/tmp/pcre_cache', getcwd().'/pcre_cache');
    }
  }

  protected function adminRequest(
    string $path,
    bool $allowFailures = true
  ){
    $url = 'http://localhost:'.PerfSettings::HttpAdminPort().$path;
    $ctx = stream_context_create(
      ['http' => ['timeout' => $this->options->maxdelayAdminRequest]]
    );
    //
    // TODO: it would be nice to suppress
    // Warning messages from file_get_contents
    // in the event that the connection can't even be made.
    //
    $result = file_get_contents($url, /* include path = */ false, $ctx);
    if ($result !== false) {
      return $result;
    }
    if ($allowFailures) {
      return "failure";
    } else {
      assert($result !== false, 'Admin request failed');
      return $result;
    }
  }

  protected function getEnvironmentVariables() {
    $envVars = array ('OSS_PERF_TARGET' => (string) $this->target);
    if ($this->options->warmupStats && $this->options->jit) {
        $traceFile = $this->options->tempDir.'/wrmpstats.log';
        $envVars['HPHP_TRACE_FILE'] = $traceFile;
        $envVars['TRACE'] = 'jittime:100';

    }
    return $envVars;
  }

  public function __toString(): string {
    return (string) $this->options->hhvm;
  }
}
