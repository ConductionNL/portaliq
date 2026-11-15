<?php

/**
 * Unit tests for OnCaseConfigNormaliser (case-page-objection-and-complaint T01).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://portaliq.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\OnCaseConfigNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * `onCase` is kept only when its `crossRef` names one of the action's
 * cross-references, its `order` is an integer, its `window` names a field and a
 * positive number of days, and its `closedText` — when present — is a string.
 * A malformed declaration drops the key and keeps the action.
 *
 * @covers \OCA\Portaliq\Contribution\OnCaseConfigNormaliser
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/portal-contribution-contract/spec.md
 */
class OnCaseConfigNormaliserTest extends TestCase {

	/**
	 * The normaliser under test.
	 *
	 * @var OnCaseConfigNormaliser
	 */
	private OnCaseConfigNormaliser $normaliser;

	protected function setUp(): void {
		$this->normaliser = new OnCaseConfigNormaliser();
	}//end setUp()

	/**
	 * An action carrying one cross-reference and the given `onCase` block.
	 *
	 * @param mixed $onCase The `onCase` block.
	 *
	 * @return array<string, mixed> The action.
	 */
	private function action(mixed $onCase): array {
		return [
			'type' => 'create',
			'crossRefs' => ['tegenZaakId' => ['register' => 'dossiq', 'schema' => 'case', 'scopeField' => 'portalSubject']],
			'onCase' => $onCase,
		];
	}//end action()

	public function testAWellFormedDeclarationIsKept(): void {
		$onCase = [
			'crossRef' => 'tegenZaakId',
			'order' => 1,
			'window' => ['from' => 'verzondenOp', 'days' => 42],
			'closedText' => 'De termijn is verstreken.',
		];

		$action = $this->normaliser->normaliseAction($this->action($onCase));

		$this->assertSame($onCase, $action['onCase']);
		$this->assertArrayHasKey('type', $action, 'the action is never dropped');
	}//end testAWellFormedDeclarationIsKept()

	public function testClosedTextIsOptional(): void {
		$onCase = ['crossRef' => 'tegenZaakId', 'order' => 2, 'window' => ['from' => 'verzondenOp', 'days' => 1]];

		$action = $this->normaliser->normaliseAction($this->action($onCase));

		$this->assertSame($onCase, $action['onCase']);
		$this->assertArrayNotHasKey('closedText', $action['onCase']);
	}//end testClosedTextIsOptional()

	public function testAnActionWithoutAnOnCaseIsUntouched(): void {
		$action = ['type' => 'create', 'crossRefs' => []];

		$this->assertSame($action, $this->normaliser->normaliseAction($action));
	}//end testAnActionWithoutAnOnCaseIsUntouched()

	public function testAnUnknownCrossRefDropsTheKeyAndKeepsTheAction(): void {
		$action = $this->normaliser->normaliseAction(
			$this->action(['crossRef' => 'andereZaak', 'order' => 1, 'window' => ['from' => 'verzondenOp', 'days' => 42]])
		);

		$this->assertArrayNotHasKey('onCase', $action);
		$this->assertSame('create', $action['type']);
		$this->assertArrayHasKey('crossRefs', $action);
	}//end testAnUnknownCrossRefDropsTheKeyAndKeepsTheAction()

	public function testZeroDaysDropsTheKey(): void {
		$action = $this->normaliser->normaliseAction(
			$this->action(['crossRef' => 'tegenZaakId', 'order' => 1, 'window' => ['from' => 'verzondenOp', 'days' => 0]])
		);

		$this->assertArrayNotHasKey('onCase', $action);
		$this->assertSame('create', $action['type']);
	}//end testZeroDaysDropsTheKey()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformed(): array {
		return [
			'not an array' => ['nope'],
			'crossRef missing' => [['order' => 1, 'window' => ['from' => 'verzondenOp', 'days' => 42]]],
			'crossRef not a string' => [['crossRef' => 3, 'order' => 1, 'window' => ['from' => 'verzondenOp', 'days' => 42]]],
			'order not an integer' => [['crossRef' => 'tegenZaakId', 'order' => '1', 'window' => ['from' => 'verzondenOp', 'days' => 42]]],
			'order missing' => [['crossRef' => 'tegenZaakId', 'window' => ['from' => 'verzondenOp', 'days' => 42]]],
			'window missing' => [['crossRef' => 'tegenZaakId', 'order' => 1]],
			'window not an array' => [['crossRef' => 'tegenZaakId', 'order' => 1, 'window' => 'soon']],
			'from missing' => [['crossRef' => 'tegenZaakId', 'order' => 1, 'window' => ['days' => 42]]],
			'from blank' => [['crossRef' => 'tegenZaakId', 'order' => 1, 'window' => ['from' => '  ', 'days' => 42]]],
			'days not an integer' => [['crossRef' => 'tegenZaakId', 'order' => 1, 'window' => ['from' => 'verzondenOp', 'days' => '42']]],
			'negative days' => [['crossRef' => 'tegenZaakId', 'order' => 1, 'window' => ['from' => 'verzondenOp', 'days' => -1]]],
			'closedText not a string' => [['crossRef' => 'tegenZaakId', 'order' => 1, 'window' => ['from' => 'verzondenOp', 'days' => 42], 'closedText' => 5]],
		];
	}//end malformed()

	/**
	 * A malformed declaration drops the key and keeps the action.
	 *
	 * @param mixed $onCase The malformed declaration.
	 *
	 * @dataProvider malformed
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationDropsTheKeyAndKeepsTheAction(mixed $onCase): void {
		$action = $this->normaliser->normaliseAction($this->action($onCase));

		$this->assertArrayNotHasKey('onCase', $action);
		$this->assertSame('create', $action['type']);
	}//end testAMalformedDeclarationDropsTheKeyAndKeepsTheAction()
}//end class
