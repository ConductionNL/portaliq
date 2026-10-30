<?php

/**
 * Portaliq Example Site Installer
 *
 * Puts a shipped example site on an instance. It writes only what is
 * missing, records what it wrote, and after writing reads the instance back
 * to prove what arrived.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\ExampleSite
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
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleSite;

use OCA\Portaliq\Service\PortalNameSetting;
use OCA\Portaliq\Service\PortalThemeResolver;

/**
 * Installs an example site.
 *
 * WHY THE PROOF IS A SECOND READ. OpenRegister answers a write with the
 * object as it was sent, also for a key the schema does not know and
 * therefore does not keep. A count of answers would call a site complete
 * whose header search never reached the portal record. So the install reads
 * every type back and compares it with the declaration, key by key.
 *
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-install-must-prove-what-arrived
 */
class ExampleSiteInstaller {
	/**
	 * The schema behind each list of a declaration.
	 */
	private const SCHEMAS = ['forms' => 'form', 'menus' => 'menu', 'pages' => 'page', 'news' => 'newsItem', 'glossary' => 'glossaryTerm'];

	/**
	 * Constructor.
	 *
	 * @param ExampleSiteStore    $store  Writes rows.
	 * @param ExampleSiteRows     $rows   Finds the portal's rows.
	 * @param ExampleSiteRecord   $record Keeps what was created.
	 * @param ExampleSiteProof    $proof  Compares declared with stored.
	 * @param PortalThemeResolver $themes Says whether the theme app offers the site's set.
	 * @param PortalNameSetting   $name   Names the portal for other apps.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSiteStore $store,
		private readonly ExampleSiteRows $rows,
		private readonly ExampleSiteRecord $record,
		private readonly ExampleSiteProof $proof,
		private readonly PortalThemeResolver $themes,
		private readonly PortalNameSetting $name,
	) {
	}//end __construct()

	/**
	 * Write what the declaration holds and the instance lacks, then prove it.
	 *
	 * @param array<string, mixed> $site A declaration from ExampleSiteCatalogue.
	 *
	 * @return array<string, mixed> `site`, `portal`, `available`, `themeOffered`,
	 *                              `types` (per schema: declared, created, kept,
	 *                              arrived), `missing`, `lost` and `ok`.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function install(array $site): array {
		$slug   = (string)$site['portal']['slug'];
		$theme  = (string)($site['portal']['theme'] ?? '');
		$report = [
			'site'         => (string)$site['id'],
			'portal'       => $slug,
			'available'    => $this->store->available(),
			'themeOffered' => ($theme === '' || $this->themes->stylesheetFor(theme: $theme) !== null),
			'types'        => [],
			'missing'      => [],
			'lost'         => [],
			'ok'           => false,
		];
		if ($report['available'] === false) {
			return $report;
		}

		$record  = $this->record->read(site: $report['site']);
		$created = ['portal' => [], 'form' => [], 'menu' => [], 'page' => [], 'newsItem' => [], 'glossaryTerm' => []];

		$report['types']['portal'] = ['declared' => 1, 'created' => 0, 'kept' => 1, 'arrived' => 0];
		if ($this->rows->portal(slug: $slug) === null) {
			$id = $this->store->create(schema: 'portal', data: $site['portal']);
			$report['types']['portal']['kept'] = 0;
			if ($id !== null) {
				$report['types']['portal']['created'] = 1;
				$record['portal']    = $id;
				$created['portal'][] = $slug;
			}
		}

		$this->name->fillFrom(portal: $site['portal']);

		foreach (self::SCHEMAS as $list => $schema) {
			$report['types'][$schema] = $this->installList(
				objects: ($site[$list] ?? []),
				schema: $schema,
				slug: $slug,
				record: $record,
				created: $created
			);
			// The forms exist now, so a page's form block can carry the id of the form it names.
			$site = $this->withInstalledForms(schema: $schema, site: $site, slug: $slug);
		}

		$this->record->write(site: $report['site'], record: $record);

		return $this->proven(site: $site, report: $report, created: $created);
	}//end install()

	/**
	 * Create the objects of one list that the instance lacks.
	 *
	 * @param array<int, array<string, mixed>>         $objects The declared objects.
	 * @param string                                   $schema  Their schema.
	 * @param string                                   $slug    The portal's slug.
	 * @param array<string, mixed>                     $record  The site's install record, extended in place.
	 * @param array<string, array<int, string>>        $created The keys created this run, extended in place.
	 *
	 * @return array{declared: int, created: int, kept: int, arrived: int} The counts.
	 */
	private function installList(array $objects, string $schema, string $slug, array &$record, array &$created): array {
		$counts   = ['declared' => count($objects), 'created' => 0, 'kept' => 0, 'arrived' => 0];
		$existing = $this->rows->keyed(schema: $schema, slug: $slug);
		foreach ($objects as $object) {
			$key = $this->rows->keyOf(schema: $schema, object: $object);
			if (isset($existing[$key]) === true) {
				$counts['kept']++;
				continue;
			}

			$id = $this->store->create(schema: $schema, data: ($object + ['portal' => $slug]));
			if ($id !== null) {
				$counts['created']++;
				$record[$schema][]  = $id;
				$created[$schema][] = $key;
			}
		}

		return $counts;
	}//end installList()

