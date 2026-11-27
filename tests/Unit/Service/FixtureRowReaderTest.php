<?php

/**
 * FixtureRowReader: every row of a fixture source, read page by page, or
 * null when the rows cannot be read.
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

use OCA\Portaliq\Service\FixtureRowReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The fixture row reader.
 */
class FixtureRowReaderTest extends TestCase {
	/**
	 * An object service that answers with the given batches, or throws.
	 *
	 * @param array<int, mixed> $batches The batches to answer, in order.
	 * @param bool $fails Whether every read throws.
	 *
	 * @return object
	 */
	private function objectService(array $batches, bool $fails=false): object {
		return new class($batches, $fails) {
			/**
			 * The register and schema of every read.
			 *
			 * @var array<int, string>
			 */
			public array $reads = [];

			/**
			 * The register set last.
			 *
			 * @var string
			 */
			private string $register = '';

			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Hold the answers.
			 *
			 * @param array<int, mixed> $batches The batches.
			 * @param bool $fails Whether reads throw.
			 */
			public function __construct(private array $batches, private bool $fails) {
			}//end __construct()

			/**
			 * Set the register.
			 *
			 * @param string $register The register.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
				$this->register = $register;
			}//end setRegister()

			/**
			 * Set the schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
				$this->schema = $schema;
			}//end setSchema()

			/**
			 * Answer the next batch.
			 *
			 * @param array<string, mixed> $config The read config.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 *
			 * @return mixed
			 */
			public function findAll(array $config, bool $_rbac, bool $_multitenancy): mixed {
				if ($this->fails === true) {
					throw new RuntimeException('register offline');
				}

				$this->reads[] = $this->register.'/'.$this->schema.'@'.$config['offset'];
				return array_shift($this->batches);
			}//end findAll()
		};
	}//end objectService()

	/**
	 * The rows of the source's register and schema are returned.
	 *
	 * @return void
	 */
	public function testTheSourceRowsAreRead(): void {
		$service = $this->objectService(batches: [[['id' => 'g1'], ['id' => 'g2']]]);
		$reader  = new FixtureRowReader(objectService: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$rows    = $reader->everyRow(source: ['register' => 'school', 'schema' => 'group'], failure: 'groups unreadable');
		$this->assertSame(expected: [['id' => 'g1'], ['id' => 'g2']], actual: $rows);
		$this->assertSame(expected: ['school/group@0'], actual: $service->reads);
	}//end testTheSourceRowsAreRead()

	/**
	 * A full page is followed by the next page.
	 *
	 * @return void
	 */
	public function testAFullPageReadsTheNext(): void {
		$full    = array_map(static fn (int $n): array => ['id' => 'r'.$n], range(1, 500));
		$service = $this->objectService(batches: [$full, [['id' => 'last']]]);
		$reader  = new FixtureRowReader(objectService: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$rows    = $reader->everyRow(source: ['register' => 'school', 'schema' => 'group'], failure: 'x');
		$this->assertCount(expectedCount: 501, haystack: $rows);
		$this->assertSame(expected: ['school/group@0', 'school/group@500'], actual: $service->reads);
	}//end testAFullPageReadsTheNext()

	/**
	 * Without OpenRegister, or when a page is not a list, there are no rows to trust.
	 *
	 * @return void
	 */
	public function testNoServiceOrABadAnswerIsNull(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$absent = new FixtureRowReader(objectService: null, logger: $logger);
		$this->assertNull(actual: $absent->everyRow(source: ['register' => 'a', 'schema' => 'b'], failure: 'x'));
		$reader = new FixtureRowReader(objectService: $this->objectService(batches: ['not rows']), logger: $logger);
		$this->assertNull(actual: $reader->everyRow(source: ['register' => 'a', 'schema' => 'b'], failure: 'x'));
	}//end testNoServiceOrABadAnswerIsNull()

	/**
	 * A failing read is logged as a warning with its reason, and yields null.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLogged(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('groups unreadable', ['reason' => 'register offline']);
		$reader = new FixtureRowReader(objectService: $this->objectService(batches: [], fails: true), logger: $logger);
		$this->assertNull(actual: $reader->everyRow(source: ['register' => 'school', 'schema' => 'group'], failure: 'groups unreadable'));
	}//end testAFailingReadIsLogged()
}//end class
