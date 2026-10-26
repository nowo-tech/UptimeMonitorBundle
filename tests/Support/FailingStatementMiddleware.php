<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use RuntimeException;
use SensitiveParameter;

use function str_contains;

/**
 * DBAL middleware that makes exec() throw for statements containing a given fragment (schema-sync error paths).
 */
final readonly class FailingStatementMiddleware implements Middleware
{
    /**
     * @param array<string, string> $failures SQL fragment => exception message
     */
    public function __construct(private array $failures)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $failures = $this->failures;

        return new class($driver, $failures) extends AbstractDriverMiddleware {
            /**
             * @param array<string, string> $failures
             */
            public function __construct(Driver $driver, private readonly array $failures)
            {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->failures) extends AbstractConnectionMiddleware {
                    /**
                     * @param array<string, string> $failures
                     */
                    public function __construct(Connection $connection, private readonly array $failures)
                    {
                        parent::__construct($connection);
                    }

                    public function exec(string $sql): int|string
                    {
                        foreach ($this->failures as $fragment => $message) {
                            if (str_contains($sql, $fragment)) {
                                throw new RuntimeException($message);
                            }
                        }

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
