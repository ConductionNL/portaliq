<?php

/**
 * Unit tests for the PublicationReportController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PublicationReportController;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Tests\Unit\Service\Publication\PublicationReportFixture;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-PER-001 and REQ-PER-002 through the route: off answers 404, a report
 * reaches every member of the owner group, a draft, a long description and
 * a flood store nothing, and no address is kept.
 *
 * @covers \OCA\Portaliq\Controller\PublicationReportController
 * @uses   \OCA\Portaliq\Service\Publication\PublicationReportService
 * @uses   \OCA\Portaliq\Service\Publication\PublicPublicationRead
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
class PublicationReportControllerTest extends TestCase {
	/**
	 * A published publication's id.
	 */
	private const PUBLISHED = '7f3c2a9e-1b4d-4e8a-9c2f-5d6e7a8b9c0d';

	/**
	 * A draft publication's id: the anonymous read answers 404 for it.
	 */
	private const DRAFT = '0a1b2c3d-0000-4000-8000-000000000001';

	/**
	 * The recorded boundaries.
	 *
	 * @var PublicationReportFixture
	 */
	private PublicationReportFixture $fixture;

	/**
	 * Fresh boundaries, with one published publication.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new PublicationReportFixture(test: $this);
		$this->fixture->publications[self::PUBLISHED] = [200, ['id' => self::PUBLISHED, 'title' => 'Besluit parkeerbeleid']];
	}//end setUp()

	/**
	 * The controller for a portal, with an optional signed-in user.
	 *
	 * @param array<string, mixed>|null $portal The portal the resolver answers.
	 * @param string                    $userId The signed-in user, '' for none.
	 *
	 * @return PublicationReportController
	 */
	private function controller(?array $portal, string $userId=''): PublicationReportController {
		$portals = $this->createMock(PortalResolverInterface::class);
		$portals->method('resolve')->willReturn($portal);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($userId !== '') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PublicationReportController(
			request: $this->createMock(IRequest::class),
			reports: $this->fixture->service(),
			portals: $portals,
			userSession: $session,
			l10n: $l10n
		);
	}//end controller()

	/**
	 * Reports off: 404, nothing stored, and the public read is not even asked.
	 *
	 * @return void
	 */
	public function testOffAnswers404(): void {
		$controller = $this->controller(portal: PublicationReportFixture::portal(overrides: ['publicationErrorReports' => false]));

		$response = $controller->create(portal: 'zuiddrecht', publication: self::PUBLISHED, description: 'Bijlage 2 is de conceptversie');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $this->fixture->created);
		$this->assertSame(expected: [], actual: $this->fixture->paths);
	}//end testOffAnswers404()

	/**
	 * An unknown portal answers the same 404.
	 *
	 * @return void
	 */
	public function testAnUnknownPortalAnswers404(): void {
		$response = $this->controller(portal: null)->create(portal: 'nergens', publication: self::PUBLISHED, description: 'Fout');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $this->fixture->created);
	}//end testAnUnknownPortalAnswers404()

	/**
	 * A report on a public publication is stored as `new` and each member of
	 * the owner group gets a notification naming it.
	 *
	 * @return void
	 */
	public function testAReportReachesTheOwnerGroup(): void {
		$response = $this->controller(portal: PublicationReportFixture::portal())->create(
			portal: 'zuiddrecht',
			publication: self::PUBLISHED,
			description: 'Bijlage 2 is de conceptversie',
			document: 'Bijlage 2',
			contactEmail: 'h.bakker@example.nl'
		);

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: 'Thank you. Your report was sent to the people who publish this.', actual: $response->getData()['message']);
		$this->assertCount(expectedCount: 1, haystack: $this->fixture->created);
		[$register, $schema, $data] = $this->fixture->created[0];
		$this->assertSame(expected: ['portaliq', 'publicationErrorReport'], actual: [$register, $schema]);
		$this->assertSame(expected: 'new', actual: $data['status']);
		$this->assertSame(expected: '2026-10-02T10:00:00+00:00', actual: $data['receivedAt']);
		$this->assertSame(expected: 'Besluit parkeerbeleid', actual: $data['publicationTitle']);
		$this->assertSame(expected: 'Bijlage 2', actual: $data['document']);

		$this->assertSame(
			expected: [
				['anna', 'publication_error_report', ['id' => 'report-1', 'portal' => 'Zuiddrecht', 'title' => 'Besluit parkeerbeleid'], 'publicationErrorReport', 'report-1'],
				['bram', 'publication_error_report', ['id' => 'report-1', 'portal' => 'Zuiddrecht', 'title' => 'Besluit parkeerbeleid'], 'publicationErrorReport', 'report-1'],
			],
			actual: $this->fixture->notified
		);
	}//end testAReportReachesTheOwnerGroup()

	/**
	 * An owner that is a user is notified alone.
	 *
	 * @return void
	 */
	public function testAUserOwnerIsNotifiedAlone(): void {
		$this->controller(portal: PublicationReportFixture::portal(overrides: ['publicationErrorReportOwner' => 'carla']))
			->create(portal: 'zuiddrecht', publication: self::PUBLISHED, description: 'Verkeerde datum');

		$this->assertSame(expected: ['carla'], actual: array_column($this->fixture->notified, 0));
	}//end testAUserOwnerIsNotifiedAlone()

	/**
	 * A publication the anonymous read does not return (a draft) answers 404,
	 * is throttled like a probe, and stores and notifies nothing.
	 *
	 * @return void
	 */
	public function testADraftIsRefusedAndNothingStored(): void {
		$response = $this->controller(portal: PublicationReportFixture::portal())
			->create(portal: 'zuiddrecht', publication: self::DRAFT, description: 'Fout in concept');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: [self::DRAFT], actual: $this->fixture->paths);
		$this->assertSame(expected: [], actual: $this->fixture->created);
		$this->assertSame(expected: [], actual: $this->fixture->notified);
		$this->assertSame(expected: ['action' => 'portaliq-publication-report'], actual: $response->getThrottleMetadata());
	}//end testADraftIsRefusedAndNothingStored()

	/**
	 * A description over 2000 characters is refused; 2000 is accepted.
	 *
	 * @return void
	 */
	public function testATooLongDescriptionIsRefused(): void {
		$controller = $this->controller(portal: PublicationReportFixture::portal());

		$tooLong = $controller->create(portal: 'zuiddrecht', publication: self::PUBLISHED, description: str_repeat('é', 2001));
		$this->assertSame(expected: Http::STATUS_REQUEST_ENTITY_TOO_LARGE, actual: $tooLong->getStatus());
		$this->assertSame(expected: [], actual: $this->fixture->created);

		$atTheLimit = $controller->create(portal: 'zuiddrecht', publication: self::PUBLISHED, description: str_repeat('é', 2000));
		$this->assertSame(expected: Http::STATUS_CREATED, actual: $atTheLimit->getStatus());
	}//end testATooLongDescriptionIsRefused()

	/**
	 * An empty description or a malformed address is a 400 and stores nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyDescriptionOrABadAddressIsRefused(): void {
		$controller = $this->controller(portal: PublicationReportFixture::portal());

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $controller->create(portal: 'zuiddrecht', publication: self::PUBLISHED, description: '   ')->getStatus());
		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $controller->create(portal: 'zuiddrecht', publication: self::PUBLISHED, description: 'Fout', contactEmail: 'geen-adres')->getStatus()
		);
		$this->assertSame(expected: [], actual: $this->fixture->created);
	}//end testAnEmptyDescriptionOrABadAddressIsRefused()

	/**
	 * The stored report holds the declared fields and nothing of the request:
	 * no IP address, no user agent.
	 *
	 * @return void
	 */
	public function testNoIpAddressIsStored(): void {
		$this->controller(portal: PublicationReportFixture::portal())
			->create(portal: 'zuiddrecht', publication: self::PUBLISHED, description: 'Naam niet gelakt', contactEmail: 'h.bakker@example.nl');

		$this->assertSame(
			expected: ['portal', 'publication', 'publicationTitle', 'document', 'description', 'contactEmail', 'status', 'receivedAt'],
			actual: array_keys($this->fixture->created[0][2])
		);
	}//end testNoIpAddressIsStored()

	/**
	 * One publication takes ten reports an hour from all addresses; the
	 * eleventh is a 429 and is not stored.
	 *
	 * @return void
	 */
	public function testAPublicationTakesTenReportsAnHour(): void {
		$controller = $this->controller(portal: PublicationReportFixture::portal());
		for ($i = 0; $i < 10; $i++) {
			$controller->create(portal: 'zuiddrecht', publication: self::PUBLISHED, description: 'Fout ' . $i);
		}

		$eleventh = $controller->create(portal: 'zuiddrecht', publication: self::PUBLISHED, description: 'Fout 11');

		$this->assertSame(expected: Http::STATUS_TOO_MANY_REQUESTS, actual: $eleventh->getStatus());
		$this->assertCount(expectedCount: 10, haystack: $this->fixture->created);
	}//end testAPublicationTakesTenReportsAnHour()

	/**
	 * The public route is public, CSRF-free, rate limited per address and
	 * brute-force protected; closing needs a signed-in user.
	 *
	 * @return void
	 */
	public function testTheRouteIsRateLimited(): void {
		$create = new ReflectionMethod(PublicationReportController::class, 'create');
		$names  = array_map(static fn ($attribute): string => $attribute->getName(), $create->getAttributes());
		$this->assertSame(
			expected: [PublicPage::class, NoCSRFRequired::class, AnonRateLimit::class, BruteForceProtection::class],
			actual: $names
		);
		$limit = $create->getAttributes(AnonRateLimit::class)[0]->getArguments();
		$this->assertSame(expected: ['limit' => 5, 'period' => 60], actual: $limit);

		$close = new ReflectionMethod(PublicationReportController::class, 'close');
		$this->assertSame(
			expected: [NoAdminRequired::class],
			actual: array_map(static fn ($attribute): string => $attribute->getName(), $close->getAttributes())
		);
	}//end testTheRouteIsRateLimited()

	/**
	 * A member of the owner group closes a report; the portal is the
	 * boundary the writer checks, and the close is stamped.
	 *
	 * @return void
	 */
	public function testTheOwnerClosesAReport(): void {
		$this->fixture->rows = [['id' => 'report-7', 'portal' => 'zuiddrecht', 'status' => 'new']];

		$response = $this->controller(portal: PublicationReportFixture::portal(), userId: 'bram')
			->close(id: 'report-7', portal: 'zuiddrecht', status: 'handled');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(
			expected: [['portal', 'zuiddrecht', 'report-7', ['status' => 'handled', 'closedAt' => '2026-10-02T10:00:00+00:00', 'closedBy' => 'bram']]],
			actual: $this->fixture->updated
		);
	}//end testTheOwnerClosesAReport()

	/**
	 * Someone outside the owner group, or nobody signed in, cannot close, and
	 * an unknown status is a 400; none of them writes.
	 *
	 * @return void
	 */
	public function testOnlyTheOwnerClosesAndOnlyAsHandledOrNotApplicable(): void {
		$this->fixture->rows = [['id' => 'report-7', 'portal' => 'zuiddrecht', 'status' => 'new']];

		$this->assertSame(
			expected: Http::STATUS_FORBIDDEN,
			actual: $this->controller(portal: PublicationReportFixture::portal(), userId: 'carla')->close(id: 'report-7', portal: 'zuiddrecht', status: 'handled')->getStatus()
		);
		$this->assertSame(
			expected: Http::STATUS_FORBIDDEN,
			actual: $this->controller(portal: PublicationReportFixture::portal())->close(id: 'report-7', portal: 'zuiddrecht', status: 'handled')->getStatus()
		);
		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $this->controller(portal: PublicationReportFixture::portal(), userId: 'anna')->close(id: 'report-7', portal: 'zuiddrecht', status: 'deleted')->getStatus()
		);
		$this->assertSame(expected: [], actual: $this->fixture->updated);
	}//end testOnlyTheOwnerClosesAndOnlyAsHandledOrNotApplicable()

	/**
	 * A report of another portal is not found: the writer refuses it.
	 *
	 * @return void
	 */
	public function testAnotherPortalsReportIsNotFound(): void {
		$this->fixture->rows = [['id' => 'report-7', 'portal' => 'elders', 'status' => 'new']];

		$response = $this->controller(portal: PublicationReportFixture::portal(), userId: 'anna')
			->close(id: 'report-7', portal: 'zuiddrecht', status: 'not-applicable');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testAnotherPortalsReportIsNotFound()
}//end class
