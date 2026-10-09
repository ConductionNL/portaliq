<?php

/**
 * Portaliq CMS Rows (portaliq-cms)
 *
 * Reads CMS rows of one portal or organisation from OpenRegister.
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The scoped read of CMS rows: an unscoped query is refused.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
 */
class CmsRows {
	/**
	 * The OpenRegister service every CMS read goes through.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container For resolving OpenRegister's ObjectService.
	 * @param LoggerInterface       $logger    The logger.
	 * @param PortalRegisterContext $context   Points the shared ObjectService at one schema.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PortalRegisterContext $context,
	) {
	}//end __construct()

	/**
	 * Query one CMS schema with the given property filters.
	 *
	 * @param string $schema  The schema slug.
	 * @param array  $filters The property filters, always including `portal` (or `organisation`, for a shared block).
	 *
	 * @return array The rows, as plain arrays.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function query(string $schema, array $filters): array {
		if (($filters['portal'] ?? '') === '' && ($filters['organisation'] ?? '') === '') {
			// Refusing here rather than returning everything: an unscoped read
			// would silently serve one site's content under another's domain,
			// and the response would look entirely normal.
			$this->logger->error('Portaliq: refusing an unscoped CMS query', ['schema' => $schema]);
			return [];
		}

		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			// Through the context helper, never through two slug setters: the
			// slug form re-resolves whatever schema ref another app left
			// pending on the shared ObjectService, and that took every content
			// read here down with a slug this app does not own. See
			// PortalRegisterContext.
			if ($this->context->apply(objectService: $objectService, schemaSlug: $schema) === false) {
				return [];
			}

			$rows = $objectService->findAll(
				config: ['filters' => $filters, 'limit' => 500, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Portaliq: CMS read failed',
				['schema' => $schema, 'reason' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		return array_map(
			static function ($row) {
				if (is_array($row) === true) {
					return $row;
				}

				return (array)$row->jsonSerialize();
			},
			$rows
		);
	}//end query()

	/**
	 * The identifier of a stored row, flat or inside the `@self` envelope.
	 *
	 * Both shapes are read because both occur: OpenRegister's object API
	 * returns a flat `id` alongside the envelope, and a row that has been
	 * projected or re-serialised elsewhere may carry only one of them.
	 *
	 * @param array $row The stored row.
	 *
	 * @return string|null The identifier, or null when the row carries none.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function rowId(array $row): ?string {
		$self = ($row['@self'] ?? null);
		$candidates = [
			($row['id'] ?? null),
			($row['uuid'] ?? null),
		];
		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? null);
			$candidates[] = ($self['uuid'] ?? null);
		}

		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return null;
	}//end rowId()
}//end class
