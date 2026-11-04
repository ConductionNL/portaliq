<?php

/**
 * Portaliq PortalTenantContext
 *
 * Hands out OpenRegister's object service for one organisation: every call on
 * it runs inside `ObjectService::runForOrganisation()`, so OpenRegister keeps
 * the organisation filter on the portal's reads and stamps the organisation on
 * its creates. A portal names its organisation by slug; the slug is mapped to
 * the organisation's uuid once per request.
 *
 * Without an organisation, on an OpenRegister that cannot act for one, or
 * without the organisation lookup, the plain object service is returned, so
 * those calls run as before and the per-row organisation checks stay the
 * boundary. A slug that names no organisation gets no service at all, so
 * nothing is read or written.
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
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-a-portal-read-or-write-runs-as-its-organisation-req-pao-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

use OCA\Portaliq\Service\ObjectServiceLookup;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OpenRegister's object service, acting for one organisation.
 *
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-a-portal-read-or-write-runs-as-its-organisation-req-pao-001
 */
final class PortalTenantContext {
	use ObjectServiceLookup;

	/**
	 * The OpenRegister method that runs a callable for an organisation.
	 */
	private const ACT_FOR = 'runForOrganisation';

	/**
	 * Organisation slug to uuid ('' when it did not resolve), per request.
	 *
	 * @var array<string, string|null>
	 */
	private array $uuids = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service and the organisation lookup.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The object service for the organisation with this slug.
	 *
	 * @param string $organisation The organisation slug, or '' for none.
	 *
	 * @return object|null The service acting for the organisation; the plain
	 *                     service for no organisation or an OpenRegister that
	 *                     cannot act for one; null when OpenRegister is absent
	 *                     or the slug names no organisation.
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-a-portal-read-or-write-runs-as-its-organisation-req-pao-001
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-an-organisation-the-portal-cannot-resolve-reads-and-writes-nothing-req-pao-002
	 */
	public function objectServiceFor(string $organisation): ?object {
		$objectService = $this->objectService();
		if ($objectService === null || $organisation === '' || method_exists($objectService, self::ACT_FOR) === false) {
			return $objectService;
		}

		$uuid = $this->uuidFor(slug: $organisation);
		if ($uuid === null) {
			return $objectService;
		}

		if ($uuid === '') {
			$this->logger->warning('Portaliq: the portal organisation does not resolve, nothing is read or written', ['organisation' => $organisation]);
			return null;
		}

		return new OrganisationScopedObjectService(objectService: $objectService, organisationUuid: $uuid);
	}//end objectServiceFor()

	/**
	 * The object service for the organisation with this uuid, as a record
	 * carries it in `@self.organisation`.
	 *
	 * @param string $organisationUuid The organisation uuid, or '' for none.
	 *
	 * @return object|null The service acting for the organisation, the plain
	 *                     service for no organisation or an OpenRegister that
	 *                     cannot act for one, or null when OpenRegister is absent.
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-claim-addressed-recipients-are-found-in-the-records-organisation-req-pao-003
	 */
	public function objectServiceForUuid(string $organisationUuid): ?object {
		$objectService = $this->objectService();
		if ($objectService === null || $organisationUuid === '' || method_exists($objectService, self::ACT_FOR) === false) {
			return $objectService;
		}

		return new OrganisationScopedObjectService(objectService: $objectService, organisationUuid: $organisationUuid);
	}//end objectServiceForUuid()

	/**
	 * The uuid of the organisation with this slug.
	 *
	 * @param string $slug The organisation slug.
	 *
	 * @return string|null The uuid, '' when the slug names no organisation, or
	 *                     null when the lookup is unavailable.
	 */
	private function uuidFor(string $slug): ?string {
		if (array_key_exists($slug, $this->uuids) === false) {
			$this->uuids[$slug] = $this->lookUp(slug: $slug);
		}

		return $this->uuids[$slug];
	}//end uuidFor()

	/**
	 * Look the slug up through the portal organisation lookup.
	 *
	 * @param string $slug The organisation slug.
	 *
	 * @return string|null The uuid, '' when it finds nothing, or null when the
	 *                     lookup is unavailable.
	 */
	private function lookUp(string $slug): ?string {
		try {
			$organisations = $this->container->get(PortalOrganisationConfigService::class);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: organisation lookup unavailable', ['reason' => $e->getMessage()]);
			return null;
		}

		if ($organisations instanceof PortalOrganisationConfigService === false) {
			return null;
		}

		return (string)($organisations->findOrganisationBySlug(slug: $slug)['uuid'] ?? '');
	}//end lookUp()
}//end class
