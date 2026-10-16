<?php

/**
 * nextcloud-account-registration: a visitor makes a Nextcloud account
 * through Nextcloud's registration app, and the portal admits them.
 *
 * Every assertion goes through the real caller: the door through
 * `NextcloudRegistrationDoor`, the first sign-in through
 * `SessionController::nextcloud()` with the real door and provisioner behind it, the
 * way back through `DashboardController::page()`.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Controller\DashboardController;
use OCA\Portaliq\Controller\SessionController;
use OCA\Portaliq\Service\AdminMenuAccess;
use OCA\Portaliq\Service\Identity\NextcloudAccountProvisioner;
use OCA\Portaliq\Service\Identity\NextcloudRegistrationDoor;
use OCA\Portaliq\Service\OidcClaimMapperService;
use OCA\Portaliq\Service\OidcClientService;
use OCA\Portaliq\Service\OidcStateStoreService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The registration door, the first sign-in and the way back.
 */
class NextcloudAccountRegistrationTest extends TestCase {
	/**
	 * The registration app's form, as the URL generator builds it.
	 */
	private const FORM = 'https://cloud.example/index.php/apps/registration/';

	/**
	 * A portal that signs residents in with a Nextcloud account and names its group.
	 */
	private const PORTAL = [
		'slug'           => 'gemeente',
		'organisation'   => 'gemeente-org',
		'authentication' => [
			'modes'        => ['nextcloud'],
			'registration' => ['residentGroup' => 'inwoners'],
		],
	];

	/**
	 * PORTAL with a business group for people who act for a company.
	 */
	private const BUSINESS_PORTAL = [
		'slug'           => 'gemeente',
		'organisation'   => 'gemeente-org',
		'authentication' => [
			'modes'        => ['nextcloud'],
			'registration' => ['residentGroup' => 'inwoners', 'businessGroup' => 'ondernemers'],
		],
	];

	/**
	 * The groups each user is in.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $memberships = ['sanne' => ['inwoners'], 'karin' => ['ondernemers'], 'clerk' => ['medewerkers'], 'admin' => ['admin', 'inwoners']];

	/**
	 * Whether the registration app is enabled.
	 *
	 * @var bool
	 */
	private bool $registrationEnabled = true;

	/**
	 * The group the registration app puts new users in.
	 *
	 * @var string
	 */
	private string $registrationGroup = 'inwoners';

