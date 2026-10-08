<?php

/**
 * Portaliq PortalReportRevealedEvent
 *
 * A reporter's identity has been revealed to somebody, by the custodian who
 * was allowed to do it. The event carries who asked, who allowed it, why, and
 * which fields were shown, so a case app can act on it and an auditor can
 * follow it. The values themselves are not in the event: what was shown is
 * named, never copied.
 *
 * @category Event
 * @package  OCA\Portaliq\Event
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
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * A reveal that happened.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */
class PortalReportRevealedEvent extends Event {
	/**
	 * The canonical name of this fact.
	 */
	public const NAME = 'portal.report.revealed';

	/**
	 * Constructor.
	 *
	 * @param string $reportId The report whose reporter was revealed.
	 * @param string $requestedBy Who asked.
	 * @param string $custodian The custodian who allowed it.
	 * @param string $motivation Why it was asked for.
	 * @param array<int, string> $revealedFields Which fields were shown.
	 * @param string $occurredAt When, ISO 8601.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the event is the
	 * record of the reveal; every parameter is part of that record.
	 */
	public function __construct(
		private readonly string $reportId,
		private readonly string $requestedBy,
		private readonly string $custodian,
		private readonly string $motivation,
		private readonly array $revealedFields,
		private readonly string $occurredAt,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The report whose reporter was revealed.
	 *
	 * @return string
	 */
	public function getReportId(): string {
		return $this->reportId;
	}//end getReportId()

	/**
	 * Who asked.
	 *
	 * @return string
	 */
	public function getRequestedBy(): string {
		return $this->requestedBy;
	}//end getRequestedBy()

	/**
	 * The custodian who allowed it.
	 *
	 * @return string
	 */
	public function getCustodian(): string {
		return $this->custodian;
	}//end getCustodian()

	/**
	 * Why it was asked for.
	 *
	 * @return string
	 */
	public function getMotivation(): string {
		return $this->motivation;
	}//end getMotivation()

	/**
	 * Which fields were shown. The values are not carried.
	 *
	 * @return array<int, string>
	 */
	public function getRevealedFields(): array {
		return $this->revealedFields;
	}//end getRevealedFields()

	/**
	 * When it happened, ISO 8601.
	 *
	 * @return string
	 */
	public function getOccurredAt(): string {
		return $this->occurredAt;
	}//end getOccurredAt()
}//end class
