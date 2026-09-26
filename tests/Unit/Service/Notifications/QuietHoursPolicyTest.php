<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use DateTimeImmutable;
use OCA\Portaliq\Service\Notifications\QuietHoursPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-non-emergency-push-during-quiet-hours-is-deferred-not-dropped
 */
class QuietHoursPolicyTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function container(array $rows): ContainerInterface {
		$objectService = new class($rows) {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public ?string $uuid = 'unset';

			public function __construct(
				private array $rows,
			) {
			}//end __construct()

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$filters = $config['filters'] ?? [];
				if (isset($filters['subjectRef']) === true) {
					return array_values(array_filter($this->rows, fn (array $r): bool => ($r['subjectRef'] ?? null) === $filters['subjectRef']));
				}

				return $this->rows;
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				$this->uuid = $uuid;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));
		return $container;
	}//end container()

	public function testResolveWindowFallsBackToTheDocumentedDefault(): void {
		$policy = new QuietHoursPolicy($this->container([]), $this->createMock(LoggerInterface::class));
		$window = $policy->resolveWindow('guardian-1');

		$this->assertSame(QuietHoursPolicy::DEFAULT_START, $window['start']);
		$this->assertSame(QuietHoursPolicy::DEFAULT_END, $window['end']);
	}//end testResolveWindowFallsBackToTheDocumentedDefault()

	public function testResolveWindowReturnsAConfiguredWindow(): void {
		$policy = new QuietHoursPolicy($this->container([['subjectRef' => 'guardian-1', 'startTime' => '20:00', 'endTime' => '08:00']]), $this->createMock(LoggerInterface::class));
		$window = $policy->resolveWindow('guardian-1');

		$this->assertSame('20:00', $window['start']);
		$this->assertSame('08:00', $window['end']);
	}//end testResolveWindowReturnsAConfiguredWindow()

	public function testIsQuietNowHandlesASameDayWindow(): void {
		$policy = new QuietHoursPolicy($this->container([['subjectRef' => 'staff-1', 'startTime' => '08:00', 'endTime' => '16:30']]), $this->createMock(LoggerInterface::class));

		$this->assertTrue($policy->isQuietNow('staff-1', new DateTimeImmutable('2026-09-26 09:00')));
		$this->assertFalse($policy->isQuietNow('staff-1', new DateTimeImmutable('2026-09-26 20:00')));
	}//end testIsQuietNowHandlesASameDayWindow()

	public function testIsQuietNowHandlesAWindowThatWrapsMidnight(): void {
		$policy = new QuietHoursPolicy($this->container([['subjectRef' => 'guardian-1', 'startTime' => '22:00', 'endTime' => '07:00']]), $this->createMock(LoggerInterface::class));

		$this->assertTrue($policy->isQuietNow('guardian-1', new DateTimeImmutable('2026-09-26 23:00')));
		$this->assertTrue($policy->isQuietNow('guardian-1', new DateTimeImmutable('2026-09-26 06:00')));
		$this->assertFalse($policy->isQuietNow('guardian-1', new DateTimeImmutable('2026-09-26 14:00')));
	}//end testIsQuietNowHandlesAWindowThatWrapsMidnight()

	public function testWindowEndResolvesToTheNextOccurrence(): void {
		$policy = new QuietHoursPolicy($this->container([['subjectRef' => 'guardian-1', 'startTime' => '22:00', 'endTime' => '07:00']]), $this->createMock(LoggerInterface::class));

		$end = $policy->windowEnd('guardian-1', new DateTimeImmutable('2026-09-26 23:00'));
		$this->assertSame('2026-09-27 07:00', $end->format('Y-m-d H:i'));
	}//end testWindowEndResolvesToTheNextOccurrence()

	public function testSetWindowRejectsAnInvalidTime(): void {
		$policy = new QuietHoursPolicy($this->container([]), $this->createMock(LoggerInterface::class));

		$this->assertFalse($policy->setWindow('guardian-1', '25:00', '07:00'));
	}//end testSetWindowRejectsAnInvalidTime()
}//end class
