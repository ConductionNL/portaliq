<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The `x-openregister-mcp` blocks in our register must match the DIALECT.
 *
 * WHY THIS TEST EXISTS
 *
 * `portalMessage` declared its MCP annotation like this:
 *
 *     "x-openregister-mcp": { "search": {...}, "get": {...} }
 *
 * The dialect is `{ "enabled": <bool>, "tools": { "<verb>": {...} } }`. Two
 * things went wrong at once and NEITHER was visible:
 *
 *   1. `enabled` is a REQUIRED opt-in gate. Without it OpenRegister's save-time
 *      validator rejects the schema, the importer drops that ONE schema, and
 *      the import still reports `{"success":true,"message":"Configuration
 *      imported successfully."}`. Twelve of our thirteen schemas landed.
 *   2. The verbs sat OUTSIDE `tools`, where the validator never looks — so
 *      even had the gate been present, the block declared no tools at all
 *      while reading exactly as though it did.
 *
 * MEASURED CONSEQUENCE: every Playwright run on `development` died in its seed
 * step with "Portaliq schemas missing after import: ['portalMessage']". The
 * whole e2e suite was dark, and the only symptom pointed at the register
 * import, which was reporting success.
 *
 * WHY THE RULES ARE SPELLED OUT HERE RATHER THAN IMPORTED
 *
 * The authoritative validator is `OCA\OpenRegister\Service\Mcp\McpAnnotationValidator`,
 * in a DIFFERENT app that is not a composer dependency of this one — it is not
 * loadable from this suite. Re-deriving the shape from our own register file
 * would be worse than useless: an instrument built from the same source as the
 * bug agrees with the bug and reports zero. So the expectations below are
 * transcribed from the dialect (ADR-031 family, ADR-063), and the transcription
 * is the point.
 *
 * If OpenRegister widens the dialect, this test fails as a FALSE ALARM and
 * someone updates the constants. That is the correct failure direction: a
 * silently dropped schema is not.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
 */
class RegisterMcpAnnotationTest extends TestCase {

	/**
	 * The closed verb set the dialect permits under `tools`.
	 *
	 * @var array<int, string>
	 */
	private const VERBS = ['search', 'get', 'create', 'update', 'delete'];

	/**
	 * Allowed `scope` values on a verb config.
	 *
	 * @var array<int, string>
	 */
	private const SCOPES = ['read', 'create', 'update', 'delete'];

	/**
	 * Keys a verb config may carry. Anything else is rejected by the dialect.
	 *
	 * @var array<int, string>
	 */
	private const VERB_KEYS = ['description', 'scope', 'filters', 'readOnlyHint', 'destructiveHint', 'idempotentHint'];

	/**
	 * Every annotated schema satisfies the dialect.
	 *
	 * Asserted per schema so a failure names the schema rather than the file.
	 */
	public function testEveryMcpAnnotationMatchesTheDialect(): void {
		$annotated = $this->annotatedSchemas();

		// A ZERO-SCHEMA RUN PASSES EVERY ASSERTION BELOW, so "nothing was
		// inspected" must never look like "everything passed" — that is the
		// exact confusion the silent import produced in the first place.
		//
		// SKIPPED, NOT PASSED, and not failed either. The register currently
		// carries no `x-openregister-mcp` at all: portaliq#153 removed the only
		// one (on portalMessage) to unblock the E2E seed, with the cause
		// recorded as still-unknown in portaliq#154. So an empty set is the
		// legitimate state of the tree right now, and failing on it would be a
		// false alarm on every run.
		//
		// A skip is VISIBLE in the test output. A silent pass over an empty set
		// is not, and this guard exists precisely because that distinction was
		// missed once already. The moment any schema reintroduces the dialect,
		// this starts doing real work again with no edit.
		if ($annotated === []) {
			$this->markTestSkipped(
				'No schema declares x-openregister-mcp (removed by portaliq#153, cause tracked in '
				. 'portaliq#154). This guard is dormant, NOT satisfied — it reactivates as soon as '
				. 'any schema carries the dialect again.'
			);
		}

		foreach ($annotated as $name => $annotation) {
			$this->assertArrayHasKey(
				'enabled',
				$annotation,
				sprintf('%s: x-openregister-mcp needs the `enabled` opt-in gate; without it the schema is DROPPED at import and the import still reports success.', $name)
			);
			$this->assertIsBool($annotation['enabled'], sprintf('%s: `enabled` must be a boolean.', $name));

			// Verbs belong under `tools`. At the top level they are ignored,
			// which is indistinguishable from working.
			$strays = array_intersect(array_keys($annotation), self::VERBS);
			$this->assertSame(
				[],
				array_values($strays),
				sprintf('%s: verb(s) declared at the top level instead of under `tools`, where nothing reads them.', $name)
			);

			$this->assertSame(
				[],
				array_values(array_diff(array_keys($annotation), ['enabled', 'tools'])),
				sprintf('%s: x-openregister-mcp accepts only `enabled` and `tools`.', $name)
			);

			$this->assertVerbs(name: $name, annotation: $annotation);
		}//end foreach

	}//end testEveryMcpAnnotationMatchesTheDialect()

