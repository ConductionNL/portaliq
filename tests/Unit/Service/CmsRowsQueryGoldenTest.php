<?php

/**
 * CmsRows::query(): what is asked of the register context and the object
 * service, and what is logged, in order.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CmsRows;
use OCA\Portaliq\Service\PortalRegisterContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The CMS read, call by call.
 */
class CmsRowsQueryGoldenTest extends TestCase {
	/**
	 * What the doubles were asked, in order.
	 *
	 * @var array<int, string>
	 */
	public array $calls = [];

	/**
	 * Rows over doubles that record each call.
	 *
	 * @param array<string, mixed> $world applies (bool), findFails (bool), rows.
	 *
	 * @return CmsRows
	 */
	private function rows(array $world): CmsRows {
		$this->calls = [];
		$test        = $this;
		$service     = new class($world, $test) {
			/**
			 * @param array                  $world The world.
			 * @param CmsRowsQueryGoldenTest $test  The test.
			 */
			public function __construct(private array $world, private CmsRowsQueryGoldenTest $test) {
			}

			public function findAll(array $config, bool $_rbac, bool $_multitenancy): mixed {
				$this->test->calls[] = 'findAll ' . json_encode($config) . ' rbac=' . var_export($_rbac, true) . ' mt=' . var_export($_multitenancy, true);
				if (($this->world['findFails'] ?? false) === true) {
					throw new RuntimeException('down');
				}

				return ($this->world['rows'] ?? []);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($service) {
			$this->calls[] = "get $id";
			return $service;
		});
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturnCallback(function (object $objectService, string $schemaSlug) use ($world, $service): bool {
			$this->calls[] = 'apply ' . $schemaSlug . ' ' . var_export($objectService === $service, true);
			return ($world['applies'] ?? true);
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context) {
			$this->calls[] = "error $message " . json_encode($context);
		});

		return new CmsRows($container, $logger, $context);
	}//end rows()

	/**
	 * A scoped read, a refused context, a failed read and an unscoped query.
	 *
	 * @return void
	 */
	public function testEveryReadIsAsRecorded(): void {
		$this->assertSame([['id' => 'a']], $this->rows(['rows' => [['id' => 'a']]])->query(schema: 'page', filters: ['portal' => 'zuid', 'slug' => 'x']));
		$this->assertSame(
			[
				'get OCA\OpenRegister\Service\ObjectService',
				'apply page true',
				'findAll {"filters":{"portal":"zuid","slug":"x"},"limit":500,"offset":0} rbac=false mt=false',
			],
			$this->calls
		);

		$this->assertSame([], $this->rows(['applies' => false])->query(schema: 'page', filters: ['organisation' => 'o']));
		$this->assertSame(['get OCA\OpenRegister\Service\ObjectService', 'apply page true'], $this->calls);

		$this->assertSame([], $this->rows(['findFails' => true])->query(schema: 'faq', filters: ['portal' => 'zuid']));
		$this->assertSame(
			[
				'get OCA\OpenRegister\Service\ObjectService',
				'apply faq true',
				'findAll {"filters":{"portal":"zuid"},"limit":500,"offset":0} rbac=false mt=false',
				'error Portaliq: CMS read failed {"schema":"faq","reason":"down"}',
			],
			$this->calls
		);

		$this->assertSame([], $this->rows([])->query(schema: 'page', filters: ['portal' => '', 'organisation' => '']));
		$this->assertSame(['error Portaliq: refusing an unscoped CMS query {"schema":"page"}'], $this->calls);
	}//end testEveryReadIsAsRecorded()
}//end class
