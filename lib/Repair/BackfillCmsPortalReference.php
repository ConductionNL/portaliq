<?php

/**
 * Portaliq repair step: give existing content a portal
 *
 * Every menu, page, glossary term and media item belongs to exactly one portal
 * (the register's `website` scope). Content written before that scope existed
 * carries no `portal`, and under the no-default-portal rule it is not served.
 * This step assigns the portal to such content when exactly one portal exists
 * and leaves the content alone when the choice would be a guess. It reports how
 * many objects it changed and how many it could not place, so a run that did
 * nothing reads differently from a run that never executed.
 *
 * Registered under post-migration only, never under install: a data migration
 * does not belong on the unconditional install hook.
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
 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Assigns the only portal to content that has none.
 *
 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-4
 */
class BackfillCmsPortalReference implements IRepairStep {
	/**
	 * The schemas whose objects must name a portal.
	 *
	 * @var string[]
	 */
	public const CONTENT_SCHEMAS = ['menu', 'page', 'glossaryTerm', 'media'];

	/**
	 * The register the content lives in.
	 */
	private const REGISTER = 'portaliq';

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
	 * @param ContainerInterface    $container Resolves OpenRegister's object service, which may be absent.
	 * @param PortalRegisterContext $context   Points the object service at a schema.
	 * @param LoggerInterface       $logger    Names an object that could not be written.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalRegisterContext $context,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-4
	 */
	public function getName(): string {
		return 'Give existing site content its portal';
	}//end getName()

	/**
	 * Assign the portal to content without one, then report what is left.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-4
	 */
	public function run(IOutput $output): void {
		$objectService = $this->objectService();
		if ($objectService === null) {
			$output->info('BackfillCmsPortalReference: OpenRegister is not available, nothing assigned.');

			return;
		}

		$slugs  = $this->portalSlugs(objectService: $objectService);
		$target = null;
		if (count($slugs) === 1) {
			$target = $slugs[0];
		}

		$changed = 0;
		$orphans = 0;
		foreach (self::CONTENT_SCHEMAS as $schema) {
			foreach ($this->rows(objectService: $objectService, schema: $schema) as $row) {
				if ($this->hasPortal(row: $row) === true) {
					continue;
				}

				if ($target !== null && $this->assign(objectService: $objectService, schema: $schema, row: $row, portal: $target) === true) {
					$changed++;
					continue;
				}

				$orphans++;
			}
		}

		$output->info('BackfillCmsPortalReference: assigned a portal to ' . $changed . ' objects, ' . $orphans . ' still without one.');
		if ($orphans > 0) {
			$output->warning('BackfillCmsPortalReference: ' . $orphans . ' objects have no portal and are not served. Assign them in the admin.');
		}
	}//end run()

	/**
	 * Whether a stored object names a portal.
	 *
	 * @param array<string, mixed> $row The stored object.
	 *
	 * @return bool
	 */
	private function hasPortal(array $row): bool {
		return is_string($row['portal'] ?? null) === true && trim($row['portal']) !== '';
	}//end hasPortal()

	/**
	 * Write the portal onto one object.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $schema        The schema slug.
	 * @param array<string, mixed> $row           The stored object.
	 * @param string               $portal        The portal slug.
	 *
	 * @return bool True when the object was written.
	 */
	private function assign(object $objectService, string $schema, array $row, string $portal): bool {
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
			$row['portal'] = $portal;
			$objectService->saveObject(object: $row, register: self::REGISTER, schema: $schema, uuid: $uuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: content could not be given its portal', ['uuid' => $uuid, 'schema' => $schema, 'reason' => $e->getMessage()]);

			return false;
		}

		return true;
	}//end assign()

	/**
	 * The slugs of the portals that exist.
	 *
	 * @param object $objectService OpenRegister's object service.
	 *
	 * @return list<string>
	 */
	private function portalSlugs(object $objectService): array {
		$slugs = [];
		foreach ($this->rows(objectService: $objectService, schema: 'portal') as $row) {
			$slug = $row['slug'] ?? null;
			if (is_string($slug) === true && $slug !== '') {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}//end portalSlugs()

	/**
	 * Every stored object of a schema, a page at a time.
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
				$this->logger->info('Portaliq: no content to place', ['schema' => $schema, 'reason' => $e->getMessage()]);

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

	/**
	 * OpenRegister's object service, or null when it is not installed.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
