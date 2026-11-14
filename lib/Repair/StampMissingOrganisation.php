<?php

/**
 * Portaliq repair step: give organisation-scoped rows their organisation
 *
 * The portal reader drops a row of an organisation-scoped schema that names no
 * organisation (operate-portals-per-organisation REQ-OPO-002), so such a row,
 * written before the rule, stops being served. This step stamps the row's
 * `organisation` from the portal the row names, or, when every portal belongs
 * to one and the same organisation, from that organisation. Anything else
 * would be a guess: the row is left as it is and counted, so a run that
 * placed nothing reads differently from a run that never executed.
 *
 * A portal without an organisation is never stamped: which organisation a
 * portal belongs to is a decision for a person.
 *
 * Registered under post-migration only, never under install.
 *
 * @category Repair
 * @package  OCA\Portaliq\Repair
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use OCA\Portaliq\Kernel\Contracts\PortalRegisterContextInterface;
use OCA\Portaliq\Service\ObjectServiceLookup;
use OCA\Portaliq\Service\Tenancy\SchemaTenancy;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps a missing organisation from the row's portal, or reports the row.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t03
 */
class StampMissingOrganisation implements IRepairStep {
	use ObjectServiceLookup;

	/**
	 * The register the rows live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema of the portals themselves.
	 */
	private const PORTAL_SCHEMA = 'portal';

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Objects read per page.
	 */
	private const PAGE = 100;

	/**
	 * A bound on the pages read per schema.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface             $container Resolves OpenRegister's object service, which may be absent.
	 * @param PortalRegisterContextInterface $context   Points the object service at a schema.
	 * @param LoggerInterface                $logger    Names a row that could not be written.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalRegisterContextInterface $context,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t03
	 */
	public function getName(): string {
		return 'Give organisation-scoped rows their organisation';
	}//end getName()

	/**
	 * Stamp what can be placed, then report per schema what is left.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t03
	 */
	public function run(IOutput $output): void {
		$objectService = $this->objectService();
		if ($objectService === null) {
			$output->info('StampMissingOrganisation: OpenRegister is not available, nothing stamped.');

			return;
		}

		$portals = $this->rows(objectService: $objectService, schema: self::PORTAL_SCHEMA);
		$byPortal = $this->organisationsByPortal(portals: $portals);
		$sole = $this->soleOrganisation(portals: $portals);

		$stamped = 0;
		$left = [];
		foreach ($this->organisationSchemas() as $schema) {
			$rows = $portals;
			if ($schema !== self::PORTAL_SCHEMA) {
				$rows = $this->rows(objectService: $objectService, schema: $schema);
			}

			foreach ($rows as $row) {
				if ($this->text(value: $row['organisation'] ?? null) !== '') {
					continue;
				}

				$organisation = $this->placement(schema: $schema, row: $row, byPortal: $byPortal, sole: $sole);
				if ($organisation !== null && $this->stamp(objectService: $objectService, schema: $schema, row: $row, organisation: $organisation) === true) {
					$stamped++;
					continue;
				}

				$left[$schema] = (($left[$schema] ?? 0) + 1);
			}
		}

		$output->info('StampMissingOrganisation: gave ' . $stamped . ' rows their organisation, ' . array_sum($left) . ' still without one.');
		foreach ($left as $schema => $count) {
			$output->warning('StampMissingOrganisation: ' . $count . ' ' . $schema . ' rows have no organisation and are not served. Set it by hand.');
		}
	}//end run()

	/**
	 * The organisation a row without one is given, or null when that would be a guess.
	 *
	 * @param string                $schema   The schema slug.
	 * @param array<string, mixed>  $row      The stored row.
	 * @param array<string, string> $byPortal Portal slug to its organisation.
	 * @param string|null           $sole     The one organisation every portal belongs to.
	 *
	 * @return string|null
	 */
	private function placement(string $schema, array $row, array $byPortal, ?string $sole): ?string {
		if ($schema === self::PORTAL_SCHEMA) {
			return null;
		}

		$portal = $this->text(value: $row['portal'] ?? null);
		if ($portal !== '') {
			return ($byPortal[$portal] ?? null);
		}

		return $sole;
	}//end placement()

	/**
	 * The organisation-scoped schemas, the portal schema included.
	 *
	 * @return list<string>
	 */
	private function organisationSchemas(): array {
		$out = [];
		foreach (array_keys(SchemaTenancy::MAP) as $schema) {
			if (SchemaTenancy::isOrganisationScoped(schema: $schema) === true) {
				$out[] = $schema;
			}
		}

		return $out;
	}//end organisationSchemas()

	/**
	 * Portal slug to the organisation it belongs to, for portals that name one.
	 *
	 * @param list<array<string, mixed>> $portals The stored portals.
	 *
	 * @return array<string, string>
	 */
	private function organisationsByPortal(array $portals): array {
		$out = [];
		foreach ($portals as $portal) {
			$slug = $this->text(value: $portal['slug'] ?? null);
			$organisation = $this->text(value: $portal['organisation'] ?? null);
			if ($slug !== '' && $organisation !== '') {
				$out[$slug] = $organisation;
			}
		}

		return $out;
	}//end organisationsByPortal()

	/**
	 * The organisation when there is at least one portal and every portal names that same one, else null.
	 *
	 * @param list<array<string, mixed>> $portals The stored portals.
	 *
	 * @return string|null
	 */
	private function soleOrganisation(array $portals): ?string {
		$seen = [];
		foreach ($portals as $portal) {
			$organisation = $this->text(value: $portal['organisation'] ?? null);
			if ($organisation === '') {
				return null;
			}

			$seen[$organisation] = true;
		}

		if (count($seen) !== 1) {
			return null;
		}

		return (string)array_key_first($seen);
	}//end soleOrganisation()

	/**
	 * Write the organisation onto one row.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $schema        The schema slug.
	 * @param array<string, mixed> $row           The stored row.
	 * @param string               $organisation  The organisation slug.
	 *
	 * @return bool True when the row was written.
	 */
	private function stamp(object $objectService, string $schema, array $row, string $organisation): bool {
		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		$uuid = (string)($self['uuid'] ?? $self['id'] ?? $row['id'] ?? '');
		if ($uuid === '') {
			return false;
		}

		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: $schema) === false) {
				return false;
			}

			unset($row['@self']);
			$row['organisation'] = $organisation;
			$objectService->saveObject(object: $row, register: self::REGISTER, schema: $schema, uuid: $uuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Portaliq: a row could not be given its organisation',
				['uuid' => $uuid, 'schema' => $schema, 'reason' => $e->getMessage()]
			);

			return false;
		}

		return true;
	}//end stamp()

	/**
	 * A value as a trimmed string; anything that is not a string is ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()

	/**
	 * Every stored row of a schema, a page at a time.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $schema        The schema slug.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(object $objectService, string $schema): array {
		$out    = [];
		$offset = 0;
		$pages  = 0;
		do {
			$pages++;
			try {
				if ($this->context->apply(objectService: $objectService, schemaSlug: $schema) === false) {
					return $out;
				}

				$page = (array)$objectService->findAll(config: ['limit' => self::PAGE, 'offset' => $offset], _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->info('Portaliq: no rows to stamp', ['schema' => $schema, 'reason' => $e->getMessage()]);

				return $out;
			}

			$read    = count($page);
			$offset += $read;
			foreach ($page as $row) {
				if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					$row = $row->jsonSerialize();
				}

				if (is_array($row) === true) {
					$out[] = $row;
				}
			}
		} while ($read === self::PAGE && $pages < self::MAX_PAGES);

		return $out;
	}//end rows()
}//end class
