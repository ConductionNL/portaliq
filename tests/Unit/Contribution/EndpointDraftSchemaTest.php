<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\EndpointDraftSchema;
use OCA\Portaliq\Contribution\FormStepsNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * endpoint-forward-draft-schema T1, T2: an endpoint action that keeps a draft
 * may name the schema it is kept on; a create action, an action without an
 * endpoint or without a draft, and a malformed declaration keep none.
 *
 * @spec openspec/changes/endpoint-forward-draft-schema/tasks.md#T1
 */
class EndpointDraftSchemaTest extends TestCase {
	private const ENDPOINT = [
		'id' => 'assess',
		'type' => 'endpoint-forward',
		'endpoint' => '/apps/learniq/api/portal/werkproces-assessments',
		'subjectField' => 'practicalTrainerId',
		'fields' => ['werkprocesCode', 'assessment'],
		'steps' => [
			['id' => 'one', 'title' => 'Werkproces', 'fields' => ['werkprocesCode']],
			['id' => 'two', 'title' => 'Oordeel', 'fields' => ['assessment']],
		],
		'draft' => ['retentionDays' => 30],
		'draftSchema' => ['register' => 'learniq', 'schema' => 'werkproces-assessment-draft'],
	];

	/**
	 * The action after the form normaliser.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>
	 */
	private function normalised(array $action): array {
		return (new FormStepsNormaliser())->applyToAction(action: $action, whitelist: (array)($action['fields'] ?? []));
	}//end normalised()

	/**
	 * An endpoint action with a draft keeps its draft schema.
	 *
	 * @return void
	 */
	public function testAnEndpointActionWithADraftKeepsItsDraftSchema(): void {
		$out = $this->normalised(action: self::ENDPOINT);
		$this->assertSame(expected: ['register' => 'learniq', 'schema' => 'werkproces-assessment-draft'], actual: $out['draftSchema']);
		$this->assertSame(expected: ['retentionDays' => 30], actual: $out['draft']);
	}//end testAnEndpointActionWithADraftKeepsItsDraftSchema()

	/**
	 * Without a draft, on a create, without an endpoint or malformed: dropped.
	 *
	 * @return void
	 */
	public function testTheDraftSchemaIsDroppedWhereItCannotHold(): void {
		$cases = [
			'no draft'    => array_diff_key(self::ENDPOINT, ['draft' => true]),
			'a create'    => array_merge(self::ENDPOINT, ['type' => 'create', 'register' => 'learniq', 'schema' => 'werkproces-assessment']),
			'no endpoint' => array_diff_key(self::ENDPOINT, ['endpoint' => true]),
			'no schema'   => array_merge(self::ENDPOINT, ['draftSchema' => ['register' => 'learniq']]),
			'not a slug'  => array_merge(self::ENDPOINT, ['draftSchema' => ['register' => 'learniq', 'schema' => '../x']]),
			'a string'    => array_merge(self::ENDPOINT, ['draftSchema' => 'werkproces-assessment-draft']),
		];
		foreach ($cases as $name => $action) {
			$this->assertArrayNotHasKey(key: 'draftSchema', array: $this->normalised(action: $action), message: $name);
		}
	}//end testTheDraftSchemaIsDroppedWhereItCannotHold()

	/**
	 * The destination and the stamp field of a draft kept elsewhere.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/endpoint-forward-draft-schema/tasks.md#T2
	 */
	public function testTheDraftIsKeptOnTheDeclaredSchemaStampedWithTheSubjectField(): void {
		$schema = new EndpointDraftSchema();
		$this->assertSame(
			expected: ['register' => 'learniq', 'schema' => 'werkproces-assessment-draft'],
			actual: $schema->destinationOf(action: self::ENDPOINT)
		);
		$this->assertTrue(condition: $schema->keepsDraftElsewhere(action: self::ENDPOINT));
		$this->assertSame(expected: 'practicalTrainerId', actual: $schema->scopeFieldOf(action: self::ENDPOINT));
		$this->assertSame(expected: 'subjectRef', actual: $schema->scopeFieldOf(action: ['id' => 'x']));
		$this->assertSame(expected: 'ownerRef', actual: $schema->scopeFieldOf(action: ['scopeField' => 'ownerRef']));
		$this->assertNull(actual: $schema->destinationOf(action: array_merge(self::ENDPOINT, ['type' => 'create'])));
		$this->assertFalse(condition: $schema->keepsDraftElsewhere(action: ['id' => 'x', 'endpoint' => '/e']));
	}//end testTheDraftIsKeptOnTheDeclaredSchemaStampedWithTheSubjectField()
}//end class
