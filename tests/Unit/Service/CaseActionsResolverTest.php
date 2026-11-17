<?php

/**
 * Unit tests for CaseActionsResolver.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-window-is-computed-on-the-server-req-coc-003
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\Portaliq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\CaseActionsResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Which create actions a case page lists, in which order, and whether each is open.
 *
 * @covers \OCA\Portaliq\Service\CaseActionsResolver
 */
class CaseActionsResolverTest extends TestCase {

	/**
	 * A resolver whose clock reads the given Amsterdam wall-clock moment.
	 *
	 * @param string $now Local time in Europe/Amsterdam, `Y-m-d H:i:s`.
	 *
	 * @return CaseActionsResolver
	 */
	private function resolver(string $now): CaseActionsResolver {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(
			(new DateTimeImmutable($now, new DateTimeZone('Europe/Amsterdam')))->getTimestamp()
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new CaseActionsResolver(time: $clock, l10n: $l10n);
	}//end resolver()

	/**
	 * A create action placed on the `zaken` collection of dossiq.
	 *
	 * @param string              $id     The action id.
	 * @param int                 $order  Its order.
	 * @param array<string,mixed> $extras Extra `onCase` keys.
	 *
	 * @return array<string,mixed>
	 */
	private function action(string $id, int $order, array $extras=[]): array {
		return [
			'id' => $id,
			'type' => 'create',
			'label' => $id . ' label',
			'crossRefs' => [
				'againstCaseId' => ['register' => 'dossiq', 'schema' => 'zaak', 'scopeField' => 'subjectRef'],
			],
			'onCase' => array_merge(['crossRef' => 'againstCaseId', 'order' => $order], $extras),
		];
	}//end action()

	/**
	 * The bezwaar action with a 42-day window from `decisionSentAt`.
	 *
	 * @return array<string,mixed>
	 */
	private function bezwaar(): array {
		return $this->action(
			id: 'createBezwaar',
			order: 10,
			extras: ['window' => ['from' => 'decisionSentAt', 'days' => 42], 'closedText' => 'De termijn voor bezwaar is voorbij.']
		);
	}//end bezwaar()

	public function testTheWindowOpensTheDayAfterTheDecisionAndClosesAfterTheLastDay(): void {
		$case = ['decisionSentAt' => '2026-10-02'];

		self::assertFalse($this->resolver(now: '2026-10-02 12:00:00')->window(action: $this->bezwaar(), case: $case)['open'], 'the decision day itself');
		self::assertTrue($this->resolver(now: '2026-10-03 00:00:01')->window(action: $this->bezwaar(), case: $case)['open'], 'day 1');
		self::assertTrue($this->resolver(now: '2026-11-13 23:59:59')->window(action: $this->bezwaar(), case: $case)['open'], 'day 42');
		self::assertFalse($this->resolver(now: '2026-11-14 00:00:01')->window(action: $this->bezwaar(), case: $case)['open'], 'day 43');
		self::assertSame('2026-11-13', $this->resolver(now: '2026-10-20 10:00:00')->window(action: $this->bezwaar(), case: $case)['closesOn']);
	}//end testTheWindowOpensTheDayAfterTheDecisionAndClosesAfterTheLastDay()

	public function testADecisionDateTimeCountsInAmsterdam(): void {
		// 22:30 UTC on 1 October is 00:30 on 2 October in Amsterdam: the decision day is the 2nd.
		$case = ['decisionSentAt' => '2026-10-01T22:30:00Z'];

		self::assertSame('2026-11-13', $this->resolver(now: '2026-10-20 10:00:00')->window(action: $this->bezwaar(), case: $case)['closesOn']);
	}//end testADecisionDateTimeCountsInAmsterdam()

	public function testAMissingOrUnreadableDateIsClosed(): void {
		$resolver = $this->resolver(now: '2026-10-20 10:00:00');

		self::assertSame(['open' => false], $resolver->window(action: $this->bezwaar(), case: []));
		self::assertSame(['open' => false], $resolver->window(action: $this->bezwaar(), case: ['decisionSentAt' => 'not a date']));
	}//end testAMissingOrUnreadableDateIsClosed()

	public function testAnActionWithoutAWindowIsAlwaysOpen(): void {
		self::assertSame(['open' => true], $this->resolver(now: '2026-10-20 10:00:00')->window(action: $this->action(id: 'createKlacht', order: 20), case: []));
	}//end testAnActionWithoutAWindowIsAlwaysOpen()

	public function testTheCaseListsItsActionsInOrderWithTheClosedSentence(): void {
		$contributions = [
			[
				'app' => 'dossiq',
				'actions' => [
					$this->action(id: 'createKlacht', order: 20),
					$this->bezwaar(),
					['id' => 'createAanvraag', 'type' => 'create', 'label' => 'Aanvraag'],
				],
			],
		];

		$listed = $this->resolver(now: '2026-12-01 10:00:00')->forCase(
			contributions: $contributions,
			register: 'dossiq',
			schema: 'zaak',
			case: ['decisionSentAt' => '2026-10-02']
		);

		self::assertSame(
			[
				['app' => 'dossiq', 'action' => 'createBezwaar', 'label' => 'createBezwaar label', 'open' => false, 'closesOn' => '2026-11-13', 'closedText' => 'De termijn voor bezwaar is voorbij.'],
				[
					'app' => 'dossiq',
					'action' => 'createKlacht',
					'label' => 'createKlacht label',
					'open' => true,
					'form' => ['id' => 'createKlacht', 'type' => 'create', 'label' => 'createKlacht label', 'crossRefs' => []],
				],
			],
			$listed
		);
	}//end testTheCaseListsItsActionsInOrderWithTheClosedSentence()

	public function testAClosedActionWithoutItsOwnSentenceGetsTheDefault(): void {
		$action = $this->action(id: 'createBezwaar', order: 1, extras: ['window' => ['from' => 'decisionSentAt', 'days' => 42]]);

		$listed = $this->resolver(now: '2026-10-20 10:00:00')->forCase(
			contributions: [['app' => 'dossiq', 'actions' => [$action]]],
			register: 'dossiq',
			schema: 'zaak',
			case: []
		);

		self::assertSame('You can no longer do this on this case.', $listed[0]['closedText']);
	}//end testAClosedActionWithoutItsOwnSentenceGetsTheDefault()

	public function testTheFormLeavesOutTheFieldTheServerFillsIn(): void {
		$action = array_merge(
			$this->action(id: 'createBezwaar', order: 1),
			[
				'fields' => ['grounds', 'againstCaseId'],
				'requiredFields' => ['grounds', 'againstCaseId'],
				'fieldConfigs' => ['againstCaseId' => ['label' => 'Zaak'], 'grounds' => ['label' => 'Gronden']],
			]
		);

		$form = $this->resolver(now: '2026-10-20 10:00:00')->forCase(
			contributions: [['app' => 'dossiq', 'actions' => [$action]]],
			register: 'dossiq',
			schema: 'zaak',
			case: []
		)[0]['form'];

		self::assertSame(['grounds'], $form['fields']);
		self::assertSame(['grounds'], $form['requiredFields']);
		self::assertSame(['grounds' => ['label' => 'Gronden']], $form['fieldConfigs']);
		self::assertSame([], $form['crossRefs']);
		self::assertArrayNotHasKey('onCase', $form);
	}//end testTheFormLeavesOutTheFieldTheServerFillsIn()

	public function testAContributionWithoutOnCaseListsNothing(): void {
		$action = $this->action(id: 'createBezwaar', order: 1);
		unset($action['onCase']);

		self::assertSame(
			[],
			$this->resolver(now: '2026-10-20 10:00:00')->forCase(
				contributions: [['app' => 'dossiq', 'actions' => [$action]]],
				register: 'dossiq',
				schema: 'zaak',
				case: []
			)
		);
	}//end testAContributionWithoutOnCaseListsNothing()

	public function testOnlyACreateActionOnThisCollectionIsPlaced(): void {
		$resolver = $this->resolver(now: '2026-10-20 10:00:00');
		$update   = array_merge($this->action(id: 'updateZaak', order: 1), ['type' => 'update']);

		self::assertSame('againstCaseId', $resolver->crossRefField(action: $this->bezwaar(), register: 'dossiq', schema: 'zaak'));
		self::assertNull($resolver->crossRefField(action: $this->bezwaar(), register: 'dossiq', schema: 'melding'));
		self::assertNull($resolver->crossRefField(action: $this->bezwaar(), register: 'other', schema: 'zaak'));
		self::assertNull($resolver->crossRefField(action: $update, register: 'dossiq', schema: 'zaak'));
	}//end testOnlyACreateActionOnThisCollectionIsPlaced()
}//end class
