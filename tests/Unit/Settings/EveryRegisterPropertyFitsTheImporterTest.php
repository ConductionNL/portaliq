<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Every property of every schema in lib/Settings/portaliq_register.json fits
 * the rule OpenRegister's importer enforces (PropertyValidatorHandler::
 * validateProperty, read on openregister origin/development):
 *
 * - `type`, when present, is ONE string from the importer's type list; a
 *   JSON Schema union such as ["object", "string"] is refused;
 * - every key on a property is in the importer's vocabulary (an `x-` key
 *   and a localised title or description excepted);
 * - a string `format` is one the importer knows; an `enum` is a non-empty list;
 * - `oneOf` alternatives, array `items` and object `properties` are held to
 *   the same rule, as the importer walks them.
 *
 * A schema that breaks it is rejected at import with "Invalid type" and the
 * import still answers success, so the instance keeps the old schema while
 * the version row moves. This test refuses such a file in CI. The lists come
 * from tests/Unit/Settings/fixtures/openregister-property-vocabulary.json.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */
class EveryRegisterPropertyFitsTheImporterTest extends TestCase {
	/**
	 * The importer's vocabulary.
	 *
	 * @var array<string, mixed>
	 */
	private static array $vocabulary = [];

	public static function setUpBeforeClass(): void {
		self::$vocabulary = (array)json_decode((string)file_get_contents(__DIR__ . '/fixtures/openregister-property-vocabulary.json'), true);
	}//end setUpBeforeClass()

	/**
	 * Every property of every shipped schema fits the importer's rule.
	 *
	 * @return void
	 */
	public function testEveryShippedPropertyFitsTheImportersRule(): void {
		$register = (array)json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/portaliq_register.json'), true);
		$problems = [];
		foreach ($register['components']['schemas'] as $name => $schema) {
			foreach ((array)($schema['properties'] ?? []) as $property => $definition) {
				$problems = array_merge($problems, $this->problems(property: $definition, path: $name . '/' . $property));
			}
		}

		$this->assertSame([], $problems);
	}//end testEveryShippedPropertyFitsTheImportersRule()

	/**
	 * The rule refuses what the importer refuses: a union type, an unknown
	 * key, an unknown format, an empty enum, and the same inside items,
	 * properties and oneOf; it accepts a plain object, oneOf and x- keys.
	 *
	 * @return void
	 */
	public function testTheRuleRefusesWhatTheImporterRefuses(): void {
		$this->assertSame([], $this->problems(property: ['type' => 'object', 'title' => 'A', 'description' => 'B', 'x-note' => 1], path: 'p'));
		$this->assertSame([], $this->problems(property: ['oneOf' => [['type' => 'string'], ['type' => 'object']]], path: 'p'));
		$this->assertSame([], $this->problems(property: ['type' => 'string', 'title_nl' => 'A'], path: 'p'));

		$refused = [
			'union'          => ['type' => ['object', 'string']],
			'unknown type'   => ['type' => 'map'],
			'unknown key'    => ['type' => 'string', 'flavour' => 'x'],
			'unknown format' => ['type' => 'string', 'format' => 'banana'],
			'empty enum'     => ['type' => 'string', 'enum' => []],
			'in items'       => ['type' => 'array', 'items' => ['type' => ['string', 'null']]],
			'in properties'  => ['type' => 'object', 'properties' => ['a' => ['type' => ['object', 'string']]]],
			'in oneOf'       => ['oneOf' => [['type' => 'map']]],
		];
		foreach ($refused as $case => $property) {
			$this->assertNotSame([], $this->problems(property: $property, path: 'p'), $case);
		}
	}//end testTheRuleRefusesWhatTheImporterRefuses()

	/**
	 * What the importer would refuse about one property, walked as it walks.
	 *
	 * @param mixed  $property The property definition.
	 * @param string $path     Where it sits.
	 *
	 * @return array<int, string>
	 */
	private function problems(mixed $property, string $path): array {
		if (is_array($property) === false) {
			return [$path . ': not an object'];
		}

		$out = [];
		foreach (array_keys($property) as $key) {
			$key = (string)$key;
			if (str_starts_with($key, 'x-') === true || is_numeric($key) === true || in_array($key, self::$vocabulary['keys'], true) === true
				|| preg_match('/^(title|description)_[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $key) === 1
			) {
				continue;
			}

			$out[] = $path . ': unknown key ' . $key;
		}

		if (isset($property['oneOf']) === true) {
			foreach ((array)$property['oneOf'] as $index => $alternative) {
				$out = array_merge($out, $this->problems(property: $alternative, path: $path . '/oneOf/' . $index));
			}

			return $out;
		}

		if (array_key_exists('type', $property) === false) {
			return $out;
		}

		$type = $property['type'];
		if (is_string($type) === false || in_array($type, self::$vocabulary['types'], true) === false) {
			return array_merge($out, [$path . ': type ' . json_encode($type)]);
		}

		return array_merge($out, $this->nested(property: $property, type: $type, path: $path));
	}//end problems()

	/**
	 * The format, enum, items and properties of a typed property.
	 *
	 * @param array<string, mixed> $property The property definition.
	 * @param string               $type     Its type.
	 * @param string               $path     Where it sits.
	 *
	 * @return array<int, string>
	 */
	private function nested(array $property, string $type, string $path): array {
		$out = [];
		if ($type === 'string' && isset($property['format']) === true && in_array($property['format'], self::$vocabulary['stringFormats'], true) === false) {
			$out[] = $path . ': format ' . json_encode($property['format']);
		}

		if (array_key_exists('enum', $property) === true && (is_array($property['enum']) === false || $property['enum'] === [])) {
			$out[] = $path . ': empty enum';
		}

		if ($type === 'array' && is_array($property['items'] ?? null) === true && isset($property['items']['$ref']) === false) {
			$out = array_merge($out, $this->problems(property: $property['items'], path: $path . '/items'));
		}

		if ($type === 'object' && is_array($property['properties'] ?? null) === true) {
			foreach ($property['properties'] as $name => $child) {
				$out = array_merge($out, $this->problems(property: $child, path: $path . '/' . $name));
			}
		}

		return $out;
	}//end nested()
}//end class
