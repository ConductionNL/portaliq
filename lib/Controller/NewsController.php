<?php

/**
 * News Controller (staff authoring)
 *
 * Staff-side create/update/publish for `newsItem` objects
 * (news-and-newsletter-authoring, finding 9.1). Requires a Nextcloud session
 * — the same posture `CmsEditorController`/`portal-cms-admin-ui` already use
 * for staff content authoring — never a portal bearer.
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
 * @spec openspec/changes/archive/2026-10-09-news-and-newsletter-authoring/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Portaliq\Service\NewsAudienceOptions;
use OCA\Portaliq\Service\ObjectServiceLookup;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Staff authoring for news items.
 *
 * @spec openspec/changes/archive/2026-10-09-news-and-newsletter-authoring/design.md#api-design
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- `IUserSession` is the
 * authorization guard every `#[NoAdminRequired]` method calls first, and
 * `NewsAudienceOptions` serves the News screen's audience choices; both are
 * the endpoint's purpose, not incidental coupling (see NewsletterController).
 */
class NewsController extends Controller {
	use ObjectServiceLookup;
	use StaffActionGuard;
	use ObjectArrayRow;
	use TargetedAudience;
	use StaffRowWrite;

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'newsItem';

	/**
	 * The ADR-023 action every staff method is gated by (create, edit, publish and unpublish a news item).
	 */
	public const ACTION = 'portal.author-news';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Confirms an authenticated Nextcloud user reached this endpoint.
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 * @param ActionAuthServiceInterface $actionAuth Decides whether this staff user may author news (ADR-023).
	 * @param NewsAudienceOptions|null $audienceOptions The school and group choices for the News screen.
	 * @param ITimeFactory|null $timeFactory The server clock that stamps the publish moment.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly ?NewsAudienceOptions $audienceOptions=null,
		private readonly ?ITimeFactory $timeFactory=null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create a draft news item. The author is the signed-in staff member,
	 * never a value from the request.
	 *
	 * @param string $title The title.
	 * @param string $body The body.
	 * @param array<string, mixed> $target The target (schoolRef/groupRefs/childRefs).
	 * @param array<int, string> $photoRefs Attached photo references.
	 * @param bool $public Whether the item also shows on a portal's public website (site-school-blocks).
	 * @param string $portal The portal whose website shows it.
	 * @param string $audienceLabel The words that website shows for who it is for.
	 * @param string $eventRef The event whose sign-up the article carries; empty for none.
	 *
	 * @return JSONResponse The created object, or a 400/500.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- `public` is a request parameter the framework
	 * binds by name; it is data the item stores, not a switch between two behaviours here.
	 */
	#[NoAdminRequired]
	public function create(
		string $title,
		string $body,
		array $target,
		array $photoRefs=[],
		bool $public=false,
		string $portal='',
		string $audienceLabel='',
		string $eventRef=''
	): JSONResponse {
		$authorRef = $this->requireAuthenticatedStaff();

		if ($title === '' || $body === '' || $this->hasAnyTarget(target: $target) === false) {
			return new JSONResponse(['error' => 'invalid_target'], Http::STATUS_BAD_REQUEST);
		}

		return $this->createRow(
			object: [
				'title' => $title,
				'body' => $body,
				'target' => $target,
				'authorRef' => $authorRef,
				'status' => 'draft',
				'photoRefs' => $photoRefs,
				'readReceipts' => [],
			] + $this->website(public: $public, portal: $portal, audienceLabel: $audienceLabel) + ['eventRef' => $this->eventRef(value: $eventRef)],
			failure: 'Portaliq: news item create failed'
		);
	}//end create()

