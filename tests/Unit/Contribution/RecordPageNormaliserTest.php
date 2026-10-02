<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The record page, its narrowing keys and the kpi, calendar and news blocks
 * survive normalisation only in their valid shape, and never reach a
 * collection outside the same contribution.
 *
 * @spec openspec/changes/contribution-record-page/tasks.md#T1
 */
class RecordPageNormaliserTest extends TestCase {

	/**
	 * A contribution with the given pages over three collections.
	 *
	 * @param array<int, mixed> $pages The pages.
	 *
	 * @return array<string, mixed>
	 */
	private function normalise(array $pages): array {
		return (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					['id' => 'children', 'schema' => 'learner-profile', 'fields' => ['givenName', 'familyName', 'schoolId']],
					['id' => 'cards', 'schema' => 'report-card'],
					['id' => 'summary', 'schema' => 'attendance-summary'],
					['id' => 'events', 'schema' => 'school-event'],
					['id' => 'periods', 'schema' => 'report-period'],
				],
				'actions' => [],
				'pages' => $pages,
			]
		);
	}

	/**
	 * The blocks of the first page.
	 *
	 * @param array<int, mixed> $blocks The declared blocks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(array $blocks): array {
		$out = $this->normalise([['id' => 'p', 'blocks' => array_merge([['type' => 'collection', 'collection' => 'children']], $blocks)]]);
		return array_slice($out['pages'][0]['blocks'], 1);
	}

	public function testARecordIsKeptOnlyWhenItsCollectionResolves(): void {
		$out = $this->normalise(
			[
				[
					'id' => 'kept',
					'record' => ['collection' => 'children', 'titleFields' => ['givenName', '', 7, 'familyName'], 'extra' => 'x'],
					'blocks' => [['type' => 'collection', 'collection' => 'children']],
				],
				[
					'id' => 'lost',
					'record' => ['collection' => 'elsewhere'],
					'blocks' => [['type' => 'collection', 'collection' => 'cards']],
				],
				[
					'id' => 'garbage',
					'record' => 'children',
					'blocks' => [['type' => 'collection', 'collection' => 'cards']],
				],
			]
		);

		$this->assertSame(['collection' => 'children', 'titleFields' => ['givenName', 'familyName']], $out['pages'][0]['record']);
		$this->assertArrayNotHasKey('record', $out['pages'][1]);
		$this->assertArrayNotHasKey('record', $out['pages'][2]);
	}

	public function testACollectionBlockKeepsItsRecordFieldAndKey(): void {
		$blocks = $this->blocks(
			[
				['type' => 'collection', 'collection' => 'cards', 'recordField' => 'learnerRef'],
				['type' => 'collection', 'collection' => 'events', 'recordField' => 'schoolId', 'recordKey' => 'schoolId'],
				['type' => 'collection', 'collection' => 'cards', 'recordField' => ['x'], 'recordKey' => ''],
			]
		);

		$this->assertSame(['type' => 'collection', 'collection' => 'cards', 'recordField' => 'learnerRef'], $blocks[0]);
		$this->assertSame(['type' => 'collection', 'collection' => 'events', 'recordField' => 'schoolId', 'recordKey' => 'schoolId'], $blocks[1]);
		$this->assertSame(['type' => 'collection', 'collection' => 'cards'], $blocks[2]);
	}

	public function testAKpiBlockKeepsOnlyWellFormedCards(): void {
		$blocks = $this->blocks(
			[
				[
					'type' => 'kpi',
					'collection' => 'summary',
					'label' => 'This school year',
					'recordField' => 'learnerRef',
					'pick' => ['field' => 'schoolYear', 'direction' => 'sideways'],
					'cards' => [
						[
							'field' => 'absentDays',
							'label' => 'Absence',
							'unit' => 'days',
							'details' => [['field' => 'absentAuthorisedDays', 'label' => 'with permission'], ['label' => 'no field'], 'x'],
						],
						['field' => 'absentUnauthorisedDays', 'label' => 'Unexcused', 'highlight' => 'true'],
						['field' => 'lateCount'],
						'not a card',
					],
				],
				['type' => 'kpi', 'collection' => 'summary', 'cards' => [['label' => 'no field']]],
				['type' => 'kpi', 'collection' => 'elsewhere', 'cards' => [['field' => 'a', 'label' => 'A']]],
			]
		);

		$this->assertCount(1, $blocks, 'a kpi block without a valid card or collection is dropped');
		$this->assertSame('kpi', $blocks[0]['type']);
		$this->assertSame('summary', $blocks[0]['collection']);
		$this->assertSame('This school year', $blocks[0]['label']);
		$this->assertSame('learnerRef', $blocks[0]['recordField']);
		$this->assertSame(['field' => 'schoolYear', 'direction' => 'desc'], $blocks[0]['pick']);
		$this->assertSame(
			[
				[
					'field' => 'absentDays',
					'label' => 'Absence',
					'unit' => 'days',
					'details' => [['field' => 'absentAuthorisedDays', 'label' => 'with permission']],
				],
				['field' => 'absentUnauthorisedDays', 'label' => 'Unexcused', 'highlight' => true],
			],
			$blocks[0]['cards']
		);
	}

	public function testACalendarBlockKeepsOnlyResolvableSources(): void {
		$blocks = $this->blocks(
			[
				[
					'type' => 'calendar',
					'label' => 'Coming up',
					'sources' => [
						['collection' => 'events', 'startField' => 'startsAt', 'endField' => 'endsAt', 'titleField' => 'title', 'kind' => 'School event', 'recordField' => 'learnerRefs'],
						[
							'collection' => 'periods',
							'expand' => ['field' => 'holidays', 'startField' => 'startDate', 'endField' => 'endDate', 'titleField' => 'name'],
							'kind' => 'Holiday',
							'recordField' => 'schoolId',
							'recordKey' => 'schoolId',
						],
						['collection' => 'elsewhere', 'startField' => 'a', 'titleField' => 'b'],
						['collection' => 'events', 'titleField' => 'title'],
						['collection' => 'periods', 'expand' => ['field' => 'holidays', 'titleField' => 'name']],
					],
				],
				['type' => 'calendar', 'sources' => [['collection' => 'elsewhere', 'startField' => 'a', 'titleField' => 'b']]],
			]
		);

		$this->assertCount(1, $blocks, 'a calendar without a resolvable source is dropped');
		$this->assertSame('Coming up', $blocks[0]['label']);
		$this->assertSame(
			[
				['collection' => 'events', 'startField' => 'startsAt', 'titleField' => 'title', 'endField' => 'endsAt', 'kind' => 'School event', 'recordField' => 'learnerRefs'],
				[
					'collection' => 'periods',
					'expand' => ['field' => 'holidays', 'startField' => 'startDate', 'titleField' => 'name', 'endField' => 'endDate'],
					'kind' => 'Holiday',
					'recordField' => 'schoolId',
					'recordKey' => 'schoolId',
				],
			],
			$blocks[0]['sources']
		);
	}

	public function testANewsBlockClampsItsLimit(): void {
		$blocks = $this->blocks(
			[
				['type' => 'news', 'label' => 'News', 'limit' => 50],
				['type' => 'news', 'limit' => 'three'],
				['type' => 'news', 'limit' => 5, 'collection' => 'cards'],
			]
		);

		$this->assertSame(['type' => 'news', 'limit' => 20, 'label' => 'News'], $blocks[0]);
		$this->assertSame(['type' => 'news', 'limit' => 3], $blocks[1]);
		$this->assertSame(['type' => 'news', 'limit' => 5], $blocks[2]);
	}

	public function testAGroupBoundBlockKeepsItsGroupFieldAndLookups(): void {
		$blocks = $this->blocks(
			[
				[
					'type' => 'collection',
					'collection' => 'events',
					'recordGroupsField' => 'cohortIds',
					'lookups' => [
						[
							'as' => 'done',
							'collection' => 'cards',
							'matchField' => 'assignmentId',
							'valueField' => 'lifecycle',
							'recordField' => 'learnerRef',
							'values' => ['submitted' => 'Handed in', 'late' => 7],
							'fallback' => 'Open',
						],
						['as' => 'x', 'collection' => 'elsewhere', 'matchField' => 'a', 'valueField' => 'b'],
						['as' => '', 'collection' => 'cards', 'matchField' => 'a', 'valueField' => 'b'],
						['collection' => 'cards', 'matchField' => 'a'],
					],
				],
			]
		);

		$this->assertSame('cohortIds', $blocks[0]['recordGroupsField']);
		$this->assertSame(
			[
				[
					'as' => 'done',
					'collection' => 'cards',
					'matchField' => 'assignmentId',
					'valueField' => 'lifecycle',
					'recordField' => 'learnerRef',
					'values' => ['submitted' => 'Handed in'],
					'fallback' => 'Open',
				],
			],
			$blocks[0]['lookups']
		);
	}

	public function testAKpiCaptionAndAStaticCalendarTitleAreKept(): void {
		$blocks = $this->blocks(
			[
				[
					'type' => 'kpi',
					'collection' => 'summary',
					'caption' => ['field' => 'schoolYear', 'label' => 'School year'],
					'cards' => [['field' => 'a', 'label' => 'A']],
				],
				[
					'type' => 'kpi',
					'collection' => 'summary',
					'caption' => ['label' => 'no field'],
					'cards' => [['field' => 'a', 'label' => 'A']],
				],
				[
					'type' => 'calendar',
					'sources' => [
						['collection' => 'events', 'startField' => 'startsAt', 'title' => 'Parent evening', 'titleField' => 'slotLabel', 'recordGroupsField' => 'cohortIds'],
						['collection' => 'events', 'startField' => 'startsAt', 'title' => ''],
					],
				],
			]
		);

		$this->assertSame(['field' => 'schoolYear', 'label' => 'School year'], $blocks[0]['caption']);
		$this->assertArrayNotHasKey('caption', $blocks[1]);
		$this->assertSame(
			[['collection' => 'events', 'startField' => 'startsAt', 'titleField' => 'slotLabel', 'title' => 'Parent evening', 'recordGroupsField' => 'cohortIds']],
			$blocks[2]['sources']
		);
	}

	public function testACalendarSourceKeepsAWellFormedOnlyRule(): void {
		$blocks = $this->blocks(
			[
				[
					'type' => 'calendar',
					'sources' => [
						['collection' => 'events', 'startField' => 'a', 'titleField' => 'b', 'only' => ['field' => 'lifecycle', 'in' => ['booked', '', 3, 'acknowledged']]],
						['collection' => 'events', 'startField' => 'a', 'titleField' => 'b', 'only' => ['field' => 'lifecycle', 'in' => []]],
					],
				],
			]
		);

		$this->assertSame(['field' => 'lifecycle', 'in' => ['booked', 'acknowledged']], $blocks[0]['sources'][0]['only']);
		$this->assertArrayNotHasKey('only', $blocks[0]['sources'][1]);
	}
}
