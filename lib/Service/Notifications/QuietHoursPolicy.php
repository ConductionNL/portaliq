<?php

/**
 * Quiet Hours Policy
 *
 * The ONE service that decides whether a subject (guardian or staff, by
 * subjectRef) is currently within their configured quiet-hours window —
 * kept as a single call site because the brief names this a candidate to
 * move to an OpenRegister primitive later (`notification-quiet-hours-
 * primitive`, `tier-b-and-sibling.md`), and a single-file rule is what
 * makes that migration small when it happens. Every notification-producing
 * feature in this app MUST go through {@see PushDeliveryService}, which is
 * this class's only caller for the send decision — never re-implement the
 * quiet-hours check elsewhere.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-non-emergency-push-during-quiet-hours-is-deferred-not-dropped
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use OCA\Portaliq\Service\ObjectRowNormaliser;
use OCA\Portaliq\Service\ObjectServiceLookup;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCP\IConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-non-emergency-push-during-quiet-hours-is-deferred-not-dropped
 */
class QuietHoursPolicy {
	use ObjectRowNormaliser;
	use ObjectServiceLookup;

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'notificationQuietHours';

	/**
	 * The documented default window when a subject has configured none —
	 * a sensible night-time default, matching the corpus convention (Kwieb
	 * "Niet storen", Social Schools "Werktijden instellen").
	 */
	public const DEFAULT_START = '22:00';

	public const DEFAULT_END = '07:00';

