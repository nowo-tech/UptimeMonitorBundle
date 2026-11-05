<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use Nowo\UptimeMonitorBundle\Command\ApplyMonitorsCommand;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function file_get_contents;
use function preg_match_all;

/**
 * Static guard of the apply-monitors command (contract §11, argv): the source declares no argument and no option
 * whose name mentions a token except `token-file`, so a token can never be put on the command line.
 */
#[CoversNothing]
final class ApplyMonitorsCommandDefinitionTest extends TestCase
{
    public function testTheSourceDeclaresNoArgumentAndNoTokenOption(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Command/ApplyMonitorsCommand.php');

        self::assertStringNotContainsString('addArgument', $source);
        self::assertStringNotContainsString('InputArgument', $source);
        preg_match_all("/addOption\\(\\s*'([^']+)'/", $source, $options);
        self::assertSame(['file', 'token-file', 'tenant', 'dry-run', 'prune', 'format'], $options[1]);
        foreach ($options[1] as $option) {
            if ($option !== 'token-file') {
                self::assertStringNotContainsStringIgnoringCase('token', $option);
                self::assertStringNotContainsStringIgnoringCase('secret', $option);
            }
        }
        self::assertSame('nowo:uptime:apply-monitors', (new ReflectionClass(ApplyMonitorsCommand::class))->getAttributes()[0]->getArguments()['name']);
    }
}
