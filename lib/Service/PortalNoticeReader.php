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
class PortalNoticeReader {

	/**
	 * OpenRegister's object service, resolved lazily.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'portalNotice';

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
		$active = [];
		foreach ($rows as $row) {
			$starts = self::time(value: ($row['startsAt'] ?? null));
			$ends   = self::time(value: ($row['endsAt'] ?? null));
			$id     = (string)($row['@self']['id'] ?? $row['id'] ?? '');
			if ($id === '' || $starts === null || $ends === null || $starts > $now || $ends <= $now
				|| self::isShown(row: $row, portal: $portal, surface: $surface) === false
			) {
				continue;
			}

			$level = 'info';
			if (($row['level'] ?? '') === 'warning') {
				$level = 'warning';
			}

			$active[] = [
				'id'        => $id,
				'message'   => (string)($row['message'] ?? ''),
				'level'     => $level,
				'linkLabel' => (string)($row['linkLabel'] ?? ''),
				'linkUrl'   => self::httpsOnly(url: (string)($row['linkUrl'] ?? '')),
				'endsAt'    => $ends->format(DATE_ATOM),
				'startsAt'  => $starts->getTimestamp(),
			];
		}//end foreach

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
	 * Whether a row is a published notice of this portal for this surface.
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param string               $portal  The portal slug.
	 * @param string               $surface `site` or `portal`.
	 *
	 * @return bool True when it may show here.
	 */
	private static function isShown(array $row, string $portal, string $surface): bool {
		return (string)($row['portal'] ?? '') === $portal
			&& ($row['status'] ?? '') === 'published'
			&& in_array($surface, (array)($row['surfaces'] ?? []), true) === true;
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
	 * Drop one portal's cached rows, so a notice written now shows at once.
	 *
	 * The minute of caching keeps the site read cheap; it was never meant to
	 * hold back an editor's notice, and without this a notice published for
	 * an outage that starts now stayed off the site for up to that minute.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
	 */
	public function invalidate(string $portal): void {
		if ($portal === '') {
			return;
		}

		$this->cache->remove($portal);
	}//end invalidate()


	/**
	 * The published notices of one portal, cached for a minute.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array> The rows.
	 */
	private function published(string $portal): array {
		$hit = $this->cache->get($portal);
		if (is_string($hit) === true) {
			$decoded = json_decode($hit, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		$rows = [];
		try {
			$objects = $this->container->get(self::OBJECT_SERVICE);
			if ($this->context->apply(objectService: $objects, schemaSlug: self::SCHEMA) === false) {
				return [];
			}

			$found = $objects->findAll(
				config: ['filters' => ['portal' => $portal, 'status' => 'published'], 'limit' => 100, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
			foreach ((array)$found as $row) {
				if (is_array($row) === false) {
					$row = (array)$row->jsonSerialize();
				}

				$rows[] = $row;
			}
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: notice read failed', ['portal' => $portal, 'reason' => $e->getMessage()]);
			return [];
		}//end try

		$this->cache->set($portal, json_encode($rows), self::TTL);

		return $rows;
	}//end published()
}//end class
