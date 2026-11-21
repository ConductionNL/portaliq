<?php

/**
 * The README's MCP tables say what the tool surface actually is.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/tasks.md#task-6-documentation--capability-spec-maintenance
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Mcp;

use OCA\Portaliq\Mcp\PortaliqToolProvider;
use OCA\Portaliq\Mcp\StaffObjectStore;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PageEditorService;
use OCA\Portaliq\Service\PortalRegionResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\Theme\PortalThemeChoice;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins the README's curated-tool table to the provider's catalogue, its
 * derived-read table to the schemas that declare the MCP dialect, and the
 * CHANGELOG to both, so the documentation cannot drift from the code.
 */
class McpDocumentationTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The README's table rows whose first cell names a portaliq tool.
	 *
	 * @return list<list<string>> The cells of each row.
	 */
	private static function readmeToolRows(): array {
		$rows = [];
		$lines = explode("\n", (string)file_get_contents(self::root() . '/README.md'));
		foreach ($lines as $line) {
			if (preg_match(pattern: '/^\|\s*`portaliq\./', subject: $line) !== 1) {
				continue;
			}

			$rows[] = array_map(callback: 'trim', array: explode('|', trim(string: $line, characters: '|')));
		}

		return $rows;
	}//end readmeToolRows()

	/**
	 * The provider's tools, by id, with mocked collaborators.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function catalogue(): array {
		$provider = new PortaliqToolProvider(
			userSession: $this->createMock(originalClassName: IUserSession::class),
			groups: $this->createMock(originalClassName: IGroupManager::class),
			editors: $this->createMock(originalClassName: PageEditorService::class),
			objects: $this->createMock(originalClassName: StaffObjectStore::class),
			regions: new PortalRegionResolver(),
			themes: $this->createMock(originalClassName: PortalThemeChoice::class),
			sessions: $this->createMock(originalClassName: PortalSessionService::class),
			traffic: $this->createMock(originalClassName: TrafficEventStore::class),
			numbers: $this->createMock(originalClassName: TrafficReportNumbers::class),
			auditor: $this->createMock(originalClassName: AuditTrailService::class),
		);

		$byId = [];
		foreach ($provider->getTools() as $tool) {
			$byId[$tool['id']] = $tool;
		}

		return $byId;
	}//end catalogue()

	/**
	 * Every curated tool has one README row with its scope, reach and
	 * approval, and the README lists no curated tool the provider lacks.
	 *
	 * @return void
	 */
	public function testTheReadmeCuratedTableMatchesTheCatalogue(): void {
		$documented = [];
		foreach (self::readmeToolRows() as $cells) {
			if (count($cells) !== 4) {
				continue;
			}

			$documented[trim(string: $cells[0], characters: '`')] = [$cells[2], $cells[3]];
		}

		$expected = [];
		foreach ($this->catalogue() as $id => $tool) {
			$approval = '—';
			if ($tool['requiresApproval'] === true) {
				$approval = 'required';
			}

			$expected[$id] = [$tool['scope'] . ' × ' . $tool['reach'], $approval];
		}

		$this->assertSame(expected: $expected, actual: $documented);
	}//end testTheReadmeCuratedTableMatchesTheCatalogue()

	/**
	 * The derived-read table names exactly the schemas that declare the
	 * `x-openregister-mcp` dialect, with their search and get tools.
	 *
	 * @return void
	 */
	public function testTheReadmeDerivedReadTableMatchesTheRegister(): void {
		$documented = [];
		foreach (self::readmeToolRows() as $cells) {
			if (count($cells) !== 3) {
				continue;
			}

			$documented[trim(string: $cells[1], characters: '`')] = $cells[0];
		}

		$register = json_decode(json: (string)file_get_contents(self::root() . '/lib/Settings/portaliq_register.json'), associative: true);
		$expected = [];
		foreach ($register['components']['schemas'] as $slug => $schema) {
			if (isset($schema['x-openregister-mcp']) === false) {
				continue;
			}

			$expected[$slug] = '`portaliq.' . strtolower(string: $slug) . '.search` / `.get`';
		}

		ksort(array: $documented);
		ksort(array: $expected);
		$this->assertSame(expected: $expected, actual: $documented);
	}//end testTheReadmeDerivedReadTableMatchesTheRegister()

	/**
	 * The CHANGELOG records the derived reads and the governed provider.
	 *
	 * @return void
	 */
	public function testTheChangelogRecordsTheDerivedReadsAndTheProvider(): void {
		$changelog = (string)file_get_contents(self::root() . '/CHANGELOG.md');

		$this->assertStringContainsString(needle: '`PortaliqToolProvider`', haystack: $changelog);
		$this->assertStringContainsString(needle: '`x-openregister-mcp`', haystack: $changelog);
		foreach (['portalSubmission', 'portalNotification', 'portalMessage'] as $schema) {
			$this->assertStringContainsString(needle: '`' . $schema . '`', haystack: $changelog);
		}
	}//end testTheChangelogRecordsTheDerivedReadsAndTheProvider()

	/**
	 * The README states that no tool is reachable from the portal edge.
	 *
	 * @return void
	 */
	public function testTheReadmeStatesThePortalEdgeRule(): void {
		$readme = (string)file_get_contents(self::root() . '/README.md');

		$this->assertStringContainsString(needle: 'No tool is reachable from the portal edge (ADR-046).', haystack: $readme);
	}//end testTheReadmeStatesThePortalEdgeRule()
}//end class
