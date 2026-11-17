<?php

/**
 * Portaliq Portal Notice Reader
 *
 * The notices a portal shows right now: maintenance or a warning, time-boxed,
 * on the public site, the signed-in portal or both (operate-maintenance-notice).
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use OCA\Portaliq\Kernel\Contracts\PortalNoticeReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides which `portalNotice` records are active for a portal and surface.
 *
 * The server decides, so a notice outside its window is never sent. The read
 * is `_rbac: false` on purpose: an anonymous visitor has no OpenRegister
 * rights, and the filter below (portal, published, surface, window) is the
 * whole of what the public may see. The rows are cached for a minute; the
 * window is checked on every call, so the cache can never extend a notice.
 *
 * @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
 */
class PortalNoticeReader implements PortalNoticeReaderInterface {

	/**
	 * OpenRegister's object service, resolved lazily.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * At most this many notices show at once: more is noise above every page.
	 *
	 * @var int
	 */
	public const MAX = 3;

	/**
	 * Seconds the published rows of one portal stay cached.
	 *
	 * @var int
	 */
	private const TTL = 60;

	/**
	 * The distributed cache.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container    For the lazy OpenRegister lookup.
	 * @param ICacheFactory         $cacheFactory Builds the row cache.
	 * @param LoggerInterface       $logger       Records a failed read.
	 * @param PortalRegisterContext $context      Points the object service at the schema.
	 * @param ITimeFactory          $time         Now.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
		private readonly PortalRegisterContext $context,
		private readonly ITimeFactory $time,
	) {
		$this->cache = $cacheFactory->createDistributed('portaliq_notices');
	}//end __construct()

	/**
	 * The notices active now for one portal on one surface, newest first.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $surface `site` or `portal`.
	 *
	 * @return array<int, array{id: string, message: string, level: string, linkLabel: string, linkUrl: string, endsAt: string}> At most three.
	 *
	 * @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
	 */
	public function active(string $portal, string $surface): array {
		if ($portal === '') {
			return [];
		}

		return self::select(rows: $this->published(portal: $portal), portal: $portal, surface: $surface, now: $this->time->now());
	}//end active()

	/**
	 * Keep the rows that are active now, shaped for the client.
	 *
	 * @param array<int, array>  $rows    The candidate rows.
	 * @param string             $portal  The portal slug.
	 * @param string             $surface `site` or `portal`.
	 * @param DateTimeImmutable  $now     Now.
	 *
	 * @return array<int, array<string, string>> At most three, newest start first: id, message, level, linkLabel, linkUrl, endsAt.
	 *
	 * @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
	 */
	public static function select(array $rows, string $portal, string $surface, DateTimeImmutable $now): array {
		$query  = new NoticeQuery(portal: $portal, surface: $surface, now: $now);
		$active = [];
		foreach ($rows as $row) {
			if (self::isActive(row: $row, query: $query) === true) {
				$active[] = self::shaped(row: $row);
			}
		}

		usort($active, static fn (array $a, array $b): int => $b['startsAt'] <=> $a['startsAt']);

		return array_map(
			static function (array $notice): array {
				unset($notice['startsAt']);
				return $notice;
			},
			array_slice($active, 0, self::MAX)
		);
	}//end select()

	/**
	 * Whether a row has an id, a window that holds now, and may show on
	 * this portal and surface.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param NoticeQuery          $query The portal, surface and moment.
	 *
	 * @return bool
	 */
	private static function isActive(array $row, NoticeQuery $query): bool {
		return self::idOf(row: $row) !== ''
			&& self::isRunning(row: $row, now: $query->now) === true
			&& self::isShown(row: $row, query: $query) === true;
	}//end isActive()

	/**
	 * Whether a row's window holds now: started, and not yet ended. A window
	 * with a missing or unreadable end point holds never.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param DateTimeImmutable    $now Now.
	 *
	 * @return bool
	 */
	private static function isRunning(array $row, DateTimeImmutable $now): bool {
		$starts = self::time(value: ($row['startsAt'] ?? null));
		$ends   = self::time(value: ($row['endsAt'] ?? null));
		if ($starts === null || $ends === null) {
			return false;
		}

		return $starts <= $now && $ends > $now;
	}//end isRunning()

