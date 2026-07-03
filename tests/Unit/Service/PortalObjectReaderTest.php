<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the OR-backed reader: it degrades to empty without OpenRegister, filters
 * on the scope field, and re-verifies every row so a foreign-subject object can
 * never leak.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T05
 */
class PortalObjectReaderTest extends TestCase
{

    private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

    public function testReturnsEmptyWhenOpenRegisterUnavailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('OR not installed'));

        $reader = new PortalObjectReader($container, $this->createMock(LoggerInterface::class));
        $this->assertSame([], $reader->readCollection('portaliq', 'exampleDocument', 'subjectRef', 's1'));

    }//end testReturnsEmptyWhenOpenRegisterUnavailable()

    public function testFiltersOnScopeAndDropsForeignRows(): void
    {
        $objectService = new class {
            /** @var array<string,mixed> */
            public array $received = [];

            public string $register = '';

            public string $schema = '';

            public function setRegister(string $register): self
            {
                $this->register = $register;
                return $this;
            }

            public function setSchema(string $schema): self
            {
                $this->schema = $schema;
                return $this;
            }

            public bool $rbac = true;

            public bool $multitenancy = true;

            /**
             * @param array<string,mixed> $config
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $config, bool $_rbac=true, bool $_multitenancy=true): array
            {
                $this->received     = $config;
                $this->rbac         = $_rbac;
                $this->multitenancy = $_multitenancy;
                // OR mistakenly returns a foreign row too — the reader must drop it.
                return [
                    ['subjectRef' => 's1', 'organisation' => 'org-1', 'title' => 'Mine'],
                    ['subjectRef' => 's2', 'organisation' => 'org-1', 'title' => 'Not mine'],
                ];
            }
        };

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1');

        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['title']);
        // register/schema are set via the setters, NOT leaked into filters.
        $this->assertSame('portaliq', $objectService->register);
        $this->assertSame('exampleDocument', $objectService->schema);
        $this->assertArrayNotHasKey('register', $objectService->received['filters']);
        // organisation is a multitenancy field, NOT an OR filter (it is only a
        // per-row check), so it must not appear in the query filters.
        $this->assertArrayNotHasKey('organisation', $objectService->received['filters']);
        $this->assertSame('s1', $objectService->received['filters']['subjectRef']);
        // Portal reads bypass OR's NC-user RBAC/multitenancy — Portaliq scopes.
        $this->assertFalse($objectService->rbac);
        $this->assertFalse($objectService->multitenancy);

    }//end testFiltersOnScopeAndDropsForeignRows()

    private function container(object $objectService): ContainerInterface
    {
        $mock = $this->createMock(ContainerInterface::class);
        $mock->method('get')->willReturnCallback(
            function (string $id) use ($objectService) {
                if ($id === self::OS) {
                    return $objectService;
                }

                throw new RuntimeException('no service: '.$id);
            }
        );
        return $mock;

    }//end container()

}//end class
