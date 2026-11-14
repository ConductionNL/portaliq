<?php

/**
 * Portaliq Public Publication Read
 *
 * One publication as an anonymous visitor reads it: through opencatalogi's
 * public by-id route on this instance, with no credentials, so the answer is
 * exactly what the publication page would get. A draft, a withdrawn or an
 * unknown publication answer as they do for that page.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Publication
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
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Publication;

use OCA\Portaliq\Service\InstanceLoopback;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads one publication the way an anonymous visitor does.
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */
class PublicPublicationRead {
	/**
	 * Opencatalogi's public by-id route, the one the publication page reads.
	 */
	public const PATH = '/index.php/apps/opencatalogi/api/federation/publications/';

	/**
	 * The answer for a publication the visitor may read.
	 */
	public const FOUND = 200;

	/**
	 * The answer for a withdrawn publication (opencatalogi REQ-PWA-002).
	 */
	public const GONE = 410;

	/**
	 * The answer for everything else: a draft, an unknown id, a failed read.
	 */
	public const NOT_FOUND = 404;

	/**
	 * Constructor.
	 *
	 * @param InstanceLoopback $loopback Calls this instance.
	 * @param LoggerInterface  $logger   Notes a failed read.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly InstanceLoopback $loopback,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What the public read answers for one publication id.
	 *
	 * `status` is FOUND with the publication as `body` when the read returns
	 * the object with that id; GONE with the withdrawal as `body` (only
	 * `withdrawnAt`, `publicReason` and, when present, `title`) on a 410;
	 * NOT_FOUND otherwise, a failed read included, with a null `body`.
	 *
	 * @param string $id The publication id.
	 *
	 * @return array{status: int, body: array<string, mixed>|null}
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	public function read(string $id): array {
		$id = trim($id);
		if ($id === '' || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $id) !== 1) {
			return ['status' => self::NOT_FOUND, 'body' => null];
		}

		try {
			$response = $this->loopback->request(
				method: 'GET',
				path: self::PATH . rawurlencode($id),
				options: ['headers' => ['Accept' => 'application/json'], 'http_errors' => false, 'timeout' => 10]
			);
			$status = (int)$response->getStatusCode();
			$body   = json_decode((string)$response->getBody(), true);
		} catch (Throwable $failure) {
			$this->logger->warning('Portaliq: the public publication read failed', ['exception' => $failure->getMessage()]);
			return ['status' => self::NOT_FOUND, 'body' => null];
		}

		if (is_array($body) === false) {
			return ['status' => self::NOT_FOUND, 'body' => null];
		}

		if ($status === self::GONE) {
			return ['status' => self::GONE, 'body' => self::tombstone(body: $body)];
		}

		if ($status === self::FOUND && self::idOf(row: $body) === $id) {
			return ['status' => self::FOUND, 'body' => $body];
		}

		return ['status' => self::NOT_FOUND, 'body' => null];
	}//end read()

	/**
	 * The withdrawal as the site may show it: the date, the public reason and
	 * the title only when the answer carries one. Nothing else of the record.
	 *
	 * @param array<mixed> $body The 410 answer.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-withdrawn-link-says-it-was-withdrawn-and-why-req-per-004
	 */
	public static function tombstone(array $body): array {
		$tombstone = [];
		foreach (['withdrawnAt', 'publicReason', 'title'] as $key) {
			if (is_string($body[$key] ?? null) === true && trim($body[$key]) !== '') {
				$tombstone[$key] = trim($body[$key]);
			}
		}

		return $tombstone;
	}//end tombstone()

	/**
	 * A row's id, wherever the register put it.
	 *
	 * @param array<mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		$self = $row['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		return (string)($row['id'] ?? $row['uuid'] ?? $self['id'] ?? '');
	}//end idOf()
}//end class
