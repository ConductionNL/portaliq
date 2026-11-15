<?php

/**
 * Portaliq Reference Link Run
 *
 * The state of one request for a one-time case link while
 * `PortalIdentityController` walks it: the portal being visited and the
 * case type, case number and address the anonymous request names, then the
 * case type read and the case found behind them. It never leaves the
 * controller that builds it.
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
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * One reference-link request in progress.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
 */
final class ReferenceLinkRun {

	/**
	 * The register the case type lives in.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * The schema the case type lives in.
	 *
	 * @var string
	 */
	public readonly string $schema;

	/**
	 * The case type.
	 *
	 * @var string
	 */
	public readonly string $caseType;

	/**
	 * The case number.
	 *
	 * @var string
	 */
	public readonly string $caseReference;

	/**
	 * The address the link goes to.
	 *
	 * @var string
	 */
	public readonly string $email;

	/**
	 * The case type read, once it is offered.
	 *
	 * @var array<string, mixed>
	 */
	public array $type = [];

	/**
	 * The case collection the link opens, once the address matches.
	 *
	 * @var array{register: string, schema: string}
	 */
	public array $linkable = ['register' => '', 'schema' => ''];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $site  The portal being visited.
	 * @param array{register: string, schema: string, caseType: string, caseReference: string, email: string} $asked What the request names.
	 */
	public function __construct(
		public readonly array $site,
		array $asked,
	) {
		$this->register      = $asked['register'];
		$this->schema        = $asked['schema'];
		$this->caseType      = $asked['caseType'];
		$this->caseReference = $asked['caseReference'];
		$this->email         = $asked['email'];
	}//end __construct()
}//end class
