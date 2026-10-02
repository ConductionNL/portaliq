<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * A collection may say how the values of any of its fields read, through
 * `fieldConfigs.<field>.valueLabels` and `.label`, so a detail field that is
 * no column reads in words too (resident-sees-words-not-codes).
 *
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-say-how-the-values-of-any-of-its-fields-read
 */
class CollectionFieldConfigsTest extends TestCase {
	/**
	 * Normalise one collection with the given fieldConfigs.
	 *
	 * @param mixed                   $fieldConfigs The declared fieldConfigs.
	 * @param array<int, string>|null $fields       The projected fields, or null for none.
	 *
	 * @return array<string, mixed> The normalised collection.
	 */
	private function collection(mixed $fieldConfigs, ?array $fields = null): array {
		$collection = ['id' => 'klachten', 'schema' => 'complaint', 'fieldConfigs' => $fieldConfigs];
		if ($fields !== null) {
			$collection['fields'] = $fields;
		}

		return (new PortalManifestNormaliser())->normalise(['collections' => [$collection], 'actions' => []])['collections'][0];
	}//end collection()

	/**
	 * A well-formed label and value labels are kept; every other key goes.
	 *
	 * @return void
	 */
	public function testAFieldKeepsItsLabelAndValueLabels(): void {
		$out = $this->collection(
			fieldConfigs: [
				'complaintCategory' => ['label' => 'Soort klacht', 'valueLabels' => ['service' => 'Dienstverlening'], 'required' => true],
				'status'            => ['valueLabels' => ['in_progress' => 'In behandeling']],
			]
		);

		$this->assertSame(
			[
				'complaintCategory' => ['label' => 'Soort klacht', 'valueLabels' => ['service' => 'Dienstverlening']],
				'status'            => ['valueLabels' => ['in_progress' => 'In behandeling']],
			],
			$out['fieldConfigs']
		);
	}//end testAFieldKeepsItsLabelAndValueLabels()

	/**
	 * A malformed map, a field the collection does not project and an entry
	 * with nothing usable are dropped; nothing left drops the key.
	 *
	 * @return void
	 */
	public function testFieldConfigsAreFailClosed(): void {
		$out = $this->collection(
			fieldConfigs: [
				'status'  => ['valueLabels' => ['in_progress' => 'In behandeling'], 'label' => 7],
				'secret'  => ['label' => 'Geheim'],
				'empty'   => ['label' => '  ', 'valueLabels' => 'nope'],
				'notAMap' => 'x',
				3         => ['label' => 'Drie'],
			],
			fields: ['status', 'empty', 'notAMap']
		);
		$this->assertSame(['status' => ['valueLabels' => ['in_progress' => 'In behandeling']]], $out['fieldConfigs']);

		$this->assertArrayNotHasKey('fieldConfigs', $this->collection(fieldConfigs: 'nope'));
		$this->assertArrayNotHasKey('fieldConfigs', $this->collection(fieldConfigs: ['status' => ['label' => '']]));
	}//end testFieldConfigsAreFailClosed()
}//end class
