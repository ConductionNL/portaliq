<?php

/**
 * Portaliq Portal Timeline Controller
 *
 * The history of one object in a contribution collection, for the subject who
 * owns it (portaliq#723). A contributing app declares it with a collection's
 * `timeline: {label, provider}`; dossiq uses it for "Wat er is gebeurd" on a
 * resident's case.
 *
 * The order is the whole design. The subject's session, the collection being
 * one of the subject's own, its minTrust and the object being the subject's
 * are all proven first, through the same scoped read as a single object
 * (`contribution#object`). Only then is the provider asked, so a foreign or
 * absent id is one 404 and never reaches the contributing app.
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
 * @spec openspec/specs/portal-contribution-contract/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\PortalItemReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PortalTimelineReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves one object's declared history to its owner.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService::trustSatisfies,
 * the one trust ordering every portal gate shares.
 */
class PortalTimelineController extends Controller implements PortalProtected {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalContributionRegistry $registry The subject's contributions.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalObjectReader $reader The scoped single-object read.
	 * @param PortalTimelineReader $timelines Calls the declared provider method.
	 * @param PortalItemReader|null $items Calls the item-list provider method (my-dossiers).
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalSessionService $session,
		private readonly PortalObjectReader $reader,
		private readonly PortalTimelineReader $timelines,
		private readonly ?PortalItemReader $items = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The history of one object the subject owns.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 * @param string $id The object id (never trusted; ownership proven first).
	 *
	 * @return JSONResponse `{label, entries}`, or 401 / 403 / 404 / 502.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $register, string $schema, string $id): JSONResponse {
		$proven = $this->provenObject(register: $register, schema: $schema, id: $id, key: 'timeline');
		if ($proven instanceof JSONResponse) {
			return $proven;
		}

		$entries = $this->timelines->entries(appId: $proven['app'], method: $proven['declared']['provider'], id: $id);
		if ($entries === null) {
			// A history that could not be read is not an empty one.
			return new JSONResponse(['error' => 'timeline_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['label' => (string)($proven['declared']['label'] ?? ''), 'entries' => $entries]);
	}//end show()

	/**
	 * The items of one object the subject owns (my-dossiers): a dossier's
	 * publications, from the provider method its collection's `itemList`
	 * names. Proven exactly as the history is.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 * @param string $id The object id (never trusted; ownership proven first).
	 *
	 * @return JSONResponse `{label, items, removeAction}`, or 401 / 403 / 404 / 502.
	 *
	 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function items(string $register, string $schema, string $id): JSONResponse {
		$proven = $this->provenObject(register: $register, schema: $schema, id: $id, key: 'itemList');
		if ($proven instanceof JSONResponse) {
			return $proven;
		}

		$items = $this->items?->items(appId: $proven['app'], method: $proven['declared']['provider'], id: $id);
		if ($items === null) {
			// A list that could not be read is not an empty dossier.
			return new JSONResponse(['error' => 'items_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(
			[
				'label' => (string)($proven['declared']['label'] ?? ''),
				'items' => $items,
				'removeAction' => (string)($proven['declared']['removeAction'] ?? ''),
			]
		);
	}//end items()

	/**
	 * Prove the object is the subject's, in a collection that declares `$key`.
	 *
	 * The subject's session, the collection being one of the subject's own,
	 * its minTrust, the declaration and the scoped read of the object, in that
	 * order; a foreign or absent id is one 404 and the provider is never asked.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema   The schema of the collection.
	 * @param string $id       The object id.
	 * @param string $key      `timeline` or `itemList`.
	 *
	 * @return JSONResponse|array{app: string, declared: array<string, mixed>} The refusal, or the proven match.
	 *
	 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
	 */
	private function provenObject(string $register, string $schema, string $id, string $key): JSONResponse|array {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$match = $this->authorisedCollection(subject: $subject, register: $register, schema: $schema);
		if ($match === null || PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($match['collection']['minTrust'] ?? null)) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$collection = $match['collection'];
		$declared = ($collection[$key] ?? null);
		if (is_array($declared) === false || is_string($declared['provider'] ?? null) === false) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
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
		// Not the subject's OR does not exist: one 404, no oracle, and the
		// provider is never asked about it.
		if ($owned === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return ['app' => $match['app'], 'declared' => $declared];
	}//end provenObject()

	/**
	 * The subject's own collection for a register and schema, with its app.
	 *
	 * The same match `contribution#object` makes, honouring `?collection=`
	 * when two collections share a register and schema.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The requested register.
	 * @param string $schema The requested schema.
	 *
	 * @return array{collection: array<string, mixed>, app: string}|null
	 */
	private function authorisedCollection(array $subject, string $register, string $schema): ?array {
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
	}//end authorisedCollection()
}//end class
