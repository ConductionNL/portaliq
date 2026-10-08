<?php

/**
 * Event Deadline
 *
 * When an event stops taking answers. A date closes at the end of that day in
 * Amsterdam; a date and time closes at that moment. A deadline that does not
 * parse counts as passed, so a broken value never leaves a sign-up open.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-pupil-may-answer-an-event-for-herself-with-the-number-of-seats
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The sign-up deadline of an event.
 *
 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-pupil-may-answer-an-event-for-herself-with-the-number-of-seats
 */
class EventDeadline {
	/**
	 * Whether the deadline has passed.
	 *
	 * @param string|null $deadline The event's `signupDeadline`; empty means none.
	 * @param string|null $now      The moment, ISO 8601; defaults to the clock.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-pupil-may-answer-an-event-for-herself-with-the-number-of-seats
	 */
	public static function hasPassed(?string $deadline, ?string $now = null): bool {
		$deadline = trim((string)$deadline);
		if ($deadline === '') {
			return false;
		}

		try {
			$zone  = new DateTimeZone('Europe/Amsterdam');
			$end   = new DateTimeImmutable($deadline, $zone);
			$clock = new DateTimeImmutable(($now ?? 'now'), $zone);
		} catch (Throwable $e) {
			return true;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) === 1) {
			$end = $end->setTime(23, 59, 59);
		}

		return $clock > $end;
	}//end hasPassed()
}//end class
