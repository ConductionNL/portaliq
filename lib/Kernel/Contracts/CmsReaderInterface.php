<?php

/**
 * Contract of CmsReader
 *
 * The public surface of CmsReader that other components call, so they depend
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
 * Contract of CmsReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CmsReaderInterface {

	/**
	 * Read the published FAQ entries of a portal, for one page or one topic or all.
	 *
	 * @param string $portal   The portal slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 * @param string $page     Only the entries shown on this page route, or '' for no filter.
	 * @param string $topic    Only the entries of this topic, or '' for no filter.
	 *
	 * @return array The entries: question, answer, topic, pages.
	 *
	 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t02
	 */
	public function faq(string $portal, string $locale, string $audience, string $page='', string $topic=''): array;

	/**
	 * Read one published product finder of a portal.
	 *
	 * @param string $portal   The portal slug.
	 * @param string $id       The finder's id, or '' for the first published one.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array|null The finder, or null when there is none published.
	 *
	 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t02
	 */
	public function finder(string $portal, string $id, string $locale, string $audience): ?array;

	/**
	 * Read the glossary of a portal.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array The glossary terms, alphabetical.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	public function glossary(string $portal, string $locale, string $audience): array;

	/**
	 * Resolve the stored identifier of a page at a route, published or not.
	 *
	 * FOR EDITORS ONLY, and the caller is what makes that true. Everything else
	 * on this class answers a PUBLIC question and filters `status` in the query
	 * so a draft never reaches this process; this method deliberately does not,
	 * because an editor's whole reason to ask is to open the draft. The
	 * `_rbac: false` read underneath it therefore has no authorization of its
	 * own — {@see \OCA\Portaliq\Controller\CmsEditorController::editingContext()}
	 * refuses before calling here, and this method must never be reached from a
	 * path that does not.
	 *
	 * Nothing is cached. An editor who has just created a page and is looking
	 * for the way into it is precisely the caller a stale negative would strand.
	 *
	 * @param string $portal The portal slug.
	 * @param string $route  The in-site route, leading slash included.
	 *
	 * @return string|null The object identifier, or null when no page is there.
	 *
	 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	public function identify(string $portal, string $route): ?string;

	/**
	 * Drop every cached entry for a portal.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function invalidate(string $portal): void;

	/**
	 * Read the menus of a portal.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array The menus, ordered by position.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	public function menus(string $portal, string $locale, string $audience): array;

	/**
	 * Read one published page by route.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $route    The in-site route.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 * @param string $organisation The serving portal's organisation, for shared blocks; '' leaves them unexpanded.
	 *
	 * @return array|null The page, or null when there is no published page there.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t02
	 */
	public function page(string $portal, string $route, string $locale, string $audience, string $organisation=''): ?array;

	/**
	 * Read the published pages of a portal, without their bodies.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array The page summaries.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	public function pages(string $portal, string $locale, string $audience): array;

	/**
	 * Every row of one schema for a portal, drafts included, for the editor's
	 * publish check. The caller has already established that the person may
	 * edit; this read bypasses RBAC like every other read here.
	 *
	 * @param string $portal The portal slug.
	 * @param string $schema `page`, `menu` or `glossaryTerm`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-2
	 */
	public function rowsForCheck(string $portal, string $schema): array;

	/**
	 * The portal's shell as the public site contract serves it.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed> `authentication`, `headerVariant`, `footer` and `regions`.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
	 */
	public function shell(array $portal): array;
}//end interface
