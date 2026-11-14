<?php

/**
 * Unit tests for PortalCosign.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use DateTimeImmutable;
use OCA\Portaliq\Service\Intake\PortalCosign;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Service\Intake\QueuedSubmission;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RIF-003: a held submission is delivered only after a different person co-signs it before the deadline.
 *
 * @covers \OCA\Portaliq\Service\Intake\PortalCosign
 * @uses \OCA\Portaliq\Service\Intake\PortalIntakeQueue
 * @uses \OCA\Portaliq\Service\Intake\QueuedSubmission
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class PortalCosignTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * The moment Sanne submits.
	 */
	private const SUBMITTED = '2026-10-08T10:00:00+00:00';

	/**
	 * Reset the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	/**
	 * The service over the in-memory store; codes are drawn as `7RQK2M`.
	 *
	 * @return PortalCosign
	 */
	private function cosign(): PortalCosign {
		$random  = $this->createMock(ISecureRandom::class);
		$counter = 0;
		$random->method('generate')->willReturnCallback(
			static function (int $length, string $characters = '') use (&$counter): string {
				if ($length === 6) {
					return '7RQK2M';
				}

				$counter++;
				return 'REF' . $counter;
			}
		);

		return new PortalCosign(new PortalIntakeQueue($this->fakeReader(), $this->fakeWriter(), $random), $random);

	}//end cosign()

	/**
	 * Sanne's Woo request, held for Henk.
	 *
	 * @param PortalCosign $cosign The service.
	 * @param int          $days   The deadline in days.
	 *
	 * @return array<string, string>
	 */
	private function held(PortalCosign $cosign, int $days = 14): array {
		$held = $cosign->hold(
			submission: new QueuedSubmission(portal: 'gemeente-x', route: 'woo', answers: ['onderwerp' => 'Parkeerbeleid'], subjectRef: 'bsn-sanne'),
			email: ' henk@example.nl ',
			name: 'Henk de Vries',
			deadlineDays: $days,
			now: new DateTimeImmutable(self::SUBMITTED)
		);
		$this->assertNotNull($held);
		return $held;

	}//end held()

	/**
	 * The stored submission.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return $this->storedRows('portalIntakeSubmission')[0];

	}//end stored()

	/**
	 * A required co-sign is read from the binding; anything else is off.
	 *
	 * @return void
	 */
	public function testRequiredComesFromTheBinding(): void {
		$cosign = $this->cosign();

		$this->assertTrue($cosign->required(binding: ['cosign' => ['required' => true, 'deadlineDays' => 7]]));
		$this->assertFalse($cosign->required(binding: ['cosign' => ['required' => 'yes']]));
		$this->assertFalse($cosign->required(binding: ['cosign' => true]));
		$this->assertFalse($cosign->required(binding: []));

	}//end testRequiredComesFromTheBinding()

	/**
	 * A held submission waits with a two-group code, the deadline and only the code's hash.
	 *
	 * @return void
	 */
	public function testAHeldSubmissionWaitsForItsCoSigner(): void {
		$held = $this->held(cosign: $this->cosign(), days: 14);

		$this->assertSame('awaiting-cosign', $held['state']);
		$this->assertSame('7RQ-K2M', $held['code']);
		$this->assertSame('2026-10-22T10:00:00+00:00', $held['deadline']);
		$this->assertStringStartsWith('AANVRAAG-', $held['reference']);

		$row = $this->stored();
		$this->assertSame('awaiting-cosign', $row['state']);
		$this->assertSame('bsn-sanne', $row['subjectRef']);
		$this->assertSame(
			['state' => 'awaiting', 'email' => 'henk@example.nl', 'name' => 'Henk de Vries', 'codeHash' => hash('sha256', '7RQK2M'), 'deadline' => '2026-10-22T10:00:00+00:00'],
			$row['cosign']
		);
		$this->assertStringNotContainsString('7RQ', json_encode($row));

	}//end testAHeldSubmissionWaitsForItsCoSigner()

	/**
	 * A non-positive deadline is the default fourteen days; a bad address holds nothing.
	 *
	 * @return void
	 */
	public function testDeadlineDefaultAndAddressCheck(): void {
		$cosign = $this->cosign();

		$this->assertSame('2026-10-22T10:00:00+00:00', $this->held(cosign: $cosign, days: 0)['deadline']);
		$this->assertNull($cosign->hold(submission: new QueuedSubmission(portal: 'gemeente-x', route: 'woo', answers: []), email: 'not an address', name: '', deadlineDays: 7, now: new DateTimeImmutable(self::SUBMITTED)));
		$this->assertNull($cosign->hold(submission: new QueuedSubmission(portal: '', route: 'woo', answers: []), email: 'henk@example.nl', name: '', deadlineDays: 7, now: new DateTimeImmutable(self::SUBMITTED)));
		$this->assertCount(1, $this->storedRows('portalIntakeSubmission'));

	}//end testDeadlineDefaultAndAddressCheck()

	/**
	 * Henk opens the request read-only with the code typed loosely.
	 *
	 * @return void
	 */
	public function testTheCoSignerSeesTheRequest(): void {
		$cosign = $this->cosign();
		$held = $this->held(cosign: $cosign);

		$opened = $cosign->open(code: ' 7rq k2m ', subjectRef: 'bsn-henk', now: new DateTimeImmutable('2026-10-09T09:00:00+00:00'));

		$this->assertTrue($opened['ok']);
		$this->assertSame(
			['reference' => $held['reference'], 'route' => 'woo', 'answers' => ['onderwerp' => 'Parkeerbeleid'], 'submittedAt' => $this->stored()['submittedAt'], 'deadline' => '2026-10-22T10:00:00+00:00'],
			$opened['submission']
		);
		$this->assertSame('awaiting-cosign', $this->stored()['state']);

	}//end testTheCoSignerSeesTheRequest()

	/**
	 * Henk co-signs: the submission is queued for delivery with his subject, time and assurance.
	 *
	 * @return void
	 */
	public function testHenkCoSigns(): void {
		$cosign = $this->cosign();
		$held = $this->held(cosign: $cosign);

		$signed = $cosign->sign(code: '7RQ-K2M', subjectRef: 'bsn-henk', levelOfAssurance: 'substantieel', now: new DateTimeImmutable('2026-10-10T12:00:00+00:00'));

		$this->assertSame(['ok' => true, 'reason' => '', 'reference' => $held['reference']], $signed);
		$row = $this->stored();
		$this->assertSame('queued', $row['state']);
		$this->assertSame('signed', $row['cosign']['state']);
		$this->assertSame('bsn-henk', $row['cosign']['cosignerSubject']);
		$this->assertSame('2026-10-10T12:00:00+00:00', $row['cosign']['signedAt']);
		$this->assertSame('substantieel', $row['cosign']['levelOfAssurance']);
		$this->assertSame('henk@example.nl', $row['cosign']['email']);

		$this->assertSame('unknown', $cosign->sign(code: '7RQ-K2M', subjectRef: 'bsn-henk', levelOfAssurance: 'substantieel', now: new DateTimeImmutable('2026-10-10T12:01:00+00:00'))['reason']);

	}//end testHenkCoSigns()

	/**
	 * The submitter cannot co-sign their own request.
	 *
	 * @return void
	 */
	public function testCoSigningYourOwnRequestIsRefused(): void {
		$cosign = $this->cosign();
		$this->held(cosign: $cosign);

		$now = new DateTimeImmutable('2026-10-10T12:00:00+00:00');
		$this->assertSame(['ok' => false, 'reason' => 'same-subject', 'reference' => ''], $cosign->sign(code: '7RQ-K2M', subjectRef: 'bsn-sanne', levelOfAssurance: 'substantieel', now: $now));
		$this->assertSame('same-subject', $cosign->open(code: '7RQ-K2M', subjectRef: 'bsn-sanne', now: $now)['reason']);
		$this->assertSame('no-subject', $cosign->open(code: '7RQ-K2M', subjectRef: ' ', now: $now)['reason']);
		$this->assertSame('awaiting-cosign', $this->stored()['state']);

	}//end testCoSigningYourOwnRequestIsRefused()

	/**
	 * A wrong or malformed code finds nothing.
	 *
	 * @return void
	 */
	public function testAWrongCodeFindsNothing(): void {
		$cosign = $this->cosign();
		$this->held(cosign: $cosign);
		$now = new DateTimeImmutable('2026-10-10T12:00:00+00:00');

		$this->assertSame('unknown', $cosign->open(code: 'AAA-AAA', subjectRef: 'bsn-henk', now: $now)['reason']);
		$this->assertSame('unknown', $cosign->open(code: '7RQ-K2', subjectRef: 'bsn-henk', now: $now)['reason']);
		$this->assertSame([], $cosign->open(code: '', subjectRef: 'bsn-henk', now: $now)['submission']);

	}//end testAWrongCodeFindsNothing()

	/**
	 * Refusing stops the submission for good.
	 *
	 * @return void
	 */
	public function testRefusingStopsTheDelivery(): void {
		$cosign = $this->cosign();
		$this->held(cosign: $cosign);

		$refused = $cosign->refuse(code: '7RQ-K2M', subjectRef: 'bsn-henk', now: new DateTimeImmutable('2026-10-11T08:00:00+00:00'));

		$this->assertTrue($refused['ok']);
		$row = $this->stored();
		$this->assertSame('not-cosigned', $row['state']);
		$this->assertSame('refused', $row['cosign']['state']);
		$this->assertSame('2026-10-11T08:00:00+00:00', $row['cosign']['refusedAt']);

	}//end testRefusingStopsTheDelivery()

	/**
	 * After the deadline the code no longer works, and the lapse is recorded.
	 *
	 * @return void
	 */
	public function testACodePastItsDeadlineLapses(): void {
		$cosign = $this->cosign();
		$this->held(cosign: $cosign);

		$late = $cosign->sign(code: '7RQ-K2M', subjectRef: 'bsn-henk', levelOfAssurance: 'substantieel', now: new DateTimeImmutable('2026-10-22T10:00:00+00:00'));

		$this->assertSame(['ok' => false, 'reason' => 'expired', 'reference' => ''], $late);
		$row = $this->stored();
		$this->assertSame('not-cosigned', $row['state']);
		$this->assertSame('expired', $row['cosign']['state']);

	}//end testACodePastItsDeadlineLapses()

	/**
	 * The deadline run lapses only what is past its deadline.
	 *
	 * @return void
	 */
	public function testTheDeadlinePasses(): void {
		$cosign = $this->cosign();
		$first = $this->held(cosign: $cosign, days: 14);
		$this->held(cosign: $cosign, days: 30);
		$this->seedRow('portalIntakeSubmission', ['reference' => 'AANVRAAG-BROKEN', 'state' => 'awaiting-cosign', 'cosign' => ['state' => 'awaiting', 'deadline' => 'whenever']]);
		$this->seedRow('portalIntakeSubmission', ['reference' => 'AANVRAAG-QUEUED', 'state' => 'queued', 'cosign' => ['deadline' => '2000-01-01T00:00:00+00:00']]);

		$lapsed = $cosign->expire(now: new DateTimeImmutable('2026-10-23T00:00:00+00:00'));

		$this->assertSame([$first['reference'], 'AANVRAAG-BROKEN'], $lapsed);
		$states = array_column($this->storedRows('portalIntakeSubmission'), 'state', 'reference');
		$this->assertSame('not-cosigned', $states[$first['reference']]);
		$this->assertSame('not-cosigned', $states['AANVRAAG-BROKEN']);
		$this->assertSame('queued', $states['AANVRAAG-QUEUED']);
		$this->assertSame(1, count(array_filter($states, static fn (string $state): bool => $state === 'awaiting-cosign')));

	}//end testTheDeadlinePasses()

	/**
	 * The status page says it waits for the co-signer, with name and deadline.
	 *
	 * @return void
	 */
	public function testTheStatusSaysItWaits(): void {
		$cosign = $this->cosign();
		$held = $this->held(cosign: $cosign);
		$queue = new PortalIntakeQueue($this->fakeReader(), $this->fakeWriter(), $this->createMock(ISecureRandom::class));

		$status = $queue->status(reference: $held['reference'], portal: 'gemeente-x');

		$this->assertSame('awaiting-cosign', $status['state']);
		$this->assertSame(['state' => 'awaiting', 'name' => 'Henk de Vries', 'deadline' => '2026-10-22T10:00:00+00:00'], $status['cosign']);

		$queue->accept(portal: 'gemeente-x', route: 'melding', answers: []);
		$plain = $this->storedRows('portalIntakeSubmission')[1];
		$this->assertArrayNotHasKey('cosign', $queue->status(reference: $plain['reference'], portal: 'gemeente-x'));

	}//end testTheStatusSaysItWaits()
}//end class
