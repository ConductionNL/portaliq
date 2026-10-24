<?php

/**
 * Portaliq Public Record Controller
 *
 * The anonymous reads behind the public records block: a contributed list and
 * one record from it.
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
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PublicRecordReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves public record lists and records without a session.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
 */
class PublicRecordController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PublicRecordReader $reader Reads the lists and records.
	 */
	public function __construct(
		IRequest $request,
		private readonly PublicRecordReader $reader,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The entries of one record list.
	 *
	 * @param string $app The contributing app.
	 * @param string $list The list id.
	 *
	 * @return JSONResponse `{entries}` or 404 when the app declares no such list.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
	 *
	 * @no-admin-idor-exempt Public by design: the list holds only what the contributing
	 * app's provider chose to publish, with no subject, and is rate limited per client.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function list(string $app, string $list): JSONResponse {
		$entries = $this->reader->entries(app: $app, list: $list);
		if ($entries === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['entries' => $entries]);
	}//end list()

	/**
	 * One record, only for an id the list holds.
	 *
	 * @param string $app The contributing app.
	 * @param string $list The list id.
	 * @param string $id The record id.
	 *
	 * @return JSONResponse The record, or 404.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
	 *
	 * @no-admin-idor-exempt Public by design: a record is fetched only for an id the
	 * list holds, and the list is what the provider published.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function record(string $app, string $list, string $id): JSONResponse {
		$record = $this->reader->record(app: $app, list: $list, id: $id);
		if ($record === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($record);
	}//end record()
}//end class
