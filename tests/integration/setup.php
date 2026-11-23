<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Prepara o banco descartável em que a suíte de integração roda.
 *
 * Rode uma vez antes da suíte:
 *
 *     composer install
 *     php tests/integration/setup.php
 *     vendor/bin/phpunit --testsuite integration
 *
 * O banco é apagado e recriado a cada execução, então não pode ser o do fórum.
 * A conexão vem de DB_DRIVER / DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME /
 * DB_PASSWORD / DB_PREFIX e cai em SQLite por padrão, sem precisar de
 * argumentos. A config vai para FLARUM_TEST_TMP_DIR_LOCAL (tests/integration/tmp
 * no workflow reutilizável do core) ou, sem ele, para vendor/flarum/testing.
 */

use Flarum\Testing\integration\Setup\SetupScript;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

require __DIR__.'/../../vendor/autoload.php';

$setup = new SetupScript();

$setup->run();

// O cache do catálogo de traduções grava aqui; sem a pasta, cada teste que
// traduz algo dispara um warning.
$tmpDir = getenv('FLARUM_TEST_TMP_DIR_LOCAL') ?: getenv('FLARUM_TEST_TMP_DIR') ?: __DIR__.'/../../vendor/flarum/testing/src/integration/tmp';
@mkdir($tmpDir.'/storage/locale', 0777, true);

// A tabela do ernestdefoe/calendar, como a migration dele cria, para os testes do
// horário do canal lerem eventos de verdade (ChannelScheduleTest). Criada aqui,
// antes de qualquer teste: um CREATE TABLE no meio do teste confirma a transação
// dele no MySQL e no MariaDB, e no InnoDB uma tabela criada depois do snapshot da
// transação nem pode ser lida por ela.
$config = require $tmpDir.'/config.php';
$database = $config['database'];

if ($database['driver'] === 'sqlite' && ! file_exists($database['database'])) {
    $database['database'] = $tmpDir.'/'.$database['database'];
}

$capsule = new Manager();
$capsule->addConnection($database);
$schema = $capsule->getConnection()->getSchemaBuilder();

if (! $schema->hasTable('calendar_events')) {
    $schema->create('calendar_events', function (Blueprint $table) {
        $table->increments('id');
        $table->string('title', 255);
        $table->string('slug', 255);
        $table->dateTime('start_at');
        $table->dateTime('end_at')->nullable();
        $table->boolean('all_day')->default(false);
        $table->string('timezone', 64)->default('UTC');
        $table->string('rrule', 600)->nullable();
        $table->boolean('is_published')->default(true);
    });
}