	/**
	 * An active row shaped for the client, with its start kept for sorting.
	 *
	 * @param array<string, mixed> $row An active row.
	 *
	 * @return array<string, mixed>
	 */
	private static function shaped(array $row): array {
		$level = 'info';
		if (($row['level'] ?? '') === 'warning') {
			$level = 'warning';
		}

		return [
			'id'        => self::idOf(row: $row),
			'message'   => (string)($row['message'] ?? ''),
			'level'     => $level,
			'linkLabel' => (string)($row['linkLabel'] ?? ''),
			'linkUrl'   => self::httpsOnly(url: (string)($row['linkUrl'] ?? '')),
			'endsAt'    => self::time(value: $row['endsAt'])->format(DATE_ATOM),
			'startsAt'  => self::time(value: $row['startsAt'])->getTimestamp(),
		];
	}//end shaped()

	/**
	 * A row's id: its `@self` id, else its own.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 */
	private static function idOf(array $row): string {
		return (string)($row['@self']['id'] ?? $row['id'] ?? '');
	}//end idOf()

	/**
	 * Whether a row is a published notice of this portal for this surface.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param NoticeQuery          $query The portal and surface.
	 *
	 * @return bool True when it may show here.
	 */
	private static function isShown(array $row, NoticeQuery $query): bool {
		return (string)($row['portal'] ?? '') === $query->portal
			&& ($row['status'] ?? '') === 'published'
			&& in_array($query->surface, (array)($row['surfaces'] ?? []), true) === true;
	}//end isShown()

	/**
	 * Parse a date-time, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The moment, or null when absent or unreadable.
	 */
	private static function time(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end time()

	/**
	 * A link is only offered when it is https.
	 *
	 * @param string $url The stored link.
	 *
	 * @return string The link, or '' when it is not https.
	 */
	private static function httpsOnly(string $url): string {
		if (preg_match('#^https://[^\s]+$#i', trim($url)) === 1) {
			return trim($url);
		}

		return '';
	}//end httpsOnly()

	/**
	 * The published notices of one portal, cached for a minute.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array> The rows.
	 */
	private function published(string $portal): array {
		$hit = $this->cached(portal: $portal);
		if ($hit !== null) {
			return $hit;
		}

		$rows = $this->readPublished(portal: $portal);
		if ($rows === null) {
			return [];
		}

		$this->cache->set($portal, json_encode($rows), self::TTL);

		return $rows;
	}//end published()

	/**
	 * The cached published notices of one portal, or null on a miss.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array>|null
	 */
	private function cached(string $portal): ?array {
		$hit = $this->cache->get($portal);
		if (is_string($hit) === false) {
			return null;
		}

		$decoded = json_decode($hit, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end cached()

	/**
	 * Read the published notices of one portal from OpenRegister.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array>|null The rows, or null when they could not be read.
	 */
	private function readPublished(string $portal): ?array {
		try {
			$objects = $this->container->get(self::OBJECT_SERVICE);
			if ($this->context->apply(objectService: $objects, schemaSlug: self::SCHEMA) === false) {
				return null;
			}

			$found = $objects->findAll(
				config: ['filters' => ['portal' => $portal, 'status' => 'published'], 'limit' => 100, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);

			return self::asRows(found: $found);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: notice read failed', ['portal' => $portal, 'reason' => $e->getMessage()]);
			return null;
		}//end try
	}//end readPublished()

	/**
	 * The found objects as plain rows.
	 *
	 * @param mixed $found What the object service found.
	 *
	 * @return array<int, array>
	 */
	private static function asRows(mixed $found): array {
		$rows = [];
		foreach ((array)$found as $row) {
			if (is_array($row) === false) {
				$row = (array)$row->jsonSerialize();
			}

			$rows[] = $row;
		}

		return $rows;
	}//end asRows()
}//end class
