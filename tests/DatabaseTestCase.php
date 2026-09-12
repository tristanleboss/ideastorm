<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Base class for tests that need a database.
 *
 * Each test gets a schema built from the current mappings and torn down afterwards, so the
 * suite never depends on a migration having been run, nor on the order tests happen to run in.
 */
abstract class DatabaseTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        // Rate-limit counters are keyed by user id, and every test starts numbering users at
        // 1 — without this, the tail of the suite would run into limits earned by its head.
        $limiterCache = static::getContainer()->get('cache.rate_limiter');
        if ($limiterCache instanceof CacheItemPoolInterface) {
            $limiterCache->clear();
        }
    }

    protected function tearDown(): void
    {
        $uploadDir = static::getContainer()->getParameter('app.upload_dir');
        if (\is_string($uploadDir) && is_dir($uploadDir)) {
            (new Filesystem())->remove($uploadDir);
        }

        parent::tearDown();

        // Without this the entity manager of a finished test leaks into the next one.
        $this->entityManager->close();
    }
}
