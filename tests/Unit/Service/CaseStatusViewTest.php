<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CaseStatusView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * How a case's status reads on the portal: its label, description, the
 * button the case type offers for it, and the status that comes next.
 */
#[CoversClass(CaseStatusView::class)]
class CaseStatusViewTest extends TestCase {

	private const TYPE = [
		'portalStatusLabels' => [
			'received' => ['label' => 'Received', 'description' => 'We have your request.'],
			'review'   => ['label' => 'In review'],
			'decided'  => ['label' => ''],
			'closed'   => ['label' => 'Closed'],
		],
		'portalStatusActions' => [
			'received' => ['label' => '  Add documents ', 'kind' => 'task', 'target' => ' upload '],
			'review'   => ['label' => 'Pay', 'kind' => 'link', 'target' => 'pay'],
			'closed'   => ['label' => 'Reopen', 'kind' => 'action', 'target' => '   '],
		],
	];

	public function testALabelledStatusReadsWithItsButtonAndNextStep(): void {
		$this->assertSame(
			[
				'value' => 'received',
				'label' => 'Received',
				'description' => 'We have your request.',
				'action' => ['label' => 'Add documents', 'kind' => 'task', 'target' => 'upload'],
				'next' => ['value' => 'review', 'label' => 'In review'],
			],
			(new CaseStatusView())->status(self::TYPE, 'received')
		);
	}

	public function testAnUnknownButtonKindOrBlankTargetIsNoButton(): void {
		$view = new CaseStatusView();

		$this->assertNull($view->status(self::TYPE, 'review')['action']);
		$this->assertNull($view->status(self::TYPE, 'closed')['action']);
	}

	public function testANextStatusWithoutALabelIsNotOffered(): void {
		$this->assertNull((new CaseStatusView())->status(self::TYPE, 'review')['next']);
	}

	public function testTheLastStatusHasNoNextStep(): void {
		$this->assertNull((new CaseStatusView())->status(self::TYPE, 'closed')['next']);
	}

	public function testAnUnlabelledStatusReadsAsItsValue(): void {
		$this->assertSame(
			['value' => 'paused', 'label' => '', 'description' => '', 'action' => null, 'next' => null],
			(new CaseStatusView())->status(self::TYPE, 'paused')
		);
	}

	public function testACaseTypeWithoutLabelsOrActionsGivesTheBareStatus(): void {
		$this->assertSame(
			['value' => 'open', 'label' => '', 'description' => '', 'action' => null, 'next' => null],
			(new CaseStatusView())->status(['portalStatusLabels' => 'nope', 'portalStatusActions' => 'nope'], 'open')
		);
	}

	public function testNonStringLabelAndDescriptionReadAsEmpty(): void {
		$type = ['portalStatusLabels' => ['a' => ['label' => 5, 'description' => ['x']], 'b' => 'not an entry']];

		$this->assertSame(
			['value' => 'a', 'label' => '', 'description' => '', 'action' => null, 'next' => null],
			(new CaseStatusView())->status($type, 'a')
		);
	}

	public function testNumericStatusKeysAreMatchedAsStrings(): void {
		$type = ['portalStatusLabels' => [1 => ['label' => 'One'], 2 => ['label' => 'Two']]];

		$this->assertSame(['value' => '2', 'label' => 'Two'], (new CaseStatusView())->status($type, '1')['next']);
	}
}
