<?php

/**
 * Portaliq Form Upload Controller
 *
 * The anonymous file upload of a landing-page form: after the submission was
 * created, each file of its file fields comes here with the one-time token
 * the create answer carried (decision 25 Nov 2026 15:15 CET).
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
 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\FormUploadGrantsInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFileFieldPolicyInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFileWriterInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Takes one file into one file field of a just-created submission.
 *
 * No session: the token is the whole authorisation, and it only reaches the
 * one object it was minted for, the fields its form declares, one file per
 * field (ten when the field allows several), for thirty minutes. The file is
 * checked against the field's size and type before anything is written, and
 * goes into the object's own OpenRegister folder, tagged with its field.
 * Nextcloud's antivirus app, when installed, scans that write like any other
 * and its refusal answers 502 with nothing kept.
 *
 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
 */
class FormUploadController extends Controller {
	/**
	 * The header the token travels in.
	 */
	public const TOKEN_HEADER = 'X-Portaliq-Upload-Token';

	/**
	 * The tag a file carries, followed by its field.
	 */
	public const FIELD_TAG = 'portaliq-form-field:';

	/**
	 * HTTP status per policy refusal.
	 */
	private const REFUSAL_STATUS = [
		PortalFileFieldPolicyInterface::ERROR_TOO_LARGE => Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
		PortalFileFieldPolicyInterface::ERROR_TYPE_REFUSED => Http::STATUS_UNSUPPORTED_MEDIA_TYPE,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                       $request    The request.
	 * @param FormUploadGrantsInterface      $grants     The one-time upload tokens.
	 * @param PortalFileFieldPolicyInterface $policy     Reads and checks the file.
	 * @param PortalFileWriterInterface      $fileWriter Attaches the file through OpenRegister.
	 */
	public function __construct(
		IRequest $request,
		private readonly FormUploadGrantsInterface $grants,
		private readonly PortalFileFieldPolicyInterface $policy,
		private readonly PortalFileWriterInterface $fileWriter,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Upload one file into a file field of the submission the token names.
	 *
	 * Multipart part `file`, token in the `X-Portaliq-Upload-Token` header.
	 *
	 * @param string $field The file field.
	 *
	 * @return JSONResponse `{file, field}`, or 400 / 403 / 413 / 415 / 502.
	 *
	 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function upload(string $field): JSONResponse {
		$token = trim((string)$this->request->getHeader(self::TOKEN_HEADER));
		$grant = $this->grants->grantFor(token: $token, field: $field);
		if ($grant === null) {
			// One answer for a missing, unknown, expired or used token and for
			// a field the form does not declare: no oracle.
			return new JSONResponse(['error' => 'upload_not_allowed'], Http::STATUS_FORBIDDEN);
		}

		$upload = $this->policy->readUpload(request: $this->request);
		if ($upload === null) {
			return new JSONResponse(['error' => 'no_file'], Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->policy->refusal(config: $grant['config'], fileName: $upload['name'], content: $upload['content']);
		if ($refusal !== null) {
			return new JSONResponse(['error' => $refusal], self::REFUSAL_STATUS[$refusal]);
		}

		// Used before the write, so two requests at once cannot both pass.
		if ($this->grants->use(token: $token, field: $field) === false) {
			return new JSONResponse(['error' => 'upload_not_allowed'], Http::STATUS_FORBIDDEN);
		}

		return $this->attached(target: $grant['target'], field: $field, upload: $upload);
	}//end upload()

	/**
	 * Attach the checked file to the submission's object.
	 *
	 * @param array{register: string, schema: string, id: string} $target The object.
	 * @param string                                              $field  The file field.
	 * @param array{name: string, content: string}                $upload The checked file.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
	 */
	private function attached(array $target, string $field, array $upload): JSONResponse {
		$file = $this->fileWriter->attachFile(
			register: $target['register'],
			schema: $target['schema'],
			id: $target['id'],
			fileName: $upload['name'],
			content: $upload['content'],
			tags: [self::FIELD_TAG . $field]
		);
		if (is_array($file) === false || is_scalar($file['id'] ?? null) === false) {
			return new JSONResponse(['error' => 'upload_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(
			[
				'file' => ['id' => (string)$file['id'], 'name' => (string)($file['name'] ?? $upload['name']), 'size' => ($file['size'] ?? null)],
				'field' => $field,
			]
		);
	}//end attached()
}//end class
