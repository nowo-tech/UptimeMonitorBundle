<?php

declare(strict_types=1);
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\TwigInspectorBundle\NowoTwigInspectorBundle;
use Nowo\UptimeMonitorBundle\UptimeMonitorBundle;
use Symfony\Bundle\DebugBundle\DebugBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MercureBundle\MercureBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;

return [
    FrameworkBundle::class         => ['all' => true],
    DoctrineBundle::class          => ['all' => true],
    TwigBundle::class              => ['all' => true],
    DebugBundle::class             => ['dev' => true],
    WebProfilerBundle::class       => ['dev' => true],
    UptimeMonitorBundle::class     => ['all' => true],
    NowoTwigInspectorBundle::class => ['dev' => true, 'test' => true],
    SecurityBundle::class          => ['all' => true],
    MercureBundle::class           => ['all' => true],
];
