DISCONTINUATION OF PROJECT

This project will no longer be maintained by Intel.

Intel has ceased development and contributions including, but not limited to, maintenance, bug fixes, new releases, or updates, to this project.  

Intel no longer accepts patches to this project.

If you have an ongoing need to use this project, are interested in independently developing it, or would like to maintain patches for the open source software community, please create your own fork of this project.  

Contact: webadmin@linux.intel.com
Overview
========

The goal is to provide a benchmark suite, testing something representative
of real-world situations. This suite also includes some unrealistic
microbenchmarks - comparing the results of these is fairly pointless, however
they can still be useful to profile, to find optimization opportunities that may
carry over to a real site.

This script configures and runs nginx, siege, and PHP5/PHP7/HHVM over FastCGI, over a TCP
socket. Configuration is as close to identical as possible.

The script will run 300 warmup requests, then as many requests as possible in 1
minute. Statistics are only collected for the second set of data.

Usage
=====

As a regular user:

    php composer.phar install # see https://getcomposer.org/download/
    php perf.php --wordpress --php5=/path/to/bin/php-cgi # also works with php7
    php perf.php --wordpress --php=/path/to/bin/php-fpm # also works with php7
    php perf.php --wordpress --hhvm=/path/to/hhvm

Running with --hhvm gives some additional server-side statistics. It is usual
for HHVM to report more requests than siege - some frameworks do call-back
requests to the current webserver.

:heavy_exclamation_mark: If you run with a debug build you may hit timeouts and
other issues.

Batch Usage
===========

If you want to run multiple combinations:

    php composer.phar install # see https://getcomposer.org/download
    php batch-run.php < batch-run.json > batch-run-out.json

See batch-run.json.example to get an idea of how to create batch-run.json. Please note this feature has not been heavily tested yet.

Requirements
============

On Ubuntu you can run scripts/setup.sh. This should provision your machine with
everything you need to begin running the benchmarks.

This installs:

- composer
- nginx
- siege (versions 2.x, or 3.1.x or 4.0.3)
- unzip
- A mysql server on 127.0.0.1:3306
- php

You need to set 'variables_order = "GPCSE"' in your php.ini to get \_ENV functional.
Note that php asserts are disabled by default.

Siege 3.0.x is not supported; as of writing, all 3.0.x releases have bugs that make
it unable to correctly make the benchmark requests.  4.0.0, 4.0.1, 4.0.2 all
automatically request resources on pages, and should not be used for benchmarking.

The Targets
===========

Toys
----

Unrealistic microbenchmarks. We do not care about these results - they're only
here to give a simple, quick target to test that the script works correctly.

'hello, world' is useful for profiling request handling.

WordPress
---------

- To enable client sweep for Wordpress, assign client-threads=0 
- Data comes from installing the demo-data-creator plugin (included) on a
  fresh install of WordPress, and clicking 'generate data' in the admin panel a
  bunch of times.
- `DISABLE_WP_CRON` is set to true to disable the auto-update and other requests
  to `rpc.pingomatic.com` and `wordpress.org`.
  - auto-updating is not suitable for a like-to-like benchmark system like this
  - `WP_CRON` increases noise, as it makes the benchmark results include the time
    taken by external sites
  - serious deployments should trigger the `WP_CRON` jobs via cron or similar:
    - an administrator should be aware of all production changes, especially
      code version changes
    - scheduled maintainance should not make an unfortunate end-user request take
      significantly more time
- URLs file is based on traffic to hhvm.com - request ratios are:

  100: even spread over long tail of posts
  50: WP front page. This number is an estimate - we get ~ 90 to /, ~ 1 to
      /blog/. Assuming most wordpress sites don't have our magic front page, so
      taking a value roughly in the middle.
  40: RSS feed
  5: Some popular post
  5: Some other popular post
  3: Some other not quite as popular post


The long tail was generated with:

    <?php
      for ($i = 0; $i <= 52; ++$i) {
      printf("http://localhost:__HTTP_PORT__/?p=%d\n", mt_rand(1,52));
    }

Ordering of the URLs file is courtesy of the unix 'shuf' command.

Upgrade WordPress from existing v4.2 to v5.2 

WordPress Update to v5.2
------------------------

Follow the below steps to be able to run WordPress v5.2.
Note that the database dump and URLs file are characeterized by our performance study, and can be found in the wordpress DIR.
These are not standard files downloadable from any other site.
1. Change the directory to the wordpress target directory
cd targets/wordpress
2. Download WordPress 5.2.0 from https://wordpress.org/wordpress-5.2.tar.gz
3. git diff targets/wordpress/WordpressTarget.php
28c28
<         __DIR__.'/wordpress-4.2.0.tar.gz',
---
>         __DIR__.'/wordpress-5.2.0.tar.gz',

4. Make copies of the original dbdumps and URLs file for v4.2
cp targets/wordpress/dbdump.sql.gz targets/wordpress/dbdump_v4.sql.gz 
cp targets/wordpress/WordpressTarget.urls targets/wordpress/WordpressTarget_v4.urls

5. Rename the version 5 files as the files to be used by the workload
cp targets/wordpress/dbdump_v5.sql.gz targets/wordpress/dbdump.sql.gz 
cp targets/wordpress/WordpressTarget_v5.urls targets/wordpress/WordpressTarget.urls

6. Run the workload as before



Drupal 7
--------

Aims to be realistic. Demo data is from devel-generate,
provided by the devel module. Default values were used, except:

