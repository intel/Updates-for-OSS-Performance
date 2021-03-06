<?php
/*
 *  Copyright (c) 2014-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

final class Utils {

  public static function ExtractTar(string $tar_file, string $extract_to) {
    // Wrong argument order?
    assert(is_dir($extract_to), '%s is not a directory'. $extract_to);
    $flags = null;
    if (substr($tar_file, -3) === '.gz') {
      $flags = '-zxf';
    } else if (substr($tar_file, -4) === '.bz2') {
      $flags = '-jxf';
    }
    assert(
      $flags !== null,
      "Couldn't guess compression for %s. $tar_file"
    );

    shell_exec(
      self::EscapeCommand(
        array ('tar', '-C', $extract_to, $flags, $tar_file)
      )
    );
  }

  public static function CopyDirContents(string $from, string $to) {
    assert(is_dir($from), '%s is not a directory', $from);
    mkdir($to, 0777, true);
    $from_dir = opendir($from);
    while (($name = readdir($from_dir)) !== false) {
      if ($name != '.' && $name != '..') {
        $from_name = $from.DIRECTORY_SEPARATOR.$name;
        $to_name = $to.DIRECTORY_SEPARATOR.$name;
        if (is_dir($from_name)) {
          Utils::CopyDirContents($from_name, $to_name);
        } else {
          copy($from_name, $to_name);
        }
      }
    }
    closedir($from_dir);
  }

  public static function EscapeCommand($command) {
    return implode(' ',array_map(function($x){ return escapeshellarg($x); },$command));
  }

  public static function RunCommand($args) {
    return shell_exec(self::EscapeCommand($args));
  }
}
