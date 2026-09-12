<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\SocialLoginRegistry;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Signing in, signing up and signing out.
 *
 * Two ways in: an email and a password, or one of the social providers configured in
 * config/packages/knpu_oauth2_client.yaml. The callback half of the social flow is handled
 * by App\Security\SocialAuthenticator — the `connect_check` route below only exists so the
 * firewall has something to match.
 */
class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, SocialLoginRegistry $socialLogins): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/login.html.twig', [
            'lastEmail' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'socialProviders' => $socialLogins->enabledProviders(),
        ]);
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        UserAuthenticatorInterface $authenticator,
        // The firewall's own form-login authenticator, referenced by service id: the class
        // itself is registered per firewall rather than under its own name.
        #[Autowire(service: 'security.authenticator.form_login.main')] AuthenticatorInterface $formLoginAuthenticator,
        SocialLoginRegistry $socialLogins,
    ): Response {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_home');
        }

        $submitted = [
            'displayName' => trim((string) $request->request->get('displayName', '')),
            'email' => trim((string) $request->request->get('email', '')),
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');

            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_csrf_token'))) {
                $errors['_'][] = 'Your form session expired. Please try again.';
            }

            $user = new User();
            $user->setDisplayName($submitted['displayName']);
            $user->setEmail($submitted['email']);

            foreach ($validator->validate($user) as $violation) {
                $errors[$violation->getPropertyPath()][] = $violation->getMessage();
            }

            foreach ($validator->validate($password, [
                new Assert\NotBlank(message: 'Please choose a password.'),
                new Assert\Length(min: 8, minMessage: 'Use at least {{ limit }} characters.', max: 4096),
            ]) as $violation) {
                $errors['password'][] = $violation->getMessage();
            }

            if ([] === $errors) {
                if (null !== $users->findOneByEmail($submitted['email'])) {
                    $errors['email'][] = 'An account already exists with this email address.';
                } else {
                    $user->setPassword($passwordHasher->hashPassword($user, $password));
                    $entityManager->persist($user);
                    $entityManager->flush();

                    // Straight in — asking somebody to log in again right after signing up is
                    // a needless step when the credentials were just verified.
                    return $authenticator->authenticateUser($user, $formLoginAuthenticator, $request)
                        ?? $this->redirectToRoute('app_home');
                }
            }
        }

        return $this->render('security/register.html.twig', [
            'submitted' => $submitted,
            'errors' => $errors,
            'socialProviders' => $socialLogins->enabledProviders(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('This method is intercepted by the logout key on the firewall.');
    }

    /** Starts a social sign-in by redirecting to the provider's consent screen. */
    #[Route('/connect/{service}', name: 'connect_start', requirements: ['service' => '[a-z]+'], methods: ['GET'])]
    public function connectStart(string $service, ClientRegistry $clientRegistry, SocialLoginRegistry $socialLogins): RedirectResponse
    {
        if (!$socialLogins->isKnown($service)) {
            throw $this->createNotFoundException(\sprintf('"%s" is not a provider IdeaStorm knows about.', $service));
        }

        if (!$socialLogins->isEnabled($service)) {
            $this->addFlash('error', \sprintf('Signing in with %s is not configured on this site yet.', $socialLogins->labelFor($service)));

            return $this->redirectToRoute('app_login');
        }

        return $clientRegistry->getClient($service)->redirect($socialLogins->scopesFor($service), []);
    }

    /**
     * The provider's callback. App\Security\SocialAuthenticator intercepts this request, so
     * the body only runs if the firewall let it through — which should never happen.
     */
    #[Route('/connect/{service}/check', name: 'connect_check', requirements: ['service' => '[a-z]+'], methods: ['GET'])]
    public function connectCheck(string $service): RedirectResponse
    {
        $this->addFlash('error', \sprintf('The sign-in with %s could not be completed.', ucfirst($service)));

        return $this->redirectToRoute('app_login');
    }
}
