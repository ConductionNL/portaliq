<?php

/**
 * Portaliq portal navigation catalogue
 *
 * What the "Navigation" section of a portal's page reads and saves: per
 * audience, the menu pages the installed apps contribute, in the order the
 * portal chose and with whether it hides each, and the save of that choice
 * into `portal.navigation`. A stored entry for a page no installed app
 * contributes now is kept on save, so uninstalling and reinstalling an app
 * does not lose the choice made for it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalNavigationCatalogueInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectWriterInterface;
use OCA\Portaliq\Service\PortalSlugLookup;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;

/**
 * Lists and saves the pages one portal shows, per audience.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */
class PortalNavigationCatalogue implements PortalNavigationCatalogueInterface {
	/**
	 * The register portals live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The portal schema.
	 *
	 * @var string
	 */
	private const SCHEMA = 'portal';

	/**
	 * A page key: `<app>:<pageId>`, the same shape PageChoice accepts.
	 *
	 * @var string
	 */
	private const PAGE_KEY = '/^[A-Za-z0-9_-]+:[^\s]+$/';

	/**
	 * An audience key.
	 *
	 * @var string
	 */
	private const AUDIENCE_KEY = '/^[A-Za-z0-9_-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface     $reader   Reads the portal by slug.
	 * @param PortalObjectWriterInterface         $writer   Writes the portal's navigation.
	 * @param PortalContributionRegistryInterface $registry The contributions of the installed apps.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly PortalObjectWriterInterface $writer,
		private readonly PortalContributionRegistryInterface $registry,
	) {
	}//end __construct()

	/**
	 * The portal with this slug, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
	 */
	public function portalBySlug(string $slug): ?array {
		return (new PortalSlugLookup(reader: $this->reader))->find(slug: $slug);
	}//end portalBySlug()

	/**
	 * Per audience, the menu pages the installed apps contribute: the pages
	 * the portal lists first, in its order, then the others in the order the
	 * apps give them, each with `hidden`.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return list<array{audience: string, pages: list<array<string, mixed>>}>
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
	 */
	public function listFor(array $portal): array {
		$stored = $this->normalise(navigation: ($portal['navigation'] ?? null));
		$out    = [];
		foreach ((new ContributedPages(registry: $this->registry))->byAudience() as $audience => $pages) {
			$menu  = array_values(array_filter($pages, static fn (array $page): bool => $page['menu'] === true));
			$out[] = [
				'audience' => (string)$audience,
				'pages'    => $this->arranged(pages: $menu, listed: ($stored[$audience] ?? [])),
			];
		}

		return $out;
	}//end listFor()

	/**
	 * Store the portal's navigation choice. Audiences not sent keep their
	 * stored list; within a sent audience, a stored entry for a page the
	 * request does not name is kept after the sent ones.
	 *
	 * @param array<string, mixed> $portal     The portal.
	 * @param mixed                $navigation Per audience, a list of `{page, hidden}`.
	 *
	 * @return array<string, mixed>|null The saved portal, or null when the write failed.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
	 */
	public function save(array $portal, mixed $navigation): ?array {
		$merged = $this->normalise(navigation: ($portal['navigation'] ?? null));
		foreach ($this->normalise(navigation: $navigation) as $audience => $sent) {
			$named = array_flip(array_column($sent, 'page'));
			$kept  = array_values(
				array_filter(
					($merged[$audience] ?? []),
					static fn (array $entry): bool => isset($named[$entry['page']]) === false
				)
			);
			$merged[$audience] = array_merge($sent, $kept);
		}

		$id = (string)($portal['id'] ?? $portal['uuid'] ?? ($portal['@self']['id'] ?? ''));

		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'slug',
			subjectRef: (string)($portal['slug'] ?? ''),
			organisation: '',
			id: $id,
			data: ['navigation' => (object)$merged]
		);
	}//end save()

	/**
	 * The contributed pages in the stored order, unlisted ones after, each
	 * with `hidden`.
	 *
	 * @param list<array<string, mixed>>                $pages  The contributed menu pages.
	 * @param list<array{page: string, hidden: bool}>   $listed The stored list for the audience.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function arranged(array $pages, array $listed): array {
		$byKey = [];
		foreach ($pages as $page) {
			$byKey[$page['page']] = $page;
		}

		$out = [];
		foreach ($listed as $entry) {
			if (isset($byKey[$entry['page']]) === true) {
				$out[] = $this->row(page: $byKey[$entry['page']], hidden: $entry['hidden']);
				unset($byKey[$entry['page']]);
			}
		}

		foreach ($byKey as $page) {
			$out[] = $this->row(page: $page, hidden: false);
		}

		return $out;
	}//end arranged()

	/**
	 * One row of the section.
	 *
	 * @param array<string, mixed> $page   The contributed page.
	 * @param bool                 $hidden Whether the portal hides it.
	 *
	 * @return array<string, mixed>
	 */
	private function row(array $page, bool $hidden): array {
		unset($page['menu']);
		$page['hidden'] = $hidden;

		return $page;
	}//end row()

	/**
	 * The navigation as `audience => [{page, hidden}]`: unknown audiences
	 * keys, malformed and repeated pages dropped, `hidden` a boolean.
	 *
	 * @param mixed $navigation The stored or sent navigation.
	 *
	 * @return array<string, list<array{page: string, hidden: bool}>>
	 */
	private function normalise(mixed $navigation): array {
		if (is_object($navigation) === true) {
			$navigation = (array)$navigation;
		}

		$out = [];
		if (is_array($navigation) === false) {
			return $out;
		}

		foreach ($navigation as $audience => $entries) {
			if (is_string($audience) === false || preg_match(self::AUDIENCE_KEY, $audience) !== 1 || is_array($entries) === false) {
				continue;
			}

			$out[$audience] = $this->entries(entries: $entries);
		}

		return $out;
	}//end normalise()

	/**
	 * The well-formed `{page, hidden}` entries of one audience, first one wins.
	 *
	 * @param array<mixed> $entries The entries.
	 *
	 * @return list<array{page: string, hidden: bool}>
	 */
	private function entries(array $entries): array {
		$out = [];
		foreach ($entries as $entry) {
			if (is_object($entry) === true) {
				$entry = (array)$entry;
			}

			$page = null;
			if (is_array($entry) === true) {
				$page = ($entry['page'] ?? null);
			}

			if (is_string($page) === false || preg_match(self::PAGE_KEY, $page) !== 1 || isset($out[$page]) === true) {
				continue;
			}

			$out[$page] = ['page' => $page, 'hidden' => (($entry['hidden'] ?? false) === true)];
		}

		return array_values($out);
	}//end entries()
}//end class
