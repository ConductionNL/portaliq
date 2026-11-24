<?php

/**
 * Portaliq Portal Audience Media Controller
 *
 * The media group of a documents block (`extraGroups`, "Van school"): the
 * list and one download, for a register and schema that a documents block of
 * the subject's own contributions names. Which media the person sees is
 * OpenRegister's answer (AudienceMediaReader).
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
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Service\AudienceMediaReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Serves a documents block's media group to the signed-in person.
 * Order: the session (401), the register and schema named by a documents
 * block of the subject's own aggregate (404), then OpenRegister's per-object
 * answer. No refusal carries more than its status.
 *
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
 */
class PortalAudienceMediaController extends Controller implements PortalProtected {


	/**
	 * Constructor.
	 *
	 * @param IRequest                            $request  The request.
	 * @param PortalContributionRegistryInterface $registry The subject's contributions.
	 * @param PortalSessionServiceInterface       $session  Resolves the subject from the bearer.
	 * @param AudienceMediaReader                 $media    Lists and opens the media.
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistryInterface $registry,
		private readonly PortalSessionServiceInterface $session,
		private readonly AudienceMediaReader $media,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()


	/**
	 * The media of the group the person may read.
	 *
	 * @param string $register The register of the group.
	 * @param string $schema   The schema of the group.
	 *
	 * @return JSONResponse `{documents}`, or 401 / 404.
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function list(string $register, string $schema): JSONResponse {
		$subject = $this->declaredFor(register: $register, schema: $schema);
		if ($subject instanceof JSONResponse) {
			return $subject;
		}

		return new JSONResponse(['documents' => $this->media->list(subject: $subject, register: $register, schema: $schema)]);
	}//end list()


	/**
	 * Open one medium of the group.
	 *
	 * @param string $register The register of the group.
	 * @param string $schema   The schema of the group.
	 * @param string $id       The medium's id.
	 *
	 * @return Response The file, or 401 / 404.
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function open(string $register, string $schema, string $id): Response {
		$subject = $this->declaredFor(register: $register, schema: $schema);
		if ($subject instanceof JSONResponse) {
			return $subject;
		}

		$stream = $this->media->open(subject: $subject, register: $register, schema: $schema, id: $id);
		if ($stream === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return $stream;
	}//end open()


	/**
	 * The session's subject when a documents block of its own aggregate names
	 * the register and schema as an extra group, else the refusal.
	 *
	 * @param string $register The requested register.
	 * @param string $schema   The requested schema.
	 *
	 * @return JSONResponse|array<string, mixed>
	 */
	private function declaredFor(string $register, string $schema): JSONResponse|array {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		foreach (($this->registry->aggregateFor($subject)['contributions'] ?? []) as $contribution) {
			if ($this->names(pages: ($contribution['pages'] ?? []), register: $register, schema: $schema) === true) {
				return $subject;
			}
		}

		return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
	}//end declaredFor()


	/**
	 * Whether a documents block on the pages names the register and schema
	 * as an extra group.
	 *
	 * @param mixed  $pages    The contribution's normalised pages.
	 * @param string $register The requested register.
	 * @param string $schema   The requested schema.
	 *
	 * @return bool
	 */
	private function names(mixed $pages, string $register, string $schema): bool {
		foreach ((is_array($pages) === true ? $pages : []) as $page) {
			foreach ((array)($page['blocks'] ?? []) as $block) {
				if (($block['type'] ?? '') !== 'documents') {
					continue;
				}

				foreach ((array)($block['extraGroups'] ?? []) as $group) {
					if (($group['register'] ?? '') === $register && ($group['schema'] ?? '') === $schema) {
						return true;
					}
				}
			}
		}

		return false;
	}//end names()
}//end class
