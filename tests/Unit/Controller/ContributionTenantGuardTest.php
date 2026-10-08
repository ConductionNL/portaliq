<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\InternalBaseUrl;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalInboxReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * operate-portals-per-organisation REQ-OPO-002: a session that names no
 * organisation reads nothing of an organisation-scoped schema, and a schema
 * scoped by subject is left as it was.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t04
 */
class ContributionTenantGuardTest extends TestCase {

	private MockObject $reader;

	private function controller(string $organisation, string $schema, ?PortalResolver $portals=null): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer t');
		$request->method('getParam')->willReturn('');
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'portaliq', 'collections' => [['id' => 'c', 'register' => 'portaliq', 'schema' => $schema, 'scopeField' => 'subjectRef']]]]]);
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(['subjectRef' => 's1', 'audience' => 'client', 'organisation' => $organisation, 'trust' => 'low', 'roles' => [], 'jti' => 'j']);
		$this->reader = $this->createMock(PortalObjectReader::class);
		$this->reader->method('readCollection')->willReturn([['id' => 'x']]);
		$this->reader->method('readObject')->willReturn(['id' => 'x']);

		return new ContributionController(
			$request,
			$registry,
			$session,
			$this->reader,
			$this->createMock(PortalObjectWriter::class),
			$this->createMock(PortalFileWriter::class),
			$this->createMock(PortalFileReader::class),
			$this->createMock(PortalSchemaReader::class),
			$this->createMock(PortalInboxReader::class),
			$this->createMock(PortalAuditHook::class),
			new PortalActionForwarder($request, new InstanceLoopback($this->createMock(IClientService::class), $this->createMock(IURLGenerator::class), $this->createMock(InternalBaseUrl::class), $this->createMock(LoggerInterface::class)), $session),
			$this->createMock(AuditTrailService::class),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class),
			portals: $portals
		);
	}

	public function testSubjectWithoutOrganisationGetsNothing(): void {
		$controller = $this->controller(organisation: '', schema: 'portalMessage');
		$this->reader->expects($this->never())->method('readCollection');
		$this->reader->expects($this->never())->method('readObject');

		$this->assertSame([], $controller->collection('portaliq', 'portalMessage')->getData()['objects']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->object('portaliq', 'portalMessage', 'x')->getStatus());

	}//end testSubjectWithoutOrganisationGetsNothing()

	public function testASubjectWithAnOrganisationReadsAsBefore(): void {
		$controller = $this->controller(organisation: 'org-a', schema: 'portalMessage');

		$this->assertCount(1, $controller->collection('portaliq', 'portalMessage')->getData()['objects']);
		$this->assertSame(Http::STATUS_OK, $controller->object('portaliq', 'portalMessage', 'x')->getStatus());

	}//end testASubjectWithAnOrganisationReadsAsBefore()

	/**
	 * Own-area pages are announced only for a portal that switched them on.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t08
	 */
	public function testAreaPagesAreAnnouncedOnlyWhenThePortalSwitchedThemOn(): void {
		$cases = [
			[['contactsEnabled' => true], ['contacts']],
			[['plansEnabled' => true], ['samenwerken']],
			[['contactsEnabled' => true, 'plansEnabled' => true], ['contacts', 'samenwerken']],
			[['contactsEnabled' => 'yes', 'plansEnabled' => 1], []],
			[[], []],
		];
		foreach ($cases as [$portal, $expected]) {
			$resolver = $this->createMock(PortalResolver::class);
			$resolver->method('resolve')->willReturn($portal);
			$controller = $this->controller(organisation: 'org-a', schema: 'portalMessage', portals: $resolver);
			$method = new \ReflectionMethod($controller, 'areaPages');
			$this->assertSame($expected, array_column($method->invoke($controller), 'special'));
		}

		$bare = $this->controller(organisation: 'org-a', schema: 'portalMessage');
		$this->assertSame([], (new \ReflectionMethod($bare, 'areaPages'))->invoke($bare));
	}//end testAreaPagesAreAnnouncedOnlyWhenThePortalSwitchedThemOn()

	public function testSubjectScopedSchemaUnchanged(): void {
		$controller = $this->controller(organisation: '', schema: 'pushSubscription');

		$this->assertCount(1, $controller->collection('portaliq', 'pushSubscription')->getData()['objects']);

	}//end testSubjectScopedSchemaUnchanged()
}//end class
