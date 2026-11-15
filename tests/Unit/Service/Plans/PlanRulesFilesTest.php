<?php

/**
 * Portaliq Plan Rules file-list test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Plans
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-participants-share-the-goal-actions-note-and-file-versions-req-spl-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Plans;

use OCA\Portaliq\Service\Plans\PlanRules;
use PHPUnit\Framework\TestCase;

/**
 * A plan's stored file list is read defensively: junk entries are skipped
 * and every kept entry has string keys and a version of at least 1.
 *
 * @covers \OCA\Portaliq\Service\Plans\PlanRules
 */
class PlanRulesFilesTest extends TestCase {

	public function testAFileListThatIsNotAListReadsAsNoFiles(): void {
		$rules = new PlanRules();

		$this->assertSame([], $rules->filesOf(['files' => 'f1']));
		$this->assertSame([], $rules->filesOf([]));
	}

	public function testEntriesWithoutAnIdAreSkippedAndTheRestNormalised(): void {
		$files = (new PlanRules())->filesOf(
			[
				'files' => [
					'junk',
					['name' => 'zonder-id.pdf'],
					['id' => ''],
					['id' => 'f1', 'name' => 'begroting.pdf', 'version' => 0],
					['id' => 'f2', 'name' => 'begroting.pdf', 'series' => 'f1', 'version' => '2', 'previousVersion' => 'f1', 'addedBy' => 'mark', 'addedAt' => '2026-09-02'],
				],
			]
		);

		$this->assertSame(
			[
				['id' => 'f1', 'name' => 'begroting.pdf', 'series' => 'f1', 'version' => 1, 'previousVersion' => '', 'addedBy' => '', 'addedAt' => ''],
				['id' => 'f2', 'name' => 'begroting.pdf', 'series' => 'f1', 'version' => 2, 'previousVersion' => 'f1', 'addedBy' => 'mark', 'addedAt' => '2026-09-02'],
			],
			$files
		);
	}
}
