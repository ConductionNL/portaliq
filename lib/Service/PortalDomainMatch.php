<?php

/**
 * Portaliq Portal Domain Match
 *
 * Finds the portal, and the verified domain entry, a request host belongs to.
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Log\LoggerInterface;

/**
 * The one rule host resolution rests on: a host matches a portal only through
 * a domain entry that is verified. An unverified entry is treated exactly like
 * an unknown host, because DNS pointing here is not consent.
 *
 * Kept apart from PortalResolver so the resolver answers both "which portal"
 * (`resolveByHost`) and "on which domain" (`servingDomain`) from the same loop.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves
 */
final class PortalDomainMatch {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Notes a host that matched an unverified domain.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The portal and the verified domain entry a host matches, or null.
	 *
	 * @param string $host  The request host, lower-cased, without port.
	 * @param array  $sites The published portals.
	 *
	 * @return array{site: array<string, mixed>, domain: array<string, mixed>}|null
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-host-that-resolves-no-portal-must-get-no-site-req-srh-003
	 */
	public function find(string $host, array $sites): ?array {
		if ($host === '') {
			return null;
		}

		foreach ($sites as $site) {
			$match = $this->verifiedMatch(site: $site, host: $host);
			if ($match !== null) {
				return $match;
			}
		}

		return null;
	}//end find()

	/**
	 * The site and its verified domain entry naming the host, or null. A
	 * named but unverified entry is logged and skipped.
	 *
	 * @param mixed  $site The published portal.
	 * @param string $host The request host, lower-cased, without port.
	 *
	 * @return array{site: array<string, mixed>, domain: array<string, mixed>}|null
	 */
	private function verifiedMatch(mixed $site, string $host): ?array {
		foreach (($site['domains'] ?? []) as $domain) {
			if ($this->namesHost(domain: $domain, host: $host) === false) {
				continue;
			}

			// Unverified is treated exactly like unknown. This is the whole
			// point of verification: DNS pointing here is not consent.
			if (($domain['verified'] ?? false) === true) {
				return ['site' => $site, 'domain' => $domain];
			}

			$this->logger->info(
				'Portaliq: host matched an UNVERIFIED domain; refusing to serve',
				['host' => $host, 'site' => $site['slug'] ?? null]
			);
		}

		return null;
	}//end verifiedMatch()

	/**
	 * Whether a domain entry names the host: its stored name, trimmed and
	 * lower-cased, is the host.
	 *
	 * @param mixed  $domain The domain entry.
	 * @param string $host   The request host, lower-cased, without port.
	 *
	 * @return bool
	 */
	private function namesHost(mixed $domain, string $host): bool {
		if (is_array($domain) === false) {
			return false;
		}

		$hostname = strtolower(trim((string)($domain['hostname'] ?? '')));
		return $hostname !== '' && $hostname === $host;
	}//end namesHost()
}//end class
