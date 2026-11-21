<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

use function str_contains;

/**
 * DBAL middleware that runs a callback once, right before the first statement whose SQL contains a fragment is
 * prepared (race tests: another connection writes between the bundle's read and its write).
 */
final class BeforeStatementMiddleware implements Middleware
{
    /** @var array<string, callable(): void> SQL fragment => callback, removed once run */
    private array $callbacks = [];

    /**
     * Runs `$callback` once before the next statement containing `$fragment`.
     *
     * @param callable(): void $callback
     */
    public function before(string $fragment, callable $callback): void
    {
        $this->callbacks[$fragment] = $callback;
    }

    public function wrap(Driver $driver): Driver
    {
        $self = $this;

        return new class($driver, $self) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly BeforeStatementMiddleware $self)
            {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->self) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly BeforeStatementMiddleware $self)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->self->run($sql);

                        return parent::prepare($sql);
                    }
                };
            }
        };
    }

    /**
     * Runs (and forgets) the callbacks whose fragment the SQL contains.
     */
    public function run(string $sql): void
    {
        foreach ($this->callbacks as $fragment => $callback) {
            if (str_contains($sql, $fragment)) {
                unset($this->callbacks[$fragment]);
                $callback();
            }
        }
    }
}
