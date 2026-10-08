<?php

/**
 * Portaliq Portal Plans Controller
 *
 * The plans a resident works on with their contacts.
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Http\PdfDownloadResponse;
use OCA\Portaliq\Service\Plans\PortalPlanService;
use OCA\Portaliq\Service\PortalPdfExport;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Every route answers only for a plan the bearer owns or takes part in, and an
 * unknown plan and someone else's plan answer the same 404.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) -- one route per thing a resident does with a plan.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */
class PortalPlansController extends Controller implements PortalProtected {

	/**
	 * The fields a request may change on a plan.
	 *
	 * @var array<int, string>
	 */
	private const PLAN_FIELDS = ['title', 'goal', 'goalDetail', 'note', 'endDate', 'status'];

	/**
	 * The fields a request may change on an action.
	 *
	 * @var array<int, string>
	 */
	private const ACTION_FIELDS = ['title', 'description', 'kind', 'status', 'endDate', 'assignee'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalPlanService $plans The plan rules.
	 * @param PortalResolver $portals Finds the serving portal, for its templates.
	 * @param PortalPdfExport|null $pdf Renders the plan as a PDF through OpenRegister.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalPlanService $plans,
		private readonly PortalResolver $portals,
		private readonly ?PortalPdfExport $pdf = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The resident's plans with counts.
	 *
	 * @return JSONResponse `{plans, counts}`, or 401.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return new JSONResponse($this->plans->overview(subject: $subject, today: $this->today()));
	}//end index()

