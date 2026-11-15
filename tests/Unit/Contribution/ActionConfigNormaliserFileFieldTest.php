<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The file half of an action's field config (assignment-portal-file-upload).
 * Driven through PortalManifestNormaliser, the one entry point the registry
 * uses, so the test sees exactly what the portal SPA and the upload endpoint
 * will read.
 *
 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-an-action-must-be-able-to-declare-a-file-field
 */
class ActionConfigNormaliserFileFieldTest extends TestCase {
	/**
	 * A sound file field keeps every key, with `accept` lowercased.
	 *
	 * @return void
	 */
	public function testASoundFileFieldIsKept(): void {
		$config = $this->normalisedConfig(
			type: 'create',
			config: ['type' => 'file', 'label' => 'Your work', 'multiple' => true, 'accept' => ['.PDF', 'image/*'], 'maxSizeMb' => 20]
		);

		$this->assertSame('file', $config['type']);
		$this->assertTrue($config['multiple']);
		$this->assertSame(['.pdf', 'image/*'], $config['accept']);
		$this->assertSame(20, $config['maxSizeMb']);
		$this->assertSame('Your work', $config['label']);
	}//end testASoundFileFieldIsKept()

	/**
	 * `multiple` defaults to false and an update action may carry a file field.
	 *
	 * @return void
	 */
	public function testMultipleDefaultsToFalseAndUpdateActionsQualify(): void {
		$config = $this->normalisedConfig(type: 'update', config: ['type' => 'file']);

		$this->assertSame('file', $config['type']);
		$this->assertFalse($config['multiple']);
		$this->assertArrayNotHasKey('accept', $config);
		$this->assertArrayNotHasKey('maxSizeMb', $config);
	}//end testMultipleDefaultsToFalseAndUpdateActionsQualify()

	/**
	 * Malformed `accept` entries are dropped and an oversized limit is clamped;
	 * an unknown type is not a type at all.
	 *
	 * @return void
	 */
	public function testMalformedFileKeysAreDroppedOrClamped(): void {
		$file = $this->normalisedConfig(
			type: 'create',
			config: ['type' => 'file', 'accept' => ['pdf', '<script>', 7, 'image/*;x'], 'maxSizeMb' => 900]
		);
		$this->assertSame('file', $file['type']);
		$this->assertArrayNotHasKey('accept', $file);
		$this->assertSame(50, $file['maxSizeMb']);

		$zero = $this->normalisedConfig(type: 'create', config: ['type' => 'file', 'maxSizeMb' => 0]);
		$this->assertSame(1, $zero['maxSizeMb']);

		$text = $this->normalisedConfig(type: 'create', config: ['type' => 'file', 'maxSizeMb' => 'lots']);
		$this->assertArrayNotHasKey('maxSizeMb', $text);

		$unknown = $this->normalisedConfig(type: 'create', config: ['type' => 'upload', 'label' => 'Bijlage']);
		$this->assertArrayNotHasKey('type', $unknown);
		$this->assertArrayNotHasKey('multiple', $unknown);
		$this->assertSame('Bijlage', $unknown['label']);
	}//end testMalformedFileKeysAreDroppedOrClamped()

	/**
	 * An endpoint action is not saved by the generic form, so a file type on it
	 * is dropped.
	 *
	 * @return void
	 */
	public function testAFileTypeOnAnEndpointActionIsDropped(): void {
		$config = $this->normalisedConfig(type: 'endpoint', config: ['type' => 'file', 'multiple' => true]);

		$this->assertArrayNotHasKey('type', $config);
		$this->assertArrayNotHasKey('multiple', $config);
	}//end testAFileTypeOnAnEndpointActionIsDropped()

	/**
	 * A file config on a field outside the whitelist goes with the rest of
	 * that config: a file type never widens what an action may write.
	 *
	 * @return void
	 */
	public function testAFileConfigNeverWidensTheWhitelist(): void {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [],
				'actions' => [
					[
						'id' => 'createSubmission',
						'type' => 'create',
						'schema' => 'submission',
						'fields' => ['assignmentId'],
						'fieldConfigs' => ['attachmentRefs' => ['type' => 'file']],
					],
				],
			]
		);

		$this->assertSame(['assignmentId'], $out['actions'][0]['fields']);
		$this->assertSame([], $out['actions'][0]['fieldConfigs']);
	}//end testAFileConfigNeverWidensTheWhitelist()

	/**
	 * Normalise one action carrying one field config for `attachmentRefs`.
	 *
	 * @param string $type The action type.
	 * @param array<string, mixed> $config The declared field config.
	 *
	 * @return array<string, mixed> The normalised field config.
	 */
	private function normalisedConfig(string $type, array $config): array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [],
				'actions' => [
					[
						'id' => 'a1',
						'type' => $type,
						'schema' => 'submission',
						'endpoint' => '/apps/learniq/api/x',
						'method' => 'POST',
						'fields' => ['assignmentId', 'attachmentRefs'],
						'fieldConfigs' => ['attachmentRefs' => $config],
					],
				],
			]
		);

		return $out['actions'][0]['fieldConfigs']['attachmentRefs'];
	}//end normalisedConfig()
}//end class
