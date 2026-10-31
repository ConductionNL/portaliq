<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalFieldProjector;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One page of a scoped collection for a machine caller (revoke-all): the
 * scope value is a filter, every row is re-verified against it, and the page
 * reports how many rows OpenRegister returned so a caller can page on.
 *
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
 */
class PortalObjectReaderScopedPageTest extends TestCase {
	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	public function testWithoutOpenRegisterThereIsNoPage(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$this->assertNull($this->reader($container)->readScopedPage('r', 's', 'organisation', 'org-1', 'org-1', [], 10, 0));
	}//end testWithoutOpenRegisterThereIsNoPage()

	public function testThePageIsFilteredByScopeAndDeclaredFilterAndForeignRowsAreDropped(): void {
		$service = $this->service(
			[
				['organisation' => 'org-1', 'jti' => 'mine'],
				['organisation' => 'org-2', 'jti' => 'foreign'],
			]
		);

		$page = $this->reader($this->container($service))->readScopedPage('portaliq', 'portalSession', 'organisation', 'org-1', 'org-1', ['revoked' => false, '' => 'dropped'], 25, 50);

		$this->assertNotNull($page);
		$this->assertSame(2, $page['read']);
		$this->assertCount(1, $page['rows']);
		$this->assertSame('mine', $page['rows'][0]['jti']);
		$this->assertSame('portaliq', $service->register);
		$this->assertSame('portalSession', $service->schema);
		$this->assertSame(['revoked' => false, 'organisation' => 'org-1'], $service->received['filters']);
		$this->assertSame(25, $service->received['limit']);
		$this->assertSame(50, $service->received['offset']);
		$this->assertFalse($service->rbac);
		$this->assertFalse($service->multitenancy);
	}//end testThePageIsFilteredByScopeAndDeclaredFilterAndForeignRowsAreDropped()

	public function testAFailingReadIsNullNotAnEmptyPage(): void {
		$service = $this->service([]);
		$service->throw = true;

		$this->assertNull($this->reader($this->container($service))->readScopedPage('r', 's', 'organisation', 'org-1', 'org-1', [], 10, 0));
	}//end testAFailingReadIsNullNotAnEmptyPage()

	public function testANonListAnswerIsNull(): void {
		$service = $this->service([]);
		$service->answer = 'nope';

		$this->assertNull($this->reader($this->container($service))->readScopedPage('r', 's', 'organisation', 'org-1', 'org-1', [], 10, 0));
	}//end testANonListAnswerIsNull()

	private function reader(ContainerInterface $container): PortalObjectReader {
		return new PortalObjectReader($container, $this->createMock(LoggerInterface::class), new PortalFieldProjector($this->createMock(LoggerInterface::class)));
	}//end reader()

	/**
	 * @param array<int, array<string, mixed>> $rows The rows OpenRegister hands back.
	 *
	 * @return object
	 */
	private function service(array $rows): object {
		return new class($rows) {
			public array $received = [];

			public string $register = '';

			public string $schema = '';

			public bool $rbac = true;

			public bool $multitenancy = true;

			public bool $throw = false;

			public mixed $answer = null;

			public function __construct(private array $rows) {
			}

			public function setRegister(string $register): self {
				$this->register = $register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): mixed {
				if ($this->throw === true) {
					throw new RuntimeException('boom');
				}

				$this->received     = $config;
				$this->rbac         = $_rbac;
				$this->multitenancy = $_multitenancy;
				return $this->answer ?? $this->rows;
			}
		};
	}//end service()

	private function container(object $service): ContainerInterface {
		$mock = $this->createMock(ContainerInterface::class);
		$mock->method('get')->willReturnCallback(
			function (string $id) use ($service) {
				if ($id === self::OS) {
					return $service;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);
		return $mock;
	}//end container()
}//end class
