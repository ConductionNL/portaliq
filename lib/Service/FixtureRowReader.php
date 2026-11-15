<?php

/**
 * Fixture Row Reader
 *
 * Reads every row of one portaliq fixture schema, page by page, failing
 * closed to null: OpenRegister unavailable, a read that throws (logged) or a
 * page that is not a list all answer null, so the caller keeps its own
 * empty answer.
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
 * @link https://conduction.nl
 *
 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every row of a fixture schema, or null.
 *
 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
 */
final class FixtureRowReader {
	use PagedObjectReads;

	/**
	 * Constructor.
	 *
	 * @param object|null     $objectService OpenRegister's ObjectService, or null when it is unavailable.
	 * @param LoggerInterface $logger        The logger.
	 */
	public function __construct(
		private readonly ?object $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every row of the schema, or null when OpenRegister is unavailable, the
	 * read fails (logged under the caller's message) or a page is not a list.
	 *
	 * @param array{register: string, schema: string} $source  The register and schema.
	 * @param string                                  $failure The warning a failed read is logged under.
	 *
	 * @return array<int, mixed>|null
	 *
	 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
	 */
	public function everyRow(array $source, string $failure): ?array {
		if ($this->objectService === null) {
			return null;
		}

		try {
			return $this->readEveryPage(objectService: $this->objectService, register: $source['register'], schema: $source['schema']);
		} catch (Throwable $e) {
			$this->logger->warning($failure, ['reason' => $e->getMessage()]);
			return null;
		}
	}//end everyRow()
}//end class
