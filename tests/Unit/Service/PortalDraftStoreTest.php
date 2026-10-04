<?php

/**
 * PortalDraftStore (site-multi-step-forms REQ-SMF-021): whose draft a read
 * answers with, what a save stores, and which drafts a purge deletes.
 *
 * The purge's fake store answers with every draft whatever filter it is
 * handed, so the test fails if the store trusts the query instead of reading
 * each row's own `expiresAt`.
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

use OCA\Portaliq\Service\PortalDraftStore;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A resident's own form drafts.
 */
class PortalDraftStoreTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'sanne-1', 'organisation' => 'gemeente-x'];

	private const NOW = '2026-10-05T12:00:00+00:00';

	/**
	 * One stored draft row.
	 *
	 * @param array<string, mixed> $overrides Keys to replace.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(array $overrides = []): array {
		return array_merge(
			[
				'@self'           => ['uuid' => 'draft-1'],
				'subjectRef'      => 'sanne-1',
				'contributionApp' => 'dossiq',
				'actionId'        => 'startWooVerzoek',
				'answers'         => ['onderwerp' => 'De nieuwe brug'],
				'step'            => 1,
				'expiresAt'       => '2026-11-01T12:00:00+00:00',
			],
			$overrides
		);
	}//end row()

	/**
	 * The read is scoped on the subject's own `subjectRef`, and filtered to
	 * this app and action: the draft's id is never the way in.
	 *
	 * @return void
	 */
	public function testAReadIsScopedToTheSubjectsOwnDraft(): void {
		$seen   = [];
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (...$args) use (&$seen): array {
				$seen = $args;
				return [$this->row()];
			}
		);

		$draft = $this->store(reader: $reader)->mine(
			subject: self::SUBJECT,
			app: 'dossiq',
			actionId: 'startWooVerzoek',
			now: self::NOW
		);

		$this->assertSame('De nieuwe brug', $draft['answers']['onderwerp']);
		$this->assertSame('portaliq', $seen[0]);
		$this->assertSame('portalDraft', $seen[1]);
		$this->assertSame('subjectRef', $seen[2]);
		$this->assertSame('sanne-1', $seen[3]);
		$this->assertSame(['contributionApp' => 'dossiq', 'actionId' => 'startWooVerzoek'], end($seen));
	}//end testAReadIsScopedToTheSubjectsOwnDraft()

	/**
	 * A row the scoped read handed back for another action, or one past its
	 * date, is not served. A subject without a ref gets nothing at all.
	 *
	 * @return void
	 */
	public function testAnotherActionsOrAnExpiredDraftIsNotServed(): void {
		foreach (
			[
				$this->row(['actionId' => 'startWooVerzoekAlgemeen']),
				$this->row(['contributionApp' => 'pipelinq']),
				$this->row(['expiresAt' => '2026-10-04T12:00:00+00:00']),
				$this->row(['expiresAt' => '']),
			] as $row
		) {
			$reader = $this->createMock(PortalObjectReader::class);
			$reader->method('readCollection')->willReturn([$row]);

			$this->assertNull(
				$this->store(reader: $reader)->mine(subject: self::SUBJECT, app: 'dossiq', actionId: 'startWooVerzoek', now: self::NOW)
			);
		}

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())->method('readCollection');
		$this->assertNull(
			$this->store(reader: $reader)->mine(subject: ['subjectRef' => ''], app: 'dossiq', actionId: 'startWooVerzoek', now: self::NOW)
		);
	}//end testAnotherActionsOrAnExpiredDraftIsNotServed()

	/**
	 * A first save creates, a second one updates the resident's own row.
	 *
	 * @return void
	 */
	public function testASaveCreatesOnceAndThenUpdates(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([]);
		$writer = $this->createMock(PortalObjectWriter::class);
		$created = [];
		$writer->expects($this->once())->method('createObject')->willReturnCallback(
			function (...$args) use (&$created): array {
				$created = $args;
				return ['@self' => ['uuid' => 'draft-1']];
			}
		);
		$writer->expects($this->never())->method('updateObject');

		$this->store(reader: $reader, writer: $writer)->save(
			subject: self::SUBJECT,
			app: 'dossiq',
			actionId: 'startWooVerzoek',
			answers: ['onderwerp' => 'De nieuwe brug'],
			step: 1,
			expiresAt: '2026-11-04T12:00:00+00:00',
			now: self::NOW
		);

		$this->assertSame('subjectRef', $created[2]);
		$this->assertSame('sanne-1', $created[3]);
		$this->assertSame(
			[
				'contributionApp' => 'dossiq',
				'actionId'        => 'startWooVerzoek',
				'answers'         => ['onderwerp' => 'De nieuwe brug'],
				'step'            => 1,
				'savedAt'         => self::NOW,
				'expiresAt'       => '2026-11-04T12:00:00+00:00',
			],
			$created[5]
		);

		$again = $this->createMock(PortalObjectReader::class);
		$again->method('readCollection')->willReturn([$this->row()]);
		$second = $this->createMock(PortalObjectWriter::class);
		$second->expects($this->never())->method('createObject');
		$second->expects($this->once())->method('updateObject')->willReturn($this->row());

		$this->store(reader: $again, writer: $second)->save(
			subject: self::SUBJECT,
			app: 'dossiq',
			actionId: 'startWooVerzoek',
			answers: ['onderwerp' => 'De brug'],
			step: 2,
			expiresAt: '2026-11-04T12:00:00+00:00',
			now: self::NOW
		);
	}//end testASaveCreatesOnceAndThenUpdates()

	/**
	 * Sending the action throws the draft away, by the subject's own row.
	 *
	 * @return void
	 */
	public function testDiscardDeletesTheSubjectsOwnDraft(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([$this->row()]);
		$fake = $this->objectService();

		$gone = $this->store(reader: $reader, objectService: $fake)->discard(
			subject: self::SUBJECT,
			app: 'dossiq',
			actionId: 'startWooVerzoek',
			now: self::NOW
		);

		$this->assertTrue($gone);
		$this->assertSame(['draft-1'], $fake->deleted);

		$empty = $this->createMock(PortalObjectReader::class);
		$empty->method('readCollection')->willReturn([]);
		$other = $this->objectService();
		$this->assertFalse(
			$this->store(reader: $empty, objectService: $other)->discard(subject: self::SUBJECT, app: 'dossiq', actionId: 'startWooVerzoek', now: self::NOW)
		);
		$this->assertSame([], $other->deleted);
	}//end testDiscardDeletesTheSubjectsOwnDraft()

	/**
	 * The purge deletes a draft past its day count and leaves one inside it,
	 * even when the store answers with both.
	 *
	 * @return void
	 */
	public function testThePurgeDeletesOnlyTheExpiredDrafts(): void {
		$fake = $this->objectService(
			[
				$this->row(['@self' => ['uuid' => 'old-1'], 'expiresAt' => '2026-10-04T23:59:00+00:00']),
				$this->row(['@self' => ['uuid' => 'fresh-1'], 'expiresAt' => '2026-10-05T12:00:01+00:00']),
				$this->row(['@self' => ['uuid' => 'undated-1'], 'expiresAt' => null]),
				'not a row',
			]
		);

		$removed = $this->store(objectService: $fake)->purgeExpired(now: self::NOW);

		$this->assertSame(2, $removed);
		$this->assertSame(['old-1', 'undated-1'], $fake->deleted);
		$this->assertSame(
			['register' => 'portaliq', 'schema' => 'portalDraft', 'expiresAt' => ['lt' => self::NOW]],
			$fake->asked['filters']
		);
	}//end testThePurgeDeletesOnlyTheExpiredDrafts()

	/**
	 * Without OpenRegister nothing is deleted and nothing throws.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThePurgeDoesNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(null);
		$store = new PortalDraftStore(
			$this->createMock(PortalObjectReader::class),
			$this->createMock(PortalObjectWriter::class),
			$container,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(0, $store->purgeExpired(now: self::NOW));
	}//end testWithoutOpenRegisterThePurgeDoesNothing()

	/**
	 * A store that answers with every draft it holds, whatever it is asked,
	 * and records what it was asked and what it deleted.
	 *
	 * @param array<int, mixed> $rows The drafts it holds.
	 *
	 * @return object The fake.
	 */
	private function objectService(array $rows = []): object {
		return new class($rows) {
			public array $deleted = [];

			public array $asked = [];

			/**
			 * @param array<int, mixed> $rows The drafts it holds.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * Answers with every row, ignoring the filters on purpose.
			 *
			 * @param array<string, mixed> $config The query.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->asked = $config;
				return $this->rows;
			}

			/**
			 * Records the delete.
			 *
			 * @param string $uuid The row.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 *
			 * @return bool True.
			 */
			public function deleteObject(string $uuid, string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true): bool {
				$this->deleted[] = $uuid;
				return true;
			}
		};
	}//end objectService()

	/**
	 * The store under test.
	 *
	 * @param PortalObjectReader|null $reader The reader double.
	 * @param PortalObjectWriter|null $writer The writer double.
	 * @param object|null $objectService The OpenRegister fake.
	 *
	 * @return PortalDraftStore The store.
	 */
	private function store(
		?PortalObjectReader $reader = null,
		?PortalObjectWriter $writer = null,
		?object $objectService = null,
	): PortalDraftStore {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(($objectService ?? $this->objectService()));

		return new PortalDraftStore(
			($reader ?? $this->createMock(PortalObjectReader::class)),
			($writer ?? $this->createMock(PortalObjectWriter::class)),
			$container,
			$this->createMock(LoggerInterface::class)
		);
	}//end store()
}//end class
