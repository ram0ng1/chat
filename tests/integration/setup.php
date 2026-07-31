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
 *     php tests/integration/setup.php
 *     vendor/bin/phpunit --testsuite integration
 *
 * The database it points at is dropped and rebuilt on every run, so it must not be
 * the forum's own. Connection details come from the DB_DRIVER / DB_HOST / DB_PORT /
 * DB_DATABASE / DB_USERNAME / DB_PASSWORD / DB_PREFIX environment variables and
 * default to SQLite, so an unattended run needs no arguments. The resulting config
 * is written under vendor/flarum/testing/src/integration/tmp/.
 */

use Flarum\Testing\integration\Setup\SetupScript;

require __DIR__.'/../../vendor/autoload.php';

$setup = new SetupScript();

$setup->run();
