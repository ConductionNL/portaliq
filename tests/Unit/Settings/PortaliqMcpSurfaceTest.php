<?php

/**
 * Portaliq's MCP tool surface, as OpenRegister derives it from the register.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Portaliq declares exactly one read-only pair of MCP tools, over the portal
 * inbox, and no hand-written provider.
 *
 * OpenRegister's SchemaDerivedToolProvider names a derived tool
 * `{appId}.{lowercased schema}.{verb}` for every verb an enabled
 * `x-openregister-mcp` block declares under `tools`. The catalog is computed
 * here the same way from the register file, so adding a schema to the surface,
 * or a write verb to the inbox, fails this test until the spec says so.
 *
 * @spec openspec/changes/portaliq-mcp-adoption/specs/portaliq-mcp-surface/spec.md
 */
class PortaliqMcpSurfaceTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @var string
	 */
	private const ROOT = (__DIR__ . '/../../..');

	/**
	 * Every schema in the register, by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$register = json_decode((string)file_get_contents(self::ROOT . '/lib/Settings/portaliq_register.json'), associative: true);
		$this->assertIsArray($register, 'The register is not valid JSON.');

		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * The tool ids OpenRegister derives from the register.
	 *
	 * @return array<int, string>
	 */
	private function derivedCatalog(): array {
		$ids = [];
		foreach ($this->schemas() as $slug => $schema) {
			$annotation = ($schema['x-openregister-mcp'] ?? null);
			if (is_array($annotation) === false || ($annotation['enabled'] ?? false) !== true) {
				continue;
			}

			foreach (array_keys($annotation['tools'] ?? []) as $verb) {
				$ids[] = ('portaliq.' . strtolower((string)$slug) . '.' . $verb);
			}
		}

		sort($ids);
		return $ids;
	}//end derivedCatalog()

	/**
	 * The catalog is exactly the inbox search and get, and nothing else.
	 *
	 * @return void
	 */
	public function testTheCatalogIsTheInboxSearchAndGet(): void {
		$this->assertSame(
			[
				'portaliq.portalmessage.get',
				'portaliq.portalmessage.search',
				'portaliq.portalnotification.get',
				'portaliq.portalnotification.search',
				'portaliq.portalsubmission.get',
				'portaliq.portalsubmission.search',
			],
			$this->derivedCatalog()
		);
	}//end testTheCatalogIsTheInboxSearchAndGet()

	/**
	 * The operational records each carry a read-only search and get, with
	 * exactly the filters the spec names, and each filter is a declared
	 * property, or OpenRegister refuses the schema at import.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-derived-read-dialects-cover-the-operational-record
	 */
	public function testTheOperationalRecordsCarryReadOnlyDialects(): void {
		$expected = [
			'portalSubmission' => ['deliveryStatus', 'organisation', 'appId', 'actionId'],
			'portalNotification' => ['status', 'channel', 'appId', 'organisation'],
		];
		foreach ($expected as $slug => $filters) {
			$schema = $this->schemas()[$slug];
			$tools = $schema['x-openregister-mcp']['tools'];

			$this->assertTrue($schema['x-openregister-mcp']['enabled'], $slug . ' dialect must be enabled');
			$this->assertSame(['search', 'get'], array_keys($tools), $slug . ' must offer search and get only');
			foreach ($tools as $verb => $config) {
				$this->assertSame('read', $config['scope'], $slug . '.' . $verb . ' must be read-scoped');
				$this->assertTrue($config['readOnlyHint'], $slug . '.' . $verb . ' must carry readOnlyHint');
				$this->assertArrayNotHasKey('destructiveHint', $config);
				$this->assertGreaterThan(40, strlen($config['description']), $slug . '.' . $verb . ' needs agent-facing prose');
			}

			$this->assertSame($filters, $tools['search']['filters']);
			foreach ($filters as $filter) {
				$this->assertArrayHasKey($filter, $schema['properties'], $slug . ' filter ' . $filter . ' is not a property');
			}
		}
	}//end testTheOperationalRecordsCarryReadOnlyDialects()

	/**
	 * No derived tool writes: no id ends in create, update or delete.
	 *
	 * @return void
	 */
	public function testNoWriteToolIsDerivable(): void {
		foreach ($this->derivedCatalog() as $id) {
			$this->assertDoesNotMatchRegularExpression('/\.(create|update|delete)$/', $id);
		}

		$this->assertNotSame([], $this->derivedCatalog(), 'An empty catalog would pass the loop above vacuously.');
	}//end testNoWriteToolIsDerivable()

	/**
	 * Each inbox verb is read-scoped, read-only and described for an agent.
	 *
	 * @return void
	 */
	public function testEveryInboxVerbIsReadOnly(): void {
		$tools = $this->schemas()['portalMessage']['x-openregister-mcp']['tools'];

		$this->assertSame(['search', 'get'], array_keys($tools));
		foreach ($tools as $verb => $config) {
			$this->assertSame('read', $config['scope'], $verb . ' must be read-scoped');
			$this->assertTrue($config['readOnlyHint'], $verb . ' must carry readOnlyHint');
			$this->assertArrayNotHasKey('destructiveHint', $config, $verb . ' must not be classed destructive');
			$this->assertGreaterThan(40, strlen($config['description']), $verb . ' needs agent-facing description prose');
		}
	}//end testEveryInboxVerbIsReadOnly()

	/**
	 * The search filters are exactly read, organisation and subjectRef, and
	 * each names a declared property, or OpenRegister refuses the schema.
	 *
	 * @return void
	 */
	public function testTheSearchFiltersAreDeclaredProperties(): void {
		$schema = $this->schemas()['portalMessage'];
		$filters = $schema['x-openregister-mcp']['tools']['search']['filters'];

		$this->assertSame(['read', 'organisation', 'subjectRef'], $filters);
		foreach ($filters as $filter) {
			$this->assertArrayHasKey($filter, $schema['properties']);
		}
	}//end testTheSearchFiltersAreDeclaredProperties()

	/**
	 * The dialect does not widen who reads a message: the inbox is still not
	 * anonymously readable, and the recipient field it is scoped on is still
	 * declared.
	 *
	 * @return void
	 */
	public function testTheInboxIsNotOpenedUpByTheDialect(): void {
		$schema = $this->schemas()['portalMessage'];
		$read = ($schema['authorization']['read'] ?? []);

		$this->assertNotSame([], $read, 'portalMessage must declare its read authorization');
		$groups = array_map(
			static fn ($rule) => is_array($rule) === true ? ($rule['group'] ?? null) : $rule,
			$read
		);
		$this->assertNotContains('public', $groups);
		$this->assertArrayHasKey('subjectRef', $schema['properties']);
	}//end testTheInboxIsNotOpenedUpByTheDialect()

	/**
	 * No identity, session or scaffold tool exists, under any verb.
	 *
	 * @return void
	 */
	public function testIdentityAndSessionObjectsAreNotTools(): void {
		foreach ($this->derivedCatalog() as $id) {
			$this->assertStringStartsNotWith('portaliq.portalaccount.', $id);
			$this->assertStringStartsNotWith('portaliq.portalsession.', $id);
			$this->assertStringStartsNotWith('portaliq.exampledocument.', $id);
		}

		$this->assertNotSame([], $this->derivedCatalog(), 'An empty catalog would pass the loop above vacuously.');
	}//end testIdentityAndSessionObjectsAreNotTools()

	/**
	 * The scaffold provider is gone and nothing replaces it: no file under
	 * lib/ implements IMcpToolProvider or carries #[McpTool], and Application
	 * registers no provider alias.
	 *
	 * @return void
	 */
	public function testNoHandWrittenProviderIsRegistered(): void {
		$this->assertFileDoesNotExist(self::ROOT . '/lib/Mcp/ExampleToolProvider.php');
		$this->assertDirectoryDoesNotExist(self::ROOT . '/lib/Mcp');

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/lib', \FilesystemIterator::SKIP_DOTS));
		$scanned = 0;
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$scanned++;
			$code = (string)file_get_contents($file->getPathname());
			$this->assertDoesNotMatchRegularExpression('/implements[^{]*IMcpToolProvider/', $code, $file->getPathname() . ' implements IMcpToolProvider');
			$this->assertDoesNotMatchRegularExpression('/#\[\s*\\\\?(\w+\\\\)*McpTool\b/', $code, $file->getPathname() . ' carries #[McpTool]');
		}

		$this->assertGreaterThan(0, $scanned, 'lib/ moved; this test scanned nothing.');

		$application = (string)file_get_contents(self::ROOT . '/lib/AppInfo/Application.php');
		$this->assertDoesNotMatchRegularExpression('/registerServiceAlias\([^;]*IMcpToolProvider/s', $application);
		$this->assertStringNotContainsString("IMcpToolProvider::class . '::portaliq'", $application);
	}//end testNoHandWrittenProviderIsRegistered()
}//end class
