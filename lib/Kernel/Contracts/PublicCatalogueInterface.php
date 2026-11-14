<?php

/**
 * Contract of PublicCatalogue
 *
 * The public surface of PublicCatalogue that other components call, so they depend
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

/**
 * Contract of PublicCatalogue.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicCatalogueInterface {

	/**
	 * The page of one item of an app's public index, or null. The item must be
	 * one the app's index returns for this portal, of this kind, with this
	 * slug; anything else is the same null, so an unknown slug and an item that
	 * is not public cannot be told apart.
	 *
	 * @param string $portal The portal slug.
	 * @param string $appId  The app whose index holds the item.
	 * @param string $kind   The item's `type` in the index.
	 * @param string $slug   The item's slug.
	 *
	 * @return array{item: array<string, mixed>, detail: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-2
	 */
	public function detailFor(string $portal, string $appId, string $kind, string $slug): ?array;

	/**
	 * Every public item of one portal: its news, then each app's index.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
	 */
	public function itemsFor(string $portal): array;

	/**
	 * What each installed app declares its public index kinds can be narrowed
	 * by and drawn as. Not cached: it is read only when an editor opens the
	 * palette.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>> The kinds.
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-1
	 */
	public function kindsFor(string $portal): array;

	/**
	 * The filter values the signed-in person resolves to in one app: the
	 * value `visitor` of a table filter. An app that cannot say answers
	 * nothing, and the filter stays empty.
	 *
	 * @param string               $portal  The portal slug.
	 * @param string               $appId   The app whose index is read.
	 * @param array<string, mixed> $subject The signed-in subject.
	 *
	 * @return array<string, array<int, string>> Filter label to values.
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-3
	 */
	public function visitorValuesFor(string $portal, string $appId, array $subject): array;
}//end interface
