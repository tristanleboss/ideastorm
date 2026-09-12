<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\SocialIdentity;
use App\Repository\UserRepository;
use App\Security\SocialAccountLinker;
use App\Security\SocialProfile;
use App\Tests\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Registration, sign-in, CSRF and the social-account linking rules. */
class SecurityTest extends DatabaseTestCase
{
    public function testTheAppShellRendersForVisitors(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('#app'), 'Vue has a mount point');
        self::assertCount(1, $crawler->filter('#ideastorm-bootstrap'), 'the boot payload is inlined');
        self::assertCount(1, $crawler->filter('meta[name="csrf-token"]'));
        self::assertStringContainsString('Sign in', $crawler->filter('.site-nav')->text());
    }

    public function testProjectAndIdeaUrlsRenderTheSameShellSoLinksCanBePasted(): void
    {
        foreach (['/projects/sea-breeze', '/projects/sea-breeze/ideas/12'] as $path) {
            $crawler = $this->client->request('GET', $path);

            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $path);
            self::assertCount(1, $crawler->filter('#app'), $path);
        }
    }

    public function testSomebodyCanRegisterAndIsSignedInImmediately(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Create account', [
            'displayName' => 'Ada Okonkwo',
            'email' => 'ada@example.com',
            'password' => 'a-long-enough-password',
        ]);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();

        self::assertStringContainsString('Ada Okonkwo', (string) $this->client->getResponse()->getContent());
        self::assertNotNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('ada@example.com'));
    }

    public function testRegistrationRejectsAShortPasswordAndKeepsWhatWasTyped(): void
    {
        $this->client->request('GET', '/register');
        $crawler = $this->client->submitForm('Create account', [
            'displayName' => 'Ada Okonkwo',
            'email' => 'ada@example.com',
            'password' => 'short',
        ]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('at least 8 characters', $crawler->filter('.field__error')->text());
        self::assertSame('ada@example.com', $crawler->filter('#email')->attr('value'));
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('ada@example.com'));
    }

    public function testRegistrationRefusesAnEmailThatIsAlreadyTaken(): void
    {
        $this->createUser('ada@example.com');

        $this->client->request('GET', '/register');
        $crawler = $this->client->submitForm('Create account', [
            'displayName' => 'Someone Else',
            'email' => 'ada@example.com',
            'password' => 'a-long-enough-password',
        ]);

        self::assertStringContainsString('already exists', $crawler->filter('.field__error')->text());
    }

    public function testSigningInWithEmailAndPassword(): void
    {
        $this->createUser('ada@example.com');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'ada@example.com', 'password' => 'password']);

        self::assertResponseRedirects('/');
        self::assertStringContainsString('Ada', (string) $this->client->followRedirect()->filter('.site-nav')->text());
    }

    public function testTheLoginPageOnlyOffersSocialProvidersThatAreConfigured(): void
    {
        // No OAUTH_* credentials are set in the test environment.
        $crawler = $this->client->request('GET', '/login');

        self::assertCount(0, $crawler->filter('.btn--social'));
        self::assertStringNotContainsString('or continue with', $crawler->text());
    }

    public function testStartingASocialFlowForAnUnconfiguredProviderExplainsItself(): void
    {
        $this->client->request('GET', '/connect/google');

        self::assertResponseRedirects('/login');
        self::assertStringContainsString(
            'not configured on this site yet',
            (string) $this->client->followRedirect()->filter('.flash')->text(),
        );
    }

    public function testAnUnknownSocialProviderIs404(): void
    {
        $this->client->request('GET', '/connect/myspace');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testAWriteRequestWithoutTheCsrfHeaderIsRefused(): void
    {
        $user = $this->createUser('ada@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/api/projects', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'name' => 'Forged from another site',
            'summary' => 'Should never be created.',
        ]));

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('invalid_csrf_token', (string) $this->client->getResponse()->getContent());
    }

    public function testReadRequestsNeedNoCsrfHeader(): void
    {
        $this->client->request('GET', '/api/projects');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testSigningInWithAProviderCreatesAnAccount(): void
    {
        $linker = static::getContainer()->get(SocialAccountLinker::class);

        $user = $linker->resolve(new SocialProfile('google', '10001', 'ada@example.com', 'Ada Okonkwo', 'https://example.com/ada.png'));

        self::assertSame('ada@example.com', $user->getEmail());
        self::assertSame('Ada Okonkwo', $user->getDisplayName());
        self::assertSame('https://example.com/ada.png', $user->getAvatarUrl());
        self::assertNull($user->getPassword(), 'social accounts never get a password');
        self::assertSame(['google'], array_map(
            static fn (SocialIdentity $identity): string => $identity->getProvider(),
            $user->getSocialIdentities()->toArray(),
        ));
    }

    public function testSigningInAgainWithTheSameProviderReusesTheAccount(): void
    {
        $linker = static::getContainer()->get(SocialAccountLinker::class);

        $first = $linker->resolve(new SocialProfile('google', '10001', 'ada@example.com', 'Ada Okonkwo'));
        $second = $linker->resolve(new SocialProfile('google', '10001', 'ada@example.com', 'Ada Okonkwo'));

        self::assertSame($first->getId(), $second->getId());
    }

    public function testADifferentProviderWithTheSameEmailLandsOnTheSameAccount(): void
    {
        $existing = $this->createUser('ada@example.com');
        $linker = static::getContainer()->get(SocialAccountLinker::class);

        $user = $linker->resolve(new SocialProfile('github', '55', 'ADA@example.com', 'ada-o'));

        self::assertSame($existing->getId(), $user->getId(), 'no duplicate account is created');
        self::assertSame('Ada', $user->getDisplayName(), 'the existing display name is kept');
        self::assertCount(1, $user->getSocialIdentities());
    }

    public function testAProviderThatSharesNoEmailStillGetsAnAccount(): void
    {
        $linker = static::getContainer()->get(SocialAccountLinker::class);

        $user = $linker->resolve(new SocialProfile('github', '777', null, 'anonymous-coder'));

        self::assertSame('github-777@users.noreply.ideastorm.local', $user->getEmail());
        self::assertSame('anonymous-coder', $user->getDisplayName());
    }

    private function createUser(string $email): \App\Entity\User
    {
        $user = new \App\Entity\User();
        $user->setDisplayName('Ada');
        $user->setEmail($email);
        $user->setPassword(
            static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password'),
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
