<?php

/**
 * CaseStatusView::status(): the button the current status offers, kept or
 * dropped, as recorded.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CaseStatusView;
use PHPUnit\Framework\TestCase;

/**
 * The status button, as recorded.
 */
class CaseStatusActionGoldenTest extends TestCase {
	/**
	 * The button a status offers on a case type.
	 *
	 * @param mixed  $actions The case type's status actions.
	 * @param string $status  The current status.
	 *
	 * @return mixed
	 */
	private static function action(mixed $actions, string $status = 'open'): mixed {
		return (new CaseStatusView())->status(caseType: ['portalStatusActions' => $actions], status: $status)['action'];
	}//end action()

	/**
	 * A button needs a label, a known kind and a target; label and target
	 * are trimmed.
	 *
	 * @return void
	 */
	public function testEveryStatusButtonIsAsRecorded(): void {
		$button = ['label' => ' Upload ', 'kind' => 'task', 'target' => ' t-1 ', 'extra' => 1];
		$this->assertSame(['label' => 'Upload', 'kind' => 'task', 'target' => 't-1'], self::action(['open' => $button]));
		foreach (['page', 'action'] as $kind) {
			$this->assertSame($kind, self::action(['open' => ['kind' => $kind] + $button])['kind']);
		}

		foreach ([null, 'x', ['other' => $button], ['open' => 'x'], ['open' => ['label' => ' '] + $button], ['open' => ['label' => 3] + $button], ['open' => ['kind' => 'Task'] + $button], ['open' => ['target' => ''] + $button], ['open' => ['target' => 9] + $button]] as $actions) {
			$this->assertNull(self::action($actions), json_encode($actions));
		}
	}//end testEveryStatusButtonIsAsRecorded()
}//end class
