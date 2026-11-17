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
use OCA\Portaliq\Kernel\Contracts\PortalDomainVerifierInterface;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shows and checks the TXT record of a portal's domains.
 *
 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
 */
class PortalDomainVerifier implements PortalDomainVerifierInterface {
	public const PENDING = 'pending';

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
	 * The certificate probe: a host name in, the certificate's end as a
	 * timestamp out, or null when the host gave none.
	 *
	 * @var Closure(string): (int|null)
	 */
	private readonly Closure $probe;

	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface $reader The portal reader.
	 * @param PortalObjectWriter $writer The portal writer.
	 * @param LoggerInterface    $logger The logger.
	 * @param Closure|null       $lookup Resolves a TXT name to its values; DNS by default.
	 * @param Closure|null       $probe  Reads the end of a host's certificate as a timestamp; a TLS connection to port 443 by default.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly PortalObjectWriter $writer,
		private readonly LoggerInterface $logger,
		?Closure $lookup = null,
		?Closure $probe = null,
	) {
		$this->probe = ($probe ?? static fn (string $host): ?int => self::probeCertificate(host: $host));
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
	 * @return array<int, array<string, mixed>>|null Per domain: hostname, name, value, verified, the certificate dates,
	 *                                               servedAtRoot and landingRoute.
	 *                                               Null for an unknown portal.
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/tasks.md#t8
	 */
	public function records(string $slug): ?array {
		$portal = $this->portal(slug: $slug);
		if ($portal === null) {
			return null;
		}

		[$domains, $changed] = $this->tokened(portal: $portal);
		if ($changed === true && $this->save(portal: $portal, domains: $domains) === false) {
			return null;
		}

		return $this->recordViews(domains: $domains);
	}//end records()

	/**
	 * The portal's domains, each with a verification token; a domain without one gets one now.
	 *
	 * @param array<string, mixed> $portal The portal row.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: bool} The domains, and whether a token was added.
	 */
	private function tokened(array $portal): array {
		$domains = $this->domains(portal: $portal);
		$changed = false;
		foreach ($domains as $i => $domain) {
			if (trim((string)($domain['verificationToken'] ?? '')) === '') {
				$domains[$i]['verificationToken'] = 'portaliq-site-verification=' . bin2hex(random_bytes(16));
				$changed = true;
			}
		}

		return [$domains, $changed];
	}//end tokened()

	/**
	 * The record each domain must publish, with its verification and certificate state.
	 *
	 * @param array<int, array<string, mixed>> $domains The domains.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function recordViews(array $domains): array {
		return array_map(
			static fn (array $domain): array => [
				'hostname' => (string)$domain['hostname'],
				'name' => self::PREFIX . $domain['hostname'],
				'value' => (string)$domain['verificationToken'],
				'verified' => (($domain['verified'] ?? false) === true),
				'certificateValidUntil' => ($domain['certificateValidUntil'] ?? null),
				'certificateCheckedAt' => ($domain['certificateCheckedAt'] ?? null),
				// How the host is served (site-at-the-root-of-a-portal-host), so the
				// Domains list can show it.
				'servedAtRoot' => (($domain['servedAtRoot'] ?? false) === true),
				'landingRoute' => (string)($domain['landingRoute'] ?? ''),
			],
			$domains
		);
	}//end recordViews()

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

			return $this->verifyDomain(portal: $portal, domains: $domains, index: $i);
		}

		return self::NOT_FOUND;
	}//end verify()

	/**
	 * Mark one listed domain verified when its record is published, and save.
	 *
	 * @param array<string, mixed>|null        $portal  The portal row.
	 * @param array<int, array<string, mixed>> $domains The portal's domains.
	 * @param int                              $index   The position of the domain to check.
	 *
	 * @return string|null `verified` or `pending`; null when the save failed.
	 */
	private function verifyDomain(array|null $portal, array $domains, int $index): string|null {
		if ($this->published(domain: $domains[$index]) === false) {
			return self::PENDING;
		}

		$domains[$index]['verified']   = true;
		$domains[$index]['verifiedAt'] = gmdate('c');
		$domains[$index]            = $this->withCertificate(domain: $domains[$index]);

		if ($portal === null || $this->save(portal: $portal, domains: $domains) === false) {
			return null;
		}

		return self::VERIFIED;
	}//end verifyDomain()

	/**
	 * The domain with the end of its certificate and the moment it was looked
	 * at. A host that gives no certificate keeps the earlier end date and only
	 * updates the moment.
	 *
	 * @param array<string, mixed> $domain The domain entry.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-10-12-portal-domains-and-editors/tasks.md#t03
	 */
	private function withCertificate(array $domain): array {
		$until = null;
		try {
			$until = ($this->probe)((string)$domain['hostname']);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: certificate probe failed', ['reason' => $e->getMessage()]);
		}

		if ($until !== null) {
			$domain['certificateValidUntil'] = gmdate('c', $until);
		}

		$domain['certificateCheckedAt'] = gmdate('c');

		return $domain;
	}//end withCertificate()

	/**
	 * Read the end of the certificate a host presents on port 443. The chain is
	 * not verified and no request is sent: only the end date is read. A host that
	 * resolves to a private or reserved address is not contacted.
	 *
	 * @param string $host The host name.
	 *
	 * @return int|null The end as a timestamp, null when the host gave no certificate.
	 *
	 * @spec openspec/changes/archive/2026-10-12-portal-domains-and-editors/tasks.md#t03
	 */
	public static function probeCertificate(string $host): ?int {
		$address = self::publicAddress(host: $host);
		if ($address === null) {
			return null;
		}

		$certificate = self::peerCertificate(host: $host, address: $address);
		if ($certificate === null) {
			return null;
		}

		$parsed = openssl_x509_parse($certificate);
		if ($parsed === false || isset($parsed['validTo_time_t']) === false) {
			return null;
		}

		return (int)$parsed['validTo_time_t'];
	}//end probeCertificate()

	/**
	 * The address a host resolves to, unless it is private or reserved.
	 *
	 * @param string $host The host name.
	 *
	 * @return string|null The address, null when the host must not be contacted.
	 */
	private static function publicAddress(string $host): ?string {
		$address = gethostbyname($host);
		if ($address === $host || self::isGlobalAddress(address: $address) === false) {
			return null;
		}

		return $address;
	}//end publicAddress()

	/**
	 * Whether an address is globally routable: not private, reserved, shared
	 * (CGNAT), benchmarking or documentation space.
	 *
	 * @param string $address The IP address.
	 *
	 * @return bool
	 */
	private static function isGlobalAddress(string $address): bool {
		$flags = (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE);

		return filter_var($address, FILTER_VALIDATE_IP, $flags) !== false;
	}//end isGlobalAddress()

	/**
	 * The certificate a host presents on port 443 of an address already checked.
	 * The connection goes to the address, so a second lookup cannot redirect it.
	 *
	 * @param string $host    The host name, sent as the TLS server name.
	 * @param string $address The checked address.
	 *
	 * @return mixed The certificate, null when the connection failed or gave none.
	 */
	private static function peerCertificate(string $host, string $address): mixed {
		$errorCode    = 0;
		$errorMessage = '';
		$context      = self::tlsContext(host: $host);

		set_error_handler(static fn (): bool => true);
		try {
			$socket = stream_socket_client(
				address: 'ssl://' . $address . ':443',
				error_code: $errorCode,
				error_message: $errorMessage,
				timeout: 5,
				flags: STREAM_CLIENT_CONNECT,
				context: $context
			);
		} finally {
			restore_error_handler();
		}

		if ($socket === false || ($errorCode !== 0 && $errorMessage !== '')) {
			return null;
		}

		$params = stream_context_get_params($socket);
		fclose($socket);

		return ($params['options']['ssl']['peer_certificate'] ?? null);
	}//end peerCertificate()

	/**
	 * A TLS context that captures the peer's certificate without verifying the chain.
	 *
	 * @param string $host The host name, sent as the TLS server name.
	 *
	 * @return resource The context.
	 */
	private static function tlsContext(string $host) {
		return stream_context_create(
			[
				'ssl' => [
					'capture_peer_cert' => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'peer_name'         => $host,
					'SNI_enabled'       => true,
					'SNI_server_name'   => $host,
				],
			]
		);
	}//end tlsContext()

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
