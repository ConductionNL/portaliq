<?php

/**
 * What an endpoint row action may ask and when it is offered
 * (case-actions-row-inputs-and-conditions).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Contribution\RowActionInputs;
use PHPUnit\Framework\TestCase;

class RowActionInputsTest extends TestCase {

	private static function action(array $extra = []): array {
		return $extra + ['id' => 'sign', 'label' => 'Sign', 'type' => 'endpoint-forward', 'endpoint' => '/apps/filinq/api/sign', 'method' => 'POST', 'rowField' => 'requestId'];
	}

	public function testRowInputsNeedAProjectedField(): void {
		$inputs = new RowActionInputs();

		$kept = $inputs->normaliseAction(self::action(['rowInputs' => ['from' => 'signerInputs', 'into' => 'fields', 'extra' => 'x']]));
		$this->assertSame(['from' => 'signerInputs', 'into' => 'fields'], $kept['rowInputs']);

		foreach ([
			['from' => 'bad name', 'into' => 'fields'],
			['from' => 'signerInputs', 'into' => '../x'],
			['from' => 'signerInputs'],
			'junk',
		] as $bad) {
			$this->assertArrayNotHasKey('rowInputs', $inputs->normaliseAction(self::action(['rowInputs' => $bad])), json_encode($bad));
		}

		$own = $inputs->normaliseAction(self::action(['fields' => ['fields', 'reason'], 'rowInputs' => ['from' => 'signerInputs', 'into' => 'fields']]));
		$this->assertArrayNotHasKey('rowInputs', $own, 'the body key may not be one of the action\'s own fields');
		$stamp = $inputs->normaliseAction(self::action(['rowInputs' => ['from' => 'signerInputs', 'into' => 'requestId']]));
		$this->assertArrayNotHasKey('rowInputs', $stamp, 'nor the stamped row field');
	}

	public function testAvailableWhenNeedsAScalar(): void {
		$inputs = new RowActionInputs();

		$kept = $inputs->normaliseAction(self::action(['availableWhen' => ['field' => 'withdrawable', 'equals' => true], 'unavailableReasonField' => 'blockedReason']));
		$this->assertSame(['field' => 'withdrawable', 'equals' => true], $kept['availableWhen']);
		$this->assertSame('blockedReason', $kept['unavailableReasonField']);

		$array = $inputs->normaliseAction(self::action(['availableWhen' => ['field' => 'withdrawable', 'equals' => [1]], 'unavailableReasonField' => 'blockedReason']));
		$this->assertArrayNotHasKey('availableWhen', $array);
		$this->assertArrayNotHasKey('unavailableReasonField', $array, 'a reason field means nothing without a condition');

		$lone = $inputs->normaliseAction(self::action(['unavailableReasonField' => 'blockedReason']));
		$this->assertArrayNotHasKey('unavailableReasonField', $lone);
	}

	public function testUnknownKeysAreDroppedAndOtherActionsLoseTheseKeys(): void {
		$inputs = new RowActionInputs();

		$create = $inputs->normaliseAction(['id' => 'new', 'type' => 'create', 'rowInputs' => ['from' => 'a', 'into' => 'b'], 'availableWhen' => ['field' => 'a', 'equals' => 1], 'confirmText' => 'x']);
		$this->assertSame(['id' => 'new', 'type' => 'create'], $create);

		$text = $inputs->normaliseAction(self::action(['confirmText' => ['not text'], 'successText' => str_repeat('x', 501)]));
		$this->assertArrayNotHasKey('confirmText', $text);
		$this->assertArrayNotHasKey('successText', $text);

		$words = $inputs->normaliseAction(self::action(['confirmText' => 'You withdraw from the contract.', 'successText' => 'Done.']));
		$this->assertSame('You withdraw from the contract.', $words['confirmText'], 'the contribution\'s words are kept unchanged');
	}

	public function testTheActionNormaliserAppliesIt(): void {
		$out = (new PortalManifestNormaliser())->normalise(contribution: [
			'collections' => [],
			'actions' => [self::action(['rowInputs' => ['from' => 'signerInputs', 'into' => 'fields'], 'confirmText' => 'Sure?'])],
		])['actions'];

		$this->assertSame(['from' => 'signerInputs', 'into' => 'fields'], $out[0]['rowInputs']);
		$this->assertSame('Sure?', $out[0]['confirmText']);
	}

	public function testOnlyTheDeclaredNamesAreCollectedAndARequiredOneMustBeFilled(): void {
		$inputs = new RowActionInputs();
		$action = ['rowInputs' => ['from' => 'signerInputs', 'into' => 'fields']];
		$row    = ['signerInputs' => [
			['name' => 'iban', 'label' => 'Bank account', 'required' => true],
			['name' => 'note', 'type' => 'weird'],
			['name' => 'bad name'],
			['name' => 'iban'],
			'junk',
		]];

		$declared = $inputs->declaredInputs($action, $row);
		$this->assertSame(['iban', 'note'], array_column($declared, 'name'));
		$this->assertSame('text', $declared[1]['type'], 'an unknown type is text');
		$this->assertSame('note', $declared[1]['label'], 'no label reads as the name');

		$ok = $inputs->collect($action, $row, ['iban' => ' NL91 ', 'email' => 'x@example.org', 'note' => '']);
		$this->assertSame(['values' => ['iban' => 'NL91'], 'errors' => []], $ok);

		$missing = $inputs->collect($action, $row, ['note' => 'hi']);
		$this->assertSame(['iban' => 'required'], $missing['errors']);

		$this->assertSame([], $inputs->declaredInputs(['rowInputs' => ['from' => 'nothing', 'into' => 'fields']], $row));
		$this->assertSame([], $inputs->declaredInputs([], $row));
	}

	public function testAvailabilityFollowsTheRowAndNeverLeaksAnotherFieldAsTheReason(): void {
		$inputs = new RowActionInputs();
		$action = ['availableWhen' => ['field' => 'withdrawable', 'equals' => true], 'unavailableReasonField' => 'blockedReason'];

		$this->assertSame(['available' => true, 'reason' => ''], $inputs->availability($action, ['withdrawable' => true]));
		$this->assertSame(['available' => true, 'reason' => ''], $inputs->availability([], ['withdrawable' => false]));
		$this->assertSame(['available' => false, 'reason' => 'Too late.'], $inputs->availability($action, ['withdrawable' => false, 'blockedReason' => 'Too late.']));
		$this->assertSame(['available' => false, 'reason' => ''], $inputs->availability($action, ['withdrawable' => 'true', 'blockedReason' => ['not text']]), 'a string is not true');
		$this->assertSame(['available' => false, 'reason' => ''], $inputs->availability($action, []), 'a row without the field is not available');
	}
}
