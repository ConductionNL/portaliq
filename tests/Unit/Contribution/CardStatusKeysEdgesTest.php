<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CardStatusKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Card status declarations: the projected field list gates every field, the
 * label is bounded and an `only` filter needs at least one value.
 */
#[CoversClass(CardStatusKeys::class)]
class CardStatusKeysEdgesTest extends TestCase {

	private const STATUS = [
		'collection' => 'lessons',
		'matchField' => 'subject',
		'fromField'  => 'startsAt',
		'toField'    => 'endsAt',
		'label'      => '  Nu bezig ',
	];

	private function keys(array $status, array $collections = []): array {
		return (new CardStatusKeys())->keys(['display' => 'cards', 'status' => $status], ['lessons'], $collections);
	}//end keys()

	public function testWithoutAProjectionEveryWellFormedFieldIsAccepted(): void {
		$out = $this->keys(self::STATUS + ['tone' => 'positive', 'otherLabel' => 'Straks', 'otherTone' => 'bogus', 'schoolDaysOnly' => true]);

		$this->assertSame(
			[
				'status' => [
					'collection'     => 'lessons',
					'matchField'     => 'subject',
					'fromField'      => 'startsAt',
					'toField'        => 'endsAt',
					'label'          => 'Nu bezig',
					'tone'           => 'success',
					'otherLabel'     => 'Straks',
					'otherTone'      => 'neutral',
					'schoolDaysOnly' => true,
				],
			],
			$out
		);
	}//end testWithoutAProjectionEveryWellFormedFieldIsAccepted()

	public function testAFieldTheCollectionDoesNotProjectDropsTheStatus(): void {
		$collections = [['id' => 'other', 'fields' => []], ['id' => 'lessons', 'fields' => ['subject', 'startsAt', 7]]];
		$this->assertSame([], $this->keys(self::STATUS, $collections));
	}//end testAFieldTheCollectionDoesNotProjectDropsTheStatus()

	public function testANonStringOrTooLongLabelDropsTheStatus(): void {
		$this->assertSame([], $this->keys(array_merge(self::STATUS, ['label' => 5])));
		$this->assertSame([], $this->keys(array_merge(self::STATUS, ['label' => str_repeat('x', 61)])));
	}//end testANonStringOrTooLongLabelDropsTheStatus()

	public function testOnlyKeepsNonEmptyStringValuesCappedAtTen(): void {
		$values = array_merge(['', 7], array_map(static fn (int $i): string => 'v' . $i, range(1, 12)));
		$out = $this->keys(self::STATUS + ['only' => ['field' => 'subject', 'in' => $values]]);

		$this->assertSame('subject', $out['status']['only']['field']);
		$this->assertSame(array_map(static fn (int $i): string => 'v' . $i, range(1, 10)), $out['status']['only']['in']);
		$this->assertFalse($out['status']['schoolDaysOnly']);
	}//end testOnlyKeepsNonEmptyStringValuesCappedAtTen()

	public function testOnlyWithoutUsableValuesOrWithABadFieldIsLeftOut(): void {
		$noValues = $this->keys(self::STATUS + ['only' => ['field' => 'subject', 'in' => ['', 3]]]);
		$badField = $this->keys(self::STATUS + ['only' => ['field' => '1bad', 'in' => ['a']]]);

		$this->assertArrayNotHasKey('only', $noValues['status']);
		$this->assertArrayNotHasKey('only', $badField['status']);
		$this->assertSame('neutral', $noValues['status']['tone']);
	}//end testOnlyWithoutUsableValuesOrWithABadFieldIsLeftOut()

	public function testAnUnknownCollectionOrNonCardsDisplayYieldsNothing(): void {
		$this->assertSame([], $this->keys(array_merge(self::STATUS, ['collection' => 'unknown'])));
		$this->assertSame([], (new CardStatusKeys())->keys(['display' => 'list', 'status' => self::STATUS], ['lessons'], []));
	}//end testAnUnknownCollectionOrNonCardsDisplayYieldsNothing()
}//end class
