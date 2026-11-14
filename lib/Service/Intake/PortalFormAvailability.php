<?php

/**
 * Portaliq Form Availability
 *
 * Whether a bound form takes submissions right now, and if not, which
 * situation the resident is shown instead: not yet open, closed, replaced,
 * in maintenance or full for the current period.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\PortalObjectWriter;
use Throwable;

/**
 * Reads a binding's `availability` against the clock and the submission count.
 *
 * The count is the number of objects the form's destination received in the
 * current period (decision 179: a submit writes the destination object
 * directly, there is no portal-side submission store to count).
 */
class PortalFormAvailability {
	/**
	 * The zone dates and maintenance windows are written in.
	 */
	public const ZONE = 'Europe/Amsterdam';

	/**
	 * The form has not opened yet.
	 */
	public const NOT_YET_OPEN = 'notYetOpen';

	/**
	 * The form's active period has ended and nothing replaced it.
	 */
	public const CLOSED = 'closed';

	/**
	 * Another form replaced this one.
	 */
	public const REPLACED = 'replaced';

	/**
	 * The form is in its maintenance window.
	 */
	public const MAINTENANCE = 'maintenance';

	/**
	 * The limit of the current period is reached.
	 */
	public const FULL = 'full';

	/**
	 * The periods a limit may count over.
	 */
	private const PERIODS = ['total', 'month', 'year'];

	/**
	 * Constructor.
	 *
	 * @param DestinationMapping      $mapping The binding's destination pair.
	 * @param PortalObjectWriter|null $objects Counts the destination's objects; null counts nothing.
	 */
	public function __construct(
		private readonly DestinationMapping $mapping,
		private readonly ?PortalObjectWriter $objects = null,
	) {
	}//end __construct()

	/**
	 * Why the form is not available now, or null when it takes submissions.
	 *
	 * Checked in this order: replaced, not yet open, closed, maintenance,
	 * full. Only the limit asks OpenRegister anything.
	 *
	 * @param array<string, mixed>   $binding The binding.
	 * @param DateTimeImmutable|null $now     The moment to judge; now when null.
	 *
	 * @return array<string, mixed>|null `situation` and what its page shows.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
	 */
	public function unavailable(array $binding, ?DateTimeImmutable $now = null): ?array {
		$availability = $binding['availability'] ?? null;
		if (is_array($availability) === false) {
			return null;
		}

		$now = ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone(self::ZONE));

		$replacedBy = trim((string)($availability['replacedBy'] ?? ''));
		if ($replacedBy !== '') {
			return ['situation' => self::REPLACED, 'replacedBy' => $replacedBy];
		}

		$period = $this->periodState(availability: $availability, now: $now);
		if ($period !== null) {
			return $period;
		}

		$maintenance = $this->maintenance(availability: $availability, now: $now);
		if ($maintenance !== null) {
			return $maintenance;
		}

		$counter = $this->counter(binding: $binding, now: $now);
		if ($counter !== null && $counter['count'] >= $counter['limit']) {
			return [
				'situation' => self::FULL,
				'limit' => $counter['limit'],
				'per' => $counter['per'],
				'reopensOn' => $this->dateOrNull(value: ($availability['reopensOn'] ?? null)),
			];
		}

