<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\Notifications\QuietHoursPolicy;
use OCP\IConfig;
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

	/**
	 * A config that names these zones for the subject and the instance.
	 *
	 * @param string $userZone The subject's own Nextcloud time zone, or ''.
	 * @param string $instanceZone The instance's default_timezone, or ''.
	 */
	private function config(string $userZone = '', string $instanceZone = ''): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($userZone);
		$config->method('getSystemValueString')->willReturn($instanceZone);
		return $config;
	}//end config()

	/**
	 * A moment on the Dutch clock, the zone the policy falls back to.
	 *
	 * @param string $time The wall-clock time.
	 */
	private function local(string $time): DateTimeImmutable {
		return new DateTimeImmutable($time, new DateTimeZone(QuietHoursPolicy::FALLBACK_TIMEZONE));
	}//end local()

	public function testResolveWindowFallsBackToTheDocumentedDefault(): void {
		$policy = new QuietHoursPolicy($this->container([]), $this->createMock(LoggerInterface::class), $this->config());
		$window = $policy->resolveWindow('guardian-1');

		$this->assertSame(QuietHoursPolicy::DEFAULT_START, $window['start']);
		$this->assertSame(QuietHoursPolicy::DEFAULT_END, $window['end']);
	}//end testResolveWindowFallsBackToTheDocumentedDefault()

	public function testResolveWindowReturnsAConfiguredWindow(): void {
		$policy = new QuietHoursPolicy($this->container([['subjectRef' => 'guardian-1', 'startTime' => '20:00', 'endTime' => '08:00']]), $this->createMock(LoggerInterface::class), $this->config());
		$window = $policy->resolveWindow('guardian-1');

		$this->assertSame('20:00', $window['start']);
		$this->assertSame('08:00', $window['end']);
	}//end testResolveWindowReturnsAConfiguredWindow()

	public function testIsQuietNowHandlesASameDayWindow(): void {
		$policy = new QuietHoursPolicy($this->container([['subjectRef' => 'staff-1', 'startTime' => '08:00', 'endTime' => '16:30']]), $this->createMock(LoggerInterface::class), $this->config());

		$this->assertTrue($policy->isQuietNow('staff-1', $this->local('2026-09-26 09:00')));
		$this->assertFalse($policy->isQuietNow('staff-1', $this->local('2026-09-26 20:00')));
	}//end testIsQuietNowHandlesASameDayWindow()

	public function testIsQuietNowHandlesAWindowThatWrapsMidnight(): void {
		$policy = new QuietHoursPolicy($this->container([['subjectRef' => 'guardian-1', 'startTime' => '22:00', 'endTime' => '07:00']]), $this->createMock(LoggerInterface::class), $this->config());

		$this->assertTrue($policy->isQuietNow('guardian-1', $this->local('2026-09-26 23:00')));
		$this->assertTrue($policy->isQuietNow('guardian-1', $this->local('2026-09-26 06:00')));
		$this->assertFalse($policy->isQuietNow('guardian-1', $this->local('2026-09-26 14:00')));
	}//end testIsQuietNowHandlesAWindowThatWrapsMidnight()

	public function testWindowEndResolvesToTheNextOccurrence(): void {
		$policy = new QuietHoursPolicy($this->container([['subjectRef' => 'guardian-1', 'startTime' => '22:00', 'endTime' => '07:00']]), $this->createMock(LoggerInterface::class), $this->config());

		$end = $policy->windowEnd('guardian-1', $this->local('2026-09-26 23:00'));
		$this->assertSame('2026-09-27 07:00', $end->format('Y-m-d H:i'));
	}//end testWindowEndResolvesToTheNextOccurrence()

	public function testSetWindowRejectsAnInvalidTime(): void {
		$policy = new QuietHoursPolicy($this->container([]), $this->createMock(LoggerInterface::class), $this->config());

		$this->assertFalse($policy->setWindow('guardian-1', '25:00', '07:00'));
	}//end testSetWindowRejectsAnInvalidTime()

	/**
	 * Nextcloud runs PHP in UTC; the window is read on the Dutch clock. In
	 * summer 20:30 UTC is 22:30 in Amsterdam (quiet) and 05:30 UTC is 07:30
	 * (no longer quiet).
	 */
	public function testTheWindowIsReadOnTheLocalClockNotOnUtc(): void {
		$policy = new QuietHoursPolicy($this->container([]), $this->createMock(LoggerInterface::class), $this->config());
		$utc = new DateTimeZone('UTC');

		$this->assertTrue($policy->isQuietNow('guardian-1', new DateTimeImmutable('2026-07-01 20:30', $utc)));
		$this->assertFalse($policy->isQuietNow('guardian-1', new DateTimeImmutable('2026-07-01 05:30', $utc)));

		$end = $policy->windowEnd('guardian-1', new DateTimeImmutable('2026-07-01 20:30', $utc));
		$this->assertSame('2026-07-02T07:00:00+02:00', $end->format('c'));
		$this->assertSame('2026-07-02 05:00', $end->setTimezone($utc)->format('Y-m-d H:i'));
	}//end testTheWindowIsReadOnTheLocalClockNotOnUtc()

	/**
	 * The subject's own zone wins over the instance's, the instance's over the
	 * fallback, and an unknown zone name is skipped.
	 */
	public function testTheTimeZoneComesFromTheSubjectThenTheInstance(): void {
		$logger = $this->createMock(LoggerInterface::class);

		$this->assertSame('America/New_York', (new QuietHoursPolicy($this->container([]), $logger, $this->config('America/New_York', 'Asia/Tokyo')))->timeZoneFor('staff-1')->getName());
		$this->assertSame('Asia/Tokyo', (new QuietHoursPolicy($this->container([]), $logger, $this->config('', 'Asia/Tokyo')))->timeZoneFor('guardian-1')->getName());
		$this->assertSame('Asia/Tokyo', (new QuietHoursPolicy($this->container([]), $logger, $this->config('Not/AZone', 'Asia/Tokyo')))->timeZoneFor('staff-1')->getName());
		$this->assertSame(QuietHoursPolicy::FALLBACK_TIMEZONE, (new QuietHoursPolicy($this->container([]), $logger, $this->config('', 'Not/AZone')))->timeZoneFor('guardian-1')->getName());
	}//end testTheTimeZoneComesFromTheSubjectThenTheInstance()

	/**
	 * Nextcloud refuses a user id longer than 64 bytes; a subjectRef from an
	 * identity provider's claim can be one. Such a subject has no zone of its
	 * own, and the instance's zone is used.
	 */
	public function testASubjectNextcloudCannotLookUpFallsBackToTheInstanceZone(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willThrowException(new \InvalidArgumentException('Value for userId is too long (64)'));
		$config->method('getSystemValueString')->willReturn('Asia/Tokyo');
		$policy = new QuietHoursPolicy($this->container([]), $this->createMock(LoggerInterface::class), $config);

		$this->assertSame('Asia/Tokyo', $policy->timeZoneFor(str_repeat('claim-subject-', 6))->getName());
	}//end testASubjectNextcloudCannotLookUpFallsBackToTheInstanceZone()
}//end class
