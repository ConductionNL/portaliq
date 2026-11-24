<?php

/**
 * Portaliq Audience Media Reader
 *
 * The media of an `extraGroups` entry on a documents block ("Van school"):
 * the objects of a schema whose OpenRegister object permissions are set per
 * group, each shown only when OpenRegister's own permission check lets the
 * signed-in person read it (FINISH-ORDER decision of 24 Nov 2026 on
 * documents-grouped-per-record task 3). Portaliq keeps no audience rule of
 * its own: it asks OpenRegister, as the person's Nextcloud account, per
 * object.
 *
 * A session that is not a Nextcloud account (DigiD, eHerkenning, an e-mail
 * link) has no OpenRegister principal, so it is shown no restricted media at
 * all: fail closed.
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
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\AppFramework\Http\StreamResponse;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists and opens the media a signed-in Nextcloud account may read.
 *
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
 */
class AudienceMediaReader {

	/**
	 * The way in a Nextcloud account's portal session is minted with.
	 */
	public const NEXTCLOUD_PROVIDER = 'nextcloud';

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * OpenRegister's permission check.
	 */
	private const PERMISSIONS = 'OCA\\OpenRegister\\Service\\Object\\PermissionHandler';

	/**
	 * OpenRegister's schema mapper.
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * How many media one group reads at most.
	 */
	private const LIMIT = 100;


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The server container (OpenRegister's services).
	 * @param IUserManager       $users     Whether the session's subject is a Nextcloud account.
	 * @param PortalFileReader   $files     Lists and streams an object's files.
	 * @param LoggerInterface    $logger    Records a read that failed.
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IUserManager $users,
		private readonly PortalFileReader $files,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * The media of the schema the person may read, each with its first file's
	 * name and size, never where the file lives.
	 *
	 * @param array<string, mixed> $subject  The resolved session subject.
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 *
	 * @return array<int, array<string, mixed>> `{id, title, kind, date, size?}` per medium.
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
	 */
	public function list(array $subject, string $register, string $schema): array {
		$uid = $this->principal(subject: $subject);
		$schemaEntity = $this->schemaEntity(schema: $schema);
		if ($uid === null || $schemaEntity === null) {
			return [];
		}

		$entries = [];
		foreach ($this->objects(register: $register, schema: $schema) as $object) {
			$entry = $this->entryOf(object: $object, schemaEntity: $schemaEntity, uid: $uid, register: $register, schema: $schema);
			if ($entry !== null) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end list()


	/**
	 * Open the first file of one medium the person may read, or null.
	 *
	 * @param array<string, mixed> $subject  The resolved session subject.
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param string               $id       The medium's id.
	 *
	 * @return StreamResponse|null
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
	 */
	public function open(array $subject, string $register, string $schema, string $id): ?StreamResponse {
		$uid = $this->principal(subject: $subject);
		$schemaEntity = $this->schemaEntity(schema: $schema);
		if ($uid === null || $schemaEntity === null || $id === '') {
			return null;
		}

		$object = $this->object(register: $register, schema: $schema, id: $id);
		if ($object === null || $this->mayRead(object: $object, schemaEntity: $schemaEntity, uid: $uid) === false) {
			return null;
		}

		$file = ($this->files->listFiles(register: $register, schema: $schema, id: $id)[0] ?? null);
		if (is_array($file) === false || $file['id'] === null) {
			return null;
		}

		return $this->files->streamFile(register: $register, schema: $schema, id: $id, fileId: (string)$file['id']);
	}//end open()


	/**
	 * The Nextcloud account the session is, or null: only a session minted
	 * by the Nextcloud sign-in, for an account that exists.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
	 */
	public function principal(array $subject): ?string {
		$uid = (string)($subject['subjectRef'] ?? '');
		if (($subject['provider'] ?? '') !== self::NEXTCLOUD_PROVIDER || $uid === '' || $this->users->userExists($uid) === false) {
			return null;
		}

		return $uid;
	}//end principal()


	/**
	 * One medium as a listed entry when the person may read it and it holds a file.
	 *
	 * @param object $object       The OpenRegister object.
	 * @param object $schemaEntity The schema.
	 * @param string $uid          The person's Nextcloud account.
	 * @param string $register     The register slug.
	 * @param string $schema       The schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function entryOf(object $object, object $schemaEntity, string $uid, string $register, string $schema): ?array {
		$id = $this->idOf(object: $object);
		if ($id === '' || $this->mayRead(object: $object, schemaEntity: $schemaEntity, uid: $uid) === false) {
			return null;
		}

		$file = ($this->files->listFiles(register: $register, schema: $schema, id: $id)[0] ?? null);
		if (is_array($file) === false) {
			return null;
		}

		$data  = $this->dataOf(object: $object);
		$title = $this->text(value: ($data['title'] ?? $data['name'] ?? $file['name'] ?? ''));
		if ($title === '') {
			return null;
		}

		$entry = ['id' => $id, 'title' => $title, 'kind' => 'document', 'date' => $this->text(value: ($data['date'] ?? ''))];
		if (is_int($file['size'] ?? null) === true) {
			$entry['size'] = $file['size'];
		}

		return $entry;
	}//end entryOf()


	/**
	 * OpenRegister's own answer to "may this account read this object";
	 * anything but true, or a throw, is no.
	 *
	 * @param object $object       The OpenRegister object.
	 * @param object $schemaEntity The schema.
	 * @param string $uid          The person's Nextcloud account.
	 *
	 * @return bool
	 */
	private function mayRead(object $object, object $schemaEntity, string $uid): bool {
		try {
			$permissions = $this->container->get(self::PERMISSIONS);
			$owner       = null;
			if (method_exists($object, 'getOwner') === true) {
				$owner = $object->getOwner();
			}

			return $permissions->hasPermission(
				schema: $schemaEntity,
				action: 'read',
				userId: $uid,
				objectOwner: (is_string($owner) === true ? $owner : null),
				_rbac: true,
				object: $object
			) === true;
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: media permission check failed', ['reason' => $e->getMessage()]);
			return false;
		}
	}//end mayRead()


	/**
	 * The schema entity for the slug, or null.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return object|null
	 */
	private function schemaEntity(string $schema): ?object {
		try {
			$found = $this->container->get(self::SCHEMA_MAPPER)->findBySlug(slug: $schema, limit: 1, offset: 0, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: media schema lookup failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		$entity = (is_array($found) === true ? ($found[0] ?? null) : null);
		if (is_object($entity) === true) {
			return $entity;
		}

		return null;
	}//end schemaEntity()


	/**
	 * The schema's objects, unfiltered: each is checked by mayRead().
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return array<int, object>
	 */
	private function objects(string $register, string $schema): array {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
			$service->setRegister(register: $register);
			$service->setSchema(schema: $schema);
			$rows = $service->findAll(config: ['filters' => [], 'limit' => self::LIMIT, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: media read failed', ['register' => $register, 'schema' => $schema, 'reason' => $e->getMessage()]);
			return [];
		}

		return array_values(array_filter((is_array($rows) === true ? $rows : []), 'is_object'));
	}//end objects()


	/**
	 * One object of the schema, or null.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param string $id       The object id.
	 *
	 * @return object|null
	 */
	private function object(string $register, string $schema, string $id): ?object {
		foreach ($this->objects(register: $register, schema: $schema) as $object) {
			if ($this->idOf(object: $object) === $id) {
				return $object;
			}
		}

		return null;
	}//end object()


	/**
	 * The object's id.
	 *
	 * @param object $object The OpenRegister object.
	 *
	 * @return string
	 */
	private function idOf(object $object): string {
		if (method_exists($object, 'getUuid') === true) {
			return $this->text(value: $object->getUuid());
		}

		return '';
	}//end idOf()


	/**
	 * The object's own data.
	 *
	 * @param object $object The OpenRegister object.
	 *
	 * @return array<string, mixed>
	 */
	private function dataOf(object $object): array {
		if (method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end dataOf()


	/**
	 * A string or int as text, else ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === true || is_int($value) === true) {
			return trim((string)$value);
		}

		return '';
	}//end text()
}//end class
