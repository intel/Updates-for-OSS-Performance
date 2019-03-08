<?php
/*
 *  Copyright (c) 2014-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

enum BatchRuntimeType : string {
  HHVM = 'hhvm';
  PHP_SRC = 'php-src';
  PHP_FPM = 'php-fpm';
}
;

function batch_get_runtime(string $name, array $data) {
  return array(
    'name' => $name,
    'type' => $data['type'],
    'bin' => $data['bin'],
    'setUpTest' =>
      array_key_exists('setUpTest', $data) ? $data['setUpTest'] : null,
    'tearDownTest' =>
      array_key_exists('tearDownTest', $data)
        ? $data['tearDownTest']
        : null,
    'args' =>
      array_key_exists('args', $data)
        ? array($data['args'])
        : []
  );
}

function batch_get_target(
  $name,
  $runtimes,
  $overrides,
  $settings,
  ) {
  $target_overrides = [];
  if (array_key_exists($name, $overrides)) {
    $target_overrides = $overrides[$name];
  }

  $target_runtimes = [];
  foreach ($runtimes as $runtime_name => $runtime) {
    if ((array_key_exists($runtime_name, $target_overrides)) {
      $runtime = $target_overrides[$runtime_name];
    }
    // An override can skip a runtime
    if ($runtime !== null) {
      $target_runtimes[] = $runtime;
    }
  }
  return array(
    'name' => $name,
    'runtimes' => $target_runtimes,
    'settings' => $settings
  );
}

function batch_get_targets(string $json_data) {
  $data = json_decode($json_data, true, 512);
  if ($data === null) {
    throw new Exception('Invalid JSON: '.json_last_error_msg());
  }

  $runtimes = [];
  foreach ($data['runtimes'] as $name => $runtime_data) {
    $runtimes[$name] = batch_get_runtime($name, $runtime_data);
  }

  $overrides = [];
  if (array_key_exists('runtime-overrides', $data)) {
    foreach ($data['runtime-overrides'] as $target => $target_overrides) {
      foreach ($target_overrides as $name => $override_data) {
        if ($name === '__comment') {
          continue;
        }
        $skip = false;
        assert(
          array_key_exists($name, $runtimes),
          'Overriding a non-existing runtime "%s"',
          $name
        );
        $override = $runtimes[$name];
        foreach ($override_data as $key => $value) {
          if ($key === 'bin') {
            $override['bin'] = $value;
            continue;
          }
          if ($key === 'skip') {
            $skip = true;
            break;
          }
          echo "Can't override '%s'". $key;
        }
        if (!array_key_exists($target, $overrides)) {
          $overrides[$target] = [];
        }
        $overrides[$target][$name] = $skip ? null : $override;
      }
    }
  }

  $settings = [];
  foreach ($data['settings'] as $name => $value) {
    $settings[$name] = $value;
  }

  $targets = [];
  foreach ($data['targets'] as $target) {
    $targets[] = batch_get_target($target, $runtimes, $overrides, $settings);
  }

  return $targets;
}

function batch_get_single_run(
  $target,
  $runtime,
  $base_argv,
): PerfOptions {
  $argv = clone $base_argv;
  $argv->addAll($runtime['args']);
  $argv[] = '--'.$target['name'];

  foreach ($target['settings'] as $name => $value) {
    if ($name === 'options') {
      foreach ((array)$value as $v) {
        $argv[] = '--'.$v;
      }
    }
  }
  $options = new PerfOptions($argv);
  switch ($runtime['type']) {
    case BatchRuntimeType::HHVM:
      $options->hhvm = $runtime['bin'];
      break;
    case BatchRuntimeType::PHP_SRC:
      $options->php5 = $runtime['bin'];
      $options->fpm = false;
      break;
    case BatchRuntimeType::PHP_FPM:
      $options->php5 = $runtime['bin'];
      $options->fpm = true;
      break;
  }

  foreach ($target['settings'] as $name => $value) {
    switch ($name) {
      case 'username':
        $options->dbUsername = $value;
        break;
      case 'password':
        $options->dbPassword = $value;
        break;
    }
  }

  $options->setUpTest = $runtime['setUpTest'];
  $options->tearDownTest = $runtime['tearDownTest'];

  $options->validate();

  return $options;
}

function batch_get_all_runs_for_target(
  $target,
  $argv,
) {
  $options = [];
  foreach ($target['runtimes'] as $runtime) {
    $options[$runtime['name']] =
      batch_get_single_run($target, $runtime, $argv);
  }
  return $options;
}

function batch_get_all_runs(
  $targets,
  $argv,
) {
  $options = [];
  foreach ($targets as $target) {
    $options[$target['name']] =
      batch_get_all_runs_for_target($target, $argv);
  }
  return $options;
}

function batch_main($argv): void {
  $json_config = file_get_contents('php://stdin');

  $targets = batch_get_targets($json_config);
  $all_runs = batch_get_all_runs($targets, $argv);

  $results = [];
  foreach ($all_runs as $target => $target_runs) {
    $results[$target] = [];
    foreach ($target_runs as $engine => $run) {
      $results[$target][$engine] = PerfRunner::RunWithOptions($run);
      Process::cleanupAll();
      // Allow some time for things to shut down as we need to immediately
      // re-use the ports.
      sleep(5);
    }
  }
  $json = json_encode($results, JSON_PRETTY_PRINT)."\n";
  print ($json);
  file_put_contents('results'.uniqid().'.json', $json);
}

require_once ('base/cli-init.php');
batch_main($argv);
