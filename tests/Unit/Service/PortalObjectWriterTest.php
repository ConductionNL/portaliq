<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the OR writer: it degrades to null without OpenRegister, and — the
 * security-critical part — it STAMPS the subject ref + tenant server-side,
 * overriding any client-supplied value, and writes with OR RBAC bypassed.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T06
 */
class PortalObjectWriterTest extends TestCase
{

    private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

    public function testReturnsNullWhenOpenRegisterUnavailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('OR not installed'));

        $writer = new PortalObjectWriter($container, $this->createMock(LoggerInterface::class));
        $this->assertNull($writer->createObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1', ['title' => 'X']));

    }//end testReturnsNullWhenOpenRegisterUnavailable()

    public function testStampsOwnershipOverClientInputAndBypassesRbac(): void
    {
        $objectService = new class {
            /** @var array<string,mixed> */
            public array $saved = [];

            public mixed $register = null;

            public mixed $schema = null;

            public bool $rbac = true;

            public bool $multitenancy = true;

            /**
             * @param array<string,mixed> $object
             *
             * @return array<string,mixed>
             */
            public function saveObject(
                array $object,
                ?array $extend=null,
                mixed $register=null,
                mixed $schema=null,
                ?string $uuid=null,
                bool $_rbac=true,
                bool $_multitenancy=true
            ): array {
                $this->saved        = $object;
                $this->register     = $register;
                $this->schema       = $schema;
                $this->rbac         = $_rbac;
                $this->multitenancy = $_multitenancy;
                return array_merge($object, ['id' => 'new-id']);
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($objectService) {
                if ($id === self::OS) {
                    return $objectService;
                }

                throw new RuntimeException('no service: '.$id);
            }
        );

        $writer = new PortalObjectWriter($container, $this->createMock(LoggerInterface::class));
        // The client tries to smuggle a foreign subjectRef — it must be overwritten.
        $result = $writer->createObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1', ['title' => 'X', 'subjectRef' => 'HACKER']);

        $this->assertSame('s1', $objectService->saved['subjectRef']);
        $this->assertSame('org-1', $objectService->saved['organisation']);
        $this->assertSame('X', $objectService->saved['title']);
        $this->assertSame('portaliq', $objectService->register);
        $this->assertSame('exampleDocument', $objectService->schema);
        $this->assertFalse($objectService->rbac);
        $this->assertFalse($objectService->multitenancy);
        $this->assertSame('new-id', $result['id']);

    }//end testStampsOwnershipOverClientInputAndBypassesRbac()

}//end class
