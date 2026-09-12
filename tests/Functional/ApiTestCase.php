<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Idea;
use App\Entity\Project;
use App\Entity\ProjectMembership;
use App\Entity\User;
use App\Enum\ProjectRole;
use App\Enum\ProjectStatus;
use App\EventSubscriber\ApiCsrfSubscriber;
use App\Tests\DatabaseTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Fixtures and request helpers shared by the API tests. */
abstract class ApiTestCase extends DatabaseTestCase
{
    protected function user(string $name, string $email): User
    {
        $user = new User();
        $user->setDisplayName($name);
        $user->setEmail($email);
        $user->setPassword(
            static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password'),
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    protected function project(string $name, string $slug, ProjectStatus $status = ProjectStatus::Open): Project
    {
        $project = new Project();
        $project->setName($name)->setSlug($slug)->setSummary('A summary for '.$name)->setStatus($status);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    protected function assignRole(Project $project, User $user, ProjectRole $role): ProjectMembership
    {
        $membership = new ProjectMembership($project, $user, $role);
        $project->addMembership($membership);

        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        return $membership;
    }

    protected function idea(Project $project, User $author, string $title = 'An idea'): Idea
    {
        $idea = new Idea();
        $idea->setProject($project)->setAuthor($author)->setTitle($title)->setBody('Some detail.');

        $this->entityManager->persist($idea);
        $this->entityManager->flush();

        return $idea;
    }

    protected function signIn(User $user): void
    {
        $this->client->loginUser($user);
    }

    /**
     * Sends a JSON request with the CSRF header the API requires on unsafe methods.
     *
     * @param array<string, mixed> $payload
     */
    protected function json(string $method, string $uri, array $payload = []): array
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.str_replace('-', '_', strtoupper(ApiCsrfSubscriber::HEADER)) => $this->csrfToken(),
        ], content: json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /**
     * @param array<string, mixed>                                             $fields
     * @param array<string, \Symfony\Component\HttpFoundation\File\UploadedFile|list<\Symfony\Component\HttpFoundation\File\UploadedFile>> $files
     */
    protected function multipart(string $uri, array $fields = [], array $files = []): array
    {
        $this->client->request('POST', $uri, $fields, $files, [
            'HTTP_'.str_replace('-', '_', strtoupper(ApiCsrfSubscriber::HEADER)) => $this->csrfToken(),
        ]);

        return $this->decode();
    }

    /** @return array<string, mixed> */
    protected function get(string $uri): array
    {
        $this->client->request('GET', $uri);

        return $this->decode();
    }

    protected function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> */
    protected function decode(): array
    {
        $content = $this->client->getResponse()->getContent();

        return \is_string($content) && '' !== $content ? (array) json_decode($content, true) : [];
    }

    /**
     * Fetches the API CSRF token the way the frontend does — from /api/session, over the
     * same session cookie — rather than reaching into the token manager, which has no
     * session to read outside a request.
     */
    protected function csrfToken(): string
    {
        $this->client->request('GET', '/api/session');
        $data = $this->decode();

        return \is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';
    }
}
