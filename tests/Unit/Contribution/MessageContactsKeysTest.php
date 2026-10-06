<?php

/**
 * A collection's `contacts` key survives the manifest normaliser in shape,
 * and a provider name portaliq may not call drops it (site-messages-per-record).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-name-who-a-resident-may-write-to-about-each-row
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-name-who-a-resident-may-write-to-about-each-row
 */
class MessageContactsKeysTest extends TestCase {

	/**
	 * One collection through the real manifest normaliser.
	 *
	 * @param mixed $contacts The declared key.
	 *
	 * @return array<string, mixed>
	 */
	private function collection(mixed $contacts): array {
		$out = (new PortalManifestNormaliser())->normalise(
			['collections' => [['id' => 'inschrijvingen', 'register' => 'learniq', 'schema' => 'enrolment', 'fields' => ['learnerName', 'cohortName'], 'contacts' => $contacts]]]
		);

		return $out['collections'][0];
	}

	public function testTheKeyKeepsItsProviderProjectedLabelFieldsAndWords(): void {
		$collection = $this->collection(
			['provider' => 'messageContactsFor', 'recordLabelFields' => ['learnerName', 'secret', 'cohortName'], 'composeLabel' => ' Een bericht aan de leerkracht ', 'composeHint' => '', 'extra' => 'x']
		);

		$this->assertSame(
			['provider' => 'messageContactsFor', 'recordLabelFields' => ['learnerName', 'cohortName'], 'composeLabel' => 'Een bericht aan de leerkracht'],
			$collection['contacts'],
			'a field the collection does not project, an empty word and an unknown key are dropped'
		);
	}

	public function testAProviderPortaliqMayNotCallDropsTheKey(): void {
		foreach (['getContribution', 'Messages', 'a-b', '', null] as $provider) {
			$this->assertArrayNotHasKey('contacts', $this->collection(['provider' => $provider]));
		}

		$this->assertArrayNotHasKey('contacts', $this->collection('messageContactsFor'));
	}
}
