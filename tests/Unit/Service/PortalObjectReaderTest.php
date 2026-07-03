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

            /**
             * @param array<string,mixed> $config
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $config): array
            {
                $this->received = $config;
                // OR mistakenly returns a foreign row too — the reader must drop it.
                return [
                    ['subjectRef' => 's1', 'title' => 'Mine'],
                    ['subjectRef' => 's2', 'title' => 'Not mine'],
                ];
            }
        };

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1');

        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['title']);
        $this->assertSame('portaliq', $objectService->received['filters']['register']);
        $this->assertSame('exampleDocument', $objectService->received['filters']['schema']);
        $this->assertSame('s1', $objectService->received['filters']['subjectRef']);
        $this->assertSame('org-1', $objectService->received['filters']['organisation']);

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
