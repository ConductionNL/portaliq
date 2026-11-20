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
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use DateTimeInterface;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PageHistoryInterface;
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
class PageHistory implements PageHistoryInterface {

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
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	public function versions(string $pageId): array {
		$trail = $this->pageTrail(pageId: $pageId);
		if ($trail === null) {
			return [];
		}

		return $this->versionsIn(rows: $trail[1], schema: $trail[0]);
	}//end versions()

	/**
	 * The page schema and the create and update audit rows of a page, or null
	 * when there is no page schema or the trail cannot be read.
	 *
	 * @param string $pageId The page object's uuid.
	 *
	 * @return array{0: object, 1: iterable<object>}|null
	 */
	private function pageTrail(string $pageId): array|null {
		try {
			$trail   = $this->container->get(self::TRAIL_MAPPER);
			$schemas = $this->container->get(self::SCHEMA_MAPPER);
			$schema  = $schemas->findByApplicationAndSlug(slug: 'page', application: AppIdentity::APP_ID);
			if ($schema === null) {
				return null;
			}

			$rows = $trail->findForObjectByAction($pageId, ['create', 'update'], self::LIMIT);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: page history unavailable', ['reason' => $e->getMessage()]);
			return null;
		}

		return [$schema, $rows];
	}//end pageTrail()

	/**
	 * The versions among the audit rows of the page schema.
	 *
	 * @param iterable<object> $rows   The audit rows.
	 * @param object           $schema The page schema.
	 *
	 * @return list<array{id: int|null, publishedAt: string, by: string, restorable: bool, body: array<mixed>|null}>
	 */
	private function versionsIn(iterable $rows, object $schema): array {
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
	}//end versionsIn()

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

		$body = $this->bodyOf(changed: $changed);
		if ($this->publishesNothing(changed: $changed, body: $body) === true) {
			return null;
		}

		return $this->versionRow(row: $row, body: $body);
	}//end versionOf()

	/**
	 * The page body an audit row wrote, or null.
	 *
	 * @param array<string, mixed> $changed The row's changes.
	 *
	 * @return array<mixed>|null
	 */
	private function bodyOf(array $changed): array|null {
		$body = null;
		if (is_array($changed['body']['new'] ?? null) === true) {
			$body = $changed['body']['new'];
		}

		return $body;
	}//end bodyOf()

	/**
	 * Whether an audit row neither wrote a body nor published the page.
	 *
	 * @param array<string, mixed> $changed The row's changes.
	 * @param array<mixed>|null    $body    The body it wrote, or null.
	 *
	 * @return bool
	 */
	private function publishesNothing(array $changed, array|null $body): bool {
		$published = (($changed['status']['new'] ?? null) === 'published');
		return $body === null && $published === false;
	}//end publishesNothing()

	/**
	 * One version, from its audit row and the body it wrote.
	 *
	 * @param object            $row  An OpenRegister AuditTrail.
	 * @param array<mixed>|null $body The body it wrote, or null.
	 *
	 * @return array{id: int|null, publishedAt: string, by: string, restorable: bool, body: array<mixed>|null}
	 */
	private function versionRow(object $row, array|null $body): array {
		$when   = $this->publishedAtOf(row: $row);
		$author = $this->authorOf(row: $row);

		return [
			'id'          => $row->getId(),
			'publishedAt' => $when,
			'by'          => $author,
			'restorable'  => ($body !== null),
			'body'        => $body,
		];
	}//end versionRow()

	/**
	 * When an audit row was written, as an ATOM date, or ''.
	 *
	 * @param object $row An OpenRegister AuditTrail.
	 *
	 * @return string
	 */
	private function publishedAtOf(object $row): string {
		$created = $row->getCreated();
		$when    = '';
		if ($created instanceof DateTimeInterface) {
			$when = $created->format(DATE_ATOM);
		}

		return $when;
	}//end publishedAtOf()

	/**
	 * Who wrote an audit row: the user's name, else the user id.
	 *
	 * @param object $row An OpenRegister AuditTrail.
	 *
	 * @return string
	 */
	private function authorOf(object $row): string {
		$author = (string) ($row->getUserName() ?? '');
		if ($author === '') {
			$author = (string) ($row->getUser() ?? '');
		}

		return $author;
	}//end authorOf()
}//end class