		return null;
	}//end unavailable()

	/**
	 * The submissions of the current period against the limit, or null when
	 * the binding sets no usable limit.
	 *
	 * @param array<string, mixed>   $binding The binding.
	 * @param DateTimeImmutable|null $now     The moment to judge; now when null.
	 *
	 * @return array{count: int, limit: int, per: string}|null
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#scenario-the-admin-sees-the-counter
	 */
	public function counter(array $binding, ?DateTimeImmutable $now = null): ?array {
		$limit = $binding['availability']['limit'] ?? null;
		if (is_array($limit) === false) {
			return null;
		}

		$count = (int)($limit['count'] ?? 0);
		$per   = (string)($limit['per'] ?? 'total');
		if ($count < 1 || in_array($per, self::PERIODS, true) === false) {
			return null;
		}

		$now = ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone(self::ZONE));

		return ['count' => $this->submissionsSince(binding: $binding, start: $this->periodStart(per: $per, now: $now)), 'limit' => $count, 'per' => $per];
	}//end counter()

	/**
	 * The not-yet-open or closed situation, or null inside the active period.
	 *
	 * `activeFrom` and `activeUntil` are calendar days, both inclusive.
	 *
	 * @param array<string, mixed> $availability The binding's availability.
	 * @param DateTimeImmutable    $now          The moment, in ZONE.
	 *
	 * @return array<string, mixed>|null
	 */
	private function periodState(array $availability, DateTimeImmutable $now): ?array {
		$today = $now->format('Y-m-d');

		$from = $this->dateOrNull(value: ($availability['activeFrom'] ?? null));
		if ($from !== null && $today < $from) {
			return ['situation' => self::NOT_YET_OPEN, 'opensOn' => $from];
		}

		$until = $this->dateOrNull(value: ($availability['activeUntil'] ?? null));
		if ($until !== null && $today > $until) {
			return ['situation' => self::CLOSED, 'closedOn' => $until];
		}

		return null;
	}//end periodState()

	/**
	 * The maintenance situation while its window is open, else null.
	 *
	 * @param array<string, mixed> $availability The binding's availability.
	 * @param DateTimeImmutable    $now          The moment, in ZONE.
	 *
	 * @return array<string, mixed>|null
	 */
	private function maintenance(array $availability, DateTimeImmutable $now): ?array {
		$window = $availability['maintenance'] ?? null;
		if (is_array($window) === false) {
			return null;
		}

		$from  = $this->moment(value: ($window['from'] ?? null));
		$until = $this->moment(value: ($window['until'] ?? null));
		if ($from === null || $until === null || $now < $from || $now >= $until) {
			return null;
		}

		return [
			'situation' => self::MAINTENANCE,
			'from' => $from->format('Y-m-d\TH:i'),
			'until' => $until->format('Y-m-d\TH:i'),
			'text' => (string)($window['text'] ?? ''),
		];
	}//end maintenance()

	/**
	 * The first moment of the period a limit counts over.
	 *
	 * @param string            $per The period: total, month or year.
	 * @param DateTimeImmutable $now The moment, in ZONE.
	 *
	 * @return DateTimeImmutable|null Null for `total`, which counts from the start.
	 */
	private function periodStart(string $per, DateTimeImmutable $now): ?DateTimeImmutable {
		if ($per === 'month') {
			return $now->setDate((int)$now->format('Y'), (int)$now->format('n'), 1)->setTime(0, 0);
		}

		if ($per === 'year') {
			return $now->setDate((int)$now->format('Y'), 1, 1)->setTime(0, 0);
		}

		return null;
	}//end periodStart()

	/**
	 * How many objects the form's destination received since a moment.
	 *
	 * A binding without a destination, or a count that cannot be made,
	 * counts 0: a form is never closed because the count could not be read.
	 *
	 * @param array<string, mixed>   $binding The binding.
	 * @param DateTimeImmutable|null $start   The first moment counted; null counts all.
	 *
	 * @return int
	 */
	private function submissionsSince(array $binding, ?DateTimeImmutable $start): int {
		$destination = $this->mapping->destinationOf(binding: $binding);
		if ($destination === null || $this->objects === null) {
			return 0;
		}

		$filters = [];
		if ($start !== null) {
			$filters['@self'] = ['created' => ['gte' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]];
		}

		return $this->objects->countObjects(register: $destination['register'], schema: $destination['schema'], filters: $filters);
	}//end submissionsSince()

	/**
	 * A `YYYY-MM-DD` date, or null for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function dateOrNull(mixed $value): ?string {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return null;
		}

		return $value;
	}//end dateOrNull()

	/**
	 * A local `YYYY-MM-DDTHH:MM` moment in ZONE, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value, new DateTimeZone(self::ZONE));
		} catch (Throwable) {
			return null;
		}
	}//end moment()
}//end class
