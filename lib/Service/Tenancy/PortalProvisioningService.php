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
		$wanted  = new PortalProvisionRequest(organisation: trim($organisation), slug: trim($slug), title: trim($title), host: strtolower(trim($host)));
		$refused = $this->refusal(wanted: $wanted);
		if ($refused !== null) {
			return ['status' => $refused, 'dns' => null];
		}

		$token = 'portaliq-site-verification='.bin2hex(random_bytes(16));
		if ($this->createPortal(wanted: $wanted, token: $token) === null) {
			return ['status' => self::FAILED, 'dns' => null];
		}

		$this->createMenuAndHome(wanted: $wanted);

		return ['status' => self::CREATED, 'dns' => ['name' => PortalDomainVerifier::PREFIX.$wanted->host, 'value' => $token]];
	}//end provision()

	/**
	 * Why the portal cannot be created, before anything is written, or null.
	 *
	 * @param PortalProvisionRequest $wanted The portal asked for.
	 *
	 * @return string|null INVALID, UNAVAILABLE, SLUG_TAKEN, HOST_TAKEN, or null.
	 */
	private function refusal(PortalProvisionRequest $wanted): ?string {
		if ($this->isValid(wanted: $wanted) === false) {
			return self::INVALID;
		}

		if ($this->store->available() === false) {
			return self::UNAVAILABLE;
		}

		return $this->takenBy(slug: $wanted->slug, host: $wanted->host);
	}//end refusal()

	/**
	 * Whether the request names an organisation, a title, a plain slug and a plain host.
	 *
	 * @param PortalProvisionRequest $wanted The portal asked for.
	 *
	 * @return bool
	 */
	private function isValid(PortalProvisionRequest $wanted): bool {
		return $wanted->organisation !== '' && $wanted->title !== ''
			&& preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $wanted->slug) === 1 && $this->isHostname(host: $wanted->host) === true;
	}//end isValid()

	/**
	 * Write the draft portal with its unverified host.
	 *
	 * @param PortalProvisionRequest $wanted The portal asked for.
	 * @param string                 $token  The host's verification token.
	 *
	 * @return string|null The new portal's id; null when nothing was written.
	 */
	private function createPortal(PortalProvisionRequest $wanted, string $token): ?string {
		return $this->store->create(
			schema: 'portal',
			data: [
				'title'          => $wanted->title,
				'slug'           => $wanted->slug,
				'status'         => 'draft',
				'kind'           => 'site',
				'organisation'   => $wanted->organisation,
				'domains'        => [['hostname' => $wanted->host, 'verified' => false, 'verificationToken' => $token]],
				'authentication' => ['modes' => ['public']],
				'locales'        => ['nl'],
			]
		);
	}//end createPortal()

	/**
	 * Write the portal's menu and home page: a portal without them opens on nothing.
	 *
	 * @param PortalProvisionRequest $wanted The portal asked for.
	 *
	 * @return void
	 */
	private function createMenuAndHome(PortalProvisionRequest $wanted): void {
		$this->store->create(schema: 'menu', data: ['title' => 'Hoofdmenu', 'position' => 0, 'items' => [], 'portal' => $wanted->slug]);
		$this->store->create(
			schema: 'page',
			data: [
				'title'  => $wanted->title,
				'route'  => '/',
				'status' => 'published',
				'locale' => 'nl',
				'body'   => ['type' => 'grid', 'widgets' => []],
				'portal' => $wanted->slug,
			]
		);
	}//end createMenuAndHome()

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

			if ($this->listsHost(portal: $portal, host: $host) === true) {
				return self::HOST_TAKEN;
			}
		}

		return null;
	}//end takenBy()

	/**
	 * Whether a portal lists a host among its domains, in any case.
	 *
	 * @param array<string, mixed> $portal The portal.
	 * @param string               $host   The wanted host.
	 *
	 * @return bool
	 */
	private function listsHost(array $portal, string $host): bool {
		foreach ((array)($portal['domains'] ?? []) as $domain) {
			if (strtolower((string)($domain['hostname'] ?? '')) === $host) {
				return true;
			}
		}

		return false;
	}//end listsHost()

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
