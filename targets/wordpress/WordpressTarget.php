<?php
/*
 *  Copyright (c) 2014-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

final class WordpressTarget extends PerfTarget {
  private $options;

  public function __construct($options) {
    $this->options = $options;
  }

  public function getSanityCheckString(): string {
    return 'Recent Comments';
  }

  public function install(): void {
    $src_dir = $this->options->srcDir;
    if ($src_dir) {
      Utils::CopyDirContents($src_dir, $this->getSourceRoot());
    } else {
      Utils::ExtractTar(
        __DIR__.'/wordpress-4.2.0.tar.gz',
        $this->options->tempDir
      );
    }

    copy(__DIR__.'/wp-config.php', $this->getSourceRoot().'/wp-config.php');

    $file = $this->getSourceRoot().'/wp-config.php';
    $file_contents = file_get_contents($file);
    $file_contents = str_replace('__DB_HOST__', $this->options->dbHost, $file_contents );
    file_put_contents($file, $file_contents);

    $created_database =
      (new DatabaseInstaller($this->options))
        ->setDatabaseName('wp_bench')
        ->setDumpFile(__DIR__.'/dbdump.sql.gz')
        ->installDatabase();
    if (!$created_database) {
      return;
    }

    $visible_port =
      $this->options->proxygen
        ? PerfSettings::BackendPort()
        : PerfSettings::HttpPort();
    $root = 'http://'.gethostname().':'.$visible_port;

    $conn = mysqli_connect($this->options->dbHost, 'wp_bench', 'wp_bench');
    $db_selected = mysqli_select_db($conn, 'wp_bench');
    $result = mysqli_query(
      $conn,
      'UPDATE wp_options '.
      "SET option_value='".
      mysqli_real_escape_string($conn,$root).
      "' ".
      'WHERE option_name IN ("siteurl", "home")'
    );
    if ($result !== true) {
      throw new Exception(mysqli_error($conn));
    }
    mysqli_query(
      $conn,
      'DELETE FROM wp_options WHERE option_name = "admin_email"'
    );
  }

  public function getSourceRoot(): string {
    return $this->options->tempDir.'/wordpress';
  }

  // See PerfTarget::ignorePath() for documentation
  public function ignorePath(string $path): bool {
    // Users don't actually request this
    if (strstr($path, 'wp-cron.php')) {
      return true;
    }
    return false;
  }

  public function needsUnfreeze(): bool {
    return true;
  }

  // Contact rpc.pingomatic.com, upgrade to latest .z release, other periodic
  // tasks
  public function unfreeze(PerfOptions $options): void {
    // Does basic bookkeeping...
    $this->unfreezeRequest($options);
    // Does more involved stuff like upgrading wordpress...
    $this->unfreezeRequest($options);
    // Let's just be paranoid and do it again.
    $this->unfreezeRequest($options);
  }

  private function unfreezeRequest(PerfOptions $options): void {
    $url = 'http://'.gethostname().':'.PerfSettings::HttpPort().'/';
    $ctx = stream_context_create(
      ['http' => ['timeout' => $options->maxdelayUnfreeze]]
    );
    $data = file_get_contents($url, /* include path = */ false, $ctx);
    assert(
      $data !== false,
      'Failed to unfreeze'.$url.'after '.$options->maxdelayUnfreeze.' secs'
    );
  }
}
