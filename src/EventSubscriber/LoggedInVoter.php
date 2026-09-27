<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\EventSubscriber;

use Qoliber\TridentSymfony\EventSubscriber\CacheabilityVoter;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * A page rendered for a logged-in customer is never shared (the header shows
 * their account), even when Trident did not see the login marker.
 */
final class LoggedInVoter implements CacheabilityVoter
{
    public function __construct(private readonly TokenStorageInterface $tokens)
    {
    }

    public function refuse(Request $request, Response $response): ?string
    {
        return $this->tokens->getToken()?->getUser() instanceof ShopUserInterface ? 'logged-in' : null;
    }
}
