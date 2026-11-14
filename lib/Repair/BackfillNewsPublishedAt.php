<?php

/**
 * Portaliq repair step: give published news items their publish moment
 *
 * Until change news-publish-date, publishing a news item only changed its
 * status, so a published item carries no `publishedAt`. This step gives each
 * such item its creation moment, the best date there is for when it went out,
 * so the feed keeps sorting it among the items published since. A draft, an
 * item that already carries the moment and an item without a creation moment
 * stay as they are, so the step is safe to run on every upgrade.
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
 * @spec openspec/changes/archive/2026-10-07-news-publish-date/tasks.md#T3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Kernel\Contracts\PortalRegisterContextInterface;
use OCA\Portaliq\Service\ObjectServiceLookup;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps `publishedAt` on published news items that have none.
 *
 * @spec openspec/changes/archive/2026-10-07-news-publish-date/tasks.md#T3
 */
class BackfillNewsPublishedAt implements IRepairStep {
	use PagedSchemaRead;
	use ObjectServiceLookup;

	/**
	 * The news item schema.
	 */
	private const SCHEMA = 'newsItem';

	/**
	 * The register it lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Items read per page.
	 */
	private const PAGE = 100;

	/**
	 * A bound on the pages read.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * Logged when the schema cannot be read.
	 */
	private const EMPTY_PAGE_LOG = 'Portaliq: no news items to stamp';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service, which may be absent.
	 * @param PortalRegisterContextInterface $context Points the object service at the news item schema.
	 * @param LoggerInterface $logger Names an item that could not be stamped.
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
	 * @spec openspec/changes/archive/2026-10-07-news-publish-date/tasks.md#T3
	 */
	public function getName(): string {
		return 'Give published news items their publish moment';
	}//end getName()

	/**
	 * Stamp every published item without a moment, a page at a time.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-07-news-publish-date/tasks.md#T3
	 */
	public function run(IOutput $output): void {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return;
		}

		$stamped = 0;
		$offset  = 0;
		$pages   = 0;
		do {
			$pages++;
			$page    = $this->page(objectService: $objectService, offset: $offset);
			$read    = $page['read'];
			$offset += $read;
			foreach ($page['rows'] as $row) {
				if ($this->stamp(objectService: $objectService, row: $row) === true) {
					$stamped++;
				}
			}
		} while ($read === self::PAGE && $pages < self::MAX_PAGES);

		$output->info('BackfillNewsPublishedAt: stamped ' . $stamped . ' news items.');
	}//end run()

	/**
	 * Stamp one item when it is published, has no moment and has a creation moment.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param array<string, mixed> $row The stored item.
	 *
	 * @return bool True when the item was written.
	 */
	private function stamp(object $objectService, array $row): bool {
		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		$uuid    = (string)($self['uuid'] ?? $self['id'] ?? $row['id'] ?? '');
		$created = $self['created'] ?? null;
		if (($row['status'] ?? '') !== 'published' || empty($row['publishedAt']) === false
			|| $uuid === '' || is_string($created) === false || $created === ''
		) {
			return false;
		}

		try {
			$moment = (new DateTimeImmutable($created))->setTimezone(new DateTimeZone('UTC'));
			unset($row['@self']);
			$row['publishedAt'] = $moment->format(DATE_ATOM);
			$objectService->saveObject(object: $row, register: self::REGISTER, schema: self::SCHEMA, uuid: $uuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: a news item could not be given its publish moment', ['uuid' => $uuid, 'reason' => $e->getMessage()]);

			return false;
		}

		return true;
	}//end stamp()
}//end class
