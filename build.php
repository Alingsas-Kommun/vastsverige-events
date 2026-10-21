#!/bin/php
<?php

/**
 * Called by the site build.php during deploy.
 *
 * There is nothing to compile. The plugin autoloads src/ itself and has no
 * runtime Composer or npm dependencies, so a source checkout runs as-is.
 * --cleanup strips files that should not land on a public server.
 *
 * Accepted flags (same as the other plugins, so the root build can pass them):
 * --cleanup      Remove the files listed below.
 * --no-composer  Ignored. There is no vendor tree to install.
 * --install-npm  Ignored. There are no front-end assets.
 */

if (php_sapi_name() !== 'cli') {
    exit(0);
}

$removables = [
    '.git',
    '.gitignore',
    '.gitattributes',
    '.github',
    '.cursor',
    '.phpunit.cache',
    'AGENTS.md',
    'build.php',
    'composer.json',
    'composer.lock',
    'phpunit.xml',
    'README.md',
    'tests',
    'vendor',
];

if (! is_array($argv) || ! in_array('--cleanup', $argv, true)) {
    print "Nothing to build.\n";
    exit(0);
}

$dirName = basename(__DIR__);

foreach ($removables as $removable) {
    if (file_exists($removable)) {
        print "Removing $removable from $dirName\n";
        shell_exec('rm -rf ' . escapeshellarg($removable));
    }
}
