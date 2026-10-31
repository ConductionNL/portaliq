<?php

/**
 * Example data for the DqZaak boards: Lars de Koning of Mobiliteit en
 * verkeer gathered the Lindelaan documents, so the task list and the history
 * name him.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-set-lars-gathers-documents/spec.md#requirement-lars-de-koning-gathered-the-lindelaan-documents
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The finished task "Stukken opvragen bij Mobiliteit en verkeer" is Lars's.
 *
 * @spec openspec/specs/example-set-lars-gathers-documents/spec.md#requirement-lars-de-koning-gathered-the-lindelaan-documents
 */
class ZuiddrechtLarsGathersDocumentsTest extends TestCase {

	/**
	 * Lars was given the task and finished it.
	 *
	 * @return void
	 */
	public function testLarsGatheredTheDocuments(): void {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		$task = array_column($set['tasks'], 'data', 'key')['woo-fietspad-taak-stukken'];
		$this->assertSame(expected: 'completed', actual: $task['state']);
		$this->assertSame(expected: '{{cast:lars.uid}}', actual: $task['assignee']);
		$this->assertSame(expected: '{{cast:lars.uid}}', actual: $task['completedBy']);
	}//end testLarsGatheredTheDocuments()
}//end class