	/**
	 * The accounts the register holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * The accounts written during the test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * The door under test, on its doubles.
	 *
	 * @param array<int, array<string, mixed>> $published   The published portals.
	 * @param NextcloudAccountProvisioner|null  $provisioner Writes a resident's first account.
	 *
	 * @return NextcloudRegistrationDoor
	 */
	private function door(array $published = [self::PORTAL], ?NextcloudAccountProvisioner $provisioner = null): NextcloudRegistrationDoor {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturnCallback(fn (string $app): bool => $app === 'registration' && $this->registrationEnabled);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'registration' && $key === 'registered_user_group') ? $this->registrationGroup : $default
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => $route === 'registration.register.showEmailForm' ? self::FORM : 'https://cloud.example/other'
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => in_array($group, ($this->memberships[$uid] ?? []), true));
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array('admin', ($this->memberships[$uid] ?? []), true));

		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn($published);

		return new NextcloudRegistrationDoor($apps, $appConfig, $urls, $groups, $portals, $provisioner);
	}//end door()

	/**
	 * A portal like PORTAL with a change to its `authentication`.
	 *
	 * @param array<string, mixed> $authentication The replacement block.
	 *
	 * @return array<string, mixed>
	 */
	private static function portalWith(array $authentication): array {
		return array_merge(self::PORTAL, ['authentication' => $authentication]);
	}//end portalWith()

	/**
	 * The door leads to the registration app's own form for a portal that
	 * offers the account route and names the group the app fills.
	 *
	 * @return void
	 */
	public function testTheDoorLeadsToTheRegistrationForm(): void {
		$this->assertSame(self::FORM, $this->door()->registerUrl(portal: self::PORTAL));
	}//end testTheDoorLeadsToTheRegistrationForm()

	/**
	 * A portal that names no group, does not offer the account route, or
	 * offers only the e-mail link has no door; neither has one whose group
	 * the registration app does not fill, nor one on an instance without
	 * the app.
	 *
	 * @return void
	 */
	public function testTheDoorStaysShutWhenItWouldLeadNowhere(): void {
		$door = $this->door();
		$this->assertSame('', $door->registerUrl(portal: self::portalWith(['modes' => ['nextcloud']])), 'no group named');
		$this->assertSame('', $door->registerUrl(portal: self::portalWith(['modes' => ['digid'], 'registration' => ['residentGroup' => 'inwoners']])), 'no account route');
		// REQ-IWI-014: the e-mail link never opens registration.
		$this->assertSame('', $door->registerUrl(portal: self::portalWith(['modes' => ['email-link'], 'registration' => ['residentGroup' => 'inwoners', 'policy' => 'activation']])), 'e-mail link only');

		$this->registrationGroup = 'none';
		$this->assertSame('', $this->door()->registerUrl(portal: self::PORTAL), 'the app adds to no group');

		$this->registrationGroup = 'medewerkers';
		$this->assertSame('', $this->door()->registerUrl(portal: self::PORTAL), 'the app fills another group');

		$this->registrationGroup = 'inwoners';
		$this->registrationEnabled = false;
		$this->assertSame('', $this->door()->registerUrl(portal: self::PORTAL), 'the app is not enabled');
	}//end testTheDoorStaysShutWhenItWouldLeadNowhere()

	/**
	 * A setting stored under another type shuts the door instead of failing the site.
	 *
	 * @return void
	 */
	public function testAnUnreadableGroupSettingShutsTheDoor(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(new AppConfigTypeConflictException('typed as int'));
		$door = new NextcloudRegistrationDoor(
			$apps,
			$appConfig,
			$this->createMock(IURLGenerator::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(PortalResolver::class)
		);

		$this->assertSame('', $door->registerUrl(portal: self::PORTAL));
	}//end testAnUnreadableGroupSettingShutsTheDoor()

	/**
	 * The resident audience is the portal's, or `citizen`.
	 *
	 * @return void
	 */
	public function testTheResidentAudienceDefaultsToCitizen(): void {
		$door = $this->door();
		$this->assertSame('citizen', $door->residentAudience(portal: self::PORTAL));
		$this->assertSame(
			'inwoner',
			$door->residentAudience(portal: self::portalWith(['modes' => ['nextcloud'], 'registration' => ['residentGroup' => 'inwoners', 'residentAudience' => 'inwoner']]))
		);
	}//end testTheResidentAudienceDefaultsToCitizen()

	/**
	 * The business group is read like the resident group, with its own
	 * audience (default `supplier`), and only on a portal with the account
	 * route.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/business-audience-group/specs/portal-nextcloud-account-login/spec.md#requirement-a-portal-may-name-a-business-group-with-its-own-audience-req-ncl-008
	 */
	public function testTheBusinessGroupCarriesItsOwnAudience(): void {
		$door = $this->door();
		$this->assertSame('ondernemers', $door->businessGroup(portal: self::BUSINESS_PORTAL));
		$this->assertSame('supplier', $door->businessAudience(portal: self::BUSINESS_PORTAL));
		$this->assertSame(['inwoners' => 'citizen', 'ondernemers' => 'supplier'], $door->portalGroups(portal: self::BUSINESS_PORTAL));
		$this->assertSame('supplier', $door->audienceOf(portal: self::BUSINESS_PORTAL, uid: 'karin'));
		$this->assertSame('citizen', $door->audienceOf(portal: self::BUSINESS_PORTAL, uid: 'sanne'));
		$this->assertSame('', $door->audienceOf(portal: self::BUSINESS_PORTAL, uid: 'clerk'), 'staff are in neither group');
		$this->assertSame('', $door->audienceOf(portal: self::PORTAL, uid: 'karin'), 'control: a portal without a business group does not know her');

		$named = self::portalWith(['modes' => ['nextcloud'], 'registration' => ['businessGroup' => 'ondernemers', 'businessAudience' => 'business']]);
		$this->assertSame(['ondernemers' => 'business'], $door->portalGroups(portal: $named));

		$noAccountRoute = self::portalWith(['modes' => ['digid'], 'registration' => ['businessGroup' => 'ondernemers']]);
		$this->assertSame('', $door->businessGroup(portal: $noAccountRoute));
		$this->assertSame([], $door->portalGroups(portal: $noAccountRoute));

		$same = self::portalWith(['modes' => ['nextcloud'], 'registration' => ['residentGroup' => 'inwoners', 'businessGroup' => 'inwoners']]);
		$this->assertSame(['inwoners' => 'citizen'], $door->portalGroups(portal: $same), 'one group is one audience: the resident one');
	}//end testTheBusinessGroupCarriesItsOwnAudience()

	/**
	 * The registration app never fills the business group: a registration
	 * app set to it opens no "Create an account" link.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/business-audience-group/specs/portal-nextcloud-account-login/spec.md#requirement-a-portal-may-name-a-business-group-with-its-own-audience-req-ncl-008
	 */
	public function testTheBusinessGroupOpensNoRegistrationLink(): void {
		$this->registrationGroup = 'ondernemers';
		$this->assertSame('', $this->door()->registerUrl(portal: self::BUSINESS_PORTAL));

		$this->registrationGroup = 'inwoners';
		$this->assertSame(self::FORM, $this->door()->registerUrl(portal: self::BUSINESS_PORTAL), 'control: the resident group still opens it');
	}//end testTheBusinessGroupOpensNoRegistrationLink()

	/**
	 * The session controller on the real door and the real provisioner.
	 *
	 * @param string                  $uid      The signed-in Nextcloud user.
	 * @param array<string, mixed>    $portal   The portal being signed in to.
	 * @param PortalSessionService    $session  The session double.
	 *
	 * @return SessionController
	 */
	private function sessionController(string $uid, array $portal, PortalSessionService $session): SessionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($uid . '@example.org');
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn($portal);
		$resolver->method('allPublishedPortals')->willReturn([$portal]);

		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->method('findBySubjectRef')->willReturnCallback(
			function (string $subjectRef): ?array {
				foreach (array_merge($this->rows, $this->written) as $row) {
					if (($row['subjectRef'] ?? null) === $subjectRef) {
						return $row;
					}
				}

				return null;
			}
		);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			fn (string $register, string $schema, string $scopeField, string $subjectRef): array => array_values(
				array_filter(array_merge($this->rows, $this->written), static fn (array $row): bool => ($row[$scopeField] ?? null) === $subjectRef)
			)
		);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array {
				$this->written[] = $data;
				return $data;
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example' . $url);

		return new SessionController(
			$this->createMock(IRequest::class),
			$session,
			$this->createMock(IConfig::class),
			$this->createMock(PortalOrganisationConfigService::class),
			$this->createMock(OidcClientService::class),
			$this->createMock(OidcClaimMapperService::class),
			$this->createMock(OidcStateStoreService::class),
			$accounts,
			$urls,
			$userSession,
			$resolver,
			null,
			$this->door(published: [$portal], provisioner: new NextcloudAccountProvisioner($reader, $writer, $users, $resolver))
		);
	}//end sessionController()

	/**
	 * A resident who just activated their account signs in through the
	 * account route: an active `citizen` account is written under their user
	 * id in the portal's organisation, and the session's trust stays `low`.
	 *
	 * @return void
	 */
	public function testAResidentGetsTheirAccountOnFirstSignIn(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->once())
			->method('issueSession')
			->with('sanne', 'citizen', 'gemeente-org', 'low', ['citizen:read'])
			->willReturn(['token' => 'tok']);

		$response = $this->sessionController(uid: 'sanne', portal: self::PORTAL, session: $session)->nextcloud(portal: 'gemeente');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('https://cloud.example/apps/portaliq/site?portal=gemeente#token=tok', $response->getRedirectURL());
		$this->assertCount(1, $this->written);
		$this->assertSame('sanne', $this->written[0]['subjectRef']);
		$this->assertSame('citizen', $this->written[0]['audience']);
		$this->assertSame('gemeente-org', $this->written[0]['organisation']);
		$this->assertSame('active', $this->written[0]['status']);
		$this->assertSame('portaliq', $this->written[0]['provisionedBy']);
		$this->assertFalse($this->written[0]['verifiedEmail']);
	}//end testAResidentGetsTheirAccountOnFirstSignIn()

	/**
	 * A business contact signing in through the account route gets an
	 * account with the business audience, and a session for it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/business-audience-group/specs/portal-nextcloud-account-login/spec.md#requirement-a-portal-may-name-a-business-group-with-its-own-audience-req-ncl-008
	 */
	public function testABusinessContactGetsAnAccountWithTheBusinessAudience(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->once())
			->method('issueSession')
			->with('karin', 'supplier', 'gemeente-org', 'low', ['supplier:read'])
			->willReturn(['token' => 'tok']);

		$response = $this->sessionController(uid: 'karin', portal: self::BUSINESS_PORTAL, session: $session)->nextcloud(portal: 'gemeente');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertCount(1, $this->written);
		$this->assertSame('karin', $this->written[0]['subjectRef']);
		$this->assertSame('supplier', $this->written[0]['audience']);
	}//end testABusinessContactGetsAnAccountWithTheBusinessAudience()

	/**
	 * Control: on a portal that names no business group, the same business
	 * contact is refused and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/business-audience-group/specs/portal-nextcloud-account-login/spec.md#requirement-a-portal-may-name-a-business-group-with-its-own-audience-req-ncl-008
	 */
	public function testABusinessContactIsRefusedWhereNoBusinessGroupIsNamed(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');

		$response = $this->sessionController(uid: 'karin', portal: self::PORTAL, session: $session)->nextcloud(portal: 'gemeente');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->written);
	}//end testABusinessContactIsRefusedWhereNoBusinessGroupIsNamed()

	/**
	 * A Nextcloud user outside the resident group is still refused, and
	 * nothing is written.
	 *
	 * @return void
	 */
	public function testAWorkplaceUserIsNotMadeAResident(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');

		$response = $this->sessionController(uid: 'clerk', portal: self::PORTAL, session: $session)->nextcloud(portal: 'gemeente');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'no_portal_account'], $response->getData());
		$this->assertSame([], $this->written);
	}//end testAWorkplaceUserIsNotMadeAResident()

	/**
	 * A portal that names no resident group admits nobody new, members or not.
	 *
	 * @return void
	 */
	public function testAPortalWithoutAResidentGroupAdmitsNobodyNew(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');

		$response = $this->sessionController(uid: 'sanne', portal: self::portalWith(['modes' => ['nextcloud']]), session: $session)->nextcloud(portal: 'gemeente');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->written);
	}//end testAPortalWithoutAResidentGroupAdmitsNobodyNew()

	/**
	 * An e-mail-link portal naming a group is not entered through the account
	 * route at all (REQ-NCL-001 stands, REQ-IWI-014 stands).
	 *
	 * @return void
	 */
	public function testAnEmailLinkPortalIsNotEnteredThroughTheAccountRoute(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');
		$portal = self::portalWith(['modes' => ['email-link'], 'registration' => ['residentGroup' => 'inwoners']]);

		$response = $this->sessionController(uid: 'sanne', portal: $portal, session: $session)->nextcloud(portal: 'gemeente');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->written);
	}//end testAnEmailLinkPortalIsNotEnteredThroughTheAccountRoute()

	/**
	 * A resident whose address another account in the tenant already holds
	 * is refused; the person is not split in two.
	 *
	 * @return void
	 */
	public function testAnAddressAnotherAccountHoldsIsNotTakenOver(): void {
		$this->rows[] = ['subjectRef' => 'minted-1', 'email' => 'sanne@example.org', 'organisation' => 'gemeente-org', 'status' => 'pending'];
		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');

		$response = $this->sessionController(uid: 'sanne', portal: self::PORTAL, session: $session)->nextcloud(portal: 'gemeente');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->written);
	}//end testAnAddressAnotherAccountHoldsIsNotTakenOver()

	/**
	 * The dashboard controller for a signed-in user.
	 *
	 * @param string                           $uid       The user.
	 * @param array<int, array<string, mixed>> $published The published portals.
	 *
	 * @return DashboardController
	 */
	private function dashboard(string $uid, array $published = [self::PORTAL]): DashboardController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $parameters = []): string => '/' . $route . '?' . http_build_query($parameters)
		);

		// Staff here: the clerk and the administrator are staff, so the staff
		// check (close-portaliq-register-to-staff) lets them keep the app.
		$access = $this->createMock(AdminMenuAccess::class);
		$access->method('isStaff')->willReturn(true);

		return new DashboardController($this->createMock(IRequest::class), $access, null, $this->door(published: $published), $userSession, $urls);
	}//end dashboard()

	/**
	 * A resident who lands on the workplace (the registration app ends on
	 * the default page) is sent to their portal's account route.
	 *
	 * @return void
	 */
	public function testAResidentOnTheWorkplaceIsSentBackToTheirPortal(): void {
		$response = $this->dashboard(uid: 'sanne')->page();

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/portaliq.session.nextcloud?portal=gemeente', $response->getRedirectURL());
		$this->assertInstanceOf(RedirectResponse::class, $this->dashboard(uid: 'sanne')->catchAll());
	}//end testAResidentOnTheWorkplaceIsSentBackToTheirPortal()

	/**
	 * A business contact who lands on the workplace is sent to their portal
	 * too, and only where the portal names the business group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/business-audience-group/specs/portal-nextcloud-account-login/spec.md#requirement-a-portal-may-name-a-business-group-with-its-own-audience-req-ncl-008
	 */
	public function testABusinessContactOnTheWorkplaceIsSentBackToTheirPortal(): void {
		$response = $this->dashboard(uid: 'karin', published: [self::BUSINESS_PORTAL])->page();

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/portaliq.session.nextcloud?portal=gemeente', $response->getRedirectURL());
		$this->assertNotInstanceOf(RedirectResponse::class, $this->dashboard(uid: 'karin')->page(), 'control: no business group, no way back');
	}//end testABusinessContactOnTheWorkplaceIsSentBackToTheirPortal()

	/**
	 * Staff and administrators keep the app, even an administrator who is
	 * also in the resident group.
	 *
	 * @return void
	 */
	public function testStaffAndAdministratorsKeepTheApp(): void {
		foreach (['clerk', 'admin'] as $uid) {
			$response = $this->dashboard(uid: $uid)->page();
			$this->assertInstanceOf(TemplateResponse::class, $response, $uid);
			$this->assertSame('index', $response->getTemplateName(), $uid);
		}
	}//end testStaffAndAdministratorsKeepTheApp()
}//end class
