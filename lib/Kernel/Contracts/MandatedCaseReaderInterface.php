<?php

/**
 * Contract of MandatedCaseReader
 *
 * The public surface of MandatedCaseReader that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;

/**
 * Contract of MandatedCaseReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MandatedCaseReaderInterface {

	/**
	 * The `mandate` value that means "acting for yourself".
	 */
	public const ACTING_FOR_SELF = 'self';

	/**
	 * The refusal, or the read-only screen of the case the person sees under
	 * the named mandate when the refusal is only that the case is not theirs.
	 *
	 * Only a signed-in person is looked up. Nothing can be changed or added
	 * under a mandate on this screen, and no documents are listed.
	 *
	 * @param JSONResponse $refusal Why the case is not opened as their own.
	 * @param array<string, mixed>|null $subject The resolved subject, or null.
	 * @param string $mandateId The mandate the person acts under.
	 * @param array{register: string, schema: string, id: string} $target The case asked for.
	 * @param IL10N $l10n The sentence the read-only screen is explained with.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-you-choose-whom-you-act-for-req-cmc-004
	 */
	public function screenOr(JSONResponse $refusal, ?array $subject, string $mandateId, array $target, IL10N $l10n): JSONResponse;

	/**
	 * The case, as the mandated list shows it, or null.
	 *
	 * A mandate is spent only when it is named: an empty id or "yourself"
	 * opens nothing here, and neither does an id the identity does not hold.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $mandateId The mandate the person acts under.
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-you-choose-whom-you-act-for-req-cmc-004
	 */
	public function read(array $subject, string $mandateId, string $register, string $schema, string $id): ?array;
}//end interface
