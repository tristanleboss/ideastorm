<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\EventSubscriber\ApiCsrfSubscriber;
use App\Service\ApiPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Serves the single page the Vue app lives in.
 *
 * All three routes render the same shell; the Vue router reads the URL and fetches what it
 * needs over AJAX. They exist as separate routes so that a link to a project or an idea can
 * be pasted, bookmarked and opened directly rather than only reached by clicking.
 */
class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[Route('/projects/{slug}', name: 'app_project', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    #[Route('/projects/{slug}/ideas/{ideaId}', name: 'app_idea', requirements: ['slug' => '[a-z0-9-]+', 'ideaId' => '\d+'], methods: ['GET'])]
    public function index(ApiPresenter $presenter, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $user = $this->getUser();

        return $this->render('app.html.twig', [
            // Handed to Vue as a JSON island so the first paint does not have to wait for
            // a round trip just to know whether somebody is signed in.
            'bootstrap' => [
                'user' => $presenter->viewer($user instanceof User ? $user : null),
                'csrfToken' => $csrfTokenManager->getToken(ApiCsrfSubscriber::TOKEN_ID)->getValue(),
            ],
        ]);
    }
}