	/**
	 * Change a news item's title, body and audience. The status, the author,
	 * the photos and the read receipts stay as they are; publishing has its
	 * own endpoints.
	 *
	 * @param string $id The news item id.
	 * @param string $title The title.
	 * @param string $body The body.
	 * @param array<string, mixed> $target The target (schoolRef/groupRefs/childRefs).
	 * @param bool|null $public Whether the item also shows on a portal's public website; null leaves it as it is.
	 * @param string $portal The portal whose website shows it.
	 * @param string $audienceLabel The words that website shows for who it is for.
	 * @param string|null $eventRef The event whose sign-up the article carries; empty clears it, null leaves it.
	 *
	 * @return JSONResponse The updated object, a 400 or a 404.
	 *
	 * @spec openspec/changes/archive/2026-10-07-staff-news-screen/tasks.md#T1
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	#[NoAdminRequired]
	public function update(
		string $id,
		string $title,
		string $body,
		array $target,
		?bool $public=null,
		string $portal='',
		string $audienceLabel='',
		?string $eventRef=null
	): JSONResponse {
		$this->requireAuthenticatedStaff();

		if ($title === '' || $body === '' || $this->hasAnyTarget(target: $target) === false) {
			return new JSONResponse(['error' => 'invalid_target'], Http::STATUS_BAD_REQUEST);
		}

		$data = ['title' => $title, 'body' => $body, 'target' => $target];
		// A screen that does not send `public` leaves the website choice as it is.
		if ($public !== null) {
			$data += $this->website(public: $public, portal: $portal, audienceLabel: $audienceLabel);
		}

		if ($eventRef !== null) {
			$data['eventRef'] = $this->eventRef(value: $eventRef);
		}

		return $this->write(id: $id, data: $data);
	}//end update()

	/**
	 * The schools and groups the News screen offers as a news item's audience.
	 *
	 * @return JSONResponse `{schools: [{id, label}], groups: [{id, label}]}`.
	 *
	 * @spec openspec/changes/archive/2026-10-07-staff-news-screen/tasks.md#T2
	 */
	#[NoAdminRequired]
	public function audiences(): JSONResponse {
		$this->requireAuthenticatedStaff();

		if ($this->audienceOptions === null) {
			return new JSONResponse(['schools' => [], 'groups' => []]);
		}

		return new JSONResponse($this->audienceOptions->options());
	}//end audiences()

	/**
	 * Publish a news item and stamp `publishedAt` from the server clock, so
	 * the feed sorts it by when it went out, not by when it was written.
	 *
	 * @param string $id The news item id.
	 *
	 * @return JSONResponse The updated object, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-news-item-carries-the-moment-it-was-published
	 */
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		$now = time();
		if ($this->timeFactory !== null) {
			$now = $this->timeFactory->getTime();
		}

		return $this->write(id: $id, data: ['status' => 'published', 'publishedAt' => gmdate(DATE_ATOM, $now)]);
	}//end publish()

	/**
	 * Revert a news item to draft and clear its publish moment: a draft has
	 * not gone out, and publishing it again stamps a new one.
	 *
	 * @param string $id The news item id.
	 *
	 * @return JSONResponse The updated object, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-news-item-carries-the-moment-it-was-published
	 */
	#[NoAdminRequired]
	public function unpublish(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		return $this->write(id: $id, data: ['status' => 'draft', 'publishedAt' => null]);
	}//end unpublish()

	/**
	 * Write fields onto a news item, preserving everything else.
	 *
	 * @param string $id The news item id.
	 * @param array<string, mixed> $data The fields to write.
	 *
	 * @return JSONResponse
	 */
	private function write(string $id, array $data): JSONResponse {
		if ($id === '') {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->writeRow(id: $id, data: $data);
	}//end write()

	/**
	 * Whether the item also shows on a portal's public website, and with which
	 * words for its audience (site-school-blocks). Turned on only with a portal
	 * slug to show it on; turned off, the portal and the words are cleared, so
	 * a later "on" never revives an old choice by accident.
	 *
	 * @param bool   $public        Whether staff put the item on the website.
	 * @param string $portal        The portal slug.
	 * @param string $audienceLabel The words the website shows for who it is for.
	 *
	 * @return array{public: bool, portal: string, audienceLabel: string}
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	private function website(bool $public, string $portal, string $audienceLabel): array {
		$portal = trim($portal);
		if ($public === false || preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $portal) !== 1) {
			return ['public' => false, 'portal' => '', 'audienceLabel' => ''];
		}

		return ['public' => true, 'portal' => $portal, 'audienceLabel' => mb_substr(trim($audienceLabel), 0, 60)];
	}//end website()

	/**
	 * The event reference to store: a plain id, else empty. A reference to an
	 * event that is not published shows nothing on the website, so the id is
	 * not checked here.
	 *
	 * @param string $value The reference as sent.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event
	 */
	private function eventRef(string $value): string {
		$value = trim($value);
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $value) !== 1) {
			return '';
		}

		return $value;
	}//end eventRef()
}//end class
