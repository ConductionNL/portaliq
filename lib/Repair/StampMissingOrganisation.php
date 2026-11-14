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

/**
 * Stamps a missing organisation from the row's portal, or reports the row.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t03
 */
class StampMissingOrganisation implements IRepairStep {
	use ObjectServiceLookup;
	use SchemaRowAccess;

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
	 * Logged when a schema cannot be read.
	 */
	private const ROWS_UNREADABLE_LOG = 'Portaliq: no rows to stamp';

	/**
	 * Logged when a row cannot be written.
	 */
	private const WRITE_FAILED_LOG = 'Portaliq: a row could not be given its organisation';

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
		$placing = [
			'portals' => $portals,
			'byPortal' => $this->organisationsByPortal(portals: $portals),
			'sole' => $this->soleOrganisation(portals: $portals),
		];

		$stamped = 0;
		$left = [];
		foreach ($this->organisationSchemas() as $schema) {
			$counts = $this->stampSchema(objectService: $objectService, schema: $schema, placing: $placing);
			$stamped += $counts['stamped'];
			if ($counts['left'] > 0) {
				$left[$schema] = $counts['left'];
			}
		}

		$output->info('StampMissingOrganisation: gave ' . $stamped . ' rows their organisation, ' . array_sum($left) . ' still without one.');
		foreach ($left as $schema => $count) {
			$output->warning('StampMissingOrganisation: ' . $count . ' ' . $schema . ' rows have no organisation and are not served. Set it by hand.');
		}
	}//end run()

	/**
	 * Stamp the rows of one schema that name no organisation.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $schema        The schema slug.
	 * @param array<string, mixed> $placing       The stored portals, portal slug to organisation, and the sole organisation.
	 *
	 * @return array{stamped: int, left: int}
	 */
	private function stampSchema(object $objectService, string $schema, array $placing): array {
		$rows = $placing['portals'];
		if ($schema !== self::PORTAL_SCHEMA) {
			$rows = $this->rows(objectService: $objectService, schema: $schema);
		}

		$counts = ['stamped' => 0, 'left' => 0];
		foreach ($this->unplaced(rows: $rows) as $row) {
			$organisation = $this->placement(schema: $schema, row: $row, placing: $placing);
			if ($organisation !== null
				&& $this->writeFields(objectService: $objectService, schema: $schema, row: $row, fields: ['organisation' => $organisation]) === true
			) {
				$counts['stamped']++;
				continue;
			}

			$counts['left']++;
		}

		return $counts;
	}//end stampSchema()

	/**
	 * The rows that name no organisation.
	 *
	 * @param list<array<string, mixed>> $rows The stored rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function unplaced(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			if ($this->text(value: $row['organisation'] ?? null) === '') {
				$out[] = $row;
			}
		}

		return $out;
	}//end unplaced()

	/**
	 * The organisation a row without one is given, or null when that would be a guess.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $row     The stored row.
	 * @param array<string, mixed> $placing Portal slug to its organisation (`byPortal`) and the one organisation every portal belongs to (`sole`).
	 *
	 * @return string|null
	 */
	private function placement(string $schema, array $row, array $placing): ?string {
		if ($schema === self::PORTAL_SCHEMA) {
			return null;
		}

		$portal = $this->text(value: $row['portal'] ?? null);
		if ($portal !== '') {
			return ($placing['byPortal'][$portal] ?? null);
		}

		return $placing['sole'];
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
}//end class
