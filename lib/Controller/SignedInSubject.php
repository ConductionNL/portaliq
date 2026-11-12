<?php

/**
 * Portaliq Signed In Subject
 *
 * The resident behind a request's bearer, for the routes of the own area
 * that act only for a signed-in subject, and their one 401 answer.
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
 * @spec exclude Shared request helper; the @spec of each calling route covers the behaviour it reads for.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\Session\BearerSessionResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Resolves the signed-in subject of a request.
 *
 * @spec exclude Shared request helper; the @spec of each calling route covers the behaviour it reads for.
 */
final class SignedInSubject {
	/**
	 * The subject behind the bearer, or null when there is none or it names
	 * no subject.
	 *
	 * @param BearerSessionResolver $session Resolves the subject from the bearer.
	 * @param IRequest              $request The request carrying the bearer.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude Shared request helper; the @spec of each calling route covers the behaviour it reads for.
	 */
	public function resolve(BearerSessionResolver $session, IRequest $request): ?array {
		$subject = $session->resolveFromBearer($request->getHeader('Authorization'));
		if ($subject === null || (string)($subject['subjectRef'] ?? '') === '') {
			return null;
		}

		return $subject;
	}//end resolve()

	/**
	 * The 401 answer.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Shared request helper; the @spec of each calling route covers the behaviour it reads for.
	 */
	public function unauthenticated(): JSONResponse {
		return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
	}//end unauthenticated()
}//end class
