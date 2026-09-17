<?php

/**
 * Tests for the writable set a citizen sees on their own case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\CitizenWriteConfigNormaliser;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\CitizenWritableSetResolver;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The portal keeps no writable list: everything here is read from the case
 * type, narrowed by the action's own whitelist, and everything fails closed.
 *
 * @covers \OCA\Portaliq\Service\CitizenWritableSetResolver
 * @uses   \OCA\Portaliq\Contribution\CitizenWriteConfigNormaliser
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenWritableSetResolverTest extends TestCase {
	private const CASE_ROW = [
		'omschrijving' => 'een dakkapel',
		'toelichting' => 'aan de achterzijde',
		'referentie' => 'Z-2026-1',
		'status' => 'ontvangen',
		'zaaktype' => 'type-1',
	];

	private const ACTION = [
		'id' => 'amend-request',
		'type' => 'update',
		'register' => 'zaken',
		'schema' => 'zaak',
		'fields' => ['omschrijving', 'toelichting'],
		'citizenWrite' => [
			'typeField' => 'zaaktype',
			'typeRegister' => 'zaken',
			'typeSchema' => 'zaaktype',
			'statusField' => 'status',
			'recordField' => 'portalWrites',
			'documentsField' => 'portalDocuments',
		],
	];

	/**
	 * Two fields flagged writable for the client audience are the only two
	 * that come back writable; everything else on the case reads closed.
	 */
	public function testOnlyTheDeclaredFieldsAreWritable(): void {
		$set = $this->resolve(caseType: $this->caseType());

		$this->assertSame(['omschrijving', 'toelichting'], $set['writable']);
		$this->assertTrue($set['fields']['omschrijving']['writable']);
		$this->assertTrue($set['fields']['toelichting']['writable']);
		// `referentie` carries no flag at all, so it is not in the set.
		$this->assertArrayNotHasKey('referentie', $set['fields']);
		$this->assertFalse($this->resolver()->isWritable(set: $set, field: 'referentie'));
	}//end testOnlyTheDeclaredFieldsAreWritable()

	/**
	 * A flag for another audience is not a flag for this one. The supplier
	 * entry never reaches a client session's writable set.
	 */
	public function testAFlagForAnotherAudienceIsNotWritable(): void {
		$caseType = $this->caseType();
		$caseType['portalWritable'][] = [
			'field' => 'toelichting',
			'audiences' => ['supplier'],
		];
		// The later entry wins for the same field, which is what makes this a
		// real test: the supplier-only flag must close it, not be ignored.
		$set = $this->resolve(caseType: $caseType);

		$this->assertSame(['omschrijving'], $set['writable']);
		$this->assertFalse($set['fields']['toelichting']['writable']);
		$this->assertNotSame('', $set['fields']['toelichting']['reason']);
	}//end testAFlagForAnotherAudienceIsNotWritable()

	/**
	 * A field the case type flags but the action does not whitelist stays out
	 * of the set: a case type can never widen what the contribution granted.
	 */
	public function testTheCaseTypeCannotWidenTheActionsWhitelist(): void {
		$caseType = $this->caseType();
		$caseType['portalWritable'][] = ['field' => 'status', 'audiences' => ['client']];

		$set = $this->resolve(caseType: $caseType);

		$this->assertNotContains('status', $set['writable']);
		$this->assertArrayNotHasKey('status', $set['fields']);
	}//end testTheCaseTypeCannotWidenTheActionsWhitelist()

	/**
	 * The window closes everything at once, with the case type's own sentence,
	 * even for a field whose own flag would still be open.
	 */
	public function testAClosedWindowClosesEveryFieldWithItsReason(): void {
		$caseType = $this->caseType();
		$caseType['portalAmendmentWindow'] = [
			'openStatuses' => ['ontvangen'],
			'closedReason' => 'De aanvraag is in behandeling genomen.',
		];
		$case = self::CASE_ROW;
		$case['status'] = 'in_behandeling';

		$set = $this->resolve(caseType: $caseType, case: $case);

		$this->assertSame([], $set['writable']);
		$this->assertFalse($set['window']['open']);
		$this->assertSame('De aanvraag is in behandeling genomen.', $set['window']['reason']);
		$this->assertSame('De aanvraag is in behandeling genomen.', $set['fields']['omschrijving']['reason']);
	}//end testAClosedWindowClosesEveryFieldWithItsReason()

	/**
	 * A field may close before the window does, and it says so in its own
	 * words rather than the window's.
	 */
	public function testAFieldClosesWithItsOwnSentence(): void {
		$caseType = $this->caseType();
		$caseType['portalWritable'][1] = [
			'field' => 'toelichting',
			'audiences' => ['client'],
			'openStatuses' => ['concept'],
			'closedReason' => 'De toelichting hoort bij de indiening en staat vast.',
		];

		$set = $this->resolve(caseType: $caseType);

		$this->assertSame(['omschrijving'], $set['writable']);
		$this->assertSame(
			'De toelichting hoort bij de indiening en staat vast.',
			$set['fields']['toelichting']['reason']
		);
	}//end testAFieldClosesWithItsOwnSentence()

	/**
	 * Every way the declaration can be missing fails closed: no case type, no
	 * `citizenWrite` on the action, and a malformed flag list.
	 */
	public function testEveryMissingDeclarationFailsClosed(): void {
		$withoutType = $this->resolve(caseType: null);
		$this->assertSame([], $withoutType['writable']);
		$this->assertFalse($withoutType['window']['open']);
		$this->assertFalse($withoutType['documents']['open']);
		$this->assertNotSame('', $withoutType['window']['reason']);

		$action = self::ACTION;
		unset($action['citizenWrite']);
		$withoutDeclaration = $this->resolve(caseType: $this->caseType(), action: $action);
		$this->assertSame([], $withoutDeclaration['writable']);
		$this->assertFalse($withoutDeclaration['window']['open']);

		$malformed = $this->caseType();
		$malformed['portalWritable'] = 'alles';
		$malformed['portalAmendmentWindow'] = 'altijd';
		$set = $this->resolve(caseType: $malformed);
		$this->assertSame([], $set['fields']);
		$this->assertFalse($set['window']['open']);
	}//end testEveryMissingDeclarationFailsClosed()

	/**
	 * Documents have their own window, so a case can stay open for an
	 * aanvulling after the answers themselves are fixed.
	 */
	public function testDocumentsHaveTheirOwnWindow(): void {
		$caseType = $this->caseType();
		$caseType['portalAmendmentWindow'] = ['openStatuses' => ['concept'], 'closedReason' => 'Vast.'];
		$caseType['portalDocumentWindow'] = ['openStatuses' => ['ontvangen'], 'closedReason' => 'Gesloten.'];

		$set = $this->resolve(caseType: $caseType);

		$this->assertFalse($set['window']['open']);
		$this->assertTrue($set['documents']['open']);
	}//end testDocumentsHaveTheirOwnWindow()

	/**
	 * The public status label is rendered exactly as the case app supplied it,
	 * and an unlabelled status yields nothing rather than a portal-invented
	 * word.
	 */
	public function testTheStatusLabelIsTheCaseAppsOwnOrNothing(): void {
		$set = $this->resolve(caseType: $this->caseType());
		$this->assertSame('ontvangen', $set['status']['value']);
		$this->assertSame('Wij hebben uw aanvraag ontvangen', $set['status']['label']);
		$this->assertSame('U hoort binnen acht weken van ons.', $set['status']['description']);

		$caseType = $this->caseType();
		unset($caseType['portalStatusLabels']);
		$unlabelled = $this->resolve(caseType: $caseType);
		$this->assertSame('ontvangen', $unlabelled['status']['value']);
		$this->assertSame('', $unlabelled['status']['label']);
		$this->assertSame('', $unlabelled['status']['description']);
	}//end testTheStatusLabelIsTheCaseAppsOwnOrNothing()

	/**
	 * A case type dossiq could ship today.
	 *
	 * @return array<string, mixed>
	 */
	private function caseType(): array {
		return [
			'id' => 'type-1',
			CitizenWritableSetResolver::WRITABLE_PROPERTY => [
				['field' => 'omschrijving', 'audiences' => ['client']],
				['field' => 'toelichting', 'audiences' => ['client']],
			],
			CitizenWritableSetResolver::WINDOW_PROPERTY => [
				'openStatuses' => ['ontvangen', 'aanvullen'],
				'closedReason' => 'De aanvraag is in behandeling genomen.',
			],
			CitizenWritableSetResolver::DOCUMENTS_PROPERTY => [
				'openStatuses' => ['ontvangen', 'aanvullen'],
				'closedReason' => 'De zaak neemt geen stukken meer aan.',
			],
			CitizenWritableSetResolver::STATUS_LABELS_PROPERTY => [
				'ontvangen' => [
					'label' => 'Wij hebben uw aanvraag ontvangen',
					'description' => 'U hoort binnen acht weken van ons.',
				],
			],
		];
	}//end caseType()

	/**
	 * Resolve one set against a fake case type reader.
	 *
	 * @param array<string, mixed>|null $caseType The case type the fake answers with.
	 * @param array<string, mixed>|null $case The case row.
	 * @param array<string, mixed>|null $action The matched action.
	 *
	 * @return array<string, mixed>
	 */
	private function resolve(?array $caseType, ?array $case = null, ?array $action = null): array {
		return $this->resolver(caseType: $caseType)->resolve(
			action: ($action ?? self::ACTION),
			case: ($case ?? self::CASE_ROW),
			audience: 'client'
		);
	}//end resolve()

	/**
	 * A resolver over a fake case type reader, because dossiq's declaration
	 * does not exist yet and the contract is what is under test.
	 *
	 * @param array<string, mixed>|null $caseType The case type the fake answers with.
	 */
	private function resolver(?array $caseType = null): CitizenWritableSetResolver {
		$reader = $this->createMock(CaseTypeReader::class);
		$reader->method('readCaseType')->willReturn($caseType);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		return new CitizenWritableSetResolver($reader, $l10n);
	}//end resolver()

	/**
	 * The declaration the resolver reads is the one the normaliser produced,
	 * not a shape invented by this test.
	 */
	public function testTheDeclarationUnderTestIsTheSanitisedOne(): void {
		$normalised = (new CitizenWriteConfigNormaliser())->normaliseAction(action: self::ACTION);

		$this->assertSame(self::ACTION['citizenWrite'], $normalised['citizenWrite']);
	}//end testTheDeclarationUnderTestIsTheSanitisedOne()
}//end class
