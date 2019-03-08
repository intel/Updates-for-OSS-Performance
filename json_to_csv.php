<?php
/*
 *  Copyright (c) 2014-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

function load_file(string $file) {
  $json = file_get_contents($file);
  $data = json_decode($json, /* as associative array = */ true);

  $results = [];
  foreach ($data as $framework => $framework_data) {
    $results = array_merge($results,load_framework($framework, $framework_data));
  }

  return $results;
}

function load_framework(string $framework, $data) {
  $results = [];
  foreach ($data as $runtime => $runtime_data) {
    $results[] = load_run($framework, $runtime, $runtime_data['Combined']);
  }
  return $results;
}

function load_run(string $framework, string $runtime, $data) {
  return array(
    'framework' => $framework,
    'runtime' => $runtime,
    'rps' => $data['Siege RPS'],
  );
}

function load_files($files) {
  $rows_by_key = [];
  foreach ($files as $file) {
    $samples = load_file($file);
    foreach ($samples as $sample) {
      $key = $sample['framework']."\0".$sample['runtime'];
      if (!array_key_exists($key,$rows_by_key)) {
        $rows_by_key[$key] = array(
          'framework' => $sample['framework'],
          'runtime' => $sample['runtime'],
          'rps_samples' => [],
          'rps_mean' => null,
          'rps_sd' => null
        );
      }
      $rows_by_key[$key]['rps_samples'][] = $sample['rps'];
    }
  }

  foreach ($rows_by_key as $key => $row) {
    $samples = $row['rps_samples'];
    $count = count($samples);

    // toArray(): https://github.com/facebook/hhvm/issues/5454
    $mean = (float) array_sum($samples->toArray()) / $count;
    $variance = array_sum(
      $samples->map($x ==> pow($mean - $x, 2))->toArray()
    ) / $count;
    $sd = sqrt($variance);

    $row['rps_mean'] = $mean;
    $row['rps_sd'] =  $sd;

    $rows_by_key[$key] = $row;
  }

  return array_values($rows_by_key);
}

function dump_csv($rows): void {
  $header = array(
    'Framework',
    'Runtime',
    'Mean RPS',
    'RPS Standard Deviation'
  );

  $max_sample_count = max($rows->map($row ==> count($row['rps_samples'])));
  for ($i = 1; $i <= $max_sample_count; ++$i) {
    $header[] = 'Sample '.$i.' RPS';
  }

  fputcsv(STDOUT, $header);
  foreach ($rows as $row) {
    $out = array (
      $row['framework'],
      $row['runtime'],
      $row['rps_mean'],
      $row['rps_sd'],
    );
    $out = array_merge($out,$row['rps_samples']);
    //$out->addAll($row['rps_samples']);
    fputcsv(STDOUT, $out);
  }
}

function main($argv) {
  $files = clone $argv;
  //$files->remoiveKey(0);
  unset ($files[0]);
  if ($files->isEmpty()) {
    fprintf(STDERR, "Usage: %s results.json [results2.json ...]\n", $argv[0]);
    exit(1);
  }
  dump_csv(load_files($files));
}

main($argv);
