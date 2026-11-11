<?php

/**
 * CaseTypeNames: which case types are read, once per source, and the names
 * they give, as recorded.
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

use OCA\Portaliq\Service\CaseTypeNames;
use OCA\Portaliq\Service\CaseTypeReader;
use PHPUnit\Framework\TestCase;

/**
 * The case type names, as recorded.
 */
class CaseTypeNamesGoldenTest extends TestCase {
	/**
	 * A source is read once per register, schema and label field; a type is
	 * named by its id, its uuid or its `@self` id; a source without a
	 * register or schema reads nothing.
	 *
	 * @return void
	 */
	public function testEverySourceIsReadAndNamedAsRecorded(): void {
		$reads  = [];
		$reader = $this->createMock(CaseTypeReader::class);
		$reader->method('listCaseTypes')->willReturnCallback(function (string $register, string $schema) use (&$reads): array {
			$reads[] = $register . '/' . $schema;
			return [
				['id' => 't1', 'name' => 'Eén', 'title' => 'Titel'],
				['uuid' => 't2', 'name' => 'Twee'],
				['@self' => ['id' => 't3'], 'name' => 'Drie'],
				['name' => 'Zonder id'],
				['id' => 't5'],
			];
		});
		$names      = new CaseTypeNames($reader);
		$collection = ['kind' => 'cases', 'caseTypeField' => 'type', 'caseTypeSource' => ['register' => 'dossiq', 'schema' => 'caseType', 'labelField' => 'name']];
		$rows       = [['type' => 't1'], ['type' => 't2'], ['type' => 't3'], ['type' => 't5']];

		$first = $names->stampRows(rows: $rows, collection: $collection);
		$names->stampRows(rows: $rows, collection: $collection);
		$names->stampRows(rows: $rows, collection: ['caseTypeSource' => ['register' => 'dossiq', 'schema' => 'caseType', 'labelField' => 'title']] + $collection);
		$names->stampRows(rows: $rows, collection: ['caseTypeSource' => ['register' => 'dossiq', 'schema' => '']] + $collection);
		$names->stampRows(rows: $rows, collection: ['caseTypeSource' => 'dossiq'] + $collection);

		$this->assertSame(['dossiq/caseType', 'dossiq/caseType'], $reads);
		$this->assertSame(['Eén', 'Twee', 'Drie', null], array_map(static fn (array $row): ?string => ($row['_caseTypeName'] ?? null), $first));
	}//end testEverySourceIsReadAndNamedAsRecorded()
}//end class
