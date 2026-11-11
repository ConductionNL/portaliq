<?php

/**
 * Portaliq Action Forward
 *
 * One already-authorised action on its way to its domain endpoint: the
 * action declaration and the subject, then the whitelisted body, the
 * server-resolved scope value and the checked uploads. It never leaves the
 * forwarder that builds it.
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
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T8
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * One forward in progress.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format
 */
final class ActionForward {

	/**
	 * The rebuilt whitelisted body, or null to relay the raw request body.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $whitelisted = null;

	/**
	 * The server-resolved value of the action's declared `scopeClaim`, or ''.
	 *
	 * @var string
	 */
	public string $scopeValue = '';

	/**
	 * Checked uploads of a row action that declares `files`.
	 *
	 * @var list<array{name: string, type: string, tmp_name: string, size: int}>
	 */
	public array $files = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $action  The already-authorised action declaration.
	 * @param array<string, mixed> $subject The resolved subject.
	 */
	public function __construct(
		public readonly array $action,
		public readonly array $subject,
	) {
	}//end __construct()
}//end class
