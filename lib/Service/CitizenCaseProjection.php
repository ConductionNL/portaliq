<?php

/**
 * Portaliq Citizen Case Projection
 *
 * What of a case the case screen may send to the resident's browser. The
 * controller keeps the full row to work out the writable set and the
 * withdrawal; only this projection leaves the server.
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
 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\CitizenWriteConfigNormaliser;
use Psr\Log\LoggerInterface;

/**
 * Projects the citizen's case to what the case screen may show.
 *
 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenCaseProjection {
	/**
	 * The fields a withdrawal writes. The case screen shows them as the
	 * withdrawn state, so they travel with the case whatever the collection
	 * declares.
	 *
	 * @var array<int, string>
	 */
	public const WITHDRAWAL_FIELDS = ['withdrawnAt', 'withdrawalReason'];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Handed to the projector.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The case as the resident may read it: the fields the collection declares
	 * for them, plus what the case screen itself works with.
	 *
	 * The full row stays inside the controller, where the writable set, the
	 * withdrawal and the write record are worked out from it. Only this
	 * projection leaves the server, so a field the case app keeps for its staff
	 * (an assignee, a priority, a quality score) never reaches the resident's
	 * browser. The screen also needs the answers the resident may correct, the
	 * status the writable set is resolved on and the withdrawal fields; those
	 * are added to the collection's list, nothing else is. A collection that
	 * declares no `fields` passes the row whole, exactly as its list does.
	 *
	 * @param array<string, mixed> $context The resolved context.
	 * @param array<string, mixed> $case The full case row.
	 *
	 * @return array<string, mixed> The projected case.
	 *
	 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function visible(array $context, array $case): array {
		$declared = ($context['fields'] ?? null);
		if ($declared === null) {
			return $case;
		}

		// A malformed declaration stays malformed, so it projects to the
		// identifiers only rather than to the screen's own fields.
		if (is_array($declared) === false) {
			return (new PortalFieldProjector(logger: $this->logger))->projectRow(row: $case, fields: $declared);
		}

		$action = (array)($context['action'] ?? []);
		$config = (array)($action[CitizenWriteConfigNormaliser::KEY] ?? []);
		$whitelist = array_merge(
			array_values($declared),
			(array)($action['fields'] ?? []),
			array_keys((array)($context['set']['fields'] ?? [])),
			[(string)($config['statusField'] ?? 'status')],
			self::WITHDRAWAL_FIELDS
		);

		return (new PortalFieldProjector(logger: $this->logger))->projectRow(
			row: $case,
			fields: array_values(array_unique(array_filter($whitelist, is_string(...))))
		);
	}//end visible()
}//end class
