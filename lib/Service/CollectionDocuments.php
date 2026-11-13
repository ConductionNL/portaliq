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

use OCA\Portaliq\Kernel\Contracts\CollectionDocumentsInterface;
use OCA\Portaliq\Kernel\Contracts\PortalProviderLocatorInterface;
use OCP\AppFramework\Http\StreamResponse;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;
use OCA\Portaliq\Kernel\Contracts\TimelineProviderMethodInterface;

/**
 * The order of an open: the caller has proven the object is the subject's in a
 * collection of the subject's aggregate; here the document is looked up again
 * in the provider's answer, the `opened` hook runs, then the bytes stream.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
 */
class CollectionDocuments implements CollectionDocumentsInterface {

	/**
	 * What open() answers when the document is not listed (or cannot be read).
	 *
	 * @var string
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * Constructor.
	 *
	 * @param PortalCaseDocumentReader $published Asks the app what it lists for an object.
	 * @param PortalFileReader $files Streams a file from OpenRegister.
	 * @param PortalAuditHook $audit Records each download.
	 * @param PortalProviderLocatorInterface $locator Finds the app's provider for the hook.
	 * @param LoggerInterface $logger Records a refused hook.
	 * @param TimelineProviderMethodInterface $timelineProviderMethod The TimelineProviderMethod helper.
	 */
	public function __construct(
		private readonly PortalCaseDocumentReader $published,
		private readonly PortalFileReader $files,
		private readonly PortalAuditHook $audit,
		private readonly PortalProviderLocatorInterface $locator,
		private readonly LoggerInterface $logger,
		private readonly TimelineProviderMethodInterface $timelineProviderMethod,
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
		return $this->openListed(
			run: new DocumentOpening(subject: $subject, app: $app, documents: $documents, object: $object, documentId: $documentId)
		);
	}//end open()

	/**
	 * Open the listed document when the app's hook, if it declares one,
	 * allows it, and record the download.
	 *
	 * @param DocumentOpening $run The opening.
	 *
	 * @return StreamResponse|string The stream, or NOT_FOUND, or REFUSED.
	 */
	private function openListed(DocumentOpening $run): StreamResponse|string {
		$file = $this->listedFile(run: $run);
		if ($file === null || $run->documentId === '') {
			return self::NOT_FOUND;
		}

		if ($this->refusedByHook(run: $run) === true) {
			return self::REFUSED;
		}

		$stream = $this->files->streamFile(register: $file['register'], schema: $file['schema'], id: $file['id'], fileId: $file['fileId']);
		if ($stream === null) {
			return self::NOT_FOUND;
		}

		$this->recordDownload(run: $run);

		return $stream;
	}//end openListed()

	/**
	 * Where the listed entry's file lives, or null when the object lists no
	 * such entry.
	 *
	 * @param DocumentOpening $run The opening.
	 *
	 * @return array<string, mixed>|null
	 */
	private function listedFile(DocumentOpening $run): ?array {
		$entries = $this->published->entries(appId: $run->app, method: (string)($run->documents['provider'] ?? ''), caseId: $run->object['id']);
		foreach ($entries as $entry) {
			if ($entry['id'] === $run->documentId) {
				return $entry['file'];
			}
		}

		return null;
	}//end listedFile()

	/**
	 * Whether a declared `opened` hook refuses. Fail closed: anything but
	 * true, or a throw, streams nothing.
	 *
	 * @param DocumentOpening $run The opening.
	 *
	 * @return bool
	 */
	private function refusedByHook(DocumentOpening $run): bool {
		return isset($run->documents['opened']) === true && $this->hookAllows(run: $run) === false;
	}//end refusedByHook()

	/**
	 * Record the download for the session's subject and the proven object.
	 *
	 * @param DocumentOpening $run The opening.
	 *
	 * @return void
	 */
	private function recordDownload(DocumentOpening $run): void {
		$this->audit->download(
			subjectRef: (string)($run->subject['subjectRef'] ?? ''),
			organisation: (string)($run->subject['organisation'] ?? ''),
			register: $run->object['register'],
			schema: $run->object['schema'],
			id: $run->object['id']
		);
	}//end recordDownload()

	/**
	 * Call the app's `opened` hook; true only when it returned exactly true.
	 *
	 * The subject it gets is the session's, never the request's.
	 *
	 * @param DocumentOpening $run The opening.
	 *
	 * @return bool True when the hook allowed the opening.
	 */
	private function hookAllows(DocumentOpening $run): bool {
		$provider = $this->hookProvider(run: $run);
		if ($provider === null) {
			return false;
		}

		try {
			$allowed = $provider->{(string)$run->documents['opened']}(
				$run->object['id'],
				$run->documentId,
				$this->hookSubject(subject: $run->subject)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the opened hook failed', ['app' => $run->app]);
			return false;
		}

		if ($allowed !== true) {
			$this->logger->warning('Portaliq: the opened hook refused', ['app' => $run->app]);
		}

		return $allowed === true;
	}//end hookAllows()

	/**
	 * The app's provider when its declared hook is a public instance method,
	 * else null (logged when the hook cannot be named or found).
	 *
	 * @param DocumentOpening $run The opening.
	 *
	 * @return object|null
	 */
	private function hookProvider(DocumentOpening $run): ?object {
		$method = (string)$run->documents['opened'];
		$provider = $this->locator->locate(appId: $run->app);
		if ($provider === null || $this->timelineProviderMethod->accepts(name: $method) === false || method_exists($provider, $method) === false) {
			$this->logger->warning('Portaliq: the opened hook cannot be called', ['app' => $run->app]);
			return null;
		}

		$reflection = new ReflectionMethod($provider, $method);
		if ($reflection->isPublic() === false || $reflection->isStatic() === true) {
			return null;
		}

		return $provider;
	}//end hookProvider()

	/**
	 * What the hook is told about the subject: the session's, and only these.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array{subjectRef: string, trust: string, identityType: string, audience: string}
	 */
	private function hookSubject(array $subject): array {
		return [
			'subjectRef' => (string)($subject['subjectRef'] ?? ''),
			'trust' => (string)($subject['trust'] ?? ''),
			'identityType' => (string)($subject['identityType'] ?? ''),
			'audience' => (string)($subject['audience'] ?? ''),
		];
	}//end hookSubject()
}//end class