	/**
	 * The zone a window's clock times are read in when neither the subject
	 * nor the instance names one. Nextcloud runs PHP in UTC, so without this
	 * a 22:00-07:00 window would hold back pushes from 07:00 to 09:00 Dutch
	 * summer time and let them through from 22:00 to midnight.
	 */
	public const FALLBACK_TIMEZONE = 'Europe/Amsterdam';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 * @param IConfig $config For the subject's and the instance's time zone.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Set a subject's own quiet-hours window (`HH:MM` 24h strings).
	 *
	 * @param string $subjectRef The subject's own subjectRef (guardian or staff).
	 * @param string $start Window start, `HH:MM`.
	 * @param string $end Window end, `HH:MM`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
	 */
	public function setWindow(string $subjectRef, string $start, string $end): bool {
		if ($subjectRef === '' || $this->isValidTime(value: $start) === false || $this->isValidTime(value: $end) === false) {
			return false;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		$existingId = $this->findExistingId(objectService: $objectService, subjectRef: $subjectRef);

		try {
			$objectService->saveObject(
				object: ['subjectRef' => $subjectRef, 'startTime' => $start, 'endTime' => $end],
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $existingId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: quiet hours save failed', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end setWindow()

	/**
	 * A subject's own configured window, or the documented default.
	 *
	 * @param string $subjectRef The subject's own subjectRef.
	 *
	 * @return array{start: string, end: string}
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
	 */
	public function resolveWindow(string $subjectRef): array {
		$default = ['start' => self::DEFAULT_START, 'end' => self::DEFAULT_END];

		$row = $this->ownRow(subjectRef: $subjectRef);
		if ($row === null) {
			return $default;
		}

		return $this->validWindow(row: $row, default: $default);
	}//end resolveWindow()

	/**
	 * The subject's stored window row, or null when there is none or it
	 * cannot be read (a failed read is logged).
	 *
	 * @param string $subjectRef The subject's own subjectRef.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ownRow(string $subjectRef): ?array {
		if ($subjectRef === '') {
			return null;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		return $this->firstRow(rows: $this->readOwnRows(objectService: $objectService, subjectRef: $subjectRef));
	}//end ownRow()

	/**
	 * The subject's window rows; null when the read fails, which is logged.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $subjectRef    The subject's own subjectRef.
	 *
	 * @return mixed What the read returned, or null.
	 */
	private function readOwnRows(object $objectService, string $subjectRef): mixed {
		try {
			return $this->subjectRows(objectService: $objectService, subjectRef: $subjectRef);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: quiet hours read failed', ['reason' => $e->getMessage()]);
			return null;
		}
	}//end readOwnRows()

	/**
	 * The stored window when both of its times are valid, else the default.
	 *
	 * @param array<string, mixed>               $row     The stored row.
	 * @param array{start: string, end: string} $default The documented default.
	 *
	 * @return array{start: string, end: string}
	 */
	private function validWindow(array $row, array $default): array {
		$start = (string)($row['startTime'] ?? '');
		$end   = (string)($row['endTime'] ?? '');
		if ($this->isValidTime(value: $start) === false || $this->isValidTime(value: $end) === false) {
			return $default;
		}

		return ['start' => $start, 'end' => $end];
	}//end validWindow()

	/**
	 * The subject's window rows, read without access rules; throws when the read fails.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $subjectRef    The subject's own subjectRef.
	 *
	 * @return mixed What the read returned.
	 */
	private function subjectRows(object $objectService, string $subjectRef): mixed {
		$objectService->setRegister(register: self::REGISTER);
		$objectService->setSchema(schema: self::SCHEMA);

		return $objectService->findAll(
			config: ['filters' => ['subjectRef' => $subjectRef], 'limit' => 1, 'offset' => 0],
			_rbac: false,
			_multitenancy: false
		);
	}//end subjectRows()

	/**
	 * The first row of a read, as an array, or null when there is none.
	 *
	 * @param mixed $rows What the read returned.
	 *
	 * @return array<string, mixed>|null
	 */
	private function firstRow(mixed $rows): ?array {
		if (is_array($rows) === false || count($rows) === 0) {
			return null;
		}

		return $this->normalise(row: $rows[0]);
	}//end firstRow()

	/**
	 * Whether a subject is currently within their quiet-hours window, read
	 * on the clock of the subject's time zone ({@see self::timeZoneFor()}).
	 * Handles a window that wraps midnight (e.g. 22:00-07:00).
	 *
	 * @param string $subjectRef The subject's own subjectRef.
	 * @param DateTimeImmutable|null $now Testable clock; defaults to now.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-non-emergency-push-during-quiet-hours-is-deferred-not-dropped
	 */
	public function isQuietNow(string $subjectRef, ?DateTimeImmutable $now = null): bool {
		$window = $this->resolveWindow(subjectRef: $subjectRef);
		$current = ($now ?? new DateTimeImmutable())->setTimezone($this->timeZoneFor(subjectRef: $subjectRef))->format('H:i');

		if ($window['start'] <= $window['end']) {
			// A same-day window, e.g. 08:00-16:30.
			return $current >= $window['start'] && $current < $window['end'];
		}

		// A window that wraps midnight, e.g. 22:00-07:00.
		return $current >= $window['start'] || $current < $window['end'];
	}//end isQuietNow()

	/**
	 * The next moment a subject's quiet-hours window ends, from `$now`, on
	 * the clock of the subject's time zone. Used to set a deferred push's
	 * `deliverAfter`, which keeps its offset, so the moment stays exact.
	 *
	 * @param string $subjectRef The subject's own subjectRef.
	 * @param DateTimeImmutable|null $now Testable clock; defaults to now.
	 *
	 * @return DateTimeImmutable
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-non-emergency-push-during-quiet-hours-is-deferred-not-dropped
	 */
	public function windowEnd(string $subjectRef, ?DateTimeImmutable $now = null): DateTimeImmutable {
		$window = $this->resolveWindow(subjectRef: $subjectRef);
		$now = ($now ?? new DateTimeImmutable())->setTimezone($this->timeZoneFor(subjectRef: $subjectRef));
		[$hour, $minute] = array_map('intval', explode(':', $window['end']));

		$end = $now->setTime(hour: $hour, minute: $minute, second: 0);
		if ($end <= $now) {
			$end = $end->add(new DateInterval('P1D'));
		}

		return $end;
	}//end windowEnd()

	/**
	 * The time zone a subject's window is read in: the subject's own
	 * Nextcloud time zone when the subject is a user who has one (staff),
	 * else the instance's `default_timezone`, else {@see self::FALLBACK_TIMEZONE}.
	 * An unknown zone name is skipped, never fatal.
	 *
	 * @param string $subjectRef The subject's own subjectRef.
	 *
	 * @return DateTimeZone
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-non-emergency-push-during-quiet-hours-is-deferred-not-dropped
	 */
	public function timeZoneFor(string $subjectRef): DateTimeZone {
		$candidates = [
			$this->userTimeZoneName(subjectRef: $subjectRef),
			$this->config->getSystemValueString('default_timezone', ''),
		];

		foreach ($candidates as $name) {
			if ($name === '') {
				continue;
			}

			try {
				return new DateTimeZone($name);
			} catch (Throwable $e) {
				$this->logger->debug('Portaliq: quiet hours skips an unknown time zone', ['zone' => $name]);
			}
		}

		return new DateTimeZone(self::FALLBACK_TIMEZONE);
	}//end timeZoneFor()

	/**
	 * The subject's own Nextcloud time zone name, or ''. Nextcloud refuses a
	 * user id it cannot store (longer than 64 bytes, as a subjectRef taken
	 * from an identity provider's claim can be); such a subject is not a
	 * Nextcloud user, so it simply has no zone of its own.
	 *
	 * @param string $subjectRef The subject's own subjectRef.
	 *
	 * @return string
	 */
	private function userTimeZoneName(string $subjectRef): string {
		try {
			return (string)$this->config->getUserValue($subjectRef, 'core', 'timezone', '');
		} catch (Throwable $e) {
			$this->logger->debug('Portaliq: quiet hours cannot read a time zone for this subject', ['reason' => $e->getMessage()]);
			return '';
		}
	}//end userTimeZoneName()

	/**
	 * Whether a value is a valid `HH:MM` 24h time string.
	 *
	 * @param string $value The value to check.
	 *
	 * @return bool
	 */
	private function isValidTime(string $value): bool {
		return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
	}//end isValidTime()

	/**
	 * The id of an existing window row for a subject, if any.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $subjectRef The subject's own subjectRef.
	 *
	 * @return string|null
	 */
	private function findExistingId(object $objectService, string $subjectRef): ?string {
		try {
			$rows = $this->subjectRows(objectService: $objectService, subjectRef: $subjectRef);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: quiet hours lookup failed', ['reason' => $e->getMessage()]);
			return null;
		}

		$row = $this->firstRow(rows: $rows);
		if ($row === null) {
			return null;
		}

		return $this->rowId(row: $row);
	}//end findExistingId()

	/**
	 * A row's id, flat or from its `@self` envelope, or null.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null
	 */
	private function rowId(array $row): ?string {
		$id = ($row['id'] ?? $this->selfId(row: $row));
		if ($id === null) {
			return null;
		}

		return (string)$id;
	}//end rowId()

	/**
	 * The id in a row's `@self` envelope, or null.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return mixed
	 */
	private function selfId(array $row): mixed {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			return null;
		}

		return ($self['id'] ?? null);
	}//end selfId()
}//end class
