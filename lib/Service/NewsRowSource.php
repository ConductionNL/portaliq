<?php

/**
 * News Row Source
 *
 * The OpenRegister read behind NewsFeedReader: every row of a news schema in
 * this app's register, normalised to arrays, and a row's id. Taken out of
 * NewsFeedReader so the audience, translation and archive logic stays under
 * phpmd's class complexity threshold (news-title-and-newsletter-translation).
 * An unavailable ObjectService or a failed read yields no rows, never an error.
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
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#architecture-overview
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads news rows from OpenRegister.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#architecture-overview
 */
class NewsRowSource {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container, for OpenRegister's ObjectService.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fetch every row of a schema in this app's register, unfiltered — the
	 * matching happens in PHP against the already-fetched rows since the
	 * OR-of-three-dimensions target shape is not a filterable OR query
	 * (design.md "Architecture Overview").
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 * @spec openspec/changes/news-and-newsletter-authoring/design.md#architecture-overview
	 */
	public function findAll(string $schema): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: $schema);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => 500, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: news feed read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$normalised = [];
		foreach ($rows as $row) {
			$row = $this->normalise(row: $row);
			if ($row !== null) {
				$normalised[] = $row;
			}
		}

		return $normalised;
	}//end findAll()

	/**
	 * The row's id/uuid, from a flat property or its `@self` envelope.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 * @spec openspec/changes/news-and-newsletter-authoring/design.md#architecture-overview
	 */
	public function rowId(array $row): string {
		if (isset($row['id']) === true) {
			return (string)$row['id'];
		}

		if (isset($row['uuid']) === true) {
			return (string)$row['uuid'];
		}

		$self = $row['@self'] ?? [];
		if (is_array($self) === true) {
			return (string)($self['id'] ?? $self['uuid'] ?? '');
		}

		return '';
	}//end rowId()

	/**
	 * Normalise an OpenRegister row (array or object) to an associative array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalise(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end normalise()

	/**
	 * Resolve OpenRegister's ObjectService, or null when unavailable.
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
