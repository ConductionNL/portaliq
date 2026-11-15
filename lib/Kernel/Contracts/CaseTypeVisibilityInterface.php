<?php

/**
 * Contract of CaseTypeVisibility
 *
 * The public surface of CaseTypeVisibility that other components call, so they depend
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

use OCA\Portaliq\Kernel\Contracts\ColumnOverridesInterface;
use OCA\Portaliq\Kernel\Contracts\RequestedPortalInterface;
use OCP\IRequest;

/**
 * Contract of CaseTypeVisibility.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeVisibilityInterface {

	/**
	 * The header the resident portal names its own portal slug in.
	 */
	public const HEADER = RequestedPortalInterface::HEADER;

	/**
	 * The serving portal's column settings per case type, ready to stamp on
	 * case rows.
	 *
	 * @param IRequest $request The request.
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return ColumnOverridesInterface
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-case-row-reads-with-its-case-types-columns
	 */
	public function servingColumns(IRequest $request, array $subject): ColumnOverridesInterface;

	/**
	 * The portal a signed-in request is served from: the one it names (header,
	 * then `?portal=`, then the host), when it belongs to the subject's
	 * organisation; otherwise the organisation's single portal, or none.
	 *
	 * @param IRequest $request The request.
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function servingPortal(IRequest $request, array $subject): ?array;

	/**
	 * The case types the serving portal hides, for a `cases` collection;
	 * empty for a collection of any other kind.
	 *
	 * @param IRequest $request The request.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $collection The matched collection.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function hiddenForCollection(IRequest $request, array $subject, array $collection): array;

	/**
	 * Whether a case row's type is in a hidden list.
	 *
	 * @param array<string, mixed> $row The case row.
	 * @param array<string, mixed> $collection The declared `cases` collection.
	 * @param array<int, string> $hidden The hidden case type ids.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function rowIsHidden(array $row, array $collection, array $hidden): bool;

	/**
	 * The case types hidden in the portal a signed-in request is served from.
	 *
	 * @param IRequest $request The request.
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function hiddenForRequest(IRequest $request, array $subject): array;
}//end interface
