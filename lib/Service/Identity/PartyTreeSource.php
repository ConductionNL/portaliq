<?php

/**
 * Portaliq Party Tree Source
 *
 * Reads an organisation's group from OpenRegister's generic tree read
 * (`ObjectTreeService`, openregister change object-tree-read): the party
 * object is found by its identity (a KvK number), and the objects below it
 * come back with their level, name and identity.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one place portaliq calls OpenRegister's tree read.
 *
 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
 */
class PartyTreeSource {
	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * OpenRegister's tree read.
	 */
	private const TREE_SERVICE = 'OCA\\OpenRegister\\Service\\Relation\\ObjectTreeService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's services.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The tree below the party with this identity, or null when there is none to read.
	 *
	 * Null means: no party object carries the identity, or this OpenRegister
	 * has no tree read. Both leave the mandate reaching the party alone.
	 *
	 * RBAC is off, as on every portaliq read: a portal subject is not a
	 * Nextcloud user, and the caller has already established that the subject
	 * holds a mandate for the root.
	 *
	 * @param string $register      The register holding the parties.
	 * @param string $schema        The party schema.
	 * @param string $identityField The property carrying the identity.
	 * @param string $identity      The identity of the root.
	 * @param int    $maxDepth      How deep to read.
	 *
	 * @return array<string, mixed>|null OpenRegister's tree answer.
	 *
	 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	public function below(string $register, string $schema, string $identityField, string $identity, int $maxDepth): ?array {
		$objects = $this->service(id: self::OBJECT_SERVICE);
		$trees = $this->service(id: self::TREE_SERVICE);
		if ($objects === null || $trees === null || $identity === '') {
			return null;
		}

		try {
			$objects->setRegister(register: $register);
			$objects->setSchema(schema: $schema);
			$rows = $objects->findAll(
				config: ['filters' => [$identityField => $identity], 'limit' => 2, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);

			$root = $this->rootOf(objects: $objects, rows: (array)$rows, register: $register, schema: $schema);
			if ($root === null) {
				return null;
			}

			$tree = $trees->tree(
				root: $root,
				direction: 'descendants',
				depth: $maxDepth,
				fields: [$identityField],
				_rbac: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the party tree could not be read', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return ['nodes' => [], 'truncated' => true, 'truncatedBy' => 'unreadable'];
		}

		if (is_array($tree) === false) {
			return null;
		}

		return $tree;
	}//end below()

	/**
	 * The one object a lookup found, as the entity the tree read takes.
	 *
	 * Two objects carrying one identity is a data error, and walking either
	 * would be a guess, so it answers null.
	 *
	 * @param object             $objects  OpenRegister's object service.
	 * @param array<int, mixed>  $rows     The lookup's rows.
	 * @param string             $register The register.
	 * @param string             $schema   The schema.
	 *
	 * @return object|null The entity.
	 */
	private function rootOf(object $objects, array $rows, string $register, string $schema): ?object {
		if (count($rows) !== 1) {
			return null;
		}

		$row = reset($rows);
		if (is_object($row) === true && method_exists($row, 'getUuid') === true) {
			return $row;
		}

		$uuid = '';
		if (is_array($row) === true) {
			$uuid = (string)($row['@self']['id'] ?? ($row['id'] ?? ($row['uuid'] ?? '')));
		}

		if ($uuid === '') {
			return null;
		}

		$entity = $objects->find(id: $uuid, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		if (is_object($entity) === false) {
			return null;
		}

		return $entity;
	}//end rootOf()

	/**
	 * An OpenRegister service, or null when it is not there.
	 *
	 * @param string $id The service class.
	 *
	 * @return object|null The service.
	 */
	private function service(string $id): ?object {
		try {
			$service = $this->container->get($id);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end service()
}//end class
