<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Support;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

trait ControllerContainerTrait
{
    protected function bindController(AbstractController $controller, bool $csrfValid = true, ?Request $request = null): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route): string => '/generated/' . $route,
        );

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('rendered');

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);

        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();

        $container = new Container();
        $container->set('router', $router);
        $container->set('twig', $twig);
        $container->set('form.factory', $formFactory);
        $container->set('security.csrf.token_manager', $csrf);
        $requestStack = new RequestStack();
        if ($request instanceof Request) {
            $session = new Session(new MockArraySessionStorage());
            $request->setSession($session);
            $requestStack->push($request);
        }
        $container->set('request_stack', $requestStack);

        $controller->setContainer($container);
    }
}
