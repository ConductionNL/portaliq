<?php

/**
 * The boundaries every error report test builds the real service over.
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
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Publication;

use DateTime;
use OCA\Portaliq\Kernel\Contracts\PortalObjectWriterInterface;
use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\Publication\PublicationReportService;
use OCA\Portaliq\Service\Publication\PublicPublicationRead;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Builds the real PublicationReportService over recorded boundaries: the
 * public read answers what `$publications` holds, the writer records every
 * write, the group "woo-redactie" has two members, and the clock is fixed.
 */
final class PublicationReportFixture {
	/**
	 * The fixed clock.
	 */
	public const NOW = '2026-10-02T10:00:00+00:00';

	/**
	 * What the public read answers, by publication id: [status, body].
	 *
	 * @var array<string, array{0: int, 1: array<string, mixed>}>
	 */
	public array $publications = [];

	/**
	 * Each createAnonymousObject() call: [register, schema, data].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $created = [];

	/**
	 * Each updateObject() call: [scopeField, subjectRef, id, data].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
	 */
	public array $updated = [];

	/**
	 * The rows readCollection() answers.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Each notification sent: [user, subject, parameters, objectType, objectId].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $notified = [];

	/**
	 * The in-memory distributed cache.
	 *
	 * @var array<string, mixed>
	 */
	public array $cache = [];

	/**
	 * The publication ids the loopback was asked for, in order.
	 *
	 * @var array<int, string>
	 */
	public array $paths = [];

	/**
	 * What each notification double was given.
	 *
	 * @var \SplObjectStorage<INotification, \ArrayObject>
	 */
	private \SplObjectStorage $states;

	/**
	 * Constructor.
	 *
	 * @param TestCase $test The test, for its mock builder.
	 *
	 * @return void
	 */
	public function __construct(private readonly TestCase $test) {
		$this->states = new \SplObjectStorage();
	}//end __construct()

	/**
	 * A portal with reports on for the group "woo-redactie".
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	public static function portal(array $overrides = []): array {
		return array_merge(
			[
				'slug'                        => 'zuiddrecht',
				'title'                       => 'Zuiddrecht',
				'publicationErrorReports'     => true,
				'publicationErrorReportOwner' => 'woo-redactie',
			],
			$overrides
		);
	}//end portal()

	/**
	 * The real service over the recorded boundaries.
	 *
	 * @return PublicationReportService
	 */
	public function service(): PublicationReportService {
		return new PublicationReportService(
			writer: $this->writer(),
			reader: $this->reader(),
			publications: new PublicPublicationRead(loopback: $this->loopback(), logger: new NullLogger()),
			users: $this->users(),
			groups: $this->groups(),
			notifications: $this->notifications(),
			caches: $this->caches(),
			time: $this->time(),
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * @return InstanceLoopback
	 */
	public function loopback(): InstanceLoopback {
		$loopback = $this->mock(InstanceLoopback::class);
		$loopback->method('request')->willReturnCallback(
			function (string $method, string $path) {
				$id = rawurldecode(substr($path, strlen(PublicPublicationRead::PATH)));
				$this->paths[] = $id;
				[$status, $body] = ($this->publications[$id] ?? [404, ['message' => 'Not found']]);
				$response = $this->mock(IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				$response->method('getBody')->willReturn(json_encode($body));

				return $response;
			}
		);

		return $loopback;
	}//end loopback()

	/**
	 * @return PortalObjectWriterInterface
	 */
	private function writer(): PortalObjectWriterInterface {
		$writer = $this->mock(PortalObjectWriterInterface::class);
		$writer->method('createAnonymousObject')->willReturnCallback(
			function (string $register, string $schema, array $data): array {
				$this->created[] = [$register, $schema, $data];

				return array_merge($data, ['id' => 'report-' . count($this->created)]);
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data): ?array {
				$this->updated[] = [$scopeField, $subjectRef, $id, $data];
				foreach ($this->rows as $row) {
					if (($row['id'] ?? '') === $id && ($row[$scopeField] ?? '') === $subjectRef) {
						return array_merge($row, $data);
					}
				}

				return null;
			}
		);

		return $writer;
	}//end writer()

	/**
	 * @return ScopedCollectionReaderInterface
	 */
	private function reader(): ScopedCollectionReaderInterface {
		$reader = $this->mock(ScopedCollectionReaderInterface::class);
		$reader->method('readCollection')->willReturnCallback(fn (): array => $this->rows);

		return $reader;
	}//end reader()

	/**
	 * Users "anna" and "bram" exist; "woo-redactie" is a group, not a user.
	 *
	 * @return IUserManager
	 */
	private function users(): IUserManager {
		$users = $this->mock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['anna', 'bram', 'carla'], true));

		return $users;
	}//end users()

	/**
	 * The group "woo-redactie" holds anna and bram.
	 *
	 * @return IGroupManager
	 */
	private function groups(): IGroupManager {
		$members = [];
		foreach (['anna', 'bram'] as $uid) {
			$user = $this->mock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$members[] = $user;
		}

		$group = $this->mock(IGroup::class);
		$group->method('getUsers')->willReturn($members);

		$groups = $this->mock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => ($gid === 'woo-redactie' ? $group : null));
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $gid === 'woo-redactie' && in_array($uid, ['anna', 'bram'], true)
		);

		return $groups;
	}//end groups()

	/**
	 * Records each notification's user, subject, parameters and object.
	 *
	 * @return INotificationManager
	 */
	private function notifications(): INotificationManager {
		$manager = $this->mock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$state        = new \ArrayObject();
				$notification = $this->mock(INotification::class);
				foreach (['setApp', 'setUser', 'setDateTime'] as $setter) {
					$notification->method($setter)->willReturnCallback(
						function ($value) use ($state, $setter, $notification) {
							$state[$setter] = $value;
							return $notification;
						}
					);
				}

				$notification->method('setObject')->willReturnCallback(
					function (string $type, string $id) use ($state, $notification) {
						$state['object'] = [$type, $id];
						return $notification;
					}
				);
				$notification->method('setSubject')->willReturnCallback(
					function (string $subject, array $parameters) use ($state, $notification) {
						$state['subject'] = [$subject, $parameters];
						return $notification;
					}
				);
				$notification->method('getUser')->willReturnCallback(static fn (): string => (string)($state['setUser'] ?? ''));
				$this->states[$notification] = $state;

				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$state            = $this->states[$notification];
				$this->notified[] = [
					$state['setUser'] ?? '',
					$state['subject'][0] ?? '',
					$state['subject'][1] ?? [],
					$state['object'][0] ?? '',
					$state['object'][1] ?? '',
				];
			}
		);

		return $manager;
	}//end notifications()

	/**
	 * @return ICacheFactory
	 */
	private function caches(): ICacheFactory {
		$cache = $this->mock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => ($this->cache[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, $value): bool {
				$this->cache[$key] = $value;
				return true;
			}
		);

		$factory = $this->mock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return $factory;
	}//end caches()

	/**
	 * @return ITimeFactory
	 */
	private function time(): ITimeFactory {
		$time = $this->mock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime(self::NOW));
		$time->method('getTime')->willReturn((new DateTime(self::NOW))->getTimestamp());

		return $time;
	}//end time()

	/**
	 * A mock through the test's own builder.
	 *
	 * @param string $class The class or interface.
	 *
	 * @return mixed
	 */
	private function mock(string $class): mixed {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}//end mock()
}//end class
