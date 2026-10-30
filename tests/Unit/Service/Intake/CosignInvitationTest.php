<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\CosignInvitation;
use OCA\Portaliq\Service\Intake\CosignInviteMailer;
use OCA\Portaliq\Service\Intake\CosignOnSubmit;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Service\Intake\CosignInvitation
 * @covers \OCA\Portaliq\Service\Intake\CosignOnSubmit
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class CosignInvitationTest extends TestCase {
	/**
	 * 22 October 2026, noon in Amsterdam.
	 */
	private const NOW = 1792663200;

	/**
	 * A binding that needs a co-signer.
	 *
	 * @var array<string, mixed>
	 */
	private const BINDING = [
		'portal' => 'zuiddrecht',
		'route' => 'woo-verzoek',
		'formName' => 'Woo-verzoek',
		'cosign' => ['required' => true, 'deadlineDays' => 14, 'nameField' => 'cosignerName', 'emailField' => 'cosignerEmail', 'title' => 'mede-ondertekening'],
	];

	/**
	 * The invitation with a fake instance secret.
	 *
	 * @return CosignInvitation
	 */
	private function invitation(): CosignInvitation {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $message): string => hash_hmac('sha256', $message, 'secret'));

		return new CosignInvitation(crypto: $crypto);
	}//end invitation()

	/**
	 * A form without co-signing has no settings; one with it gets defaults.
	 *
	 * @return void
	 */
	public function testSettingsAreReadFromTheBinding(): void {
		self::assertNull($this->invitation()->settings(binding: ['cosign' => ['required' => false]]));
		self::assertNull($this->invitation()->settings(binding: []));

		$settings = $this->invitation()->settings(binding: ['cosign' => ['required' => true]]);
		self::assertSame('cosign', $settings['kind']);
		self::assertSame(14, $settings['days']);
	}//end testSettingsAreReadFromTheBinding()

	/**
	 * The entry names the co-signer and the due date; a missing address names its field.
	 *
	 * @return void
	 */
	public function testTheEntryNamesTheCosignerAndTheDueDate(): void {
		$invitation = $this->invitation();
		$settings   = (array)$invitation->settings(binding: self::BINDING);

		$entry = $invitation->entry(settings: $settings, answers: ['cosignerName' => 'Henk de Vries', 'cosignerEmail' => 'henk@example.nl'], now: self::NOW);
		self::assertSame(
			['kind' => 'cosign', 'title' => 'mede-ondertekening', 'holderName' => 'Henk de Vries', 'holderEmail' => 'henk@example.nl', 'dueAt' => '2026-11-05', 'state' => 'awaiting'],
			$entry
		);

		self::assertSame('cosignerEmail', $invitation->entry(settings: $settings, answers: ['cosignerName' => 'Henk', 'cosignerEmail' => 'not-an-address'], now: self::NOW));
		self::assertSame('cosignerName', $invitation->entry(settings: $settings, answers: ['cosignerEmail' => 'henk@example.nl'], now: self::NOW));
	}//end testTheEntryNamesTheCosignerAndTheDueDate()

	/**
	 * A token reads back; a changed one does not; it carries no subject reference.
	 *
	 * @return void
	 */
	public function testATokenIsSignedAndCarriesNoSubject(): void {
		$invitation = $this->invitation();
		$token      = $invitation->token(claims: ['case' => 'case-1', 'kind' => 'cosign', 'dueAt' => '2026-11-05', 'applicant' => 'subject-sanne']);

		$claims = $invitation->read(token: $token);
		self::assertSame('case-1', $claims['case']);
		self::assertStringNotContainsString('subject-sanne', (string)base64_decode(strtr(explode('.', $token)[0], '-_', '+/')));
		self::assertTrue($invitation->isApplicant(claims: $claims, subjectRef: 'subject-sanne'));
		self::assertFalse($invitation->isApplicant(claims: $claims, subjectRef: 'subject-henk'));

		[$payload, $signature] = explode('.', $token);
		$forged = rtrim(strtr(base64_encode((string)json_encode(['case' => 'case-2'])), '+/', '-_'), '=');
		self::assertNull($invitation->read(token: $forged.'.'.$signature));
		self::assertNull($invitation->read(token: $payload));
	}//end testATokenIsSignedAndCarriesNoSubject()

	/**
	 * The last day still counts; the day after does not.
	 *
	 * @return void
	 */
	public function testTheLastDayStillCounts(): void {
		$invitation = $this->invitation();
		self::assertFalse($invitation->isPast(claims: ['dueAt' => '2026-10-22'], now: self::NOW));
		self::assertTrue($invitation->isPast(claims: ['dueAt' => '2026-10-21'], now: self::NOW));
	}//end testTheLastDayStillCounts()

	/**
	 * The submit creates the case with the entry, then mails the link to the co-signer.
	 *
	 * @return void
	 */
	public function testTheSubmitCreatesTheCaseWaitingAndInvites(): void {
		$mailer = $this->createMock(CosignInviteMailer::class);
		$links  = $this->createMock(PortalDeepLinkBuilder::class);
		$links->method('forSite')->willReturn('https://zuiddrecht.nl/');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$onSubmit = new CosignOnSubmit(invitation: $this->invitation(), mailer: $mailer, links: $links, l10n: $l10n);

		self::assertSame(['server' => [], 'errors' => []], $onSubmit->prepare(binding: [], answers: []));
		self::assertArrayHasKey('cosignerEmail', $onSubmit->prepare(binding: self::BINDING, answers: ['cosignerName' => 'Henk'])['errors']);

		$prepared = $onSubmit->prepare(binding: self::BINDING, answers: ['cosignerName' => 'Henk de Vries', 'cosignerEmail' => 'henk@example.nl']);
		self::assertSame('awaiting', $prepared['server']['intakeObligations'][0]['state']);

		$mailer->expects(self::once())->method('send')->with(
			self::callback(static fn (array $invite): bool => $invite['email'] === 'henk@example.nl' && str_starts_with($invite['link'], 'https://zuiddrecht.nl/#cosign=')),
			self::anything()
		)->willReturn(true);

		$status = $onSubmit->invite(site: ['slug' => 'zuiddrecht'], binding: self::BINDING, server: $prepared['server'], answer: ['id' => 'case-1', 'reference' => 'ZK-1'], subjectRef: 'subject-sanne');
		self::assertSame('Henk de Vries', $status['holderName']);
		self::assertTrue($status['invited']);
		self::assertNull($onSubmit->invite(site: [], binding: [], server: [], answer: [], subjectRef: ''));
	}//end testTheSubmitCreatesTheCaseWaitingAndInvites()
}//end class
