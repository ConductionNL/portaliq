<?php

/**
 * ObjectRowNormaliserGoldenTest
 *
 * @category Test
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

use PHPUnit\Framework\TestCase;
use JsonSerializable;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Every class that turns an OpenRegister result into an array answers the
 * same way for an array, for an object that serialises to JSON (to an array
 * or to anything else), for a plain object and for a scalar.
 *
 * No coverage metadata on purpose: the test calls one private helper on each
 * of these classes, so class-level coverage metadata would claim the whole class.
 */
class ObjectRowNormaliserGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/object-row-normaliser.json';

	/**
	 * The classes whose normalise() is pinned.
	 */
	private const USERS = [
		'OCA\\Portaliq\\Controller\\PushSubscriptionController',
		'OCA\\Portaliq\\Service\\NewsRowSource',
		'OCA\\Portaliq\\Service\\PortalObjectWriter',
		'OCA\\Portaliq\\Service\\GuardianAudienceFixtureReader',
		'OCA\\Portaliq\\Service\\EventSignupService',
		'OCA\\Portaliq\\Service\\EventRsvpService',
		'OCA\\Portaliq\\Service\\GroupStaffFixtureReader',
		'OCA\\Portaliq\\Service\\ActivityStore',
		'OCA\\Portaliq\\Service\\EventFeedReader',
		'OCA\\Portaliq\\Service\\Notifications\\QuietHoursPolicy',
		'OCA\\Portaliq\\Service\\Notifications\\PendingPushService',
		'OCA\\Portaliq\\Service\\Messaging\\MessageStore',
	];

	/**
	 * Each class answers every input exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryNormaliseAnswersAsRecorded(): void {
		$actual = [];
		foreach (self::USERS as $class) {
			$reflection = new ReflectionClass(objectOrClass: $class);
			$instance   = $reflection->newInstanceWithoutConstructor();
			$method     = new ReflectionMethod(objectOrMethod: $class, method: 'normalise');
			foreach ($this->inputs() as $name => $input) {
				$actual[$class][$name] = $method->invoke($instance, $input);
			}
		}

		if (getenv(name: 'GOLDEN_RECORD') === '1') {
			file_put_contents(filename: self::GOLDEN, data: json_encode(value: $actual, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode(json: (string)file_get_contents(filename: self::GOLDEN), associative: true), actual: $actual);
	}//end testEveryNormaliseAnswersAsRecorded()

	/**
	 * The audience options read a row through the same normalising first:
	 * every input, plus rows with an id and a label, answer as recorded.
	 *
	 * @return void
	 */
	public function testTheAudienceOptionAnswersAsRecorded(): void {
		$class    = 'OCA\\Portaliq\\Service\\NewsAudienceOptions';
		$instance = (new ReflectionClass(objectOrClass: $class))->newInstanceWithoutConstructor();
		$method   = new ReflectionMethod(objectOrMethod: $class, method: 'option');
		$inputs   = $this->inputs() + [
			'titled row'      => ['id' => 'g1', 'title' => 'Groep 1'],
			'serialised row'  => $this->serialising(data: ['uuid' => 'g2', 'name' => 'Groep 2']),
			'self id row'     => ['@self' => ['id' => 'g3']],
		];
		$actual   = [];
		foreach ($inputs as $name => $input) {
			$actual[$name] = $method->invoke($instance, $input);
		}

		$golden = __DIR__ . '/../../Fixtures/Golden/object-row-normaliser-option.json';
		if (getenv(name: 'GOLDEN_RECORD') === '1') {
			file_put_contents(filename: $golden, data: json_encode(value: $actual, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode(json: (string)file_get_contents(filename: $golden), associative: true), actual: $actual);
	}//end testTheAudienceOptionAnswersAsRecorded()

	/**
	 * The inputs, by name.
	 *
	 * @return array<string, mixed>
	 */
	private function inputs(): array {
		return [
			'array'            => ['id' => 'a1', 'title' => 'One'],
			'empty array'      => [],
			'serialises array' => $this->serialising(data: ['id' => 'b2']),
			'serialises empty' => $this->serialising(data: []),
			'serialises text'  => $this->serialising(data: 'text'),
			'serialises null'  => $this->serialising(data: null),
			'serialises obj'   => $this->serialising(data: new stdClass()),
			'plain object'     => new stdClass(),
			'string'           => 'row',
			'null'             => null,
			'int'              => 7,
		];
	}//end inputs()

	/**
	 * An object whose jsonSerialize() answers the given data.
	 *
	 * @param mixed $data What jsonSerialize() returns.
	 *
	 * @return JsonSerializable
	 */
	private function serialising(mixed $data): JsonSerializable {
		return new class($data) implements JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param mixed $data What jsonSerialize() returns.
			 */
			public function __construct(private readonly mixed $data) {
			}

			/**
			 * The data.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};
	}//end serialising()
}//end class
