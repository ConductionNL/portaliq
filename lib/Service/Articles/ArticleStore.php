<?php

/**
 * Portaliq ArticleStore
 *
 * Reads and writes `article` objects in the `portaliq` register through
 * OpenRegister's ObjectService, unscoped: the controller checks the staff
 * action before anything reaches here (marketing-articles-from-pipelinq).
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Articles
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
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Articles;

use OCA\Portaliq\Service\ObjectServiceLookup;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The article rows, as plain arrays.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */
class ArticleStore {
	use ObjectServiceLookup;

	/**
	 * The register articles live in.
	 */
	public const REGISTER = 'portaliq';

	/**
	 * The schema of an article.
	 */
	public const SCHEMA = 'article';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For OpenRegister's ObjectService, resolved on use.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One article by id or slug, or null.
	 *
	 * @param string $id The article's id or slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function find(string $id): ?array {
		$service = $this->objectService();
		if ($service === null || $id === '') {
			return null;
		}

		try {
			$entity = $service->find(id: $id, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$row = $this->toArray(value: $entity);
		if ($row === []) {
			return null;
		}

		return $row;
	}//end find()

	/**
	 * Every article.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function findAll(): array {
		$service = $this->objectService();
		if ($service === null) {
			return [];
		}

		try {
			$rows = $service->findAll(
				config: ['filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: articles could not be read', ['reason' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ((array)($rows ?? []) as $row) {
			$payload = $this->toArray(value: $row);
			if ($payload !== []) {
				$out[] = $payload;
			}
		}

		return $out;
	}//end findAll()

	/**
	 * Save an article, creating it when no id is given.
	 *
	 * @param array<string, mixed> $payload What to store.
	 * @param string               $id      The article's id when it exists, else ''.
	 *
	 * @return array<string, mixed>|null The saved row, or null on failure.
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function save(array $payload, string $id = ''): ?array {
		$service = $this->objectService();
		if ($service === null) {
			return null;
		}

		$uuid = null;
		if ($id !== '') {
			$uuid = $id;
		}

		try {
			$saved = $service->saveObject(
				object: $payload,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: an article could not be saved', ['reason' => $e->getMessage()]);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end save()

	/**
	 * An article's id: its uuid or id, from the row or its `@self`.
	 *
	 * @param array<string, mixed> $row The article.
	 *
	 * @return string The id, or ''.
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public static function idOf(array $row): string {
		foreach ([$row, (array)($row['@self'] ?? [])] as $source) {
			foreach (['uuid', 'id'] as $key) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end idOf()

	/**
	 * An entity or array as a plain array.
	 *
	 * @param mixed $value What the ObjectService answered.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end toArray()
}//end class
