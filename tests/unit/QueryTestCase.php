<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\User\Guest;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that touch an Eloquent model or build a query.
 *
 * An in-memory SQLite connection is registered as the resolver rather than mocking
 * one. Eloquent reaches for the connection in more places than is obvious — casting
 * an attribute to a date goes through `getConnection()->getQueryGrammar()` for the
 * date format — so a partial mock fails in ways that look like a bug in the code
 * under test. No schema is created and nothing is executed: the queries are compiled
 * to SQL and asserted on.
 */
abstract class QueryTestCase extends TestCase
{
    private static ?Manager $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $capsule = new Manager();

        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Makes this the resolver every model uses, which is what the date casts and
        // the query grammar need.
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    protected function state(Builder $query): DatabaseSearchState
    {
        $state = new DatabaseSearchState(new Guest(), false);
        $state->setQuery($query);

        return $state;
    }

    /**
     * Normalises whitespace so an assertion is about the predicate, not about how
     * the grammar happened to space it.
     */
    protected function sql(Builder $query): string
    {
        return preg_replace('/\s+/', ' ', $query->toSql()) ?? '';
    }
}
