<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\SocialLoginRegistry;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Completes a social sign-in: exchanges the callback code for an access token, reads the
 * profile behind it and hands the matching local account to the firewall.
 */
class SocialAuthenticator extends OAuth2Authenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly SocialLoginRegistry $providers,
        private readonly SocialProfileFactory $profileFactory,
        private readonly SocialAccountLinker $accountLinker,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if ('connect_check' !== $request->attributes->get('_route')) {
            return false;
        }

        $service = (string) $request->attributes->get('service');

        return $this->providers->isKnown($service) && $this->providers->isEnabled($service);
    }

    public function authenticate(Request $request): Passport
    {
        $service = (string) $request->attributes->get('service');
        $client = $this->clientRegistry->getClient($service);
        $accessToken = $this->fetchAccessToken($client);

        $profile = $this->profileFactory->fromResourceOwner($service, $client->fetchUserFromToken($accessToken), $accessToken);

        return new SelfValidatingPassport(
            new UserBadge($profile->providerUserId, fn (): object => $this->accountLinker->resolve($profile)),
            [new RememberMeBadge()],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $target = $this->getTargetPath($request->getSession(), $firewallName);

        return new RedirectResponse($target ?? $this->urlGenerator->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->getFlashBag()->add(
            'error',
            \sprintf('We could not complete the sign-in with %s. Please try again.', $this->providers->labelFor((string) $request->attributes->get('service'))),
        );

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
