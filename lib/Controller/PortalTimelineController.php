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

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Contribution\CaseMessageEntries;
use OCA\Portaliq\Contribution\CaseMessagesProviderMethod;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\MandatedCaseReaderInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalItemReaderInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectReaderInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalTimelineReaderInterface;
use OCA\Portaliq\Kernel\Support\SessionTrust;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\Portaliq\Kernel\Contracts\StepsProviderMethodInterface;

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
	 * @param PortalContributionRegistryInterface $registry The subject's contributions.
	 * @param PortalSessionServiceInterface $session Resolves the subject from the bearer.
	 * @param PortalObjectReaderInterface $reader The scoped single-object read.
	 * @param PortalTimelineReaderInterface $timelines Calls the declared provider method.
	 * @param StepsProviderMethodInterface $stepsProviderMethod The StepsProviderMethod helper.
	 * @param PortalItemReaderInterface|null $items Calls the item-list provider method (my-dossiers).
	 * @param MandatedCaseReaderInterface|null $mandatedCases Reads a case the person sees under a mandate.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistryInterface $registry,
		private readonly PortalSessionServiceInterface $session,
		private readonly PortalObjectReaderInterface $reader,
		private readonly PortalTimelineReaderInterface $timelines,
		private readonly StepsProviderMethodInterface $stepsProviderMethod,
		private readonly ?PortalItemReaderInterface $items = null,
		private readonly ?MandatedCaseReaderInterface $mandatedCases = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
	 * Where one case the subject owns stands: the steps its app's `steps`
	 * provider answers, held to `{label, description?, state, date?}`.
	 * Proven exactly as the history is.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 * @param string $id The case id (never trusted; ownership proven first).
	 *
	 * @return JSONResponse `{label, steps}`, or 401 / 403 / 404 / 502.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-cases-collection-may-supply-steps-an-answer-date-and-whose-turn-it-is-req-smo-022
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function steps(string $register, string $schema, string $id): JSONResponse {
		$proven = $this->provenObject(register: $register, schema: $schema, id: $id, key: 'steps');
		if ($proven instanceof JSONResponse) {
			return $proven;
		}

		$entries = $this->timelines->entries(appId: $proven['app'], method: $proven['declared']['provider'], id: $id);
		if ($entries === null) {
			// Steps that could not be read are not a case without steps.
			return new JSONResponse(['error' => 'steps_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(
			[
				'label' => (string)($proven['declared']['label'] ?? ''),
				'steps' => $this->stepsProviderMethod->steps(entries: $entries),
			]
		);
	}//end steps()

	/**
	 * The messages about one object the subject owns, both ways, newest
	 * first: what its app's `messages` provider answers, held to
	 * `{id, subject, body, receivedAt, fromResident, senderName, read}`.
	 * Ownership is proven first, as for the timeline, so a foreign or absent
	 * id is one 404 and the provider is never asked.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema   The schema of the collection.
	 * @param string $id       The object id (never trusted; ownership proven first).
	 *
	 * @return JSONResponse `{label, messages}`, or 401 / 403 / 404 / 502.
	 *
	 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function messages(string $register, string $schema, string $id): JSONResponse {
		$proven = $this->provenObject(register: $register, schema: $schema, id: $id, key: CaseMessagesProviderMethod::KEY);
		if ($proven instanceof JSONResponse) {
			return $proven;
		}

		$entries = $this->timelines->entries(appId: $proven['app'], method: $proven['declared']['provider'], id: $id);
		if ($entries === null) {
			// Messages that could not be read are not a case without messages.
			return new JSONResponse(['error' => 'messages_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(
			[
				'label' => (string)($proven['declared']['label'] ?? ''),
				'messages' => (new CaseMessageEntries())->entries(entries: $entries),
			]
		);
	}//end messages()

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
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
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
	 * @param string $key      `timeline`, `steps`, `messages` or `itemList`.
	 *
	 * @return JSONResponse|array{app: string, declared: array<string, mixed>} The refusal, or the proven match.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
	 * @spec openspec/changes/mandated-case-opens-on-its-record-page/specs/portal-my-cases/spec.md#requirement-a-case-read-under-a-mandate-opens-on-its-record-page
	 */
	private function provenObject(string $register, string $schema, string $id, string $key): JSONResponse|array {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$proven = $this->provenDeclaration(subject: $subject, register: $register, schema: $schema, key: $key);
		if ($proven instanceof JSONResponse) {
			return $proven;
		}

		$match      = $proven['match'];
		$declared   = $proven['declared'];
		$collection = $match['collection'];

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
		// A case the person sees under the mandate they name is read by the
		// same reader that lists it on "My cases" (cases-my-cases-page
		// REQ-CMC-004), so this never opens a case the list would not show.
		if ($owned === null) {
			$owned = $this->mandatedCase(subject: $subject, register: $register, schema: $schema, id: $id);
		}

		// Not the subject's OR does not exist: one 404, no oracle, and the
		// provider is never asked about it.
		if ($owned === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return ['app' => $match['app'], 'declared' => $declared];
	}//end provenObject()

	/**
	 * The subject's trusted collection and its `$key` declaration, or the
	 * 403 or 404 refusal.
	 *
	 * @param array<string, mixed> $subject  The resolved subject.
	 * @param string               $register The register of the collection.
	 * @param string               $schema   The schema of the collection.
	 * @param string               $key      `timeline`, `steps`, `messages` or `itemList`.
	 *
	 * @return JSONResponse|array{match: array{collection: array<string, mixed>, app: string}, declared: array<string, mixed>}
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
	 */
	private function provenDeclaration(array $subject, string $register, string $schema, string $key): JSONResponse|array {
		$match = $this->trustedCollection(subject: $subject, register: $register, schema: $schema);
		if ($match === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$declared = $this->declaredProvider(collection: $match['collection'], key: $key);
		if ($declared === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return ['match' => $match, 'declared' => $declared];
	}//end provenDeclaration()

	/**
	 * The subject's own collection for a register and schema, or null when
	 * there is none or the subject's trust is below its minTrust.
	 *
	 * @param array<string, mixed> $subject  The resolved subject.
	 * @param string               $register The requested register.
	 * @param string               $schema   The requested schema.
	 *
	 * @return array{collection: array<string, mixed>, app: string}|null
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
	 */
	private function trustedCollection(array $subject, string $register, string $schema): array|null {
		$match = $this->authorisedCollection(subject: $subject, register: $register, schema: $schema);
		if ($match === null || SessionTrust::trustSatisfies(($subject['trust'] ?? ''), ($match['collection']['minTrust'] ?? null)) === false) {
			return null;
		}

		return $match;
	}//end trustedCollection()

	/**
	 * The collection's `$key` declaration, or null when it names no provider.
	 *
	 * @param array<string, mixed> $collection The collection.
	 * @param string               $key        `timeline`, `steps`, `messages` or `itemList`.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
	 */
	private function declaredProvider(array $collection, string $key): array|null {
		$declared = ($collection[$key] ?? null);
		if (is_array($declared) === false || is_string($declared['provider'] ?? null) === false) {
			return null;
		}

		return $declared;
	}//end declaredProvider()

	/**
	 * The case the subject sees under the mandate the request names, if any.
	 *
	 * @param array<string, mixed> $subject  The resolved subject.
	 * @param string               $register The requested register.
	 * @param string               $schema   The requested schema.
	 * @param string               $id       The object id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/mandated-case-opens-on-its-record-page/specs/portal-my-cases/spec.md#requirement-a-case-read-under-a-mandate-opens-on-its-record-page
	 */
	private function mandatedCase(array $subject, string $register, string $schema, string $id): array|null {
		return $this->mandatedCases?->read(
			subject: $subject,
			mandateId: (string)$this->request->getParam('mandate', ''),
			register: $register,
			schema: $schema,
			id: $id
		);
	}//end mandatedCase()

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
		return (new SubjectCollectionFinder(registry: $this->registry))->find(
			subject: $subject,
			requested: ['register' => $register, 'schema' => $schema, 'collection' => (string)$this->request->getParam('collection', '')]
		);
	}//end authorisedCollection()
}//end class
