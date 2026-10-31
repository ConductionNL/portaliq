<?php

/**
 * Whether a form binding takes submissions now.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Reads a binding's `availability` and says which FormulierNietBeschikbaar
 * situation applies, or null while the form is open.
 *
 * The order is fixed: a replaced form, then the active period, then a
 * maintenance window, then the limit. Only the limit costs a count, so it
 * runs last. A count OpenRegister cannot answer reads as 0: a resident is
 * never refused on a number nobody could read.
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
 */
class PortalFormAvailability {

	public const REPLACED = 'replaced';

	public const NOT_YET_OPEN = 'notYetOpen';

	public const CLOSED = 'closed';

	public const MAINTENANCE = 'maintenance';

	public const LIMIT_REACHED = 'limitReached';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'portalIntakeSubmission';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectWriter $objects The OpenRegister count.
	 * @param ITimeFactory       $clock   The clock.
	 */
	public function __construct(
		private readonly PortalObjectWriter $objects,
		private readonly ITimeFactory $clock,
	) {
	}//end __construct()

	/**
	 * The situation that keeps the form closed, or null when it is open.
	 *
	 * @param array<string, mixed> $binding The binding.
	 *
	 * @return array<string, mixed>|null `situation` plus what the page
	 *                                   needs to say it.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
	 */
	public function situation(array $binding): ?array {
		$availability = ($binding['availability'] ?? null);
		if (is_array($availability) === false) {
			return null;
		}

		$now   = $this->clock->now();
		$today = $now->format('Y-m-d');

		$replacedBy = $this->localRoute(value: ($availability['replacedBy'] ?? null));
		if ($replacedBy !== null) {
			return ['situation' => self::REPLACED, 'replacedBy' => $replacedBy];
		}

		$from = $this->day(value: ($availability['activeFrom'] ?? null));
		if ($from !== null && $today < $from) {
			return ['situation' => self::NOT_YET_OPEN, 'opensOn' => $from];
		}

		$until = $this->day(value: ($availability['activeUntil'] ?? null));
		if ($until !== null && $today > $until) {
			return ['situation' => self::CLOSED];
		}

		$maintenance = $this->maintenance(value: ($availability['maintenance'] ?? null), now: $now);
		if ($maintenance !== null) {
			return $maintenance;
		}

		return $this->limitReached(binding: $binding, availability: $availability, now: $now);
	}//end situation()

	/**
	 * The maintenance situation when now falls inside its window.
	 *
	 * @param mixed             $value The `maintenance` value.
	 * @param DateTimeImmutable $now   Now.
	 *
	 * @return array<string, mixed>|null
	 */
	private function maintenance(mixed $value, DateTimeImmutable $now): ?array {
		if (is_array($value) === false) {
			return null;
		}

		$from  = $this->moment(value: ($value['from'] ?? null), now: $now);
		$until = $this->moment(value: ($value['until'] ?? null), now: $now);
		if ($from === null || $until === null || $now < $from || $now >= $until) {
			return null;
		}

		return [
			'situation' => self::MAINTENANCE,
			'from' => $from->format('Y-m-d\TH:i'),
			'until' => $until->format('Y-m-d\TH:i'),
			'text' => trim((string)($value['text'] ?? '')),
		];
	}//end maintenance()

	/**
	 * The limit situation when the current period holds the limit's count.
	 *
	 * @param array<string, mixed> $binding      The binding.
	 * @param array<string, mixed> $availability Its availability.
	 * @param DateTimeImmutable    $now          Now.
	 *
	 * @return array<string, mixed>|null
	 */
	private function limitReached(array $binding, array $availability, DateTimeImmutable $now): ?array {
		$limit = ($availability['limit'] ?? null);
		if (is_array($limit) === false) {
			return null;
		}

		$count = (int)($limit['count'] ?? 0);
		$per   = (string)($limit['per'] ?? 'total');
		if ($count < 1 || in_array($per, ['total', 'month', 'year'], true) === false) {
			return null;
		}

		$filters = [
			'portal' => (string)($binding['portal'] ?? ''),
			'route' => (string)($binding['route'] ?? ''),
		];
		[$start, $next] = $this->period(per: $per, now: $now);
		if ($start !== null) {
			$filters['submittedAt'] = ['gte' => $start->format(DATE_ATOM)];
		}

		if ($this->objects->countObjects(register: self::REGISTER, schema: self::SCHEMA, filters: $filters) < $count) {
			return null;
		}

		$reopensOn = $this->day(value: ($availability['reopensOn'] ?? null));
		if ($reopensOn === null && $next !== null) {
			$reopensOn = $next->format('Y-m-d');
		}

		return [
			'situation' => self::LIMIT_REACHED,
			'count' => $count,
			'per' => $per,
			'reopensOn' => $reopensOn,
		];
	}//end limitReached()

	/**
	 * The start of the current period and of the next, both null for `total`.
	 *
	 * @param string            $per The period.
	 * @param DateTimeImmutable $now Now.
	 *
	 * @return array{0: DateTimeImmutable|null, 1: DateTimeImmutable|null}
	 */
	private function period(string $per, DateTimeImmutable $now): array {
		if ($per === 'month') {
			$start = $now->modify('first day of this month')->setTime(0, 0);
			return [$start, $start->modify('+1 month')];
		}

		if ($per === 'year') {
			$start = $now->setDate((int)$now->format('Y'), 1, 1)->setTime(0, 0);
			return [$start, $start->modify('+1 year')];
		}

		return [null, null];
	}//end period()

	/**
	 * A `Y-m-d` day, or null when the value is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function day(mixed $value): ?string {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
			return null;
		}

		return substr($value, 0, 10);
	}//end day()

	/**
	 * A local date and time in the clock's zone, or null.
	 *
	 * @param mixed             $value The value.
	 * @param DateTimeImmutable $now   Now, for its time zone.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function moment(mixed $value, DateTimeImmutable $now): ?DateTimeImmutable {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) !== 1) {
			return null;
		}

		return new DateTimeImmutable(substr($value, 0, 16), $now->getTimezone());
	}//end moment()

	/**
	 * A route on the same portal, or null: a path that starts with one slash.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function localRoute(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$route = trim($value);
		if (str_starts_with($route, '/') === false || str_starts_with($route, '//') === true || str_contains($route, '\\') === true) {
			return null;
		}

		return $route;
	}//end localRoute()
}//end class
