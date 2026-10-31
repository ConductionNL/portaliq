<?php

/**
 * Portaliq Mandate Days (site-mandates-the-represented-manage)
 *
 * Reads and writes the days a mandate runs between.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Whether a mandate end has passed, and an end day as the end of that day.
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
 */
class MandateDays {
	/**
	 * @param string            $value A date or date-time, '' for none.
	 * @param DateTimeImmutable $now   The moment.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
	 */
	public function expired(string $value, DateTimeImmutable $now): bool {
		if ($value === '') {
			return false;
		}

		$when = date_create_immutable($value);

		return $when === false || $when <= $now;
	}//end expired()

	/**
	 * The last moment of a day in Amsterdam, as the date-time the register
	 * stores. An empty day stays empty: no end.
	 *
	 * @param string $day A day, `Y-m-d`, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
	 */
	public function endOfDay(string $day): string {
		if ($day === '') {
			return '';
		}

		$end = date_create_immutable_from_format('!Y-m-d H:i:s', $day . ' 23:59:59', new DateTimeZone('Europe/Amsterdam'));
		if ($end === false) {
			return '';
		}

		return $end->format(DATE_ATOM);
	}//end endOfDay()

	/**
	 * @param string            $value A day, `Y-m-d`.
	 * @param DateTimeImmutable $now   The moment.
	 *
	 * @return bool Whether it is a real day after today.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
	 */
	public function futureDay(string $value, DateTimeImmutable $now): bool {
		$day = date_create_immutable_from_format('!Y-m-d', $value);
		if ($day === false || $day->format('Y-m-d') !== $value) {
			return false;
		}

		return $day > $now->setTime(23, 59, 59);
	}//end futureDay()
}//end class
