<?php

/**
 * Portaliq Portal Provisioning Service
 *
 * Creates the draft portal of one organisation in one step: the portal, its
 * host, a menu and a home page.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tenancy
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t06
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteStore;

/**
 * The portal is a draft with authentication `public` and an unverified host.
 * It goes live only when the host verifies and an administrator publishes it.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t06
 */
class PortalProvisioningService {

	public const CREATED = 'created';

	public const INVALID = 'invalid';

	public const SLUG_TAKEN = 'slug_taken';

	public const HOST_TAKEN = 'host_taken';

	public const UNAVAILABLE = 'unavailable';

	public const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param ExampleSiteStore $store Reads and writes the register's objects.
	 */
	public function __construct(
		private readonly ExampleSiteStore $store,
	) {
	}//end __construct()

	/**
	 * Create the draft portal of an organisation.
	 *
	 * Refuses a slug that any portal holds, and a host that any portal lists,
	 * whichever organisation holds it, before anything is written.
	 *
	 * @param string $organisation The organisation the portal belongs to.
	 * @param string $slug The portal's slug.
	 * @param string $title The portal's title.
	 * @param string $host The host the portal answers on, without scheme or port.
	 *
	 * @return array{status: string, dns: array{name: string, value: string}|null} The outcome and, when created, the DNS record to publish.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t06
	 */
	public function provision(string $organisation, string $slug, string $title, string $host): array {
		$organisation = trim($organisation);
		$slug         = trim($slug);
		$title        = trim($title);
		$host         = strtolower(trim($host));
		if ($organisation === '' || $title === '' || preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug) !== 1 || $this->isHostname(host: $host) === false) {
			return ['status' => self::INVALID, 'dns' => null];
		}

		if ($this->store->available() === false) {
			return ['status' => self::UNAVAILABLE, 'dns' => null];
		}

		$taken = $this->takenBy(slug: $slug, host: $host);
		if ($taken !== null) {
			return ['status' => $taken, 'dns' => null];
		}

		$token   = 'portaliq-site-verification='.bin2hex(random_bytes(16));
		$created = $this->store->create(
			schema: 'portal',
			data: [
				'title'          => $title,
				'slug'           => $slug,
				'status'         => 'draft',
				'kind'           => 'site',
				'organisation'   => $organisation,
				'domains'        => [['hostname' => $host, 'verified' => false, 'verificationToken' => $token]],
				'authentication' => ['modes' => ['public']],
				'locales'        => ['nl'],
			]
		);
		if ($created === null) {
			return ['status' => self::FAILED, 'dns' => null];
		}

		// A portal without a menu and a home page opens on nothing.
		$this->store->create(schema: 'menu', data: ['title' => 'Hoofdmenu', 'position' => 0, 'items' => [], 'portal' => $slug]);
		$this->store->create(
			schema: 'page',
			data: [
				'title'  => $title,
				'route'  => '/',
				'status' => 'published',
				'locale' => 'nl',
				'body'   => ['type' => 'grid', 'widgets' => []],
				'portal' => $slug,
			]
		);

		return ['status' => self::CREATED, 'dns' => ['name' => PortalDomainVerifier::PREFIX.$host, 'value' => $token]];
	}//end provision()

	/**
	 * Whether a portal already holds the slug or lists the host.
	 *
	 * @param string $slug The wanted slug.
	 * @param string $host The wanted host.
	 *
	 * @return string|null SLUG_TAKEN, HOST_TAKEN, or null when both are free.
	 */
	private function takenBy(string $slug, string $host): ?string {
		foreach ($this->store->find(schema: 'portal', filters: []) as $portal) {
			if (($portal['slug'] ?? null) === $slug) {
				return self::SLUG_TAKEN;
			}

			foreach ((array)($portal['domains'] ?? []) as $domain) {
				if (strtolower((string)($domain['hostname'] ?? '')) === $host) {
					return self::HOST_TAKEN;
				}
			}
		}

		return null;
	}//end takenBy()

	/**
	 * Whether a value is a plain hostname: labels of letters, digits and hyphens.
	 *
	 * @param string $host The value.
	 *
	 * @return bool
	 */
	private function isHostname(string $host): bool {
		return strlen($host) <= 253 && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
	}//end isHostname()
}//end class
