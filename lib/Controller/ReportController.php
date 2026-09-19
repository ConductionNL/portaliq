<?php

/**
 * Portaliq Report Controller
 *
 * The reporting surface: filing a report without an account, coming back with
 * the receipt code, and, on the staff side, asking the custodian to say who
 * filed it.
 *
 * Three refusals shape this controller. The portal edge never returns a
 * contact detail, whatever the caller sends; every answer goes through
 * ReportProjection. A wrong code and an unknown code answer identically, and
 * both cost the caller a throttled attempt. And revealing is not something a
 * handler can do by reading harder: it is a separate request with a
 * motivation, answered by the group the case type named, which an instance
 * administrator is not a member of by virtue of being an administrator.
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
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Reports\ReportIntakeService;
use OCA\Portaliq\Service\Reports\ReportProjection;
use OCA\Portaliq\Service\Reports\ReportTermsService;
use OCA\Portaliq\Service\Reports\ReportThreadService;
use OCA\Portaliq\Service\Reports\RevealService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Files a report, runs its thread, and gates the reveal.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one dependency per step:
 * the portal, the challenge, intake, the thread, the terms, the reveal, the
 * projection, the record, the case type and the staff user.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)  -- see above.
 */
class ReportController extends Controller {
	/**
	 * The register reports live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a report.
	 */
	private const SCHEMA = 'portalReport';

