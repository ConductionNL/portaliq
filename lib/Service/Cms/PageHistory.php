<?php

/**
 * Portaliq page history
 *
 * The published versions of one portal page, read from OpenRegister's audit
 * trail of the page object.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use DateTimeInterface;
use OCA\Portaliq\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists a page's published versions, newest first.
 *
 * A version is an audit row in which the page's `body` changed: only a
 * publication writes `body` (a draft save writes `draftBody`), so those rows
 * are exactly the published states. OpenRegister records the full new value
 * of a changed field, so the row carries the whole body to restore. A row that
 * only moved the status to published carries no body and is listed without a
 * restore.
 *
 * It reads in process because OpenRegister's per-object audit-trail endpoint
 * is admin-only, while a page editor may be any member of the configured
 * editor groups. The caller decides who may read ({@see PageEditorService}).
 * The rows are checked against portaliq's own page schema, so a uuid of some
 * other object never lends its trail to a page editor.
 */
class PageHistory {

	private const TRAIL_MAPPER = 'OCA\\OpenRegister\\Db\\AuditTrailMapper';

	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * The most versions listed.
	 */
	private const LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's mappers, which may be absent.
	 * @param LoggerInterface    $logger    Logs a failed read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The published versions of a page, newest first.
	 *
	 * @param string $pageId The page object's uuid.
	 *
	 * @return list<array{id: int|null, publishedAt: string, by: string, restorable: bool, body: array<mixed>|null}>
	 *
	 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
	 */
	public function versions(string $pageId): array {
		try {
			$trail   = $this->container->get(self::TRAIL_MAPPER);
			$schemas = $this->container->get(self::SCHEMA_MAPPER);
			$schema  = $schemas->findByApplicationAndSlug(slug: 'page', application: Application::APP_ID);
			if ($schema === null) {
				return [];
			}

			$rows = $trail->findForObjectByAction($pageId, ['create', 'update'], self::LIMIT);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: page history unavailable', ['reason' => $e->getMessage()]);
			return [];
		}

		$versions = [];
		foreach ($rows as $row) {
			if ((int) $row->getSchema() !== (int) $schema->getId()) {
				continue;
			}

			$version = $this->versionOf(row: $row);
			if ($version !== null) {
				$versions[] = $version;
			}
		}

		return $versions;
	}//end versions()

	/**
	 * One audit row as a version, or null when it published nothing.
	 *
	 * @param object $row An OpenRegister AuditTrail.
	 *
	 * @return array{id: int|null, publishedAt: string, by: string, restorable: bool, body: array<mixed>|null}|null
	 */
	private function versionOf(object $row): ?array {
		$changed = $row->getChanged();
		if (is_array($changed) === false) {
			return null;
		}

		$body = null;
		if (is_array($changed['body']['new'] ?? null) === true) {
			$body = $changed['body']['new'];
		}

		$published = (($changed['status']['new'] ?? null) === 'published');
		if ($body === null && $published === false) {
			return null;
		}

		$created = $row->getCreated();
		$when    = '';
		if ($created instanceof DateTimeInterface) {
			$when = $created->format(DATE_ATOM);
		}

		$by = (string) ($row->getUserName() ?? '');
		if ($by === '') {
			$by = (string) ($row->getUser() ?? '');
		}

		return [
			'id'          => $row->getId(),
			'publishedAt' => $when,
			'by'          => $by,
			'restorable'  => ($body !== null),
			'body'        => $body,
		];
	}//end versionOf()
}//end class
