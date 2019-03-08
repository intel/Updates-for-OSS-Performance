<?php
/*
 *  Copyright (c) 2014-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

final class DatabaseInstaller {
  private $databaseName;
  private $dumpFile;
  private $username;
  private $password = null;

  private $options;

  public function __construct($options){
    $this->options = $options;
    $this->configureMysqlAffinity();
  }

  public function getUsername(): ?string {
    return $this->username ? $this->username : $this->databaseName;
  }

  public function getPassword(): ?string {
    return $this->password !== null ? $this->password : $this->databaseName;
  }

  public function setDatabaseName(string $database_name) {
    $this->databaseName = $database_name;
    return $this;
  }

  public function setDumpFile(string $dump_file){
    $this->dumpFile = $dump_file;
    return $this;
  }

  public function configureMysqlAffinity(): void {
    if ($this->options->cpuBind) {
      exec("sudo taskset -acp ".$this->options->helperProcessors." `pgrep mysqld`");
      print "You need to restart mysql after the benchmarks to remove the ";
      print "processor affinity.\n";
    }
  }

  public function installDatabase(): bool {
    $db = $this->databaseName;
    $dump = $this->dumpFile;
    $dbHost = $this->options->dbHost;

    assert(
      $db !== null && $dump !== null,
      'database and dump must be specified'
    );
    if ($this->options->skipDatabaseInstall) {
      $this->checkMySQLConnectionLimit();
      return false;
    }

    $conn = mysqli_connect($dbHost, $db, $db);
    $db_selected = mysqli_select_db($conn, $db);
    if ($conn === false || $db_selected === false) {
      $this->createMySQLDatabase();
    }
    $this->checkMySQLConnectionLimit();

    $sed = null;
    if ($this->options->forceInnodb) {
      $sed = 'sed s/MyISAM/InnoDB/g |';
    }

    $cat = 'cat';
    if ($this->options->dumpIsCompressed) {
      $cat = trim(shell_exec('which gzcat 2>/dev/null'));
      if (!$cat) {
        $cat = 'zcat';
      }
    }

    $output = null;
    $ret = null;
    exec(
      Utils::EscapeCommand(
        array ($cat, $dump)
      ).
      '|'.
      $sed.
      Utils::EscapeCommand(
        array ('mysql', '-h', $dbHost.'', $db, '-u', $db, '-p'.$db)
      ),
      $output,
      $ret
    );

    if ($ret !== 0) {
      throw new Exception(
        'Database installation failed: '.implode("\n", $output)
      );
    }

    return true;
  }

  private function getRootConnection()  {
    if ($this->options->dbUsername !== null
        && $this->options->dbPassword !== null) {
      $this->username = $this->options->dbUsername;
      $this->password = $this->options->dbPassword;
    } else {
      print "MySQL admin user (default is 'root'): ";
      $this->username = trim(fgets(STDIN)) ?: 'root';
      fprintf(STDERR, '%s', 'MySQL admin password: ');
      $this->password = trim(fgets(STDIN));
    }
    $conn = mysqli_connect($this->options->dbHost, $this->username, $this->password);
    if ($conn === false) {
      throw new Exception('Failed to connect: '.mysql_error());
    }
    return $conn;
  }

  private function checkMySQLConnectionLimit(): void {
    $conn =
      mysqli_connect($this->options->dbHost, $this->getUsername(), $this->getPassword());
    if ($conn === false) {
      throw new Exception('Failed to connect: '.mysql_error());
    }
    $data = mysqli_fetch_assoc(
      mysqli_query(
	      $conn,
	      "SHOW variables WHERE Variable_name = 'max_connections'"
      )
    );
    mysqli_close($conn);
    if ($data['Value'] < 1000) {
      fprintf(
        STDERR,
        "Connection limit is too low - some benchmarks will have connection ".
        "errors. This can be fixed for you..\n"
      );
      $conn = $this->getRootConnection();
      mysqli_query($conn, 'SET GLOBAL max_connections = 1000');
      mysqli_close($conn);
    }
  }

  private function createMySQLDatabase(): void {
    $db = $this->databaseName;
    assert($db !== null, 'Database must be specified');
    fprintf(
      STDERR,
      '%s',
      "Can't connect to database ".
      "(mysql -h {$this->options->dbHost} -p$db -u $db $db). This can be ".
      "fixed for you.\n"
    );
    $conn = $this->getRootConnection();
    $edb = mysqli_real_escape_string($conn, $db);
    mysqli_query($conn, "DROP DATABASE IF EXISTS $edb");
    mysqli_query($conn, "CREATE DATABASE $edb");

    /* In theory, either one of these works, with 127.0.0.1 being the minimal
     * one.
     * - do % so that if someone debugs with localhost, hostname, or ::1 (IPv6)
     *   it works as expectedd
     * - do 127.0.0.1 as well, just in case there's a pre-existing incompatible
     *   grant
     */
    mysqli_query(
      $conn,
      'GRANT ALL PRIVILEGES ON '.
      $edb.
      '.* TO "'.
      $edb.
      '"@"%" '.
      'IDENTIFIED BY "'.
      $edb.
      '"'
    );
    mysqli_query(
      $conn,
      "GRANT ALL PRIVILEGES ON $edb.* TO '$edb'@'{$this->options->dbHost}' ".
      "IDENTIFIED BY '$edb'"
    );
  }
}
