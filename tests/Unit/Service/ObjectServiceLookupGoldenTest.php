<?php

/**
 * ObjectServiceLookupGoldenTest
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
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use stdClass;

/**
 * Every class that looks OpenRegister's ObjectService up lazily answers the
 * same way: the service when the container has an object for it, and null
 * when the lookup throws or answers something that is not an object.
 *
 * No coverage metadata on purpose: the test calls one private helper on each
 * of these classes, so class-level coverage metadata would claim the whole class.
 */
class ObjectServiceLookupGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/object-service-lookup.json';

	/**
	 * The classes whose lookup is pinned.
	 */
	private const USERS = [
		'OCA\\Portaliq\\Controller\\PushSubscriptionController',
		'OCA\\Portaliq\\Controller\\NewsController',
		'OCA\\Portaliq\\Controller\\EventController',
		'OCA\\Portaliq\\Controller\\NewsletterController',
		'OCA\\Portaliq\\Repair\\BackfillNewsPublishedAt',
		'OCA\\Portaliq\\Repair\\BackfillCmsPortalReference',
		'OCA\\Portaliq\\Repair\\MoveBindingDestination',
		'OCA\\Portaliq\\Repair\\MoveActionSummarySentence',
		'OCA\\Portaliq\\Service\\NewsRowSource',
		'OCA\\Portaliq\\Service\\PortalObjectWriter',
		'OCA\\Portaliq\\Service\\GuardianAudienceFixtureReader',
		'OCA\\Portaliq\\Service\\NewsAudienceOptions',
		'OCA\\Portaliq\\Service\\CaseTypeReader',
		'OCA\\Portaliq\\Service\\EventSignupService',
		'OCA\\Portaliq\\Service\\NewsReadReceiptService',
		'OCA\\Portaliq\\Service\\GuardianAccountDirectory',
		'OCA\\Portaliq\\Service\\EventRsvpService',
		'OCA\\Portaliq\\Service\\GroupStaffFixtureReader',
		'OCA\\Portaliq\\Service\\ActivityStore',
		'OCA\\Portaliq\\Service\\EventFeedReader',
		'OCA\\Portaliq\\Service\\Notifications\\QuietHoursPolicy',
		'OCA\\Portaliq\\Service\\Notifications\\PendingPushService',
		'OCA\\Portaliq\\Service\\Identity\\PortalAccountsByClaim',
		'OCA\\Portaliq\\Service\\ExampleResident\\ExampleResidentStore',
		'OCA\\Portaliq\\Service\\Messaging\\MessageStore',
		'OCA\\Portaliq\\Service\\Availability\\AvailabilityStore',
		'OCA\\Portaliq\\Service\\Traffic\\TrafficEventStore',
		'OCA\\Portaliq\\Service\\Traffic\\TrafficRecordingStore',
	];

	/**
	 * Each class answers the service, or null, exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryLookupAnswersAsRecorded(): void {
		$service = new stdClass();
		$actual  = [];
		foreach (self::USERS as $class) {
			$asked = [];
			$actual[$class] = [
				'object' => $this->lookup(class: $class, answer: ['value' => $service, 'asked' => &$asked]) === $service,
				'throws' => $this->lookup(class: $class, answer: ['value' => new RuntimeException(message: 'no'), 'asked' => &$asked]),
				'string' => $this->lookup(class: $class, answer: ['value' => 'not an object', 'asked' => &$asked]),
				'asked'  => array_values(array: array_unique(array: $asked)),
			];
		}

		if (getenv(name: 'GOLDEN_RECORD') === '1') {
			file_put_contents(filename: self::GOLDEN, data: json_encode(value: $actual, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode(json: (string)file_get_contents(filename: self::GOLDEN), associative: true), actual: $actual);
	}//end testEveryLookupAnswersAsRecorded()

	/**
	 * Run a class's private objectService() over a container that gives one answer.
	 *
	 * @param string $class  The class.
	 * @param array  $answer `value`: what the container returns or throws; `asked`: filled with the ids asked for.
	 *
	 * @return mixed What objectService() returned.
	 */
	private function lookup(string $class, array $answer): mixed {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			callback: static function (string $id) use (&$answer): mixed {
				$answer['asked'][] = $id;
				if ($answer['value'] instanceof RuntimeException) {
					throw $answer['value'];
				}

				return $answer['value'];
			}
		);

		$reflection = new ReflectionClass(objectOrClass: $class);
		$instance   = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty(name: 'container')->setValue(objectOrValue: $instance, value: $container);

		return (new ReflectionMethod(objectOrMethod: $class, method: 'objectService'))->invoke($instance);
	}//end lookup()
}//end class
