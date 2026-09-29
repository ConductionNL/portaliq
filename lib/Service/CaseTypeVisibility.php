<?php

/**
 * Portaliq case type visibility
 *
 * Which case types a portal shows to residents. An administrator lists, per
 * portal, the case types the portal does not show (`portal.hiddenCaseTypes`);
 * every place a case type reaches a resident asks this one predicate.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\IRequest;

/**
 * The per-portal list of hidden case types, and the portal it applies to.
 *
 * Hiding is about what a portal offers, not an access boundary: a case of a
 * hidden type is still the resident's, and another portal of the same
 * organisation that shows the type lists it. So the serving portal may be
 * named by the request, as long as it belongs to the resident's own
 * organisation.
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
 */
class CaseTypeVisibility {
	/**
	 * The header the resident portal names its own portal slug in.
	 */
	public const HEADER = 'X-Portaliq-Portal';

	/**
	 * The case field a `cases` collection keeps its case type in, unless it
	 * declares another one (`caseTypeField`).
	 */
	private const DEFAULT_TYPE_FIELD = 'caseType';

	/**
	 * Constructor.
	 *
	 * @param PortalResolver $portals Resolves the serving portal.
	 */
	public function __construct(
		private readonly PortalResolver $portals,
	) {
	}//end __construct()

	/**
	 * The case type ids a portal hides; empty for no portal.
	 *
	 * @param array<string, mixed>|null $portal The portal object.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
	 */
	public function hiddenTypeIds(?array $portal): array {
		$hidden = [];
		foreach ((array)($portal['hiddenCaseTypes'] ?? []) as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$typeId = trim((string)($entry['typeId'] ?? ''));
			if ($typeId !== '') {
				$hidden[] = $typeId;
			}
		}

		return array_values(array_unique($hidden));
	}//end hiddenTypeIds()

	/**
	 * Whether a portal hides one case type.
	 *
	 * @param array<string, mixed> $portal The portal object.
	 * @param string $typeId The case type id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function isHidden(array $portal, string $typeId): bool {
		if ($typeId === '') {
			return false;
		}

		return in_array($typeId, $this->hiddenTypeIds(portal: $portal), true);
	}//end isHidden()

	/**
	 * The case types the published portal with this slug hides.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function hiddenInPortal(string $slug): array {
		if ($slug === '') {
			return [];
		}

		foreach ($this->portals->allPublishedPortals() as $portal) {
			if (is_array($portal) === true && ($portal['slug'] ?? null) === $slug) {
				return $this->hiddenTypeIds(portal: $portal);
			}
		}

		return [];
	}//end hiddenInPortal()

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
	public function hiddenForRequest(IRequest $request, array $subject): array {
		return $this->hiddenTypeIds(portal: $this->servingPortal(request: $request, subject: $subject));
	}//end hiddenForRequest()

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
	public function servingPortal(IRequest $request, array $subject): ?array {
		$slug = trim($request->getHeader(self::HEADER));
		if ($slug === '') {
			$slug = trim((string)$request->getParam('portal', ''));
		}

		$named = null;
		if ($slug !== '') {
			$named = $this->portals->resolve(request: $request, portalSlug: $slug);
		}

		if ($named === null) {
			$named = $this->portals->resolve(request: $request);
		}

		$organisation = (string)($subject['organisation'] ?? '');
		if ($named !== null && ($organisation === '' || (string)($named['organisation'] ?? '') === $organisation)) {
			return $named;
		}

		if ($organisation === '') {
			return null;
		}

		return $this->portals->resolveByOrganisation(organisation: $organisation);
	}//end servingPortal()

	/**
	 * The case type id of one case row, read from the collection's type field.
	 *
	 * @param array<string, mixed> $row The case row.
	 * @param array<string, mixed> $collection The declared `cases` collection.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function caseTypeOf(array $row, array $collection): string {
		$field = (string)($collection['caseTypeField'] ?? self::DEFAULT_TYPE_FIELD);
		if ($field === '') {
			$field = self::DEFAULT_TYPE_FIELD;
		}

		$value = ($row[$field] ?? '');
		if (is_array($value) === true) {
			$value = ($value['id'] ?? $value['uuid'] ?? '');
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end caseTypeOf()

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
	public function rowIsHidden(array $row, array $collection, array $hidden): bool {
		if ($hidden === []) {
			return false;
		}

		$typeId = $this->caseTypeOf(row: $row, collection: $collection);

		return ($typeId !== '' && in_array($typeId, $hidden, true) === true);
	}//end rowIsHidden()
}//end class
