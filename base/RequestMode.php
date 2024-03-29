<?php
/*
 *  Copyright (c) 2014-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

//newtype RequestMode = string;

final class RequestModes {
  // Single threaded warmup requests to ensure near optimal TC layout
  const WARMUP = 'warmup';
  // Complete run of parallel requests is for a handful of frameworks that
  // continue to substantially JIT even after a high volume of single threaded
  // requests (mostly in conjunction with broken pseudomain JITing).
  const WARMUP_MULTI = 'warmup-multi';
  //automated client sweep to determine optimal client threads for best performance
  const CLIENT_SWEEP = 'client-sweep';
  const BENCHMARK = 'benchmark';
}
