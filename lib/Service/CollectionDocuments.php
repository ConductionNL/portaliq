<?php

/**
 * Portaliq Collection Documents
 *
 * Lists and opens the documents of one object in any contributed collection
 * that declares `documents`, with an optional fail-closed `opened` hook.
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
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\TimelineProviderMethod;
use OCP\AppFramework\Http\StreamResponse;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * The order of an open: the caller has proven the object is the subject's in a
 * collection of the subject's aggregate; here the document is looked up again
 * in the provider's answer, the `opened` hook runs, then the bytes stream.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
 */
class CollectionDocuments {

	/**
	 * What open() answers when the document is not listed (or cannot be read).
	 *
	 * @var string
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * What open() answers when the `opened` hook did not return true.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * Constructor.
	 *
	 * @param PortalCaseDocumentReader $published Asks the app what it lists for an object.
	 * @param PortalFileReader $files Streams a file from OpenRegister.
	 * @param PortalAuditHook $audit Records each download.
	 * @param PortalProviderLocator $locator Finds the app's provider for the hook.
	 * @param LoggerInterface $logger Records a refused hook.
	 */
	public function __construct(
		private readonly PortalCaseDocumentReader $published,
		private readonly PortalFileReader $files,
		private readonly PortalAuditHook $audit,
		private readonly PortalProviderLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The documents an object lists now, without where the files live.
	 *
	 * @param string $app The contributing app.
	 * @param array<string, mixed> $documents The collection's `documents` declaration.
	 * @param string $id The object id, already proven the subject's.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
	 */
	public function listFor(string $app, array $documents, string $id): array {
		$entries = $this->published->entries(appId: $app, method: (string)($documents['provider'] ?? ''), caseId: $id);

		return array_map(
			static function (array $entry): array {
				unset($entry['file']);
				return $entry;
			},
			$entries
		);
	}//end listFor()

	/**
	 * Open one listed document.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $app The contributing app.
	 * @param array<string, mixed> $documents The collection's `documents` declaration.
	 * @param array{register: string, schema: string, id: string} $object The proven object.
	 * @param string $documentId The listed entry's id.
	 *
	 * @return StreamResponse|string The stream, or NOT_FOUND, or REFUSED.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t5
	 */
	public function open(array $subject, string $app, array $documents, array $object, string $documentId): StreamResponse|string {
		$file = null;
		$entries = $this->published->entries(appId: $app, method: (string)($documents['provider'] ?? ''), caseId: $object['id']);
		foreach ($entries as $entry) {
			if ($entry['id'] === $documentId) {
				$file = $entry['file'];
				break;
			}
		}

		if ($file === null || $documentId === '') {
			return self::NOT_FOUND;
		}

		// Fail closed: anything but true, or a throw, streams nothing.
		if (isset($documents['opened']) === true) {
			$allowed = $this->hookAllows(app: $app, method: (string)$documents['opened'], objectId: $object['id'], documentId: $documentId, subject: $subject);
			if ($allowed === false) {
				return self::REFUSED;
			}
		}

		$stream = $this->files->streamFile(register: $file['register'], schema: $file['schema'], id: $file['id'], fileId: $file['fileId']);
		if ($stream === null) {
			return self::NOT_FOUND;
		}

		$this->audit->download(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			register: $object['register'],
			schema: $object['schema'],
			id: $object['id']
		);

		return $stream;
	}//end open()

	/**
	 * Call the app's `opened` hook; true only when it returned exactly true.
	 *
	 * The subject it gets is the session's, never the request's.
	 *
	 * @param string $app The contributing app.
	 * @param string $method The declared hook.
	 * @param string $objectId The object id.
	 * @param string $documentId The document id.
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return bool True when the hook allowed the opening.
	 */
	private function hookAllows(string $app, string $method, string $objectId, string $documentId, array $subject): bool {
		$provider = $this->locator->locate(appId: $app);
		if ($provider === null || (new TimelineProviderMethod())->accepts(name: $method) === false || method_exists($provider, $method) === false) {
			$this->logger->warning('Portaliq: the opened hook cannot be called', ['app' => $app]);
			return false;
		}

		$reflection = new ReflectionMethod($provider, $method);
		if ($reflection->isPublic() === false || $reflection->isStatic() === true) {
			return false;
		}

		try {
			$allowed = $provider->{$method}(
				$objectId,
				$documentId,
				[
					'subjectRef' => (string)($subject['subjectRef'] ?? ''),
					'trust' => (string)($subject['trust'] ?? ''),
					'identityType' => (string)($subject['identityType'] ?? ''),
					'audience' => (string)($subject['audience'] ?? ''),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the opened hook failed', ['app' => $app]);
			return false;
		}

		if ($allowed !== true) {
			$this->logger->warning('Portaliq: the opened hook refused', ['app' => $app]);
		}

		return $allowed === true;
	}//end hookAllows()
}//end class
