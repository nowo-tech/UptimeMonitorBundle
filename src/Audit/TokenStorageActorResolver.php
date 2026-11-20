<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

use function sprintf;

/**
 * The default actor of a monitor change (since 1.33): the user identifier of the current security token; null without
 * SecurityBundle or a token. Under `switch_user` the original user is recorded too: `admin (as alice)`. Stateless: the
 * token storage is read on every call.
 */
final readonly class TokenStorageActorResolver implements MonitorChangeActorResolverInterface
{
    /**
     * @param TokenStorageInterface|null $tokenStorage Null without SecurityBundle
     */
    public function __construct(
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function actorId(): ?string
    {
        $token = $this->tokenStorage?->getToken();
        if ($token instanceof SwitchUserToken) {
            return sprintf('%s (as %s)', $token->getOriginalToken()->getUserIdentifier(), $token->getUserIdentifier());
        }

        return $token?->getUserIdentifier();
    }
}
