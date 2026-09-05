<?php

/**
 * Portaliq Traffic Server Token.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The bearer token a trusted backend presents to report traffic on a
 * visitor's behalf, one per portal.
 *
 * SHOWN ONCE, STORED HASHED. The command prints the token the moment it
 * is minted and nothing keeps it: the portal record carries the sha256 of
 * it under `traffic.serverToken`, which verifies a presented token and
 * recovers nothing. A token has 256 bits of entropy, so the hash needs no
 * salt to resist guessing. Minting again replaces the hash and the old
 * token stops working in the same write.
 *
 * The hash is compared with `hash_equals` so the comparison's timing does
 * not say how many characters matched.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
 */
class TrafficServerToken {

	/**
	 * OpenRegister's ObjectService FQCN, resolved lazily from the container.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Characters in a minted token: 43 of the URL-safe alphabet is 256 bits.
	 */
	private const LENGTH = 43;

	/**
	 * Constructor.
	 *
	 * @param PortalResolver        $portals   Finds the portal by slug.
	 * @param TrafficConfigResolver $config    Reads the stored hash.
	 * @param ISecureRandom         $random    Mints the token.
	 * @param ContainerInterface    $container For the lazy OpenRegister lookup.
	 * @param PortalRegisterContext $context   Points the shared ObjectService at the portal schema.
	 * @param LoggerInterface       $logger    The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalResolver $portals,
		private readonly TrafficConfigResolver $config,
		private readonly ISecureRandom $random,
		private readonly ContainerInterface $container,
		private readonly PortalRegisterContext $context,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Mint a token for a portal and store its hash on the portal record.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return string|null The token, shown once; null when the portal is unknown or the write failed.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
	 */
	public function issue(string $slug): ?string {
		$portal = $this->portal(slug: $slug);
		if ($portal === null) {
			return null;
		}

		$token = $this->random->generate(self::LENGTH, ISecureRandom::CHAR_ALPHANUMERIC . '-_');
		$traffic = $portal['traffic'] ?? [];
		if (is_array($traffic) === false) {
			$traffic = [];
		}

		$traffic['serverToken'] = $this->hash(token: $token);
		$record = $portal;
		$record['traffic'] = $traffic;
		$uuid = trim((string)($portal['@self']['uuid'] ?? $portal['uuid'] ?? $portal['@self']['id'] ?? $portal['id'] ?? ''));
		unset($record['@self']);
		if ($uuid === '' || $this->save(record: $record, uuid: $uuid) === false) {
			return null;
		}

		return $token;
	}

	/**
	 * Whether a presented token is the portal's.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 * @param string               $token  The presented token.
	 *
	 * @return bool True when it matches the stored hash.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
	 */
	public function verify(array $portal, string $token): bool {
		$stored = $this->config->serverTokenHash(portal: $portal);
		$token = trim($token);
		if ($stored === '' || $token === '') {
			return false;
		}

		return hash_equals($stored, $this->hash(token: $token));
	}

	/**
	 * The hash stored for a token.
	 *
	 * @param string $token The token.
	 *
	 * @return string The sha256 hex.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
	 */
	public function hash(string $token): string {
		return hash('sha256', $token);
	}

	/**
	 * The published portal with this slug, or null.
	 *
	 * @param string $slug The slug.
	 *
	 * @return array<string, mixed>|null The record.
	 */
	private function portal(string $slug): ?array {
		foreach ($this->portals->allPublishedPortals() as $candidate) {
			if (($candidate['slug'] ?? null) === $slug) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Write the portal record back, by uuid.
	 *
	 * @param array<string, mixed> $record The record without its `@self`.
	 * @param string               $uuid   Its uuid.
	 *
	 * @return bool True when saved.
	 */
	private function save(array $record, string $uuid): bool {
		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			if (is_object($objectService) === false || $this->context->apply(objectService: $objectService, schemaSlug: 'portal') === false) {
				return false;
			}

			$objectService->saveObject(
				object: $record,
				register: TrafficEventStore::REGISTER,
				schema: 'portal',
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: traffic server token could not be stored', ['reason' => $e->getMessage()]);

			return false;
		}
	}
}
