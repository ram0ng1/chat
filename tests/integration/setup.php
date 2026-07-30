<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Prepares the throwaway database the integration suite runs against.
 *
 * Run once before the suite:
 *
 *     composer install
 *     vendor/bin/flarum-test-server setup
 *     vendor/bin/phpunit --testsuite integration
 *
 * The database it points at is dropped and rebuilt on every run, so it must not be
 * the forum's own. flarum/testing reads its connection details from
 * tests/integration/tmp/config.php, which `setup` writes interactively.
 */

use Flarum\Testing\integration\Setup\SetupScript;

require __DIR__.'/../../vendor/autoload.php';

$setup = new SetupScript();

$setup->run();
