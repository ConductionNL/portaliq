<?php

/**
 * ActionConfigNormaliser: which actions of a list are kept, shaped, or
 * removed whole.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
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

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ActionConfigNormaliser;
use OCA\Portaliq\Contribution\ActionOptionsNormaliser;
use OCA\Portaliq\Contribution\ManifestValueNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The action list as a whole: what is dropped, what is removed, what is kept.
 */
class ActionConfigNormaliserGoldenTest extends TestCase {
	/**
	 * The normaliser without a schema reader or injected guards.
	 *
	 * @return ActionConfigNormaliser
	 */
	private function normaliser(): ActionConfigNormaliser {
		$values = new ManifestValueNormaliser();

		return new ActionConfigNormaliser($values, new ActionOptionsNormaliser($values));
	}//end normaliser()

	/**
	 * A mixed list keeps the usable actions in order, shaped, and removes
	 * the ones whose guards cannot be honoured.
	 *
	 * @return void
	 */
	public function testAMixedActionListIsKeptShapedAndRemovedAsRecorded(): void {
		$actions = [
			'junk',
			['id' => 'kept', 'type' => 'create', 'fields' => ['name', 7, 'email'], 'submitLabel' => 5, 'successMessage' => 'Thanks', 'set' => ['name' => 'x', 'other' => 'y']],
			['id' => 'bad-subject', 'type' => 'endpoint', 'endpoint' => '/x', 'subjectField' => '1bad'],
			['id' => 'good-subject', 'type' => 'endpoint', 'endpoint' => '/x', 'subjectField' => 'student'],
			['id' => 'reserved-claim', 'type' => 'endpoint', 'endpoint' => '/x', 'scopeClaim' => 'sub'],
			['id' => 'empty-cross-refs', 'type' => 'create', 'fields' => ['name'], 'crossRefs' => []],
			['id' => 'read-cross-refs', 'type' => 'read', 'crossRefs' => ['a' => 1]],
			['id' => 'guest-unusable', 'type' => 'endpoint', 'endpoint' => '/x', 'guest' => true],
			['id' => 'guest-usable', 'type' => 'endpoint', 'endpoint' => '/x', 'guest' => true, 'tokenField' => 'token', 'label' => 3],
			['id' => 'tile', 'type' => 'create', 'fields' => ['name'], 'startTile' => true, 'summary' => 'Ask a question', 'audiences' => ['parent', 'alien'], 'anonymous' => 'yes'],
		];

		$this->assertSame(
			[
				['id' => 'kept', 'type' => 'create', 'fields' => ['name', 7, 'email'], 'successMessage' => 'Thanks', 'set' => ['name' => 'x']],
				['id' => 'good-subject', 'type' => 'endpoint', 'endpoint' => '/x', 'subjectField' => 'student'],
				['id' => 'read-cross-refs', 'type' => 'read'],
				['id' => 'guest-usable', 'type' => 'endpoint', 'endpoint' => '/x', 'guest' => true, 'tokenField' => 'token'],
				['id' => 'tile', 'type' => 'create', 'fields' => ['name'], 'startTile' => true, 'anonymous' => false, 'summary' => 'Ask a question', 'audiences' => ['parent']],
			],
			$this->normaliser()->normaliseActions($actions, ['parent'])
		);
	}//end testAMixedActionListIsKeptShapedAndRemovedAsRecorded()

	/**
	 * Each guard on its own removes its action and nothing else.
	 *
	 * @return void
	 */
	public function testEachGuardRemovesOnlyItsOwnAction(): void {
		$removed = [
			['id' => 'bad-subject', 'type' => 'endpoint', 'endpoint' => '/x', 'subjectField' => ['x']],
			['id' => 'reserved-claim', 'type' => 'endpoint', 'endpoint' => '/x', 'scopeClaim' => 'iss'],
			['id' => 'empty-cross-refs', 'type' => 'update', 'fields' => ['name'], 'crossRefs' => 'x'],
			['id' => 'guest-remote', 'type' => 'endpoint', 'endpoint' => 'https://elsewhere/x', 'guest' => true, 'tokenField' => 'token'],
		];
		foreach ($removed as $action) {
			$kept = $this->normaliser()->normaliseActions([$action, ['id' => 'other', 'type' => 'read']]);
			$this->assertSame([['id' => 'other', 'type' => 'read']], $kept, (string)$action['id']);
		}
	}//end testEachGuardRemovesOnlyItsOwnAction()

	/**
	 * An empty or all-junk list gives an empty list.
	 *
	 * @return void
	 */
	public function testAListWithoutActionsGivesNone(): void {
		$this->assertSame([], $this->normaliser()->normaliseActions([]));
		$this->assertSame([], $this->normaliser()->normaliseActions(['x', 3, null]));
	}//end testAListWithoutActionsGivesNone()
}//end class
