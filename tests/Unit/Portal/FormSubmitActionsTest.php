<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Portal;

use OCA\Portaliq\Portal\FormSubmitActions;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One `form` row as its anonymous submission action: the forms that get no
 * action (no id, no usable field, no destination), and the exact action,
 * field whitelist and server values a submittable form gets.
 */
#[CoversClass(FormSubmitActions::class)]
class FormSubmitActionsTest extends TestCase {
	/**
	 * A form without an id gets no action and mints no nonce.
	 *
	 * @return void
	 */
	public function testAFormWithoutAnIdGetsNoAction(): void {
		$random = $this->createMock(ISecureRandom::class);
		$random->expects($this->never())->method('generate');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$actions = new FormSubmitActions($random, $logger);

		$this->assertNull($actions->action(row: $this->row(), formId: ''));
	}//end testAFormWithoutAnIdGetsNoAction()

	/**
	 * A form whose fields carry no usable id gets no action, and nothing is logged.
	 *
	 * @return void
	 */
	public function testAFormWithoutAUsableFieldGetsNoAction(): void {
		$random = $this->createMock(ISecureRandom::class);
		$random->expects($this->never())->method('generate');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$row = $this->row(fields: ['email', ['id' => ''], ['id' => 7], ['label' => 'Name']]);

		$this->assertNull((new FormSubmitActions($random, $logger))->action(row: $row, formId: 'f-1'));
	}//end testAFormWithoutAUsableFieldGetsNoAction()

	/**
	 * A form whose destination lacks a register or a schema gets no action, and that is logged with the form id.
	 *
	 * @return void
	 */
	public function testAFormWithoutADestinationIsLoggedAndGetsNoAction(): void {
		$random = $this->createMock(ISecureRandom::class);
		$random->expects($this->never())->method('generate');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Portaliq: a landing-page form names no destination and cannot be submitted',
			['form' => 'f-1']
		);

		$row = $this->row(destination: ['register' => 'pipelinq', 'schema' => '   ']);

