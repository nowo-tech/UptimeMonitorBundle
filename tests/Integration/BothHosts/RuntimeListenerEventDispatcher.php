<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The kernel's dispatcher for tests that add and remove listeners while the kernel runs: Symfony 8.2 compiles the
 * listeners of a plain {@see EventDispatcher} and deprecates `addListener()` on the compiled one, so the container
 * gets this subclass, which the compile pass leaves alone.
 */
class RuntimeListenerEventDispatcher extends EventDispatcher
{
}
