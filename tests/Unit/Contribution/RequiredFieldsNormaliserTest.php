<?php

/**
 * RequiredFieldsNormaliser through the real manifest normaliser
 * (site-multi-step-forms REQ-SMF-023): which fields of an action a resident
 * must fill in.
 *
 * The scenarios are the real ones: learniq's two booking forms on the
 * `conference-signup` schema, which requires nothing, and dossiq's Woo
 * request, an endpoint action without a schema.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
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

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Service\PortalSchemaReader;
use PHPUnit\Framework\TestCase;

/**
 * The effective required set of an action.
 */
class RequiredFieldsNormaliserTest extends TestCase {
	/**
	 * learniq `bookConferenceSlot`: the schema requires nothing, the action
	 * names learnerRef and slotId; notes stays optional.
	 *
	 * @return void
	 */
	public function testBookConferenceSlotRequiresTheChildAndTheTime(): void {
		$action = $this->normalised(
			action: [
				'id'             => 'bookConferenceSlot',
				'type'           => 'create',
				'schema'         => 'conference-signup',
				'fields'         => ['learnerRef', 'slotId', 'notes'],
				'requiredFields' => ['learnerRef', 'slotId'],
				'fieldConfigs'   => [
					'learnerRef' => ['label' => 'Child'],
					'notes'      => ['label' => 'Anything the teacher should know beforehand'],
				],
			],
			schema: ['required' => [], 'properties' => []]
		);

		$this->assertSame(['learnerRef', 'slotId'], $action['requiredFields']);
		$this->assertTrue($action['fieldConfigs']['learnerRef']['required']);
		$this->assertSame('Child', $action['fieldConfigs']['learnerRef']['label']);
		$this->assertTrue($action['fieldConfigs']['slotId']['required'], 'a field without a config gets one');
		$this->assertArrayNotHasKey('required', $action['fieldConfigs']['notes']);
	}//end testBookConferenceSlotRequiresTheChildAndTheTime()

	/**
	 * learniq `createConferenceSignup`: conferenceRoundId and learnerRef.
	 *
	 * @return void
	 */
	public function testCreateConferenceSignupRequiresTheRoundAndTheChild(): void {
		$action = $this->normalised(
			action: [
				'id'             => 'createConferenceSignup',
				'type'           => 'create',
				'schema'         => 'conference-signup',
				'fields'         => ['conferenceRoundId', 'learnerRef', 'notes'],
				'requiredFields' => ['conferenceRoundId', 'learnerRef'],
			],
			schema: ['required' => [], 'properties' => []]
		);

		$this->assertTrue($action['fieldConfigs']['conferenceRoundId']['required']);
		$this->assertTrue($action['fieldConfigs']['learnerRef']['required']);
		$this->assertArrayNotHasKey('notes', $action['fieldConfigs']);
	}//end testCreateConferenceSignupRequiresTheRoundAndTheChild()

	/**
	 * dossiq's Woo request: an endpoint action without a schema names its
	 * subject plus the five fields of DossiqWoo.dc.html.
	 *
	 * @return void
	 */
	public function testTheWooRequestNamesItsFiveFields(): void {
		$five   = ['omschrijving', 'periodeVan', 'documentSoorten', 'verzoekerNaam', 'verzoekerEmail'];
		$action = $this->normalised(
			action: [
				'id'             => 'startWooVerzoekAlgemeen',
				'endpoint'       => '/index.php/apps/dossiq/api/portal/woo-verzoek',
				'method'         => 'POST',
				'fields'         => ['onderwerp', 'omschrijving', 'periodeVan', 'periodeTot', 'documentSoorten', 'toelichting', 'verzoekerNaam', 'verzoekerEmail', 'verzoekerType'],
				'requiredFields' => array_merge(['onderwerp'], $five),
				'fieldConfigs'   => ['periodeTot' => ['required' => true]],
			],
			schema: null
		);

		foreach (array_merge(['onderwerp'], $five) as $field) {
			$this->assertTrue($action['fieldConfigs'][$field]['required'], $field);
		}

		$this->assertArrayNotHasKey('required', $action['fieldConfigs']['periodeTot'], 'config alone still requires nothing');
		$this->assertArrayNotHasKey('toelichting', $action['fieldConfigs']);
	}//end testTheWooRequestNamesItsFiveFields()

