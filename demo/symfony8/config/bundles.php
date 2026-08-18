<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\HotReloadBundle\NowoHotReloadBundle;
use Nowo\TwigInspectorBundle\NowoTwigInspectorBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\UptimeMonitorBundle\UptimeMonitorBundle;
use Symfony\Bundle\DebugBundle\DebugBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MercureBundle\MercureBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

return [
    FrameworkBundle::class         => ['all' => true],
    DoctrineBundle::class          => ['all' => true],
    TwigBundle::class              => ['all' => true],
    DebugBundle::class             => ['dev' => true],
    WebProfilerBundle::class       => ['dev' => true],
    UptimeMonitorBundle::class     => ['all' => true],
    NowoHotReloadBundle::class     => ['dev' => true, 'test' => true],
    NowoTwigInspectorBundle::class => ['dev' => true, 'test' => true],
    SecurityBundle::class          => ['all' => true],
    MercureBundle::class           => ['all' => true],
    NowoUiKitBundle::class         => ['all' => true],
    NowoFormKitBundle::class       => ['all' => true],
    TwigExtraBundle::class         => ['all' => true],
];
