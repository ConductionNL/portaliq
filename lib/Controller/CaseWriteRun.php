<?php

/**
 * Portaliq Case Write Run
 *
 * The state of one citizen write on their own case while
 * `CitizenCaseController` walks it: the resolved context and the case the
 * route names, then the act being written and the moment it happens. It
 * never leaves the controller that builds it.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * One citizen write in progress.
 *
 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md#requirement-a-citizen-write-raises-its-own-event-req-cwoc-005
 */
final class CaseWriteRun {

	/**
	 * The register the case lives in.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * The schema the case lives in.
	 *
	 * @var string
	 */
	public readonly string $schema;

	/**
	 * The case id.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * The act being written (amendment, document, withdrawal).
	 *
	 * @var string
	 */
	public string $act = '';

	/**
	 * When the write happens.
	 *
	 * @var string
	 */
	public string $occurredAt = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                              $context The resolved subject, action, case and writable set.
	 * @param array{register: string, schema: string, id: string} $target  The case the route names.
	 */
	public function __construct(
		public readonly array $context,
		array $target,
	) {
		$this->register = $target['register'];
		$this->schema   = $target['schema'];
		$this->id       = $target['id'];
	}//end __construct()
}//end class
