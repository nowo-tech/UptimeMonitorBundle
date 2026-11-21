<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\MonitorChange;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Share\Entity\MonitorShare;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function get_object_vars;
use function ksort;
use function sys_get_temp_dir;

use const CASE_LOWER;
use const PHP_VERSION_ID;

/**
 * 1.27.0: the shipped XML mapping (src/Resources/config/doctrine/*.orm.xml) describes exactly the tables, columns,
 * indexes, constraints, associations and PHP types of the entity attributes, so switching
 * `doctrine.mapping_type` (or a host mapping the bundle itself from that directory) moves no column and drops nothing.
 */
#[CoversNothing]
final class XmlMappingParityTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function entities(): iterable
    {
        foreach ([Tenant::class, Monitor::class, CheckResult::class, CheckAggregate::class, Incident::class, Tag::class, MonitorChange::class] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('entities')]
    public function testTheXmlMetadataMatchesTheAttributes(string $class): void
    {
        $attribute = $this->manager(new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']))->getClassMetadata($class);
        $xml       = $this->manager($this->xmlDriver())->getClassMetadata($class);

        self::assertSame($attribute->getTableName(), $xml->getTableName());
        self::assertSame($attribute->getIdentifier(), $xml->getIdentifier());
        self::assertSame($attribute->generatorType, $xml->generatorType);
        self::assertEquals($this->sorted($attribute->table['indexes'] ?? []), $this->sorted($xml->table['indexes'] ?? []));
        self::assertEquals($this->sorted($attribute->table['uniqueConstraints'] ?? []), $this->sorted($xml->table['uniqueConstraints'] ?? []));
        self::assertEquals($this->fields($attribute), $this->fields($xml));
        self::assertEquals($this->associations($attribute), $this->associations($xml));
    }

    public function testTheXmlSchemaHasNoDifference(): void
    {
        $attribute = $this->manager(new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']));
        $xml       = $this->manager($this->xmlDriver());
        $from      = (new SchemaTool($attribute))->getSchemaFromMetadata($attribute->getMetadataFactory()->getAllMetadata());
        $to        = (new SchemaTool($xml))->getSchemaFromMetadata($xml->getMetadataFactory()->getAllMetadata());

        self::assertCount(7, $xml->getMetadataFactory()->getAllMetadata());
        self::assertTrue($attribute->getConnection()->createSchemaManager()->createComparator()->compareSchemas($from, $to)->isEmpty());
    }

    public function testTheShareXmlMatchesTheAttributes(): void
    {
        $root      = dirname(__DIR__, 3) . '/src';
        $attribute = $this->manager(new AttributeDriver([$root . '/Entity', $root . '/Share/Entity']));
        $xml       = $this->manager(new SimplifiedXmlDriver([
            $root . '/Resources/config/doctrine'       => 'Nowo\\UptimeMonitorBundle\\Entity',
            $root . '/Resources/config/doctrine-share' => 'Nowo\\UptimeMonitorBundle\\Share\\Entity',
        ]));
        $class = MonitorShare::class;

        $a = $attribute->getClassMetadata($class);
        $x = $xml->getClassMetadata($class);
        self::assertSame($a->getTableName(), $x->getTableName());
        self::assertEquals($this->sorted($a->table['indexes'] ?? []), $this->sorted($x->table['indexes'] ?? []));
        self::assertEquals($this->sorted($a->table['uniqueConstraints'] ?? []), $this->sorted($x->table['uniqueConstraints'] ?? []));
        self::assertEquals($this->fields($a), $this->fields($x));
        self::assertEquals($this->associations($a), $this->associations($x));

        $from = (new SchemaTool($attribute))->getSchemaFromMetadata($attribute->getMetadataFactory()->getAllMetadata());
        $to   = (new SchemaTool($xml))->getSchemaFromMetadata($xml->getMetadataFactory()->getAllMetadata());
        self::assertCount(8, $xml->getMetadataFactory()->getAllMetadata());
        self::assertTrue($attribute->getConnection()->createSchemaManager()->createComparator()->compareSchemas($from, $to)->isEmpty());
    }

    private function xmlDriver(): MappingDriver
    {
        return new SimplifiedXmlDriver([dirname(__DIR__, 3) . '/src/Resources/config/doctrine' => 'Nowo\\UptimeMonitorBundle\\Entity']);
    }

    private function manager(MappingDriver $driver): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl($driver);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->setProxyDir(sys_get_temp_dir() . '/nowo-uptime-monitor-test-proxies');
        $config->setProxyNamespace('NowoUptimeMonitorTestProxies');
        $config->enableNativeLazyObjects(PHP_VERSION_ID >= 80400);

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, array<string, mixed>>
     */
    private function fields(ClassMetadata $metadata): array
    {
        $fields = [];
        foreach ($metadata->fieldMappings as $name => $mapping) {
            $fields[$name] = get_object_vars($mapping);
        }
        ksort($fields);

        return $fields;
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, array<string, mixed>>
     */
    private function associations(ClassMetadata $metadata): array
    {
        $associations = [];
        foreach ($metadata->associationMappings as $name => $mapping) {
            $vars                = get_object_vars($mapping);
            $vars['joinColumns'] = array_map(get_object_vars(...), $vars['joinColumns'] ?? []);
            $associations[$name] = $vars;
        }
        ksort($associations);

        return $associations;
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     *
     * @return array<string, array<string, mixed>>
     */
    private function sorted(array $definitions): array
    {
        ksort($definitions);

        return $definitions;
    }
}
