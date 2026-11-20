<?php

declare(strict_types=1);

/*
 * Run by ConfigurationThreadStackTest in a PHP process whose stack is capped like a FrankenPHP thread on Alpine
 * (-d zend.max_allowed_stack_size=512K): it autoloads, and so compiles, Configuration the way a request does (the UI
 * reads its constants), builds and processes the tree, dumps the reference and prints the processed defaults (var_export) and the references.
 */

use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use Nowo\UptimeMonitorBundle\Ui\MonitorShowLayout;
use Symfony\Component\Config\Definition\Dumper\XmlReferenceDumper;
use Symfony\Component\Config\Definition\Dumper\YamlReferenceDumper;
use Symfony\Component\Config\Definition\Processor;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$configuration = new Configuration();
$config        = (new Processor())->processConfiguration($configuration, [[]]);

echo json_encode([
    'defaults' => var_export($config, true),
    'layout'   => var_export(MonitorShowLayout::resolve($config['web_ui']['monitor_show']), true),
    'yaml'     => (new YamlReferenceDumper())->dump($configuration),
    'xml'      => (new XmlReferenceDumper())->dump($configuration),
], \JSON_THROW_ON_ERROR);
