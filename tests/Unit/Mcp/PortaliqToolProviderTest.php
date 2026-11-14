<?php

/**
 * Unit tests for PortaliqToolProvider: the governed curated MCP tools.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Mcp;

use OCA\Portaliq\Mcp\PortaliqToolProvider;
use OCA\Portaliq\Mcp\StaffObjectStore;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PageEditorService;
use OCA\Portaliq\Service\PortalRegionResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\Theme\PortalThemeChoice;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The catalogue, its governance annotations, and each tool's guard and call.
 */
#[CoversClass(PortaliqToolProvider::class)]
#[UsesClass(PortalRegionResolver::class)]
#[UsesClass(AuditTrailService::class)]
class PortaliqToolProviderTest extends TestCase {

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	/** @var PageEditorService&MockObject */
	private PageEditorService $editors;

	/** @var StaffObjectStore&MockObject */
	private StaffObjectStore $objects;

	/** @var PortalThemeChoice&MockObject */
	private PortalThemeChoice $themes;

	/** @var PortalSessionService&MockObject */
	private PortalSessionService $sessions;

	/** @var TrafficEventStore&MockObject */
	private TrafficEventStore $traffic;

	/** @var TrafficReportNumbers&MockObject */
	private TrafficReportNumbers $numbers;

	/** @var AuditTrailService&MockObject */
	private AuditTrailService $auditor;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->editors = $this->createMock(PageEditorService::class);
		$this->objects = $this->createMock(StaffObjectStore::class);
		$this->themes = $this->createMock(PortalThemeChoice::class);
		$this->sessions = $this->createMock(PortalSessionService::class);
		$this->traffic = $this->createMock(TrafficEventStore::class);
		$this->numbers = $this->createMock(TrafficReportNumbers::class);
		$this->auditor = $this->createMock(AuditTrailService::class);
	}//end setUp()

	/**
	 * The provider over the mocks, with an optional real auditor.
	 *
	 * @param AuditTrailService|null $auditor An auditor other than the mock.
	 *
	 * @return PortaliqToolProvider
	 */
	private function provider(?AuditTrailService $auditor = null): PortaliqToolProvider {
		return new PortaliqToolProvider(
			userSession: $this->userSession,
			groups: $this->groups,
			editors: $this->editors,
			objects: $this->objects,
			regions: new PortalRegionResolver(),
			themes: $this->themes,
			sessions: $this->sessions,
			traffic: $this->traffic,
			numbers: $this->numbers,
			auditor: ($auditor ?? $this->auditor),
		);
	}//end provider()

	/**
	 * Sign a Nextcloud user in, admin or not.
	 *
	 * @param string $uid   The user id.
	 * @param bool   $admin Whether the user is an administrator.
	 *
	 * @return void
	 */
	private function signIn(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->with($uid)->willReturn($admin);
	}//end signIn()

	/**
	 * The descriptors by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function tools(): array {
		$byId = [];
		foreach ($this->provider()->getTools() as $tool) {
			$byId[$tool['id']] = $tool;
		}

		return $byId;
	}//end tools()

	/**
	 * The catalogue is exactly the seven curated tools under the app id.
	 *
	 * @return void
	 */
	public function testTheCatalogueIsExactlyTheSevenCuratedTools(): void {
		$this->assertSame('portaliq', $this->provider()->getAppId());
		$this->assertSame(
			[
				'portaliq.trafficSummary',
				'portaliq.updatePortalContent',
				'portaliq.updatePageRegions',
				'portaliq.applyTheme',
				'portaliq.publishPortalPage',
				'portaliq.sendPortalMessage',
				'portaliq.revokeOrganisationSessions',
			],
			array_keys($this->tools())
		);
		foreach ($this->tools() as $id => $tool) {
			$this->assertMatchesRegularExpression('/^portaliq\.[A-Za-z]+$/', $id, 'a curated id has two segments');
			$this->assertNotSame('', $tool['codePath'], $id . ' names its production code path');
			$this->assertSame('object', $tool['inputSchema']['type']);
		}
	}//end testTheCatalogueIsExactlyTheSevenCuratedTools()

	/**
	 * Every tool declares scope and reach per the spec table, with the hints.
	 *
	 * @return void
	 */
	public function testEveryToolDeclaresScopeReachAndHints(): void {
		$expected = [
			'portaliq.trafficSummary' => ['read', 'instance'],
			'portaliq.updatePortalContent' => ['update', 'instance'],
			'portaliq.updatePageRegions' => ['update', 'instance'],
			'portaliq.applyTheme' => ['update', 'instance'],
			'portaliq.publishPortalPage' => ['update', 'instance'],
			'portaliq.sendPortalMessage' => ['create', 'external'],
			'portaliq.revokeOrganisationSessions' => ['delete', 'instance'],
		];
		$tools = $this->tools();
		foreach ($expected as $id => [$scope, $reach]) {
			$this->assertSame($scope, $tools[$id]['scope'], $id . ' scope');
			$this->assertSame($reach, $tools[$id]['reach'], $id . ' reach');
		}

		$this->assertTrue($tools['portaliq.trafficSummary']['readOnlyHint']);
		$this->assertTrue($tools['portaliq.revokeOrganisationSessions']['destructiveHint']);
		foreach ($tools as $id => $tool) {
			if ($id !== 'portaliq.trafficSummary') {
				$this->assertArrayNotHasKey('readOnlyHint', $tool, $id . ' writes, so it must not claim to be read-only');
			}
		}
	}//end testEveryToolDeclaresScopeReachAndHints()

	/**
	 * Exactly the three high-impact tools wait for a person.
	 *
	 * @return void
	 */
	public function testTheApprovalRequiredSetIsPinned(): void {
		$gated = [];
		foreach ($this->tools() as $id => $tool) {
			$this->assertIsBool($tool['requiresApproval'], $id);
			if ($tool['requiresApproval'] === true) {
				$gated[] = $id;
			}
		}

		$this->assertSame(['portaliq.publishPortalPage', 'portaliq.sendPortalMessage', 'portaliq.revokeOrganisationSessions'], $gated);
	}//end testTheApprovalRequiredSetIsPinned()

	/**
	 * Without a signed-in Nextcloud user every tool answers the same refusal
	 * and nothing behind it runs, so a portal bearer can invoke nothing.
	 *
	 * @return void
	 */
	public function testEveryToolRefusesACallWithoutANextcloudUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->groups->expects($this->never())->method('isAdmin');
		$this->editors->expects($this->never())->method('mayEdit');
		$this->sessions->expects($this->never())->method('revokeAllForOrganisation');
		$this->objects->expects($this->never())->method('save');
		$this->auditor->expects($this->never())->method('record');

		$answers = [];
		foreach (array_keys($this->tools()) as $id) {
			$answers[] = $this->provider()->invokeTool($id, ['organisation' => 'acme', 'id' => 'x', 'portal' => 'p']);
		}

		$this->assertSame(array_fill(0, 7, ['error' => 'unauthenticated', 'message' => 'A signed-in Nextcloud user is required.']), $answers);
	}//end testEveryToolRefusesACallWithoutANextcloudUser()

	/**
	 * An unknown id, including a scaffold one, is an error envelope.
	 *
	 * @return void
	 */
	public function testAnUnknownToolIsRefused(): void {
		$this->assertSame('unknown_tool', $this->provider()->invokeTool('portaliq.ping', [])['error']);
	}//end testAnUnknownToolIsRefused()

	/**
	 * A non-admin cannot revoke sessions: the revocation never runs.
	 *
	 * @return void
	 */
	public function testRevokingSessionsFailsClosedForANonAdmin(): void {
		$this->signIn(uid: 'clerk', admin: false);
		$this->sessions->expects($this->never())->method('revokeAllForOrganisation');
		$this->auditor->expects($this->never())->method('record');

		$this->assertSame('forbidden', $this->provider()->invokeTool('portaliq.revokeOrganisationSessions', ['organisation' => 'acme'])['error']);
	}//end testRevokingSessionsFailsClosedForANonAdmin()

	/**
	 * An admin's revocation answers counts only and is audited as a fact.
	 *
	 * @return void
	 */
	public function testRevokingSessionsAnswersCountsAndAuditsTheFact(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->sessions->expects($this->once())->method('revokeAllForOrganisation')
			->with('acme', 'admin')
			->willReturn(['revoked' => 3, 'failed' => 0, 'complete' => true, 'jti' => 'leak', 'claims' => ['sub' => 'x']]);
		$this->auditor->expects($this->once())->method('record')->with(
			'admin-revoke',
			'admin',
			'acme',
			'portaliq',
			'portalSession',
			'',
			'',
			'portaliq',
			['tool' => 'portaliq.revokeOrganisationSessions']
		);

		$result = $this->provider()->invokeTool('portaliq.revokeOrganisationSessions', ['organisation' => 'acme']);

		$this->assertSame(['organisation' => 'acme', 'revoked' => 3, 'failed' => 0, 'complete' => true], $result);
	}//end testRevokingSessionsAnswersCountsAndAuditsTheFact()

	/**
	 * A content edit needs a content editor and only touches allowed fields:
	 * a page takes its draft, never its live body or its portal.
	 *
	 * @return void
	 */
	public function testContentEditsAreGuardedAndWhitelisted(): void {
		$this->signIn(uid: 'editor', admin: false);
		$this->editors->method('mayEdit')->willReturnOnConsecutiveCalls(false, true, true);
		$this->objects->expects($this->never())->method('save');

		$provider = $this->provider();
		$this->assertSame('forbidden', $provider->invokeTool('portaliq.updatePortalContent', ['schema' => 'page', 'id' => 'p1', 'fields' => ['draftBody' => 'x']])['error']);

		$live = $provider->invokeTool('portaliq.updatePortalContent', ['schema' => 'page', 'id' => 'p1', 'fields' => ['body' => 'x', 'portal' => 'other']]);
		$this->assertSame('invalid_argument', $live['error']);
		$this->assertStringContainsString('body, portal', $live['message']);

		$this->assertSame('invalid_argument', $provider->invokeTool('portaliq.updatePortalContent', ['schema' => 'portalAccount', 'id' => 'a', 'fields' => ['email' => 'x']])['error']);
	}//end testContentEditsAreGuardedAndWhitelisted()

	/**
	 * A page draft edit saves the page with the new draft, without the
	 * OpenRegister envelope, and is audited without any content.
	 *
	 * @return void
	 */
	public function testADraftEditSavesTheDraftAndAuditsNoContent(): void {
		$this->signIn(uid: 'editor', admin: false);
		$this->editors->method('mayEdit')->willReturn(true);
		$this->objects->method('find')->with('page', 'p1')->willReturn(['@self' => ['id' => 'p1'], 'title' => 'Hours', 'body' => 'old', 'portal' => 'site']);
		$this->objects->expects($this->once())->method('save')
			->with('page', ['title' => 'Hours', 'body' => 'old', 'portal' => 'site', 'draftBody' => 'new'], 'p1')
			->willReturn(['id' => 'p1']);
		$this->auditor->expects($this->once())->method('record')->with(
			'update',
			'editor',
			'',
			'portaliq',
			'page',
			'p1',
			'',
			'portaliq',
			['tool' => 'portaliq.updatePortalContent']
		);

		$result = $this->provider()->invokeTool('portaliq.updatePortalContent', ['schema' => 'page', 'id' => 'p1', 'fields' => ['draftBody' => 'new']]);

		$this->assertSame(['id' => 'p1', 'schema' => 'page', 'updated' => ['draftBody']], $result);
	}//end testADraftEditSavesTheDraftAndAuditsNoContent()

	/**
	 * A content edit on an object the caller cannot see is not found, unsaved.
	 *
	 * @return void
	 */
	public function testAnInvisibleObjectIsNotFound(): void {
		$this->signIn(uid: 'editor', admin: false);
		$this->editors->method('mayEdit')->willReturn(true);
		$this->objects->method('find')->willReturn(null);
		$this->objects->expects($this->never())->method('save');

		$this->assertSame('not_found', $this->provider()->invokeTool('portaliq.updatePortalContent', ['schema' => 'menu', 'id' => 'm1', 'fields' => ['title' => 'Main']])['error']);
	}//end testAnInvisibleObjectIsNotFound()

	/**
	 * Publishing promotes the draft to the body, clears it and goes live.
	 *
	 * @return void
	 */
	public function testPublishingPromotesTheDraft(): void {
		$this->signIn(uid: 'editor', admin: false);
		$this->editors->method('mayEdit')->willReturn(true);
		$this->objects->method('find')->willReturn(['@self' => [], 'title' => 'Hours', 'body' => 'old', 'draftBody' => 'new', 'status' => 'draft']);
		$this->objects->expects($this->once())->method('save')
			->with('page', ['title' => 'Hours', 'body' => 'new', 'status' => 'published'], 'p1')
			->willReturn(['id' => 'p1']);

		$this->assertSame(['id' => 'p1', 'status' => 'published'], $this->provider()->invokeTool('portaliq.publishPortalPage', ['id' => 'p1']));
	}//end testPublishingPromotesTheDraft()

	/**
	 * A failed save is an error and leaves no audit row.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsAnErrorAndNotAudited(): void {
		$this->signIn(uid: 'editor', admin: false);
		$this->editors->method('mayEdit')->willReturn(true);
		$this->objects->method('find')->willReturn(['body' => 'old']);
		$this->objects->method('save')->willReturn(null);
		$this->auditor->expects($this->never())->method('record');

		$this->assertSame('save_failed', $this->provider()->invokeTool('portaliq.publishPortalPage', ['id' => 'p1'])['error']);
	}//end testAFailedSaveIsAnErrorAndNotAudited()

	/**
	 * Region updates keep known regions only, in render order.
	 *
	 * @return void
	 */
	public function testRegionUpdatesKeepKnownRegionsInOrder(): void {
		$this->signIn(uid: 'editor', admin: false);
		$this->editors->method('mayEdit')->willReturn(true);
		$this->objects->method('find')->with('portal', 'site')->willReturn(['slug' => 'site', 'regions' => []]);
		$widget = ['type' => 'text'];
		$this->objects->expects($this->once())->method('save')
			->with('portal', ['slug' => 'site', 'regions' => ['header' => [$widget], 'footer' => []]], 'site')
			->willReturn(['id' => 'site']);

		$result = $this->provider()->invokeTool('portaliq.updatePageRegions', ['portal' => 'site', 'regions' => ['footer' => ['junk'], 'sidebar' => [$widget], 'header' => [$widget]]]);

		$this->assertSame(['id' => 'site', 'regions' => ['header', 'footer']], $result);
	}//end testRegionUpdatesKeepKnownRegionsInOrder()

	/**
	 * A list instead of a region map is refused.
	 *
	 * @return void
	 */
	public function testARegionListIsRefused(): void {
		$this->signIn(uid: 'editor', admin: false);
		$this->editors->method('mayEdit')->willReturn(true);

		$this->assertSame('invalid_argument', $this->provider()->invokeTool('portaliq.updatePageRegions', ['portal' => 'site', 'regions' => [['type' => 'text']]])['error']);
	}//end testARegionListIsRefused()

	/**
	 * A theme the admin screen would refuse is refused here too.
	 *
	 * @return void
	 */
	public function testAThemeRefusalIsPassedOn(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->themes->method('portalBySlug')->with('site')->willReturn(['id' => 'p-1', 'slug' => 'site']);
		$this->themes->expects($this->once())->method('choose')->with(['id' => 'p-1', 'slug' => 'site'], 'dark')->willReturn(['error' => 'contrast']);
		$this->themes->expects($this->never())->method('chooseConfirmingFindings');
		$this->auditor->expects($this->never())->method('record');

		$this->assertSame('contrast', $this->provider()->invokeTool('portaliq.applyTheme', ['portal' => 'site', 'theme' => 'dark'])['error']);
	}//end testAThemeRefusalIsPassedOn()

	/**
	 * Accepting findings goes through the confirming choice, like the screen.
	 *
	 * @return void
	 */
	public function testAcceptingFindingsAppliesTheTheme(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->themes->method('portalBySlug')->willReturn(['id' => 'p-1']);
		$this->themes->method('choose')->willReturn(['error' => 'contrast']);
		$this->themes->expects($this->once())->method('chooseConfirmingFindings')->willReturn(['portal' => ['id' => 'p-1']]);

		$this->assertSame(
			['portal' => 'site', 'theme' => 'dark', 'applied' => true],
			$this->provider()->invokeTool('portaliq.applyTheme', ['portal' => 'site', 'theme' => 'dark', 'acceptFindings' => true])
		);
	}//end testAcceptingFindingsAppliesTheTheme()

	/**
	 * A theme change needs an administrator.
	 *
	 * @return void
	 */
	public function testAThemeChangeNeedsAnAdmin(): void {
		$this->signIn(uid: 'clerk', admin: false);
		$this->themes->expects($this->never())->method('choose');

		$this->assertSame('forbidden', $this->provider()->invokeTool('portaliq.applyTheme', ['portal' => 'site', 'theme' => 'dark'])['error']);
	}//end testAThemeChangeNeedsAnAdmin()

	/**
	 * A staff message lands unread in the recipient's inbox, audited with
	 * the organisation and no message text.
	 *
	 * @return void
	 */
	public function testAStaffMessageIsCreatedUnread(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->objects->expects($this->once())->method('save')
			->with(
				'portalMessage',
				$this->callback(static function (array $message): bool {
					return $message['subjectRef'] === 'dev-supplier' && $message['organisation'] === 'acme'
						&& $message['subject'] === 'Hello' && $message['body'] === 'Text' && $message['read'] === false
						&& preg_match('/^\d{4}-\d{2}-\d{2}T/', $message['receivedAt']) === 1;
				}),
				null
			)
			->willReturn(['@self' => ['id' => 'm-1']]);
		$this->auditor->expects($this->once())->method('record')->with(
			'create',
			'admin',
			'acme',
			'portaliq',
			'portalMessage',
			'm-1',
			'',
			'portaliq',
			['tool' => 'portaliq.sendPortalMessage']
		);

		$result = $this->provider()->invokeTool('portaliq.sendPortalMessage', ['subjectRef' => 'dev-supplier', 'organisation' => 'acme', 'subject' => 'Hello', 'body' => 'Text']);

		$this->assertSame(['id' => 'm-1', 'sent' => true], $result);
	}//end testAStaffMessageIsCreatedUnread()

	/**
	 * A message without a body is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnIncompleteMessageIsRefused(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->objects->expects($this->never())->method('save');

		$this->assertSame('invalid_argument', $this->provider()->invokeTool('portaliq.sendPortalMessage', ['subjectRef' => 'x', 'organisation' => 'acme', 'subject' => 'Hi'])['error']);
	}//end testAnIncompleteMessageIsRefused()

	/**
	 * The traffic summary folds the period's daily rows, as the KPI cards do.
	 *
	 * @return void
	 */
	public function testTheTrafficSummaryFoldsThePeriod(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->traffic->expects($this->once())->method('dailyBetween')
			->with('site', $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/'), $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/'))
			->willReturn([['day' => 'x']]);
		$this->numbers->method('fold')->with([['day' => 'x']])->willReturn(['pageViews' => 10, 'sessions' => 4, 'visitors' => 3, 'engagedSessions' => 2, 'bounceRate' => 0.5]);

		$result = $this->provider()->invokeTool('portaliq.trafficSummary', ['portal' => 'site', 'days' => '7']);

		$this->assertSame(7, $result['days']);
		$this->assertSame([10, 4, 3, 2], [$result['pageViews'], $result['sessions'], $result['visitors'], $result['engagedSessions']]);
		$this->assertSame(6 * 86400, strtotime($result['to']) - strtotime($result['from']));
	}//end testTheTrafficSummaryFoldsThePeriod()

	/**
	 * A period the KPI cards do not offer is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownTrafficPeriodIsRefused(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->traffic->expects($this->never())->method('dailyBetween');

		$this->assertSame('invalid_argument', $this->provider()->invokeTool('portaliq.trafficSummary', ['portal' => 'site', 'days' => '5'])['error']);
	}//end testAnUnknownTrafficPeriodIsRefused()

	/**
	 * With OpenRegister's audit trail unavailable the action still completes
	 * and the gap is logged.
	 *
	 * @return void
	 */
	public function testAnAuditOutageDoesNotBlockTheAction(): void {
		$this->signIn(uid: 'admin', admin: true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Portaliq: audit record failed', $this->arrayHasKey('verb'));
		$this->sessions->method('revokeAllForOrganisation')->willReturn(['revoked' => 1, 'failed' => 0, 'complete' => true]);

		$result = $this->provider(auditor: new AuditTrailService(container: $container, logger: $logger))
			->invokeTool('portaliq.revokeOrganisationSessions', ['organisation' => 'acme']);

		$this->assertSame(1, $result['revoked']);
	}//end testAnAuditOutageDoesNotBlockTheAction()
}//end class