		$this->assertNull((new FormSubmitActions($random, $logger))->action(row: $row, formId: 'f-1'));
	}//end testAFormWithoutADestinationIsLoggedAndGetsNoAction()

	/**
	 * A submittable form gets the anonymous create action on the submission
	 * address, its field ids plus the tracking fields, its trimmed
	 * destination and the server values.
	 *
	 * @return void
	 */
	public function testASubmittableFormGetsTheAnonymousCreateAction(): void {
		$random = $this->createMock(ISecureRandom::class);
		$random->expects($this->once())->method('generate')
			->with(32, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn('nonce-123');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$row = $this->row() + [
			'submitLabel' => 'Aanmelden',
			'portal' => 'gemeente',
			'pageRoute' => 'nieuwsbrief',
			'pageId' => 'page-9',
			'sourceApp' => 'pipelinq',
			'externalReference' => 'campagne-42',
			'campaign' => ['utm_source' => 'mail'],
		];

		$action = (new FormSubmitActions($random, $logger))->action(row: $row, formId: 'f-1');
		$this->assertIsArray($action);

		$server = $action['server'];
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $server['submittedAt']);
		unset($action['server'], $server['submittedAt']);

		$this->assertSame(
			[
				'id' => 'submit-f-1',
				'type' => 'create',
				'anonymous' => true,
				'register' => 'portaliq',
				'schema' => 'landingPageSubmission',
				'label' => 'Aanmelden',
				'fields' => ['email', 'name', 'utmFirstTouch', 'utmLastTouch', 'referrer'],
				'destination' => ['register' => 'pipelinq', 'schema' => 'lead', 'mapping' => 'lead-intake'],
				'intakeScope' => 'gemeente/nieuwsbrief',
			],
			$action
		);
		$this->assertSame(
			[
				'formId' => 'f-1',
				'pageId' => 'page-9',
				'pageRoute' => 'nieuwsbrief',
				'portal' => 'gemeente',
				'sourceApp' => 'pipelinq',
				'externalReference' => 'campagne-42',
				'campaign' => ['utm_source' => 'mail'],
				'nonce' => 'nonce-123',
			],
			$server
		);
	}//end testASubmittableFormGetsTheAnonymousCreateAction()

	/**
	 * A form with no label, portal or page gets the default label and empty server values.
	 *
	 * @return void
	 */
	public function testAMinimalFormGetsTheDefaults(): void {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('n');

		$action = (new FormSubmitActions($random, $this->createMock(LoggerInterface::class)))->action(row: $this->row(), formId: 'f-2');
		$this->assertIsArray($action);

		$this->assertSame('Submit', $action['label']);
		$this->assertSame('/', $action['intakeScope']);
		$this->assertSame('', $action['server']['portal']);
		$this->assertSame('', $action['server']['pageId']);
		$this->assertSame([], $action['server']['campaign']);
	}//end testAMinimalFormGetsTheDefaults()

	/**
	 * A group's fields are accepted under their own ids, in the group's place;
	 * the group's own id, a group inside a group and a non-field are not.
	 *
	 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
	 *
	 * @return void
	 */
	public function testAGroupsFieldsAreAcceptedInItsPlace(): void {
		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturn('n');

		$fields = [
			['id' => 'email'],
			[
				'id' => 'address',
				'type' => 'group',
				'fields' => [
					['id' => 'street'],
					'not-a-field',
					['id' => 'inner', 'type' => 'group', 'fields' => [['id' => 'deep']]],
					['id' => 'city'],
				],
			],
			['id' => 'note'],
		];

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$action = (new FormSubmitActions(random: $random, logger: $logger))->action(row: $this->row(fields: $fields), formId: 'f-3');
		$this->assertIsArray(actual: $action);
		$this->assertSame(
			expected: ['email', 'street', 'city', 'note', 'utmFirstTouch', 'utmLastTouch', 'referrer'],
			actual: $action['fields']
		);
	}//end testAGroupsFieldsAreAcceptedInItsPlace()

	/**
	 * A form whose only field is an empty group has nothing to submit.
	 *
	 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
	 *
	 * @return void
	 */
	public function testAFormWithOnlyAnEmptyGroupGetsNoAction(): void {
		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->expects($this->never())->method('generate');

		$fields = [['id' => 'address', 'type' => 'group', 'fields' => []]];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$action = (new FormSubmitActions(random: $random, logger: $logger))->action(row: $this->row(fields: $fields), formId: 'f-4');
		$this->assertNull(actual: $action);
	}//end testAFormWithOnlyAnEmptyGroupGetsNoAction()

	/**
	 * A file field is whitelisted, and declares its normalised file config,
	 * also inside a group; a form without one declares none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
	 */
	public function testAFileFieldDeclaresItsFileConfig(): void {
		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturn('nonce');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$fields = [
			['id' => 'name'],
			['id' => 'photo', 'type' => 'file', 'accept' => ['.JPG', 'image/png', 'not an accept'], 'maxSizeMb' => 80],
			['id' => 'papers', 'type' => 'group', 'fields' => [['id' => 'permit', 'type' => 'file', 'multiple' => true]]],
		];
		$action = (new FormSubmitActions(random: $random, logger: $logger))->action(row: $this->row(fields: $fields), formId: 'f-5');

		$this->assertSame(expected: ['name', 'photo', 'permit', 'utmFirstTouch', 'utmLastTouch', 'referrer'], actual: $action['fields']);
		$this->assertSame(
			expected: [
				'photo' => ['type' => 'file', 'multiple' => false, 'accept' => ['.jpg', 'image/png'], 'maxSizeMb' => 50],
				'permit' => ['type' => 'file', 'multiple' => true],
			],
			actual: $action['fieldConfigs']
		);

		$plain = (new FormSubmitActions(random: $random, logger: $logger))->action(row: $this->row(), formId: 'f-6');
		$this->assertArrayNotHasKey(key: 'fieldConfigs', array: $plain);
	}//end testAFileFieldDeclaresItsFileConfig()

	/**
	 * A `form` row.
	 *
	 * @param array<int, mixed>|null    $fields      The fields, or null for two usable ones.
	 * @param array<string, mixed>|null $destination The destination, or null for a complete one.
	 *
	 * @return array<string, mixed>
	 */
	private function row(?array $fields=null, ?array $destination=null): array {
		return [
			'fields' => ($fields ?? [['id' => 'email'], 'not-a-field', ['id' => 'name']]),
			'destination' => ($destination ?? ['register' => ' pipelinq ', 'schema' => 'lead ', 'mapping' => 'lead-intake']),
		];
	}//end row()
}//end class
