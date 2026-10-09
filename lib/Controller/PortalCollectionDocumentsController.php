<?php

/**
 * Portaliq Portal Collection Documents Controller
 *
 * The documents of one object in any contributed collection that declares
 * `documents`: the list, and one download.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\CollectionDocuments;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Serves the papers of one object to the subject it is scoped to.
 *
 * Order: the session (401), the collection in the subject's own aggregate (a
 * collection the trust does not reach is absent there, so 404), the scoped read
 * of the object (404), the listed document (404), the `opened` hook (503), the
 * stream. No refusal carries more than its status.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
 */
class PortalCollectionDocumentsController extends Controller implements PortalProtected {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalContributionRegistry $registry The subject's contributions.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalObjectReader $reader The scoped single-object read.
	 * @param CollectionDocuments $documents Lists and opens the documents.
	 * @param IL10N $l10n The sentence a refused opening is given with.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalSessionService $session,
		private readonly PortalObjectReader $reader,
		private readonly CollectionDocuments $documents,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The papers one object lists.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 * @param string $id The object id (never trusted; scope proven first).
	 *
	 * @return JSONResponse `{label, documents}`, or 401 / 404.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function list(string $register, string $schema, string $id): JSONResponse {
		$proven = $this->proven(register: $register, schema: $schema, id: $id);
		if ($proven instanceof JSONResponse) {
			return $proven;
		}

		return new JSONResponse(
			[
				'label'     => (string)($proven['documents']['label'] ?? ''),
				'documents' => $this->documents->listFor(app: $proven['app'], documents: $proven['documents'], id: $id),
			]
		);
	}//end list()

	/**
	 * Open one paper the list holds.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 * @param string $id The object id.
	 * @param string $documentId The listed entry's id.
	 *
	 * @return Response The file, or 401 / 404 / 503.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t5
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function open(string $register, string $schema, string $id, string $documentId): Response {
		$proven = $this->proven(register: $register, schema: $schema, id: $id);
		if ($proven instanceof JSONResponse) {
			return $proven;
		}

		$result = $this->documents->open(
			subject: $proven['subject'],
			app: $proven['app'],
			documents: $proven['documents'],
			object: ['register' => $register, 'schema' => $schema, 'id' => $id],
			documentId: $documentId
		);
		if ($result === CollectionDocuments::REFUSED) {
			$message = $this->l10n->t('The paper cannot be opened right now');
			return new JSONResponse(['error' => 'paper_unavailable', 'message' => $message], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if (is_string($result) === true) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return $result;
	}//end open()

	/**
	 * Prove the object is the subject's, in a collection of the subject's own
	 * aggregate that declares `documents`.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 * @param string $id The object id.
	 *
	 * @return JSONResponse|array{subject: array<string, mixed>, app: string, documents: array<string, mixed>} The refusal, or the proven match.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService::trustSatisfies, the one trust ordering.
	 */
	private function proven(string $register, string $schema, string $id): JSONResponse|array {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$match = $this->collectionOf(subject: $subject, register: $register, schema: $schema);
		$notFound = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		if ($match === null || PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($match['collection']['minTrust'] ?? null)) === false) {
			return $notFound;
		}

		$collection = $match['collection'];
		$documents  = ($collection['documents'] ?? null);
		if (is_array($documents) === false || is_string($documents['provider'] ?? null) === false) {
			return $notFound;
		}

		$owned = $this->reader->readObject(
			register: $register,
			schema: $schema,
			scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			id: $id,
			organisation: (string)($subject['organisation'] ?? ''),
			scopeClaim: (string)($collection['scopeClaim'] ?? ''),
			contributingApp: $match['app'],
			via: ($collection['via'] ?? null),
			audience: (string)($subject['audience'] ?? ''),
			fields: ($collection['fields'] ?? null)
		);
		if ($owned === null) {
			return $notFound;
		}

		return ['subject' => $subject, 'app' => $match['app'], 'documents' => $documents];
	}//end proven()

	/**
	 * The subject's own collection for a register and schema, with its app.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The requested register.
	 * @param string $schema The requested schema.
	 *
	 * @return array{collection: array<string, mixed>, app: string}|null
	 */
	private function collectionOf(array $subject, string $register, string $schema): ?array {
		$collectionId = (string)$this->request->getParam('collection', '');
		foreach (($this->registry->aggregateFor($subject)['contributions'] ?? []) as $contribution) {
			foreach (($contribution['collections'] ?? []) as $collection) {
				if (($collection['register'] ?? '') !== $register || ($collection['schema'] ?? '') !== $schema) {
					continue;
				}

				if ($collectionId !== '' && ($collection['id'] ?? '') !== $collectionId) {
					continue;
				}

				return ['collection' => $collection, 'app' => (string)($contribution['app'] ?? '')];
			}
		}

		return null;
	}//end collectionOf()
}//end class
