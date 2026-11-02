<?php

/**
 * ReminderRowGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use JsonSerializable;
use OCA\Portaliq\Service\ActionReminderService;
use OCA\Portaliq\Service\Plans\PlanEndReminderService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * The row the action reminder and the plan end reminder read off a stored
 * row, pinned per row shape for both.
 *
 * @covers \OCA\Portaliq\Service\ActionReminderService
 * @covers \OCA\Portaliq\Service\Plans\PlanEndReminderService
 */
class ReminderRowGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/reminder-row.json';

	/**
	 * Every row matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryRowMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->rows() as $name => $row) {
			foreach ([ActionReminderService::class, PlanEndReminderService::class] as $class) {
				$reflection = new ReflectionClass(objectOrClass: $class);
				$reminder   = $reflection->newInstanceWithoutConstructor();
				$actual[$name][$reflection->getShortName()] = $reflection->getMethod(name: 'asArray')->invoke($reminder, $row);
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode((string)file_get_contents(self::GOLDEN), true), actual: json_decode((string)json_encode($actual), true));
	}//end testEveryRowMatchesTheGolden()

	/**
	 * Stored rows by name.
	 *
	 * @return array<string, mixed>
	 */
	private function rows(): array {
		$entity      = new class implements JsonSerializable {
			/**
			 * An entity that serialises to a row.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return ['id' => 'a1', 'title' => 'Formulier'];
			}
		};
		$objectRow   = new class implements JsonSerializable {
			/**
			 * An entity that serialises to an object.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				$object     = new stdClass();
				$object->id = 'a2';

				return $object;
			}
		};
		$textRow     = new class implements JsonSerializable {
			/**
			 * An entity that serialises to text.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return 'tekst';
			}
		};
		$plainObject     = new stdClass();
		$plainObject->id = 'a3';

		return [
			'array' => ['id' => 'a0', 'title' => 'Plan'],
			'empty array' => [],
			'entity' => $entity,
			'entity as object' => $objectRow,
			'entity as text' => $textRow,
			'object without jsonSerialize' => $plainObject,
			'text' => 'tekst',
			'null' => null,
			'number' => 7,
		];
	}//end rows()
}//end class
