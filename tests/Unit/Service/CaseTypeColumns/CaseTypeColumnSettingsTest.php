<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\CaseTypeColumns;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\CaseTypeColumns\CaseTypeColumnSettings;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A portal's per-case-type column choice: the fields the cases collections
 * offer, and a save that changes one type's columns on the stored portal
 * and leaves the other types alone.
 *
 * @covers \OCA\Portaliq\Service\CaseTypeColumns\CaseTypeColumnSettings
 * @uses   \OCA\Portaliq\Service\CaseTypeColumns\CasesCollections
 * @uses   \OCA\Portaliq\Service\CaseTypeColumns\CaseTypeColumnsNormaliser
 */
class CaseTypeColumnSettingsTest extends TestCase {

	private PortalObjectWriter&MockObject $writer;

	/**
	 * The settings over a registry whose one audience offers these collections.
	 *
	 * @param array<int, mixed> $collections The cases collections' declarations.
	 *
	 * @return CaseTypeColumnSettings
	 */
	private function settings(array $collections = []): CaseTypeColumnSettings {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('servedAudiences')->willReturn(['resident']);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['collections' => $collections]]]);
		$this->writer = $this->createMock(PortalObjectWriter::class);

		return new CaseTypeColumnSettings($this->writer, $registry);
	}//end settings()

	/**
	 * Run a save against a stored portal and give what would be written.
	 *
	 * @param array<string, mixed> $stored The portal as stored.
	 * @param array<string, mixed> $change The change: typeId and columns.
	 *
	 * @return mixed The caseTypeColumns value written.
	 */
	private function written(array $stored, array $change): mixed {
		$settings = $this->settings();
		$written = null;
		$this->writer->expects($this->once())->method('updateObject')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, \Closure $data) use ($stored, &$written): array {
				$written = $data($stored);
				return $written + $stored;
			}
		);
		$settings->save(['id' => 'p-1', 'slug' => 'gemeente'], $change);

		return $written['caseTypeColumns'];
	}//end written()

	public function testTheOfferedFieldsAreEveryCasesColumnOnceInFirstSeenOrder(): void {
		$fields = $this->settings([
			['kind' => 'cases', 'columns' => [['field' => 'status', 'label' => 'Status'], ['field' => 'reference'], ['label' => 'no field'], 'junk']],
			['kind' => 'cases', 'columns' => [['field' => 'status', 'label' => 'Stand'], ['field' => 'date', 'label' => 'Datum']]],
			['kind' => 'documents', 'columns' => [['field' => 'title', 'label' => 'Titel']]],
		])->fields();

		$this->assertSame(
			[
				['field' => 'status', 'label' => 'Status'],
				['field' => 'reference', 'label' => 'reference'],
				['field' => 'date', 'label' => 'Datum'],
			],
			$fields
		);
	}

	public function testTheStoredChoiceIsReadNormalised(): void {
		$this->assertSame(
			['vergunning' => [['field' => 'status', 'render' => 'text']]],
			$this->settings()->storedFor(['caseTypeColumns' => ['vergunning' => [['field' => 'status', 'render' => 'text'], ['field' => 'x', 'render' => 'bogus']], 'leeg' => []]])
		);
		$this->assertSame([], $this->settings()->storedFor(null));
	}

	public function testASaveWritesThroughThePortalsOwnSlugAndId(): void {
		$settings = $this->settings();
		$this->writer->expects($this->once())->method('updateObject')
			->with('portaliq', 'portal', 'slug', 'gemeente', '', 'p-uuid', $this->isInstanceOf(\Closure::class))
			->willReturn(['id' => 'p-uuid']);

		$this->assertSame(['id' => 'p-uuid'], $settings->save(['@self' => ['id' => 'p-uuid'], 'slug' => 'gemeente'], ['typeId' => 'vergunning', 'columns' => []]));
	}

	public function testASaveReplacesOneTypesColumnsAndKeepsTheOthers(): void {
		$stored = ['caseTypeColumns' => [
			'vergunning' => [['field' => 'status', 'render' => 'text']],
			'melding' => [['field' => 'date', 'render' => 'date']],
		]];

		$this->assertSame(
			[
				'melding' => [['field' => 'date', 'render' => 'date']],
				'vergunning' => [['field' => 'link', 'render' => 'link', 'linkLabel' => 'Bekijk']],
			],
			$this->written($stored, ['typeId' => 'vergunning', 'columns' => [['field' => 'link', 'render' => 'link', 'linkLabel' => ' Bekijk ']]])
		);
	}

	public function testClearingTheLastTypeStoresAnEmptyObjectNotAList(): void {
		$cleared = $this->written(['caseTypeColumns' => ['vergunning' => [['field' => 'status', 'render' => 'text']]]], ['typeId' => 'vergunning', 'columns' => []]);

		$this->assertInstanceOf(stdClass::class, $cleared);
		$this->assertSame('{}', json_encode($cleared));
	}
}
