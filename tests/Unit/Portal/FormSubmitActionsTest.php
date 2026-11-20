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
