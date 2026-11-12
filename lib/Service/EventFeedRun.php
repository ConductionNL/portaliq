<?php

/**
 * Portaliq Event Feed Run
 *
 * The state of one guardian's event feed while `EventFeedReader` builds it:
 * the guardian, the RSVPs given by or for them, and every RSVP once one
 * event asks for seats. It never leaves the reader that builds it.
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * One event feed in progress.
 *
 * @spec exclude Run state of EventFeedReader::feedFor(); its @spec covers the behaviour.
 */
final class EventFeedRun {

	/**
	 * Every RSVP, read on the first event that asks for seats.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	public ?array $everyRsvp = null;

	/**
	 * Constructor.
	 *
	 * @param string                           $subjectRef The guardian's own subjectRef.
	 * @param array<int, array<string, mixed>> $rsvps      The RSVPs given by or for them.
	 */
	public function __construct(
		public readonly string $subjectRef,
		public readonly array $rsvps,
	) {
	}//end __construct()
}//end class
