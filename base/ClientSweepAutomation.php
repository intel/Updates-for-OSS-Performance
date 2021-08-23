<?php
/*
 *  Copyright (c) 2021-present, Intel Corp.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

//Client sweep automation does the following - 
//1. Starts with 50 threads and increments by 50 for every iteration. Each iteration is run for 10 seconds.
//2. A % difference is calculated between the TPS of the current and previous iteration.
//3. When there is a % difference of <= 1% for 7 consecutive iterations, the sweep is stopped (indicating a plateau). You can change this number 7 using the const variable plateau.
//4. As the sweep progresses, the client threads corresponding to peak TPS values are saved. When there is more than 2% difference with a previous peak, a new peak is determined.
//5. The sweep can go up to 20 iterations if the optimal number of client threads is not deduced earlier. You can change this number 20 using the const client_sweep_max_iterations.

class ClientSweepAutomation {
    const client_sweep_max_iterations = 20;
    const plateau = 7;
    private $curr_tps;
    private $prev_tps;
    private $max;
    private $failedReq;
    private $diff_percent;
    private $plateau_tracker;
    private $clientsweep_results;
    private $optimalClientThreads;

    public function __construct() {
      $this->curr_tps = 1;
      $this->prev_tps = 0;
      $this->max = 0;
      $this->failedReq = 0;
      $this->diff_percent = 0;
      $this->plateau_tracker = 0;
      $this->clientsweep_results = array();
      $this->optimalClientThreads = 0;
    }

    public function GetOptimalThreads(PerfOptions $options, PerfTarget $target, $mode) {
      $options->clientThreads = 0;
      for ($iteration = 1; $iteration <= self::client_sweep_max_iterations; $iteration++) {
        $options->clientThreads += 50;
        $siege = PerfRunner::RunSiege($options, $target, $mode);
        $this->ParseSiegeOutput($siege, $options, $iteration);
        if (!($this->CheckIfContinue($options, $iteration))) {
          break;
        }       
      }
      $this->PrintClientSweepResults();
      if ($this->optimalClientThreads == 0) {
        trigger_error("Client sweep automation could not figure optimal client threads", E_USER_ERROR);
      }
      return $this->optimalClientThreads;
    }

    private function ParseSiegeOutput(Siege $siege, PerfOptions $options, $iteration) {
      $siege_stats = $siege->collectStats();
      $this->curr_tps = $siege_stats['Combined']['Siege RPS'];
      $this->failedReq = $siege_stats['Combined']['Siege failed requests'];
      if ($iteration != 1) {
        $this->diff_percent = (((floor($this->curr_tps - $this->prev_tps))/$this->prev_tps)*100);
        $this->diff_percent = intval($this->diff_percent * ($p = pow(10,2))) / $p;
      } else {
        $this->diff_percent = 0;
        $this->max = $this->curr_tps;
      }
      $row = array(
        "iter" => $iteration,
        "clientThreads" => $options->clientThreads,
        "TPS" => $this->curr_tps,
        "diffPercentage" => $this->diff_percent . " %",
        "failedRequests" => $this->failedReq
      );
      array_push($this->clientsweep_results, $row);
    }

    private function CheckIfContinue(PerfOptions $options, $iteration) {
      if ($this->failedReq > 0) {
          return false;
      }
      $this->CheckIfPeak($options, $iteration);
      if ($this->CheckIfPlateau()) {
          return false;
      } else {
        $this->prev_tps = $this->curr_tps;
        return true;
      }
    }

    private function CheckIfPeak(PerfOptions $options, $iteration) {
      if (($this->diff_percent > 0) && ($this->curr_tps > $this->max)) {
          if ($iteration != 1) {
            $diff_with_max = floor(((floor($this->curr_tps - $this->max))/$this->max)*100);
          } else {
            $diff_with_max = NULL;
          }
          if ($diff_with_max > 2) {
            $this->optimalClientThreads = $options->clientThreads;
            $this->max = $this->curr_tps;
          }
      }
    }

    private function CheckIfPlateau() {
      if ($this->diff_percent <= 1) {
        $this->plateau_tracker++;
        if ($this->plateau_tracker == self::plateau) {
          return true;
        }
      } else {
          $this->plateau_tracker = 0;
          return false;
      }
      return false;
    }

    private function PrintClientSweepResults() {
      echo "\nPrinting client sweep results\n";
      $res_tbl = new Table();
      $res_tbl->AddColumnNames(array('Iteration', 'ClientThreads', 'TPS','Difference %', 'Failed Requests'));
      foreach ($this->clientsweep_results as $row) {
        if ($row['clientThreads'] == $this->optimalClientThreads) {
          $row['clientThreads'] = $this->optimalClientThreads . "**";
        }
        $res_tbl->AddRow($row);
      }
      $res_tbl->GenerateTable();
    }
}