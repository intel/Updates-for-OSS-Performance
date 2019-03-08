<?php
/*
 *  Copyright (c) 2014-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

abstract class Process {
  protected $process=null;
  protected $stdin;
  protected $stdout;
  protected $command;
  protected $cpuRange = null;
  protected $suppress_stdout = false;
  private $executablePath;
  private static $processes = [];

  public function __construct(string $executablePath) {
    $this->executablePath = $executablePath;
    self::$processes[] = $this;
  }

  final public static function cleanupAll() {
    foreach (self::$processes as $process) {
      $process->__destruct();
    }
    self::$processes = [];
  }

  abstract protected function getArguments();
  protected function getEnvironmentVariables() {
    return [];
  }

  public function getExecutablePath(): string {
    return $this->executablePath;
  }

  abstract public function start(): void;

  public function startWorker(
    ?string $outputFileName = null,
    float $delayProcessLaunch = 0.5,
    bool $trace = false
  ): void {
  $executable = $this->getExecutablePath();
    $curr_args = $this->getArguments();
    $this->command =
      $executable.
      ' '.
      implode(' ', array_map(function($x){ return escapeshellarg($x); }, $curr_args));
    if ($this->suppress_stdout) {
      $this->command .= ' >/dev/null';
    }

    if ($this->cpuRange !== null) {
      $this->command = 'taskset -c '.$this->cpuRange.' '.$this->command;
    }

    $use_pipe = ($outputFileName === null);
    $spec =
      [
        0 => ['pipe', 'r'], // stdin
        1 => $use_pipe ? ['pipe', 'w'] : ['file', $outputFileName, 'a'], // stdout
      // not currently using file descriptor 2 (stderr)
      ];
    $pipes = [];
    $env  = $_ENV;
    $current_env = $this->getEnvironmentVariables();
    $env = array_merge($env,$current_env);

    if ($trace) {
      if ($use_pipe) {
        fprintf(STDERR, "%s\n", $this->command);
      } else {
        fprintf(STDERR, "%s >> %s\n", $this->command, $outputFileName);
      }
    }
    $proc = proc_open($this->command, $spec, $pipes, null, $env);

    // Give the shell some time to figure out if it could actually launch the
    // process
    Process::sleepSeconds($delayProcessLaunch);
    assert(
      $proc && proc_get_status($proc)['running'] === true,
      'failed to start process:'.$this->command
    );

    $this->process = $proc;
    $this->stdin = $pipes[0];
    if ($use_pipe) {
      $this->stdout = $pipes[1];
    }
  }

  public function isRunning(): bool {
    $pid = $this->getPid();
    if ($pid !== null) {
      return (bool) posix_getpgid($this->getPid());
    }
    return false;
  }

  public function stop(): void {
    if (!$this->isRunning()) {
      return;
    }

    if (is_resource($this->stdin)) {
      pclose($this->stdin);
    }
    if (is_resource($this->stdout)) {
      pclose($this->stdout);
    }
    $pid = $this->getPid();
    if ($pid !== null) {
      posix_kill($pid, SIGTERM);
    }
    if (!$this->waitForStop(2, 0.25)) {
      posix_kill($pid, SIGKILL);
    }
  }

  final protected function waitForStop($max_time, $interval): bool {
    for (
      $elapsed = 0;
      $elapsed < $max_time && $this->isRunning();
      $elapsed += $interval
    ) {
      self::sleepSeconds((float) $interval);
    }
    return !$this->isRunning();
  }

  protected function getPidFilePath(): ?string {
    return null;
  }

  public function getPid(): ?int {
    $pid_file = $this->getPidFilePath();
    if ($pid_file && file_exists($pid_file)) {
      return (int) trim(file_get_contents($pid_file));
    }

    $proc = $this->process;
    if ($proc === null) {
      return null;
    }
    $state = proc_get_status($proc);
    if (!$state['running']) {
      return null;
    }
    return $state['pid'];
  }

  public function wait(): void {
    $pid = $this->getPid();
    if ($pid === null) {
      return;
    }
    $status = null;
    pcntl_waitpid($pid, $status);
  }

  public function __destruct() {
    if ($this->isRunning()) {
      $this->stop();
    }
  }

  public static function sleepSeconds(float $secs): void {
    usleep($secs * 1e06);
  }
}