- Users were spread over the last year, rather than the last week
- New main menus and navigation menus were created
- New articles and pages were created, with up to 30 comments per content,
  spread over the last year instead of the last week

As well as the database dump, the static files generated by the above process
(user images, images embedded in content) have also been included.

Drupal 8
--------

As above, aims to be realistic. Demo data is from the `devel_generate` module
and default values were used, except:

- Users spread over the last year
- One new menu was created and the navigation menu replaced with 15 items each
- New articles and pages, up to 30 comments per node, spread over the last year

The structure is similar to the Drupal 7 target, except for:

- The settings.php.gz has been replaced by a settings.tar.gz which includes a
  `settings.php`, `services.yml`, and `setup.php` file.
- The `setup.php` file is used to pre-populate the Twig template cache so that
  Repo Authoritative mode can be used.
- A Drupal 8-compatible version of Drush 8 is included with a matching vendor
  directory. This is used to run the above setup script.

SugarCRM
--------

The upstream installation script provides an option to create
demonstration data - this was used to create the database dump included here.

There are two unrealistic microbenchmarks:

- just the login page - the page with the username/password form.
  Added to confirm a
[reported issue](http://zsuraski.blogspot.com/2014/07/benchmarking-phpng.html).
- just the logged-in home page. Added to be a little more realistic than
  rendering the form, however we have no idea what a realistic request
  distribution would look like

Laravel
-------

Unrealistic microbenchmark: just the 'You have arrived' page from an empty
installation.

Laravel 4 and 5 are both available.

Magento
-------

- Data is official Magento sample data, however because of its original size we have replaced all images with a compressed HHVM logo and removed all mp3 files.
- After importing sample data we use the Magento console installer to do the installation for us.
- URLs are a variety of different pages:
    - Homepage
    - Category page
    - CMS page
    - Quicksearch
    - Advanced search
    - Simple product
    - Product with options
    - Product reviews

MediaWiki
---------

The main page is the Barack Obama page from Wikipedia; this is based on the
Wikimedia Foundation using it as a benchmark, and finding it fairly
representative of Wikipedia. A few other pages (HHVM, talk, edit) are also
loaded to provide a slightly more rounded workload.

Profiling
=========

Perf
----
perf.php can keep the suite running indefinitely:

    php perf.php --i-am-not-benchmarking --no-time-limit --wordpress --hhvm=$HHVM_BIN

You can then attach 'perf' or another profiler to the running HHVM or php-cgi process, once the 'benchmarking'
phase has started.

There is also a script to run perf for you at the apropriate moment:

    php perf.php --i-am-not-benchmarking --wordpress --hhvm=$HHVM_BIN --exec-after-warmup="./scripts/perf.sh -e cycles"

This will record 25 seconds of samples.  To see where most time is spent you can
dive into the data using perf, or use the perf rollup script as follows:

    sudo perf script -F comm,ip,sym | hhvm -vEval.EnableHipHopSyntax=true <HHVM SRC>/hphp/tools/perf-rollup.php

In order to have all the symbols from the the translation cache you
may need to change the owner of /tmp/perf-<PID>.map to root.


TC-print
--------
TC-print will use data from perf to determine the hotest functions and
translations.  TC-print supports a number of built in perf counters.
To capture all relevant counters, run the benchmark as follows:
NOTE: perf.sh uses sudo, so look for the password prompt or disable it.

    # Just cycles
    php perf.php --i-am-not-benchmarking --mediawiki --hhvm=$HHVM_BIN --exec-after-warmup="./scripts/perf.sh -e cycles" --tcprint

    # All supported perf event types (Intel)
    php perf.php --i-am-not-benchmarking --mediawiki --hhvm=$HHVM_BIN --exec-after-warmup="./scripts/perf.sh -e cycles,branch-misses,L1-icache-misses,L1-dcache-misses,cache-misses,LLC-store-misses,iTLB-misses,dTLB-misses" --tcprint

    # All supported perf event types (ARM doesn't have LLC-store-misses)
    php perf.php --i-am-not-benchmarking --mediawiki --hhvm=$HHVM_BIN --exec-after-warmup="./scripts/perf.sh -e cycles,branch-misses,L1-icache-misses,L1-dcache-misses,cache-misses,iTLB-misses,dTLB-misses" --tcprint

In order to have all the symbols from the the translation cache you
may need to change the owner of /tmp/perf-<PID>.map to root.

We process the perf data before passing it along to tc-print

    sudo perf script -f -F hw:comm,event,ip,sym | <HHVM SRC>/hphp/tools/perf-script-raw.py > processed-perf.data

If perf script is displaying additional fields, then re-run with -F <-field>,...

    sudo perf script -f -F -tid,-pid,-time,-cpu,-period -F hw:comm,event,ip,sym | <HHVM SRC>/hphp/tools/perf-script-raw.py > processed-perf.data

tc-print is only built if the appropriate disassembly tools are available.  On
x86 this is LibXed.  Consider building hhvm using:

    cmake . -DLibXed_INCLUDE_DIR=<path to xed include> -DLibXed_LIBRARY=<path to libxed.a>

Use tc-print with the generated perf.data:

    <HHVM SRC>/hphp/tools/tc-print/tc-print -c /tmp/<TMP DIR FOR BENCHMARK RUN>/conf.hdf -p processed-perf.data


Contributing
============

Please see [CONTRIBUTING.md](https://github.com/intel/Updates-for-OSS-Performance/blob/main/CONTRIBUTING.md) for details.