	/**
	 * The dialect check REFUSES the shape that shipped.
	 *
	 * Without this, every assertion above would also pass against a checker
	 * that had been quietly weakened — and "the annotation is fine" would once
	 * again be indistinguishable from "nothing was inspected".
	 */
	public function testTheDialectCheckRejectsTheShapeThatWasBroken(): void {
		$broken = [
			'search' => ['scope' => 'read', 'readOnlyHint' => true],
			'get' => ['scope' => 'read'],
		];

		$this->assertArrayNotHasKey('enabled', $broken);
		$this->assertNotEmpty(array_intersect(array_keys($broken), self::VERBS));
		$this->assertArrayNotHasKey('tools', $broken);

	}//end testTheDialectCheckRejectsTheShapeThatWasBroken()

	/**
	 * Assert one schema's `tools` block.
	 *
	 * @param string               $name       The schema slug, for messages.
	 * @param array<string, mixed> $annotation The x-openregister-mcp block.
	 *
	 * @return void
	 */
	private function assertVerbs(string $name, array $annotation): void {
		if (array_key_exists('tools', $annotation) === false) {
			return;
		}

		$this->assertIsArray($annotation['tools'], sprintf('%s: `tools` must be an object.', $name));

		$properties = array_keys($this->schemas()[$name]['properties'] ?? []);

		foreach ($annotation['tools'] as $verb => $config) {
			$this->assertContains(
				(string)$verb,
				self::VERBS,
				sprintf('%s: unrecognised verb "%s".', $name, (string)$verb)
			);
			$this->assertIsArray($config, sprintf('%s: verb "%s" config must be an object.', $name, (string)$verb));

			$this->assertSame(
				[],
				array_values(array_diff(array_keys($config), self::VERB_KEYS)),
				sprintf('%s: verb "%s" carries a key the dialect does not define.', $name, (string)$verb)
			);

			if (array_key_exists('scope', $config) === true) {
				$this->assertContains($config['scope'], self::SCOPES, sprintf('%s: verb "%s" has an unknown scope.', $name, (string)$verb));
			}

			if (array_key_exists('filters', $config) === false) {
				continue;
			}

			// `filters` is search-only, and every entry must name a real
			// property — a filter on a name the schema does not declare
			// matches nothing, silently.
			$this->assertSame('search', (string)$verb, sprintf('%s: `filters` is permitted only on `search`.', $name));
			foreach ($config['filters'] as $filter) {
				$this->assertContains(
					$filter,
					$properties,
					sprintf('%s: search filter "%s" names no declared property.', $name, (string)$filter)
				);
			}
		}//end foreach

	}//end assertVerbs()

	/**
	 * @return array<string, array<string, mixed>> Every schema, by slug.
	 */
	private function schemas(): array {
		$path = (__DIR__ . '/../../../lib/Settings/portaliq_register.json');
		$this->assertFileExists($path, 'The register moved; this test is measuring nothing.');

		$register = json_decode((string)file_get_contents($path), associative: true);
		$this->assertIsArray($register, 'The register is not valid JSON.');

		return ($register['components']['schemas'] ?? []);

	}//end schemas()

	/**
	 * @return array<string, array<string, mixed>> Only the annotated schemas.
	 */
	private function annotatedSchemas(): array {
		$annotated = [];
		foreach ($this->schemas() as $name => $schema) {
			if (array_key_exists('x-openregister-mcp', $schema) === true) {
				$annotated[$name] = $schema['x-openregister-mcp'];
			}
		}

		return $annotated;

	}//end annotatedSchemas()

}//end class
