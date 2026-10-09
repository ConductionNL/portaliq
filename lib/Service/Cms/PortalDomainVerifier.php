<?php

/**
 * Portaliq Portal Domain Verifier (portal-cms-admin-ui)
 *
 * The record a tenant publishes to prove it controls a hostname, and the
 * check that reads it. A domain serves only once `verified` is true. "The
 * record is not there yet" is the normal first answer, so the check can be
 * run again without adding the domain again.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
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
 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use Closure;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shows and checks the TXT record of a portal's domains.
 *
 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
 */
class PortalDomainVerifier {
	public const PENDING = 'pending';

	public const VERIFIED = 'verified';

	public const NOT_FOUND = 'not_found';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'portal';

	public const PREFIX = '_portaliq-verify.';

	/**
	 * The TXT lookup: a record name in, the record values out.
	 *
	 * @var Closure(string): array<int, string>
	 */
	private readonly Closure $lookup;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader The portal reader.
	 * @param PortalObjectWriter $writer The portal writer.
	 * @param LoggerInterface    $logger The logger.
	 * @param Closure|null       $lookup Resolves a TXT name to its values; DNS by default.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly LoggerInterface $logger,
		?Closure $lookup = null,
	) {
		$this->lookup = ($lookup ?? static function (string $name): array {
			// A failed lookup warns; the handler swallows it so the miss reads as no record.
			set_error_handler(static fn (): bool => true);
			try {
				$records = dns_get_record($name, DNS_TXT);
			} finally {
				restore_error_handler();
			}

			if (is_array($records) === false) {
				return [];
			}

			return array_map(static fn (array $record): string => (string)($record['txt'] ?? ''), $records);
		});
	}//end __construct()

	/**
	 * The record each domain of a portal must publish. A domain with no token
	 * yet gets one now, and it is kept.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<int, array{hostname: string, name: string, value: string, verified: bool}>|null Null for an unknown portal.
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
	 */
	public function records(string $slug): ?array {
		$portal = $this->portal(slug: $slug);
		if ($portal === null) {
			return null;
		}

		$domains = $this->domains(portal: $portal);
		$changed = false;
		foreach ($domains as $i => $domain) {
			if (trim((string)($domain['verificationToken'] ?? '')) === '') {
				$domains[$i]['verificationToken'] = 'portaliq-site-verification=' . bin2hex(random_bytes(16));
				$changed = true;
			}
		}

		if ($changed === true && $this->save(portal: $portal, domains: $domains) === false) {
			return null;
		}

		return array_map(
			static fn (array $domain): array => [
				'hostname' => (string)$domain['hostname'],
				'name' => self::PREFIX . $domain['hostname'],
				'value' => (string)$domain['verificationToken'],
				'verified' => (($domain['verified'] ?? false) === true),
			],
			$domains
		);
	}//end records()

	/**
	 * Check one domain's record. A record that is not there yet leaves the
	 * domain pending and changes nothing, so the check can run again.
	 *
	 * @param string $slug     The portal slug.
	 * @param string $hostname The domain to check; it must be one of the portal's own.
	 *
	 * @return string|null `verified`, `pending`, or `not_found` for a domain the portal does not list;
	 *                     null when the portal is unknown or the save failed.
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
	 */
	public function verify(string $slug, string $hostname): ?string {
		if ($this->records(slug: $slug) === null) {
			return null;
		}

		$portal  = $this->portal(slug: $slug);
		$domains = $this->domains(portal: $portal ?? []);
		foreach ($domains as $i => $domain) {
			if (strtolower((string)$domain['hostname']) !== strtolower($hostname)) {
				continue;
			}

			if ($this->published(domain: $domain) === false) {
				return self::PENDING;
			}

			$domains[$i]['verified']   = true;
			$domains[$i]['verifiedAt'] = gmdate('c');

			if ($portal === null || $this->save(portal: $portal, domains: $domains) === false) {
				return null;
			}

			return self::VERIFIED;
		}

		return self::NOT_FOUND;
	}//end verify()

	/**
	 * Whether the domain's record is published with this portal's token.
	 *
	 * @param array<string, mixed> $domain The domain entry.
	 *
	 * @return bool
	 */
	private function published(array $domain): bool {
		try {
			$values = ($this->lookup)(self::PREFIX . $domain['hostname']);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: domain record lookup failed', ['reason' => $e->getMessage()]);
			return false;
		}

		return in_array((string)$domain['verificationToken'], array_map('trim', $values), true);
	}//end published()

	/**
	 * The portal row by slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function portal(string $slug): ?array {
		if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug) !== 1) {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'slug',
			subjectRef: $slug,
			organisation: '',
			limit: 5
		);
		foreach ($rows as $row) {
			if (($row['slug'] ?? '') === $slug) {
				return $row;
			}
		}

		return null;
	}//end portal()

	/**
	 * The portal's domain entries that name a host.
	 *
	 * @param array<string, mixed> $portal The portal row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function domains(array $portal): array {
		$out = [];
		foreach ((array)($portal['domains'] ?? []) as $domain) {
			$host = null;
			if (is_array($domain) === true) {
				$host = ($domain['hostname'] ?? null);
			}

			if (is_string($host) === true && preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $host) === 1) {
				$out[] = $domain;
			}
		}

		return $out;
	}//end domains()

	/**
	 * Write the domains back.
	 *
	 * @param array<string, mixed>             $portal  The portal row.
	 * @param array<int, array<string, mixed>> $domains The domains.
	 *
	 * @return bool
	 */
	private function save(array $portal, array $domains): bool {
		$id = (string)($portal['uuid'] ?? $portal['id'] ?? ($portal['@self']['uuid'] ?? ($portal['@self']['id'] ?? '')));
		if ($id === '') {
			return false;
		}

		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: ['domains' => $domains]
		) !== null;
	}//end save()
}//end class