	/**
	 * The templates the resident may start from.
	 *
	 * @return JSONResponse `{templates}`, or 401.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function templates(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return new JSONResponse(['templates' => $this->plans->templates(portal: $this->portalSlug())]);
	}//end templates()

	/**
	 * Start a plan, empty or from a template.
	 *
	 * @param string $templateId A published template, or '' for an empty plan.
	 * @param string $title The name; the template's when empty.
	 * @param array<int, string> $contactIds The approved contacts to add.
	 *
	 * @return JSONResponse `{id}`, 400, 403 for a person who is no approved contact, or 401.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function start(string $templateId='', string $title='', array $contactIds=[]): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		$result = $this->plans->start(
			subject: $subject,
			portal: $this->portalSlug(),
			templateId: $templateId,
			title: $title,
			contactIds: array_values($contactIds),
			today: $this->today()
		);
		if ($result['status'] !== PortalPlanService::OK) {
			return $this->answer(result: $result['status']);
		}

		return new JSONResponse(['id' => $result['id']], Http::STATUS_CREATED);
	}//end start()

	/**
	 * One plan.
	 *
	 * @param string $id The plan.
	 *
	 * @return JSONResponse The plan, 404 or 401.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		$plan = $this->plans->detail(subject: $subject, id: $id, today: $this->today());
		if ($plan === null) {
			return $this->answer(result: PortalPlanService::NOT_FOUND);
		}

		return new JSONResponse($plan);
	}//end show()

	/**
	 * Change a plan.
	 *
	 * @param string $id The plan.
	 *
	 * @return JSONResponse `{ok: true}`, or 400, 403, 404, 401.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function update(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		$changes = $this->sent(allowed: self::PLAN_FIELDS);

		return $this->answer(result: $this->plans->update(subject: $subject, id: $id, changes: $changes, today: $this->today()));
	}//end update()

	/**
	 * Delete a plan. Owner only.
	 *
	 * @param string $id The plan.
	 *
	 * @return JSONResponse `{ok: true}`, or 403, 404, 401.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function destroy(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(result: $this->plans->delete(subject: $subject, id: $id));
	}//end destroy()

	/**
	 * Add approved contacts to a plan. Owner only.
	 *
	 * @param string $id The plan.
	 * @param array<int, string> $contactIds The approved contacts.
	 *
	 * @return JSONResponse `{ok: true}`, or 403, 404, 401.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function addParticipants(string $id, array $contactIds=[]): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(result: $this->plans->addParticipants(subject: $subject, id: $id, contactIds: array_values($contactIds)));
	}//end addParticipants()

	/**
	 * Take a participant off a plan. Owner only.
	 *
	 * @param string $id The plan.
	 * @param string $ref The participant's subject reference.
	 *
	 * @return JSONResponse `{ok: true}`, or 403, 404, 401.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function removeParticipant(string $id, string $ref): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(result: $this->plans->removeParticipant(subject: $subject, id: $id, ref: $ref));
	}//end removeParticipant()

	/**
	 * Add an action to a plan.
	 *
	 * @param string $id The plan.
	 *
	 * @return JSONResponse `{ok: true}`, or 400, 403, 404, 401.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function addAction(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(result: $this->plans->addAction(subject: $subject, id: $id, data: $this->sent(allowed: self::ACTION_FIELDS)));
	}//end addAction()

	/**
	 * Change an action of a plan.
	 *
	 * @param string $id The plan.
	 * @param string $actionId The action.
	 *
	 * @return JSONResponse `{ok: true}`, or 400, 403, 404, 401.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function updateAction(string $id, string $actionId): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(
			result: $this->plans->updateAction(subject: $subject, id: $id, actionId: $actionId, data: $this->sent(allowed: self::ACTION_FIELDS))
		);
	}//end updateAction()

	/**
	 * The plan as a PDF, for a participant.
	 *
	 * @param string $id The plan.
	 *
	 * @return Response The file, or 404, 401, 503 when OpenRegister cannot render, 502 when the render failed.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t06
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function pdf(string $id): Response {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		$document = $this->plans->pdfRows(subject: $subject, id: $id, today: $this->today());
		if ($document === null) {
			return $this->answer(result: PortalPlanService::NOT_FOUND);
		}

		if ($this->pdf === null || $this->pdf->available() === false) {
			return new JSONResponse(['error' => 'pdf_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$bytes = $this->pdf->render(title: $document['title'], columns: $document['columns'], rows: $document['rows']);
		if ($bytes === null || $bytes === PortalPdfExport::TOO_LARGE) {
			return new JSONResponse(['error' => 'pdf_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new PdfDownloadResponse(bytes: $bytes, name: $document['title']);
	}//end pdf()

	/**
	 * The answer for a service outcome.
	 *
	 * @param string $result One of the PortalPlanService outcomes.
	 *
	 * @return JSONResponse
	 */
	private function answer(string $result): JSONResponse {
		$status = match ($result) {
			PortalPlanService::OK => Http::STATUS_OK,
			PortalPlanService::NOT_FOUND => Http::STATUS_NOT_FOUND,
			PortalPlanService::FORBIDDEN => Http::STATUS_FORBIDDEN,
			PortalPlanService::INVALID => Http::STATUS_BAD_REQUEST,
			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};
		if ($status === Http::STATUS_OK) {
			return new JSONResponse(['ok' => true]);
		}

		return new JSONResponse(['error' => $result], $status);
	}//end answer()

	/**
	 * The fields of the request that the route allows, nothing else.
	 *
	 * @param array<int, string> $allowed The field names.
	 *
	 * @return array<string, mixed>
	 */
	private function sent(array $allowed): array {
		return array_intersect_key($this->request->getParams(), array_flip($allowed));
	}//end sent()

	/**
	 * Today in the Netherlands, as `Y-m-d`.
	 *
	 * @return string
	 */
	private function today(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
	}//end today()

	/**
	 * The slug of the serving portal, or ''.
	 *
	 * @return string
	 */
	private function portalSlug(): string {
		$slug = $this->request->getParam('portal');
		if (is_string($slug) === false) {
			$slug = null;
		}

		return (string)($this->portals->resolve(request: $this->request, portalSlug: $slug)['slug'] ?? '');
	}//end portalSlug()

	/**
	 * The 401 answer.
	 *
	 * @return JSONResponse
	 */
	private function unauthenticated(): JSONResponse {
		return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
	}//end unauthenticated()

	/**
	 * The subject behind the bearer, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null || (string)($subject['subjectRef'] ?? '') === '') {
			return null;
		}

		return $subject;
	}//end subject()
}//end class
