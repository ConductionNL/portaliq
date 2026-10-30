<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\CaseActionsResolver;
use PHPUnit\Framework\TestCase;

/**
 * The actions on a case page and their window, judged on the server.
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-window-is-computed-on-the-server-req-coc-003
 */
class CaseActionsResolverTest extends TestCase {
	private const DECIDED = ['id' => 'case-1', 'decisionSentAt' => '2026-10-02'];

	/**
	 * The bezwaar is open on the first and on the 42nd day after the decision.
	 *
	 * @return void
	 */
	public function testOpenOnDayOneAndDayFortyTwo(): void {
		$day1  = $this->entries(case: self::DECIDED, now: $this->at('2026-10-03 00:05'));
		$day42 = $this->entries(case: self::DECIDED, now: $this->at('2026-11-13 23:59'));

		$this->assertTrue($day1[0]['open']);
		$this->assertSame('2026-11-13', $day1[0]['closesOn']);
		$this->assertTrue($day42[0]['open']);
	}//end testOpenOnDayOneAndDayFortyTwo()

	/**
	 * Day 43 and the decision day itself are closed, with the app's sentence.
	 *
	 * @return void
	 */
	public function testClosedOnDayFortyThreeAndOnTheDecisionDay(): void {
		$day43 = $this->entries(case: self::DECIDED, now: $this->at('2026-11-14 00:00'));
		$day0  = $this->entries(case: self::DECIDED, now: $this->at('2026-10-02 12:00'));

		$this->assertFalse($day43[0]['open']);
		$this->assertSame('De termijn voor bezwaar is voorbij.', $day43[0]['closedText']);
		$this->assertArrayNotHasKey('closesOn', $day43[0]);
		$this->assertFalse($day0[0]['open']);
	}//end testClosedOnDayFortyThreeAndOnTheDecisionDay()

	/**
	 * A case without a decision date, or with an unreadable one, is closed.
	 *
	 * @return void
	 */
	public function testClosedWithoutADate(): void {
		$none  = $this->entries(case: ['id' => 'case-1'], now: $this->at('2026-10-20 10:00'));
		$wrong = $this->entries(case: ['id' => 'case-1', 'decisionSentAt' => 'soon'], now: $this->at('2026-10-20 10:00'));

		$this->assertFalse($none[0]['open']);
		$this->assertFalse($wrong[0]['open']);
	}//end testClosedWithoutADate()

	/**
	 * A UTC moment late in the evening counts on the Amsterdam day it falls on.
	 *
	 * @return void
	 */
	public function testTheDecisionDayIsCountedInAmsterdam(): void {
		$case = ['id' => 'case-1', 'decisionSentAt' => '2026-10-02T23:30:00+00:00'];

		$entries = $this->entries(case: $case, now: $this->at('2026-10-03 12:00'));

		$this->assertFalse($entries[0]['open']);
		$this->assertSame('2026-11-14', $this->entries(case: $case, now: $this->at('2026-10-04 12:00'))[0]['closesOn']);
	}//end testTheDecisionDayIsCountedInAmsterdam()

	/**
	 * The klacht has no window, so it is open, and the two come in declared order.
	 *
	 * @return void
	 */
	public function testActionsComeInOrderAndWithoutAWindowAreOpen(): void {
		$entries = $this->entries(case: ['id' => 'case-1'], now: $this->at('2026-10-20 10:00'));

		$this->assertSame(['createBezwaar', 'createKlacht'], array_column($entries, 'action'));
		$this->assertTrue($entries[1]['open']);
		$this->assertSame('dossiq', $entries[1]['app']);
		$this->assertSame('againstCaseId', $entries[1]['crossRef']);
		$this->assertArrayNotHasKey('onCase', $entries[1]['form']);
		$this->assertSame(['againstCaseId', 'text'], $entries[1]['form']['fields']);
	}//end testActionsComeInOrderAndWithoutAWindowAreOpen()

	/**
	 * A contribution without `onCase`, or one placed on another collection, lists nothing.
	 *
	 * @return void
	 */
	public function testNothingIsListedWithoutAPlacementHere(): void {
		$plain = ['contributions' => [['app' => 'dossiq', 'actions' => [['id' => 'createBezwaar', 'type' => 'create']]]]];

		$this->assertSame([], (new CaseActionsResolver())->resolve($plain, 'dossiq', 'zaak', self::DECIDED));
		$this->assertSame([], (new CaseActionsResolver())->resolve($this->aggregate(), 'dossiq', 'aanvraag', self::DECIDED));
	}//end testNothingIsListedWithoutAPlacementHere()

	/**
	 * The entries for a case of `dossiq/zaak`.
	 *
	 * @param array<string, mixed> $case The case row.
	 * @param DateTimeImmutable    $now  The moment.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function entries(array $case, DateTimeImmutable $now): array {
		return (new CaseActionsResolver())->resolve($this->aggregate(), 'dossiq', 'zaak', $case, $now);
	}//end entries()

	/**
	 * A moment on the Amsterdam clock.
	 *
	 * @param string $moment `Y-m-d H:i`.
	 *
	 * @return DateTimeImmutable
	 */
	private function at(string $moment): DateTimeImmutable {
		return new DateTimeImmutable($moment, new DateTimeZone('Europe/Amsterdam'));
	}//end at()

	/**
	 * dossiq's two case-page actions, normalised, the klacht declared first.
	 *
	 * @return array<string, mixed>
	 */
	private function aggregate(): array {
		$reference = ['againstCaseId' => ['register' => 'dossiq', 'schema' => 'zaak', 'scopeField' => 'portalSubject', 'required' => true, 'scopeClaim' => '']];
		return [
			'contributions' => [
				[
					'app' => 'dossiq',
					'actions' => [
						[
							'id' => 'createKlacht',
							'type' => 'create',
							'label' => 'Klacht indienen',
							'fields' => ['againstCaseId', 'text'],
							'crossRefs' => $reference,
							'onCase' => ['crossRef' => 'againstCaseId', 'order' => 20],
						],
						[
							'id' => 'createBezwaar',
							'type' => 'create',
							'label' => 'Bezwaar maken',
							'fields' => ['againstCaseId', 'grounds'],
							'crossRefs' => $reference,
							'onCase' => [
								'crossRef' => 'againstCaseId',
								'order' => 10,
								'window' => ['from' => 'decisionSentAt', 'days' => 42],
								'closedText' => 'De termijn voor bezwaar is voorbij.',
							],
						],
					],
				],
			],
		];
	}//end aggregate()
}//end class
