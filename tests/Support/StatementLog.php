<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

use function array_filter;
use function array_values;
use function preg_match;

/**
 * DBAL middleware that records the SQL of every statement the connection prepares, queries or executes (the
 * "no UPDATE on an unchanged run" and "a dry run writes nothing" assertions of apply-monitors).
 */
final class StatementLog implements Middleware
{
    /** @var list<string> */
    public array $statements = [];

    public function wrap(Driver $driver): Driver
    {
        $log = $this;

        return new class($driver, $log) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly StatementLog $log)
            {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->log) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly StatementLog $log)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->log->statements[] = $sql;

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->log->statements[] = $sql;

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->log->statements[] = $sql;

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    /**
     * The recorded statements that write (INSERT, UPDATE, DELETE).
     *
     * @return list<string>
     */
    public function writes(): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql) === 1));
    }
}
