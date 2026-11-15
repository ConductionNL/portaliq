<?php

/**
 * Portaliq Scoped Collection Reader Interface
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
 * @spec openspec/changes/supplier-portal/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Reads a collection's objects scoped to one subject.
 *
 * The read side most callers need: the rows a subject holds in one collection.
 * PortalObjectReader implements it; see its readCollection() for the scoping,
 * claim, join and projection rules.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T05
 */
interface ScopedCollectionReaderInterface {

	/**
	 * Read a collection's objects scoped to the subject.
	 *
	 * @param string $register        The OpenRegister register slug/id.
	 * @param string $schema          The schema slug.
	 * @param string $scopeField      The row field that must equal the scope value.
	 * @param string $subjectRef      The server-derived subject reference.
	 * @param string $organisation    The tenant to constrain to (may be empty).
	 * @param int    $limit           Maximum rows to return.
	 * @param string $scopeClaim      Optional claim address (`claimName` or `appId.claimName`).
	 * @param string $contributingApp The app the contribution came from (bare-claim namespace).
	 * @param mixed  $via             Optional one-hop join declaration (array), fail-closed on anything else.
	 * @param string $audience        The subject's audience (portalAccount lookup filter).
	 * @param mixed  $fields          Optional projection whitelist (array of property names); null = full rows.
	 * @param array  $filter          Optional declared narrowing filter; the scope filter always wins.
	 *
	 * @return array<int, array<string, mixed>> The subject's rows (possibly empty).
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T05
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the parameters map
	 * 1:1 onto the declarative contract-v2 collection fields.
	 */
	public function readCollection(
		string $register,
		string $schema,
		string $scopeField,
		string $subjectRef,
		string $organisation = '',
		int $limit = 200,
		string $scopeClaim = '',
		string $contributingApp = '',
		mixed $via = null,
		string $audience = '',
		mixed $fields = null,
		array $filter = [],
	): array;
}//end interface
