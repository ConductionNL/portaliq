<?php

/**
 * Portaliq Publication Report Controller
 *
 * The public route a publication page posts an error report to, and the
 * owner's route that closes one (publication-error-reports-and-withheld-notices
 * REQ-PER-002). The public route answers 404 while the portal has error
 * reports off, so a portal that never switched them on shows no channel.
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
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Service\Publication\PublicationReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Receives a visitor's error report and lets the owner close it.
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */
class PublicationReportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request     The request.
	 * @param PublicationReportService $reports     Receives and closes reports.
	 * @param PortalResolverInterface  $portals     Resolves the portal by slug.
	 * @param IUserSession             $userSession The owner closing a report.
	 * @param IL10N                    $l10n        Words the receipt.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PublicationReportService $reports,
		private readonly PortalResolverInterface $portals,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Receive one error report on a publication.
	 *
	 * 201 with a receipt sentence; 404 while reports are off, or for a
	 * publication the anonymous public read does not return (a draft
	 * included), storing nothing; 400 for an empty description or a malformed
	 * address; 413 for a description over 2000 characters; 429 once the
	 * publication took its reports this hour. The address and the user agent
	 * are never stored.
	 *
	 * @param string $portal       The portal slug.
	 * @param string $publication  The publication id.
	 * @param string $description  What is wrong.
	 * @param string $document     The document it is about, if any.
	 * @param string $contactEmail An address for a reply, if any.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 5, period: 60)]
	#[BruteForceProtection(action: 'portaliq-publication-report')]
	public function create(
		string $portal='',
		string $publication='',
		string $description='',
		string $document='',
		string $contactEmail=''
	): JSONResponse {
		$record = $this->portals->resolve(request: $this->request, portalSlug: self::slugOrNull(portal: $portal));
		if ($record === null) {
			return $this->notFound();
		}

		$result = $this->reports->submit(
			portal: $record,
			input: [
				'publication'  => $publication,
				'description'  => $description,
				'document'     => $document,
				'contactEmail' => $contactEmail,
			]
		);

		return match ($result['outcome']) {
			PublicationReportService::OK => new JSONResponse(
				['message' => $this->l10n->t('Thank you. Your report was sent to the people who publish this.')],
				Http::STATUS_CREATED
			),
			PublicationReportService::INVALID => new JSONResponse(['error' => 'invalid'], Http::STATUS_BAD_REQUEST),
			PublicationReportService::TOO_LONG => new JSONResponse(['error' => 'too_long'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE),
			PublicationReportService::TOO_MANY => new JSONResponse(['error' => 'too_many'], Http::STATUS_TOO_MANY_REQUESTS),
			PublicationReportService::FAILED => new JSONResponse(['error' => 'not_stored'], Http::STATUS_BAD_GATEWAY),
			default => $this->notFound(),
		};
	}//end create()

	/**
	 * Close one report as `handled` or `not-applicable`. Only the portal's
	 * owner of error reports (the user, or a member of the group) may.
	 *
	 * @param string $id     The report's id.
	 * @param string $portal The portal slug.
	 * @param string $status `handled` or `not-applicable`.
	 *
	 * @return JSONResponse 200 with the report, 403, 400 or 404.
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	#[NoAdminRequired]
	public function close(string $id, string $portal='', string $status=''): JSONResponse {
		$user   = $this->userSession->getUser();
		$record = $this->portals->resolve(request: $this->request, portalSlug: self::slugOrNull(portal: $portal));
		if ($user === null || $record === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->reports->close(portal: $record, id: $id, status: $status, userId: $user->getUID());

		return match ($result['outcome']) {
			PublicationReportService::OK => new JSONResponse(['report' => ($result['report'] ?? [])]),
			PublicationReportService::NOT_OWNER => new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN),
			PublicationReportService::INVALID => new JSONResponse(['error' => 'invalid'], Http::STATUS_BAD_REQUEST),
			default => new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND),
		};
	}//end close()

	/**
	 * The slug the resolver takes: null when none was named.
	 *
	 * @param string $portal The named slug, or ''.
	 *
	 * @return string|null
	 */
	private static function slugOrNull(string $portal): ?string {
		if ($portal === '') {
			return null;
		}

		return $portal;
	}//end slugOrNull()

	/**
	 * The one refusal a probe gets for "off", "no portal" and "no such
	 * publication", throttled so ids cannot be walked.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		$response->throttle(['action' => 'portaliq-publication-report']);

		return $response;
	}//end notFound()
}//end class
