<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Service\PortalSchemaReader;
use PHPUnit\Framework\TestCase;

/**
 * The schema shapes a field's input (site-reaches-portal-parity, slice c):
 * a date property gets a date input and an enum a select. Driven through
 * PortalManifestNormaliser, the one entry point the registry uses.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */
class SchemaInputHintNormaliserTest extends TestCase {
	/**
	 * The guardian's absence report, as learniq declares it.
	 *
	 * @param array<string, mixed> $extra Keys to add to the action.
	 *
	 * @return array<string, mixed> The normalised action.
	 */
	private function absenceAction(array $extra = []): array {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->with('excuse-request')->willReturn(
			[
				'required'   => ['dateFrom', 'reasonKind'],
				'properties' => [
					'dateFrom'      => ['type' => 'string', 'format' => 'date'],
					'reasonKind'    => ['type' => 'string', 'enum' => ['illness', 'medical-appointment', null, ['x']]],
					'reason'        => ['type' => 'string'],
					'hours'         => ['type' => 'integer'],
					'attachmentRef' => ['type' => 'string', 'format' => 'date'],
					'secret'        => ['type' => 'string', 'enum' => ['a', 'b']],
				],
			]
		);

		$action = array_merge(
			[
				'id'           => 'createExcuseRequest',
				'type'         => 'create',
				'schema'       => 'excuse-request',
				'fields'       => ['dateFrom', 'reasonKind', 'reason', 'hours', 'attachmentRef'],
				'fieldConfigs' => [
					'dateFrom'      => ['label' => 'First day absent', 'required' => true],
					'reasonKind'    => ['label' => 'Kind of absence', 'required' => true],
					'attachmentRef' => ['label' => 'Attachment', 'type' => 'file'],
				],
			],
			$extra
		);

		$out = (new PortalManifestNormaliser($schemaReader))->normalise(['collections' => [], 'actions' => [$action]]);
		return $out['actions'][0];
	}//end absenceAction()

	/**
	 * A date property becomes a date input and an integer a number input;
	 * the manifest's own keys stay.
	 *
	 * @return void
	 */
	public function testADateAndANumberGetTheirInput(): void {
		$action = $this->absenceAction();

		$this->assertSame('date', $action['fieldConfigs']['dateFrom']['input']);
		$this->assertSame('First day absent', $action['fieldConfigs']['dateFrom']['label']);
		$this->assertTrue($action['fieldConfigs']['dateFrom']['required']);
		$this->assertSame('number', $action['fieldConfigs']['hours']['input']);
		$this->assertArrayNotHasKey('reason', $action['fieldConfigs']);
	}//end testADateAndANumberGetTheirInput()

	/**
	 * An enum becomes a static options provider with readable labels; values
	 * that are not scalars are dropped.
	 *
	 * @return void
	 */
	public function testAnEnumBecomesASelect(): void {
		$action = $this->absenceAction();

		$this->assertSame(
			[
				'type'    => 'static',
				'options' => [
					['value' => 'illness', 'label' => 'Illness'],
					['value' => 'medical-appointment', 'label' => 'Medical appointment'],
				],
			],
			$action['optionsProviders']['reasonKind']
		);
	}//end testAnEnumBecomesASelect()

	/**
	 * The manifest wins: its own options provider stays, a file field gets no
	 * input hint, and a field outside the whitelist is never touched.
	 *
	 * @return void
	 */
	public function testTheManifestWinsAndTheWhitelistHolds(): void {
		$action = $this->absenceAction(
			[
				'optionsProviders' => [
					'reasonKind' => ['type' => 'static', 'options' => [['value' => 'illness', 'label' => 'Ziek']]],
				],
			]
		);

		$this->assertSame([['value' => 'illness', 'label' => 'Ziek']], $action['optionsProviders']['reasonKind']['options']);
		$this->assertArrayNotHasKey('input', $action['fieldConfigs']['attachmentRef']);
		$this->assertArrayNotHasKey('secret', $action['optionsProviders']);
		$this->assertArrayNotHasKey('secret', $action['fieldConfigs']);
	}//end testTheManifestWinsAndTheWhitelistHolds()

	/**
	 * A `oneOf` list of `const` and `title` labels the options itself.
	 *
	 * @return void
	 */
	public function testOneOfTitlesLabelTheOptions(): void {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->willReturn(
			['properties' => ['kind' => ['type' => 'string', 'oneOf' => [['const' => 'ill', 'title' => 'Ziek'], ['const' => 'other', 'title' => 'Anders']]]]]
		);

		$out = (new PortalManifestNormaliser($schemaReader))->normalise(
			['collections' => [], 'actions' => [['id' => 'a', 'type' => 'create', 'schema' => 's', 'fields' => ['kind']]]]
		);

		$this->assertSame(
			[['value' => 'ill', 'label' => 'Ziek'], ['value' => 'other', 'label' => 'Anders']],
			$out['actions'][0]['optionsProviders']['kind']['options']
		);
	}//end testOneOfTitlesLabelTheOptions()

	/**
	 * Without a readable schema the action is unchanged.
	 *
	 * @return void
	 */
	public function testNoSchemaChangesNothing(): void {
		$out = (new PortalManifestNormaliser())->normalise(
			['collections' => [], 'actions' => [['id' => 'a', 'type' => 'create', 'schema' => 's', 'fields' => ['kind']]]]
		);

		$this->assertArrayNotHasKey('optionsProviders', $out['actions'][0]);
		$this->assertArrayNotHasKey('fieldConfigs', $out['actions'][0]);
	}//end testNoSchemaChangesNothing()
}//end class
