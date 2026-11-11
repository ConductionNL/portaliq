<?php

/**
 * Portaliq Portal File Writer
 *
 * Attaches an uploaded file to a contribution object through OpenRegister's
 * existing object-file store, on behalf of an authenticated portal subject
 * (the file-upload block, ADR-063). OpenRegister already owns the per-object
 * file endpoints (`POST /api/objects/{register}/{schema}/{id}/files*`) and the
 * underlying `FileService::addFile()`; this writer reuses that service in-process
 * so portal uploads land in the SAME object folder as internal ones — no new
 * storage path, no duplicated file plumbing.
 *
 * SECURITY: like the object reader/writer, this bypasses OpenRegister's NC-user
 * RBAC (portal subjects are not NC users) because Portaliq is the trusted
 * intermediary. The per-subject OWNERSHIP check is the caller's responsibility
 * and MUST have passed (the controller re-reads the object through the scoped
 * reader and only calls this when the subject owns it) — this class never sees a
 * subject it should refuse, it only performs the attach once ownership is proven.
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
 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-file-attachment-on-a-subject-owned-object
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Attaches subject-uploaded files to a contribution object via OpenRegister.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-file-attachment-on-a-subject-owned-object
 */
class PortalFileWriter {
	use ObjectFileLookup;

	/**
	 * OpenRegister's file service.
	 */
	private const FILE_SERVICE = 'OCA\\OpenRegister\\Service\\FileService';

	/**
	 * OpenRegister's object service — used to resolve an object UUID to a full
	 * ObjectEntity before attaching a file (see attachFile()).
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Logged when the owned object cannot be resolved.
	 */
	private const ENTITY_LOOKUP_WARNING = 'Portaliq: object resolve for file attach failed';

	/**
	 * The tag on a file a resident sent through the portal, so the case screen
	 * can list it apart from what staff put in the same folder
	 * (cases-documents-on-the-case, REQ-CDC-004).
	 *
	 * @var string
	 */
	public const TAG_FROM_APPLICANT = 'portal:from-applicant';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister's FileService.
	 * @param LoggerInterface $logger The logger.
	 * @param PortalWriteContext|null $writeContext Marks this writer's attaches as portaliq's own.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ?PortalWriteContext $writeContext = null,
	) {
	}//end __construct()

	/**
	 * Attach an uploaded file to an object the subject already owns.
	 *
	 * The caller MUST have re-verified ownership through the scoped reader before
	 * calling this (a foreign or non-existent id must never reach here). The file
	 * lands in the object's OpenRegister folder via `FileService::addFile()`, RBAC
	 * bypassed (Portaliq is the trusted scoper). Fails closed to null on any OR
	 * error.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug.
	 * @param string $id The owned object's id (ownership already verified).
	 * @param string $fileName The sanitised upload filename.
	 * @param string $content The raw file bytes.
	 * @param array<int, string> $tags Tags OpenRegister puts on the file, such as TAG_FROM_APPLICANT.
	 *
	 * @return array<string, mixed>|null The attached file's metadata, or null on failure.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-file-attachment-on-a-subject-owned-object
	 */
	public function attachFile(
		string $register,
		string $schema,
		string $id,
		string $fileName,
		string $content,
		array $tags = [],
	): ?array {
		$attachment = new FileAttachment(register: $register, schema: $schema, id: $id, fileName: $fileName, content: $content, tags: $tags);
		$fileService = $this->fileService();
		if ($fileService === null) {
			return null;
		}

		return $this->addFile(fileService: $fileService, attachment: $attachment);
	}//end attachFile()

	/**
	 * Add the file to the owned object's folder through OpenRegister's FileService.
	 *
	 * @param object         $fileService OpenRegister's FileService.
	 * @param FileAttachment $attachment  The file and where it goes.
	 *
	 * @return array<string, mixed>|null The attached file's metadata, or null on failure.
	 */
	private function addFile(object $fileService, FileAttachment $attachment): ?array {
		// Resolve the object UUID to a full ObjectEntity FIRST. OpenRegister's
		// addFile() takes `getObjectFolder()`'s entity-input branch (which
		// resolves the register from the entity itself) only when handed an
		// ObjectEntity; handed a bare string id + null registerId it falls to
		// `createObjectFolderById(string, registerId: null)` and throws "Failed
		// to create file because no objectEntity or registerId given" — so every
		// portal upload 502'd. Mirrors OpenRegister's own SharesProvider.
		$entity = $this->resolveObjectEntity(register: $attachment->register, schema: $attachment->schema, id: $attachment->id);
		if ($entity === null) {
			return null;
		}

		try {
			$file = $this->insideWriteContext(write: static fn () => $fileService->addFile(
				objectEntity: $entity,
				fileName: $attachment->fileName,
				content: $attachment->content,
				tags: $attachment->tags,
				_schema: $attachment->schema,
				_register: $attachment->register
			));
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: OR file attach failed', ['schema' => $attachment->schema, 'reason' => $e->getMessage()]);
			return null;
		}

		return $this->normalise(file: $file, fileName: $attachment->fileName);
	}//end addFile()

	/**
	 * Run an OpenRegister write inside the write context, so the change
	 * listener knows portaliq itself made it.
	 *
	 * @param callable $write The write.
	 *
	 * @return mixed What the write returned.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-is-not-told-about-their-own-change-req-nap-003
	 */
	private function insideWriteContext(callable $write): mixed {
		if ($this->writeContext === null) {
			return $write();
		}

		return $this->writeContext->run(write: $write);
	}//end insideWriteContext()
}//end class