	/**
	 * The site with its pages' form blocks pointing at the installed forms, once the forms are installed.
	 *
	 * @param string               $schema The list just installed.
	 * @param array<string, mixed> $site   The declaration.
	 * @param string               $slug   The portal's slug.
	 *
	 * @return array<string, mixed> The declaration to go on with.
	 *
	 * @spec openspec/specs/example-site/spec.md#requirement-an-example-site-can-ship-a-form-with-the-page-that-shows-it
	 */
	private function withInstalledForms(string $schema, array $site, string $slug): array {
		if ($schema !== 'form') {
			return $site;
		}

		$site['pages'] = $this->withFormIds(pages: $site['pages'], forms: $this->rows->keyed(schema: 'form', slug: $slug));

		return $site;
	}//end withInstalledForms()

	/**
	 * The pages with each `form` block's `formKey` written as the installed form's `formId`.
	 *
	 * A key that names no installed form leaves the block without a form id: it
	 * then shows its fields but cannot send them, which the install proof reports.
	 *
	 * @param array<int, array<string, mixed>>   $pages The declared pages.
	 * @param array<string, array<string, mixed>> $forms The installed forms, by external reference.
	 *
	 * @return array<int, array<string, mixed>> The pages to install.
	 *
	 * @spec openspec/specs/example-site/spec.md#requirement-an-example-site-can-ship-a-form-with-the-page-that-shows-it
	 */
	private function withFormIds(array $pages, array $forms): array {
		foreach ($pages as $p => $page) {
			foreach ((array)($page['body']['widgets'] ?? []) as $w => $widget) {
				$key = $widget['props']['formKey'] ?? null;
				if (($widget['widgetKey'] ?? '') !== 'form' || is_string($key) === false) {
					continue;
				}

				unset($pages[$p]['body']['widgets'][$w]['props']['formKey']);
				$form = ($forms[$key] ?? null);
				if (is_array($form) === true) {
					$pages[$p]['body']['widgets'][$w]['props']['formId'] = $this->store->idOf(row: $form);
				}
			}
		}

		return $pages;
	}//end withFormIds()

	/**
	 * Read the instance back and fill in what arrived, what is missing and
	 * which keys the objects of this run lost.
	 *
	 * An object that was there before this run is counted and not compared:
	 * an editor may have changed it, and that is not a loss.
	 *
	 * @param array<string, mixed>              $site    The declaration.
	 * @param array<string, mixed>              $report  The report so far.
	 * @param array<string, array<int, string>> $created The keys this run created, per schema.
	 *
	 * @return array<string, mixed> The finished report.
	 */
	private function proven(array $site, array $report, array $created): array {
		$stored = $this->stored(slug: $report['portal']);
		foreach ($this->declared(site: $site) as $schema => $objects) {
			foreach ($objects as $key => $object) {
				$name = $schema . ' ' . $key;
				if (isset($stored[$schema][$key]) === false) {
					$report['missing'][] = $name;
					continue;
				}

				$report['types'][$schema]['arrived']++;
				if (in_array((string)$key, $created[$schema], true) === false) {
					continue;
				}

				foreach ($this->proof->lostPaths(declared: $object, stored: $stored[$schema][$key]) as $path) {
					$report['lost'][] = $name . ': ' . $path;
				}
			}
		}

		$report['ok'] = ($report['missing'] === [] && $report['lost'] === []);

		return $report;
	}//end proven()

	/**
	 * What the declaration holds, per schema, by what tells objects apart.
	 *
	 * @param array<string, mixed> $site The declaration.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function declared(array $site): array {
		$declared = ['portal' => [(string)$site['portal']['slug'] => $site['portal']]];
		foreach (self::SCHEMAS as $list => $schema) {
			$declared[$schema] = [];
			foreach (($site[$list] ?? []) as $object) {
				$declared[$schema][$this->rows->keyOf(schema: $schema, object: $object)] = $object;
			}
		}

		return $declared;
	}//end declared()

	/**
	 * What the instance holds for the portal, in the same shape.
	 *
	 * @param string $slug The portal's slug.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function stored(string $slug): array {
		$stored = ['portal' => []];
		$portal = $this->rows->portal(slug: $slug);
		if ($portal !== null) {
			$stored['portal'][$slug] = $portal;
		}

		foreach (self::SCHEMAS as $schema) {
			$stored[$schema] = $this->rows->keyed(schema: $schema, slug: $slug);
		}

		return $stored;
	}//end stored()
}//end class
