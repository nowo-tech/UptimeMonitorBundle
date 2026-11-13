<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Worker;

use Closure;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Test command whose body is a closure (in-process `Application::run()` tests, audit NX).
 */
final class CallbackCommand extends Command
{
    /**
     * @param Closure(): int $body
     */
    public function __construct(string $name, private readonly Closure $body)
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return ($this->body)();
    }
}
