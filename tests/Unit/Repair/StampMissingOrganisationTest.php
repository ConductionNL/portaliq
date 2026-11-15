<?php

/**
 * StampMissingOrganisationTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Repair
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

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\StampMissingOrganisation;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * operate-portals-per-organisation T03: a row of an organisation-scoped schema
 * without an organisation takes the organisation of the portal it names, or
 * the only organisation every portal belongs to; anything else is reported,
 * never guessed, and a portal without an organisation is never stamped.
 *
 * @covers \OCA\Portaliq\Repair\StampMissingOrganisation
 * @uses \OCA\Portaliq\Service\Tenancy\SchemaTenancy
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t03
 */
class StampMissingOrganisationTest extends TestCase {
	/**
	 * A store holding rows per schema, answering for whichever schema was applied last.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $bySchema The stored rows per schema.
	 *
	 * @return object
	 */
	private function store(array $bySchema): object {
		return new class($bySchema) {
			public string $current = '';

			/**
			 * @var array<int, array{schema: string, uuid: string|null, object: array<string, mixed>}>
			 */
			public array $saves = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $bySchema The rows.
			 */
			public function __construct(public array $bySchema) {
			}

			/**
			 * @param array<string, mixed> $config The paging.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return array_slice($this->bySchema[$this->current] ?? [], (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 100));
			}

			/**
			 * @param array<string, mixed> $object The object.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->saves[] = ['schema' => (string)$schema, 'uuid' => $uuid, 'object' => $object];
				foreach ($this->bySchema[(string)$schema] as $i => $row) {
					if (($row['@self']['uuid'] ?? null) === $uuid) {
						$this->bySchema[(string)$schema][$i] = $object + ['@self' => $row['@self']];
					}
				}

				return $object;
			}
		};
	}//end store()

	/**
	 * Run the step over a store and return what it reported.
	 *
	 * @param object|null $store The store, or null for an absent OpenRegister.
	 *
	 * @return string[]
	 */
	private function runStep(?object $store): array {
		$container = $this->createMock(ContainerInterface::class);
		if ($store === null) {
			$container->method('get')->willThrowException(new RuntimeException('absent'));
		} else {
			$container->method('get')->willReturn($store);
		}

		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturnCallback(
			static function (object $service, string $schemaSlug) use ($store): bool {
				$store->current = $schemaSlug;
				return true;
			}
		);

		$lines  = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function ($m) use (&$lines): void {
			$lines[] = (string)$m;
		});
		$output->method('warning')->willReturnCallback(static function ($m) use (&$lines): void {
			$lines[] = (string)$m;
		});

		(new StampMissingOrganisation($container, $context, $this->createMock(LoggerInterface::class)))->run($output);

		return $lines;
	}//end runStep()

	/**
	 * The organisation written per uuid.
	 *
	 * @param object $store The store.
	 *
	 * @return array<string, string>
	 */
	private function written(object $store): array {
		$out = [];
		foreach ($store->saves as $save) {
			$this->assertArrayNotHasKey('@self', $save['object']);
			$out[$save['schema'] . ':' . $save['uuid']] = $save['object']['organisation'];
		}

		return $out;
	}//end written()

	public function testOnOneOrganisationEveryRowWithoutOneTakesIt(): void {
		$store = $this->store([
			'portal'        => [['slug' => 'zeist', 'organisation' => 'org-a', '@self' => ['uuid' => 'p1']]],
			'portalMessage' => [
				['subject' => 'Hallo', '@self' => ['uuid' => 'm1']],
				['subject' => 'Al goed', 'organisation' => 'org-a', '@self' => ['uuid' => 'm2']],
			],
			'portalCase'    => [['title' => 'Zaak', 'organisation' => '  ', '@self' => ['uuid' => 'c1']]],
		]);

		$lines = $this->runStep($store);

		$this->assertSame(['portalMessage:m1' => 'org-a', 'portalCase:c1' => 'org-a'], $this->written($store));
		$this->assertSame('Hallo', $store->saves[0]['object']['subject']);
		$this->assertContains('StampMissingOrganisation: gave 2 rows their organisation, 0 still without one.', $lines);
	}//end testOnOneOrganisationEveryRowWithoutOneTakesIt()

	public function testWithTwoOrganisationsARowTakesItsOwnPortalsAndTheRestIsReported(): void {
		$store = $this->store([
			'portal'          => [
				['slug' => 'zeist', 'organisation' => 'org-a', '@self' => ['uuid' => 'p1']],
				['slug' => 'utrecht', 'organisation' => 'org-b', '@self' => ['uuid' => 'p2']],
			],
			'portalEmailLink' => [
				['portal' => 'utrecht', '@self' => ['uuid' => 'e1']],
				['portal' => 'elders', '@self' => ['uuid' => 'e2']],
			],
			'portalMessage'   => [['subject' => 'Van wie?', '@self' => ['uuid' => 'm1']]],
		]);

		$lines = $this->runStep($store);

		$this->assertSame(['portalEmailLink:e1' => 'org-b'], $this->written($store));
		$this->assertContains('StampMissingOrganisation: gave 1 rows their organisation, 2 still without one.', $lines);
		$this->assertContains('StampMissingOrganisation: 1 portalEmailLink rows have no organisation and are not served. Set it by hand.', $lines);
		$this->assertContains('StampMissingOrganisation: 1 portalMessage rows have no organisation and are not served. Set it by hand.', $lines);
	}//end testWithTwoOrganisationsARowTakesItsOwnPortalsAndTheRestIsReported()

	public function testAPortalWithoutAnOrganisationIsReportedAndBlocksTheSoleGuess(): void {
		$store = $this->store([
			'portal'        => [
				['slug' => 'zeist', 'organisation' => 'org-a', '@self' => ['uuid' => 'p1']],
				['slug' => 'nieuw', '@self' => ['uuid' => 'p2']],
			],
			'portalMessage' => [['subject' => 'Hallo', '@self' => ['uuid' => 'm1']]],
		]);

		$lines = $this->runStep($store);

		$this->assertSame([], $store->saves);
		$this->assertContains('StampMissingOrganisation: 1 portal rows have no organisation and are not served. Set it by hand.', $lines);
		$this->assertContains('StampMissingOrganisation: 1 portalMessage rows have no organisation and are not served. Set it by hand.', $lines);
	}//end testAPortalWithoutAnOrganisationIsReportedAndBlocksTheSoleGuess()

	public function testPortalScopedSchemasAreNotTouched(): void {
		$store = $this->store([
			'portal' => [['slug' => 'zeist', 'organisation' => 'org-a', '@self' => ['uuid' => 'p1']]],
			'page'   => [['title' => 'Home', 'portal' => 'zeist', '@self' => ['uuid' => 'a1']]],
		]);

		$this->runStep($store);

		$this->assertSame([], $store->saves);
	}//end testPortalScopedSchemasAreNotTouched()

	public function testASecondRunWritesNothing(): void {
		$store = $this->store([
			'portal'        => [['slug' => 'zeist', 'organisation' => 'org-a', '@self' => ['uuid' => 'p1']]],
			'portalMessage' => [['subject' => 'Hallo', '@self' => ['uuid' => 'm1']]],
		]);
		$this->runStep($store);
		$this->assertCount(1, $store->saves);

		$lines = $this->runStep($store);

		$this->assertCount(1, $store->saves);
		$this->assertContains('StampMissingOrganisation: gave 0 rows their organisation, 0 still without one.', $lines);
	}//end testASecondRunWritesNothing()

	public function testWithoutOpenRegisterNothingIsStampedAndItSaysSo(): void {
		$lines = $this->runStep(null);

		$this->assertSame(['StampMissingOrganisation: OpenRegister is not available, nothing stamped.'], $lines);
	}//end testWithoutOpenRegisterNothingIsStampedAndItSaysSo()
}//end class
