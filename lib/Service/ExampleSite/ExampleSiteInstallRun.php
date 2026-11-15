<?php

/**
 * What one install of an example site carries from step to step
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\ExampleSite
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleSite;

/**
 * The declaration, the install record and the keys created so far in one run.
 *
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
 */
final class ExampleSiteInstallRun {
	/**
	 * The portal's slug.
	 *
	 * @var string
	 */
	public readonly string $slug;

	/**
	 * The keys this run created, per schema.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $created = ['portal' => [], 'form' => [], 'menu' => [], 'page' => [], 'newsItem' => [], 'glossaryTerm' => []];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $site   The declaration; its pages change once the forms are installed.
	 * @param array<string, mixed> $record The install record; grows with what is created.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function __construct(
		public array $site,
		public array $record,
	) {
		$this->slug = (string)$site['portal']['slug'];
	}//end __construct()
}//end class
