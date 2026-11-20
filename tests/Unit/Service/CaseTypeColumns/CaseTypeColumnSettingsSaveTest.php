<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\CaseTypeColumns;

use Closure;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\CaseTypeColumns\CaseTypeColumnSettings;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * How a save of one case type's columns finds the portal to write and
 * what it writes when the submitted columns are unusable.
 *
 * @covers \OCA\Portaliq\Service\CaseTypeColumns\CaseTypeColumnSettings
 * @uses   \OCA\Portaliq\Service\CaseTypeColumns\CaseTypeColumnsNormaliser
 * @uses   \OCA\Portaliq\Service\CaseTypeColumns\CasesCollections
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
 */
class CaseTypeColumnSettingsSaveTest extends TestCase {

	private PortalObjectWriter&MockObject $writer;

	private PortalContributionRegistry&MockObject $registry;

	protected function setUp(): void {
		$this->writer   = $this->createMock(PortalObjectWriter::class);
		$this->registry = $this->createMock(PortalContributionRegistry::class);
	}//end setUp()

	public function testTheUuidIsUsedWhenThePortalHasNoId(): void {
		$this->writer->expects($this->once())->method('updateObject')
			->with('portaliq', 'portal', 'slug', 'mijn', '', 'p-uuid', $this->isInstanceOf(Closure::class))
			->willReturn(['uuid' => 'p-uuid']);

		$saved = (new CaseTypeColumnSettings($this->writer, $this->registry))
			->save(['uuid' => 'p-uuid', '@self' => ['id' => 'self-id'], 'slug' => 'mijn'], ['typeId' => 't', 'columns' => []]);

		$this->assertSame(['uuid' => 'p-uuid'], $saved);
	}//end testTheUuidIsUsedWhenThePortalHasNoId()

	public function testAPortalWithoutSlugOrIdIsWrittenWithEmptyRefsAndAFailedWriteGivesNull(): void {
		$this->writer->expects($this->once())->method('updateObject')
			->with('portaliq', 'portal', 'slug', '', '', '', $this->isInstanceOf(Closure::class))
			->willReturn(null);

		$this->assertNull((new CaseTypeColumnSettings($this->writer, $this->registry))->save([], ['typeId' => 't', 'columns' => []]));
	}//end testAPortalWithoutSlugOrIdIsWrittenWithEmptyRefsAndAFailedWriteGivesNull()

	public function testOnlyUnusableColumnsRemoveTheTypeAndKeepTheOthers(): void {
		$written = $this->written(
			stored: ['caseTypeColumns' => [
				'vergunning' => [['field' => 'status', 'render' => 'text']],
				'melding' => [['field' => 'date', 'render' => 'date']],
			]],
			change: ['typeId' => 'vergunning', 'columns' => [['field' => 'status', 'render' => 'badge'], 'junk']]
		);

		$this->assertSame(['melding' => [['field' => 'date', 'render' => 'date']]], $written);
	}//end testOnlyUnusableColumnsRemoveTheTypeAndKeepTheOthers()

	public function testANewTypeIsAddedAndAnInvalidStoredTypeIsDroppedOnTheWay(): void {
		$written = $this->written(
			stored: ['caseTypeColumns' => [
				'melding' => [['field' => 'date', 'render' => 'date']],
				'kapot' => [['field' => 'x y', 'render' => 'text']],
			]],
			change: ['typeId' => 'parkeren', 'columns' => [['field' => 'kenteken', 'render' => 'text'], ['field' => 'kenteken', 'render' => 'qr']]]
		);

		$this->assertSame(
			[
				'melding' => [['field' => 'date', 'render' => 'date']],
				'parkeren' => [['field' => 'kenteken', 'render' => 'text']],
			],
			$written
		);
	}//end testANewTypeIsAddedAndAnInvalidStoredTypeIsDroppedOnTheWay()

	public function testClearingATypeOnAPortalWithNoSettingsStoresAnEmptyObject(): void {
		$written = $this->written(stored: ['slug' => 'mijn'], change: ['typeId' => 'vergunning', 'columns' => []]);

		$this->assertEquals(new stdClass(), $written);
	}//end testClearingATypeOnAPortalWithNoSettingsStoresAnEmptyObject()

	public function testNoContributingAppOffersNoFields(): void {
		$this->registry->method('servedAudiences')->willReturn([]);

		$this->assertSame([], (new CaseTypeColumnSettings($this->writer, $this->registry))->fields());
	}//end testNoContributingAppOffersNoFields()

	public function testAFieldSeenInTwoAudiencesKeepsTheFirstLabel(): void {
		$this->registry->method('servedAudiences')->willReturn(['resident', 'business']);
		$this->registry->method('aggregateFor')->willReturnCallback(
			static fn (array $subject): array => ['contributions' => [['collections' => [[
				'kind' => 'cases',
				'columns' => [['field' => 'status', 'label' => $subject['audience'] === 'resident' ? 'Status' : 'Stand'], ['field' => '', 'label' => 'Leeg']],
			]]]]]
		);

		$this->assertSame([['field' => 'status', 'label' => 'Status']], (new CaseTypeColumnSettings($this->writer, $this->registry))->fields());
	}//end testAFieldSeenInTwoAudiencesKeepsTheFirstLabel()

	/**
	 * The caseTypeColumns a save would write over a stored portal.
	 *
	 * @param array<string, mixed> $stored The portal as stored.
	 * @param array{typeId: string, columns: array<int|string, mixed>} $change The change.
	 *
	 * @return mixed
	 */
	private function written(array $stored, array $change): mixed {
		$written = null;
		$this->writer->expects($this->once())->method('updateObject')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, Closure $data) use ($stored, &$written): array {
				$written = $data($stored);
				return $written;
			}
		);
		(new CaseTypeColumnSettings($this->writer, $this->registry))->save(['id' => 'p-1', 'slug' => 'mijn'], $change);

		return $written['caseTypeColumns'];
	}//end written()
}//end class