	/**
	 * A name outside `fields`, a file field, a server-filled field and a
	 * non-string are dropped: an action can never require what it does not
	 * ask the resident for.
	 *
	 * @return void
	 */
	public function testOnlyTheActionsOwnAskedFieldsCanBeRequired(): void {
		$action = $this->normalised(
			action: [
				'id'             => 'createExcuseRequest',
				'type'           => 'create',
				'schema'         => 'excuse-request',
				'fields'         => ['reason', 'attachmentRef', 'source', 'learnerRef'],
				'defaults'       => ['source' => 'portal'],
				'subjectField'   => 'learnerRef',
				'requiredFields' => ['bsn', 'attachmentRef', 'source', 'learnerRef', 7, 'reason', 'reason'],
				'fieldConfigs'   => ['attachmentRef' => ['type' => 'file']],
			],
			schema: ['required' => [], 'properties' => []]
		);

		$this->assertSame(['reason'], $action['requiredFields']);
		$this->assertTrue($action['fieldConfigs']['reason']['required']);
		$this->assertArrayNotHasKey('required', $action['fieldConfigs']['attachmentRef']);
		$this->assertArrayNotHasKey('source', $action['fieldConfigs']);
		$this->assertArrayNotHasKey('learnerRef', $action['fieldConfigs']);
	}//end testOnlyTheActionsOwnAskedFieldsCanBeRequired()

	/**
	 * A schema-required field on a create reads required even when the
	 * action leaves it out of `requiredFields` or says `required: false`.
	 *
	 * @return void
	 */
	public function testASchemaRequiredFieldCanNeverBeOptional(): void {
		$action = $this->normalised(
			action: [
				'id'           => 'createExcuseRequest',
				'type'         => 'create',
				'schema'       => 'excuse-request',
				'fields'       => ['dateFrom', 'reason'],
				'fieldConfigs' => ['dateFrom' => ['required' => false]],
			],
			schema: ['required' => ['dateFrom', 'learnerRef'], 'properties' => []]
		);

		$this->assertTrue($action['fieldConfigs']['dateFrom']['required']);
		$this->assertArrayNotHasKey('reason', $action['fieldConfigs']);
		$this->assertArrayNotHasKey('requiredFields', $action);
	}//end testASchemaRequiredFieldCanNeverBeOptional()

	/**
	 * An update action does not ask for every schema-required field again
	 * (it changes a few), but may name its own required fields.
	 *
	 * @return void
	 */
	public function testAnUpdateMarksOnlyItsNamedFields(): void {
		$action = $this->normalised(
			action: [
				'id'             => 'amendCase',
				'type'           => 'update',
				'schema'         => 'case',
				'fields'         => ['description', 'title'],
				'requiredFields' => ['description'],
			],
			schema: ['required' => ['title'], 'properties' => []]
		);

		$this->assertTrue($action['fieldConfigs']['description']['required']);
		$this->assertArrayNotHasKey('title', $action['fieldConfigs']);
	}//end testAnUpdateMarksOnlyItsNamedFields()

	/**
	 * A malformed `requiredFields` is dropped whole.
	 *
	 * @return void
	 */
	public function testAMalformedListIsDropped(): void {
		$action = $this->normalised(
			action: [
				'id'             => 'x',
				'endpoint'       => '/index.php/apps/dossiq/api/x',
				'method'         => 'POST',
				'fields'         => ['onderwerp'],
				'requiredFields' => 'onderwerp',
			],
			schema: null
		);

		$this->assertArrayNotHasKey('requiredFields', $action);
		$this->assertArrayNotHasKey('fieldConfigs', $action);
	}//end testAMalformedListIsDropped()

	/**
	 * One action, through the real manifest normaliser.
	 *
	 * @param array<string, mixed> $action The declared action.
	 * @param array<string, mixed>|null $schema What the schema reader answers.
	 *
	 * @return array<string, mixed> The normalised action.
	 */
	private function normalised(array $action, ?array $schema): array {
		$reader = $this->createMock(PortalSchemaReader::class);
		$reader->method('readSchema')->willReturn($schema);
		$out = (new PortalManifestNormaliser($reader))->normalise(
			['collections' => [], 'actions' => [array_merge(['register' => 'learniq'], $action)]]
		);

		return $out['actions'][0];
	}//end normalised()
}//end class
