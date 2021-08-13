<?php
/*
 *  Copyright (c) 2014-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

final class Siege extends Process {
  use SiegeStats;

  private $logfile;
  private $options;
  private $target;
  private $mode;
  private $time;

  public function __construct(
    PerfOptions $options,
    PerfTarget $target,
    $mode,
    $time = 30
  ) {
    $this->options = $options;
    $this->target = $target;
    $this->mode = $mode;
    $this->time = $time;

    parent::__construct($options->siege);
    $this->suppress_stdout = true;

    if (!$options->skipVersionChecks) {
      $version_line = trim(
        exec(
          escapeshellarg($options->siege).' --version 2>&1 | head -n 1'
        )
      );
      $bad_prefixes = array (
        'SIEGE 3.0',
        'SIEGE 4.0.0',
        'SIEGE 4.0.1',
        'SIEGE 4.0.2'
);
      foreach ($bad_prefixes as $bad_prefix) {
        if (substr($version_line, 0, strlen($bad_prefix)) === $bad_prefix) {
          fprintf(
            STDERR,
            "WARNING: Siege 3.0.0-3.0.7 sends an incorrect HOST header to ".
            "ports other than :80 and :443. Siege 3.0.8 and 3.0.9 sometimes ".
            "sends full URLs as paths. Siege 4.0.0 - 4.0.2 automatically ".
            "requests page resources.  You are using '%s'.\n\n".
            "You can specify a path to a proper siege version with the ".
            "--siege=/path/to/siege option. If you have patched siege to fix ".
            "these issues, pass --skip-version-checks.\n",
            $version_line
          );
          exit(1);
        }
      }
    }

    if ($mode === RequestModes::BENCHMARK || $mode === RequestModes::CLIENT_SWEEP) {
      $this->logfile = tempnam($options->tempDir, 'siege');
    }
  }

  public function __destruct() {
    $logfile = $this->logfile;
    if ($logfile !== null && file_exists($logfile)) {
      unlink($logfile);
    }
  }

  public function start(): void {
    parent::startWorker(
      $this->options->daemonOutputFileName('siege'),
      $this->options->delayProcessLaunch,
      $this->options->traceSubProcess
    );
  }

  public function getExecutablePath(): string {
    if ($this->options->remoteSiege) {
      if ($this->options->noTimeLimit) {
        return 'ssh ' . $this->options->remoteSiege . ' ' .
          parent::getExecutablePath();
      }
      return 'ssh ' . $this->options->remoteSiege . ' \'timeout\'';
    }
    if ($this->options->noTimeLimit) {
      return parent::getExecutablePath();
    }
    // Siege calls non-signal-safe functions from it's log function, which
    // it calls from signal handlers. Leads to hang.
    return 'timeout';
  }

  protected function getArguments() {
    if ($this->options->cpuBind) {
      $this->cpuRange = $this->options->helperProcessors;
    }
    $urls_file = tempnam($this->options->tempDir, 'urls');
    $urls = file_get_contents($this->target->getURLsFile());
    $urls =
      str_replace('__HTTP_PORT__', (string) PerfSettings::HttpPort(), $urls);
    // Siege doesn't support ipv6
    $urls = str_replace('__HTTP_HOST__', gethostname(), $urls);
    file_put_contents($urls_file, $urls);

    if ($this->options->remoteSiege) {
      exec('scp ' . $urls_file . ' ' .
        $this->options->remoteSiege . ':' . $this->options->siegeTmpDir);
      $urls_file = $this->options->siegeTmpDir . '/' . basename($urls_file);
    }

    $arguments = [];
    if (!$this->options->noTimeLimit) {
      $timeout = 4 * 60; // 4 minutes
      if ($this->mode == RequestModes::BENCHMARK) {
        $timeout += $this->options->benchmarkTime;
      } else {
        $timeout += $this->time;
      }
      $arguments = array (
        // See Siege::getExecutablePath()  - these arguments get passed to
        // timeout
        '--signal=9',
        (string) $timeout,
        parent::getExecutablePath(),
      );
    }
    $siege_rc = $this->target->getSiegeRCPath();
    if ($siege_rc !== null) {
      array_push($arguments,'-R', $siege_rc);
    }

    if (!$this->options->fetchResources) {
       array_push($arguments,'--no-parser');
    }

    switch ($this->mode) {
      case RequestModes::WARMUP:
        array_push($arguments,
            '-c',
            (string) PerfSettings::WarmupConcurrency(),
            '-r',
            (string) PerfSettings::WarmupRequests(),
            '-f',
            $urls_file,
            '--benchmark',
            '--log=/dev/null'
        );
        return $arguments;
      case RequestModes::WARMUP_MULTI:
        array_push($arguments,
            '-c',
            '200',
            '-t',
            ((string) $this->time).'S',
            '-f',
            $urls_file,
            '--benchmark',
            '--log=/dev/null'
        );
        return $arguments;
      case RequestModes::CLIENT_SWEEP:
          $logfile = $this->logfile;
          $client_sweep_time = 10;
          array_push($arguments,
              '-c',
              $this->options->clientThreads,
              '-t',
              ((string) $client_sweep_time).'S',
              '-f',
              $urls_file,
              '--benchmark',
              '--log='.$logfile       
          );
          return $arguments;
      case RequestModes::BENCHMARK:
        if($this->options->remoteSiege) {
          $logfile = $this->options->siegeTmpDir . '/' .
	    basename($this->logfile);
        } else {
          $logfile = $this->logfile;
        }
        array_push($arguments,
            '-c',
            $this->options->clientThreads,
            '-f',
            $urls_file,
            '--benchmark',
            '--log='.$logfile
        );

	if (!$this->options->noTimeLimit) {
	  array_push($arguments, '-t');
	  array_push($arguments, (((string)$this->options->benchmarkTime).'S'));
        }
        return $arguments;
      default:
        echo(
          'Unexpected request mode: %s'. $this->mode
        );
    }
  }

  protected function getLogFilePath(): string {
    $logfile = $this->logfile;
    assert(
      $logfile !== null,
      'Tried to get log file path without a logfile'
    );
    return $logfile;
  }
}