	/**
	 * The schema recording a reveal request.
	 */
	private const REQUEST_SCHEMA = 'portalRevealRequest';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalResolver $portals Resolves the portal being visited.
	 * @param PortalChallengeService $challenge The portal's own challenge.
	 * @param ReportIntakeService $intake Accepts a report and mints its code.
	 * @param ReportThreadService $threads Opens a thread and carries messages.
	 * @param ReportTermsService $terms Where the declared terms stand.
	 * @param RevealService $reveals Requests, refusals and reveals.
	 * @param ReportProjection $projection Strips the reporter from an answer.
	 * @param PortalObjectReader $reader Reads the report and the request.
	 * @param CaseTypeReader $caseTypes Reads the declaration.
	 * @param IUserSession $userSession The staff user, when there is one.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $portals,
		private readonly PortalChallengeService $challenge,
		private readonly ReportIntakeService $intake,
		private readonly ReportThreadService $threads,
		private readonly ReportTermsService $terms,
		private readonly RevealService $reveals,
		private readonly ReportProjection $projection,
		private readonly PortalObjectReader $reader,
		private readonly CaseTypeReader $caseTypes,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * File a report. No session, no address, no verification.
	 *
	 * @param string $caseType The case type whose declaration governs it.
	 * @param string $register The register the case type lives in.
	 * @param string $schema The schema the case type lives in.
	 * @param array<string, mixed> $report `subject`, `body` and the answers.
	 * @param array<string, mixed> $contact What the reporter chose to give.
	 * @param string $nonce The challenge nonce, when one was issued.
	 * @param string $solution The solution to it.
	 * @param int $expiresAt The expiry issued with the nonce.
	 * @param string $signature This instance's signature over the nonce.
	 *
	 * @return JSONResponse The receipt code, once.
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function file(
		string $caseType,
		string $register = '',
		string $schema = '',
		array $report = [],
		array $contact = [],
		string $nonce = '',
		string $solution = '',
		int $expiresAt = 0,
		string $signature = '',
	): JSONResponse {
		$site = $this->portals->resolve(request: $this->request);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$solved = $this->checkReporterCredential(
			site: $site,
			report: $report,
			nonce: $nonce,
			solution: $solution,
			expiresAt: $expiresAt,
			signature: $signature
		);
		if ($solved === false) {
			return new JSONResponse(['error' => 'challenge_failed'], Http::STATUS_FORBIDDEN);
		}

		if (trim((string)($report['body'] ?? '')) === '') {
			return new JSONResponse(['error' => 'report_empty'], Http::STATUS_BAD_REQUEST);
		}

		$accepted = $this->intake->accept(
			portal: (string)($site['slug'] ?? ''),
			caseType: $caseType,
			report: $report,
			contact: $contact,
			caseTypeRegister: $register,
			caseTypeSchema: $schema
		);
		if ($accepted === null) {
			return new JSONResponse(['error' => 'not_accepted'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		// The only time the code exists outside the reporter's hands.
		return new JSONResponse([
			'code' => $accepted['code'],
			'codeShownOnce' => true,
			'recoverable' => false,
		]);
	}//end file()

	/**
	 * Whether the filing carries the credential this endpoint authenticates with.
	 *
	 * A report is filed with no session, no account and no address, so the only
	 * credential the request carries is the portal's own challenge: a honeypot
	 * and a proof of work, issued by this instance and checked by it. Nothing
	 * here calls a challenge vendor, because a request to one would tell that
	 * vendor somebody is on the whistleblowing page.
	 *
	 * accepts() runs the honeypot first and then the proof of work, and answers
	 * true when the operator switched the challenge off, so it is called
	 * unconditionally rather than behind isEnabled(): the honeypot costs
	 * nothing and should not be skipped with it.
	 *
	 * @param array<string, mixed> $site The portal the filing arrived on.
	 * @param array<string, mixed> $report The submitted report.
	 * @param string $nonce The challenge nonce, when one was issued.
	 * @param string $solution The solution to it.
	 * @param int $expiresAt The expiry issued with the nonce.
	 * @param string $signature This instance's signature over the nonce.
	 *
	 * @return bool Whether the credential checks out.
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	private function checkReporterCredential(array $site, array $report, string $nonce, string $solution, int $expiresAt, string $signature): bool {
		return $this->challenge->accepts(
			site: $site,
			surface: 'report',
			submission: $report,
			nonce: $nonce,
			solution: $solution,
			expiresAt: $expiresAt,
			signature: $signature
		);
	}//end checkReporterCredential()

	/**
	 * The report a receipt code opens, or null when it opens none.
	 *
	 * The receipt code IS the reporter's credential: it is the whole identity
	 * behind the thread, there is no session and no account to fall back on,
	 * and a wrong code is answered exactly like an unknown one so that nothing
	 * says whether a report exists.
	 *
	 * @param string $code The receipt code presented in the request.
	 * @param string $address The caller's address, which the throttle counts
	 *                        a wrong code against and nothing else keeps.
	 *
	 * @return array<string, mixed>|null The report, or null when the code opens none.
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	private function resolveReporterCredential(string $code, string $address): ?array {
		return $this->threads->openByCode(code: $code, address: $address);
	}//end resolveReporterCredential()

	/**
	 * Open the thread behind a receipt code.
	 *
	 * @param string $code The receipt code.
	 *
	 * @return JSONResponse The report, the visible messages and the terms.
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function thread(string $code): JSONResponse {
		$report = $this->resolveReporterCredential(code: $code, address: $this->request->getRemoteAddress());
		if ($report === null) {
			// A wrong code and an unknown code are the same answer. Anything
			// else would say whether a report exists.
			return new JSONResponse(['error' => 'code_not_valid'], Http::STATUS_UNAUTHORIZED);
		}

		$view = $this->projection->one(report: $report);

		return new JSONResponse([
			'report' => $view,
			'messages' => $this->threads->messagesForReporter(reportId: (string)$view['id']),
			'terms' => $this->terms->forReport(caseType: $this->declarationFor(report: $report), report: $report),
		]);
	}//end thread()

	/**
	 * Answer on the thread, as the reporter.
	 *
	 * @param string $code The receipt code.
	 * @param string $body What they write.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function answer(string $code, string $body): JSONResponse {
		$report = $this->resolveReporterCredential(code: $code, address: $this->request->getRemoteAddress());
		if ($report === null) {
			return new JSONResponse(['error' => 'code_not_valid'], Http::STATUS_UNAUTHORIZED);
		}

		$view = $this->projection->one(report: $report);
		$written = $this->threads->write(reportId: (string)$view['id'], author: 'reporter', body: $body);
		if ($written === false) {
			return new JSONResponse(['error' => 'not_written'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['written' => true]);
	}//end answer()

	/**
	 * One report as a handler reads it: everything but who filed it.
	 *
	 * @param string $id The report.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$report = $this->report(id: $id);
		if ($report === null) {
			return new JSONResponse(['error' => 'report_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([
			'report' => $this->projection->one(report: $report),
			'messages' => $this->threads->allMessages(reportId: $id),
		]);
	}//end show()

	/**
	 * Write on the thread, as a handler.
	 *
	 * @param string $id The report.
	 * @param string $body What they write.
	 * @param bool $visibleToReporter Whether the reporter may read it.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- request-bound field, not
	 * a mode switch: it is stored on the message as its visibility and is not
	 * branched on here. Splitting the method would fork the route.
	 */
	#[NoAdminRequired]
	public function reply(string $id, string $body, bool $visibleToReporter = false): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->report(id: $id) === null) {
			return new JSONResponse(['error' => 'report_not_found'], Http::STATUS_NOT_FOUND);
		}

		$written = $this->threads->write(
			reportId: $id,
			author: 'handler',
			body: $body,
			// A note is internal unless the handler says otherwise. On this
			// surface the safe default is the one that does not reach the
			// reporter.
			visibleToReporter: $visibleToReporter,
			authorName: $user->getDisplayName()
		);
		if ($written === false) {
			return new JSONResponse(['error' => 'not_written'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['written' => true]);
	}//end reply()

	/**
	 * Ask to learn who filed a report.
	 *
	 * @param string $id The report.
	 * @param string $motivation Why they need to know.
	 *
	 * @return JSONResponse The recorded request, or a refusal.
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	#[NoAdminRequired]
	public function requestReveal(string $id, string $motivation = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->report(id: $id) === null) {
			return new JSONResponse(['error' => 'report_not_found'], Http::STATUS_NOT_FOUND);
		}

		$recorded = $this->reveals->request(reportId: $id, requestedBy: $user->getUID(), motivation: $motivation);
		if ($recorded === null) {
			// No motivation, no request. Nothing is written, so nothing can
			// later be mistaken for one the custodian never saw.
			return new JSONResponse(['error' => 'motivation_required'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['state' => 'pending'], Http::STATUS_CREATED);
	}//end requestReveal()

	/**
	 * Answer a reveal request, as the custodian the case type named.
	 *
	 * @param string $id The request.
	 * @param bool $allow Whether it is allowed.
	 * @param string $reason What the custodian says about their answer.
	 *
	 * @return JSONResponse The contact details on an allowed reveal, the
	 *                      refusal otherwise.
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- request-bound field: the
	 * custodian's answer is the payload of this endpoint, and it is recorded on
	 * the request alongside $reason. Two routes would record one decision.
	 */
	#[NoAdminRequired]
	public function decideReveal(string $id, bool $allow = false, string $reason = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$request = $this->reader->readObject(
			register: self::REGISTER,
			schema: self::REQUEST_SCHEMA,
			scopeField: '',
			subjectRef: '',
			id: $id
		);
		if ($request === null) {
			return new JSONResponse(['error' => 'request_not_found'], Http::STATUS_NOT_FOUND);
		}

		$report = $this->report(id: (string)($request['reportRef'] ?? ''));
		if ($report === null) {
			return new JSONResponse(['error' => 'report_not_found'], Http::STATUS_NOT_FOUND);
		}

		$decided = $this->reveals->decide(
			request: $request,
			report: $report,
			custodian: $user,
			caseType: $this->declarationFor(report: $report),
			allow: $allow,
			reason: $reason
		);
		if (isset($decided['error']) === true) {
			// `not_custodian` covers the administrator who is not in the
			// declared group, and it is a refusal that shows nothing at all.
			$status = Http::STATUS_CONFLICT;
			if ($decided['error'] === 'not_custodian') {
				$status = Http::STATUS_FORBIDDEN;
			}

			return new JSONResponse(['error' => $decided['error']], $status);
		}

		return new JSONResponse($decided);
	}//end decideReveal()

	/**
	 * One report row, read with no scoping: a report has no subject to scope
	 * it to, which is the point of it.
	 *
	 * @param string $id The report.
	 *
	 * @return array<string, mixed>|null
	 */
	private function report(string $id): ?array {
		if ($id === '') {
			return null;
		}

		return $this->reader->readObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			id: $id
		);
	}//end report()

	/**
	 * The case type whose declaration governs this report.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return array<string, mixed> The case type, or [] when it cannot be read.
	 *                              An unreadable declaration yields no terms
	 *                              and no custodian, which refuses rather than
	 *                              allows.
	 */
	private function declarationFor(array $report): array {
		$type = $this->caseTypes->readCaseType(
			register: (string)($report['caseTypeRegister'] ?? ''),
			schema: (string)($report['caseTypeSchema'] ?? ''),
			id: (string)($report['caseType'] ?? '')
		);

		return ($type ?? []);
	}//end declarationFor()
}//end class
