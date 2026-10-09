<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Portaliq\Controller\AccessibilityController;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Cms\AccessibilityMeasurements;
use OCA\Portaliq\Service\Cms\AccessibilityRun;
use OCA\Portaliq\Service\Cms\AccessibilityStatement;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * site-accessibility-statement: the measurement is stored with its evidence
 * by a manager only, an unmeasured page stays unmeasured, the audit cannot
 * claim beyond itself, and the statement is public.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
 */
class AccessibilityControllerTest extends TestCase {
	use PortalIdentityStoreTrait;

	private const NOW = '2026-10-09T12:00:00+00:00';

	/** @var bool Whether the signed-in user is an administrator. */
	private bool $admin = true;

	/** @var list<string> The signed-in user's groups. */
	private array $groups = [];

	protected function setUp(): void {
		$this->rows   = [];
		$this->admin  = true;
		$this->groups = [];
		$this->seedRow('portal', [
			'title' => 'Open Tilburg',
			'slug' => 'open-tilburg',
			'status' => 'published',
			'organisation' => 'tilburg',
			'theme' => 'vng',
		]);
	}//end setUp()

	public function testARunIsStoredWithItsEvidence(): void {
		$response = $this->controller()->store(
			slug: 'open-tilburg',
			axeVersion: '4.10.3',
			tags: AccessibilityStatement::TAGS,
			theme: 'vng',
			pages: [
				['url' => '/', 'measured' => true, 'violations' => [['rule' => 'color-contrast', 'impact' => 'serious', 'nodes' => 3, 'help' => 'Contrast', 'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.10/color-contrast', 'html' => '<p>dropped</p>']]],
				['url' => '/zoeken', 'measured' => true, 'violations' => []],
			]
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$stored = $this->storedRows('accessibilityMeasurement');
		$this->assertCount(1, $stored);
		$row = array_values($stored)[0];
		$this->assertSame('open-tilburg', $row['portal']);
		$this->assertSame('2026-10-09T12:00:00+00:00', $row['measuredAt']);
		$this->assertSame('beheer', $row['measuredBy']);
		$this->assertSame('4.10.3', $row['axeVersion']);
		$this->assertSame(AccessibilityStatement::TAGS, $row['tags']);
		$this->assertSame('vng', $row['theme']);
		$this->assertCount(2, $row['pages']);
		$this->assertArrayNotHasKey('html', $row['pages'][0]['violations'][0]);
		$this->assertValidAgainstSchema(row: $row, schema: 'accessibilityMeasurement');

		// The public statement now names the issue and the date.
		$statement = $this->controller()->statement(portal: 'open-tilburg', locale: 'en')->getData();
		$this->assertSame('color-contrast', $statement['statement']['issues'][0]['rule']);
		$this->assertSame('2026-10-09T12:00:00+00:00', $statement['statement']['measurement']['measuredAt']);
		$this->assertSame('C', $statement['statement']['status']);
	}//end testARunIsStoredWithItsEvidence()

	public function testANonManagerIsRefused(): void {
		$this->admin = false;
		$this->groups = ['residents'];

		$response = $this->controller()->store(slug: 'open-tilburg', axeVersion: '4.10.3', tags: AccessibilityStatement::TAGS, theme: 'vng', pages: [['url' => '/', 'measured' => true]]);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->storedRows('accessibilityMeasurement'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->index(slug: 'open-tilburg')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->update(slug: 'open-tilburg', audit: [], registerUrl: '', pages: [])->getStatus());

		// A group the matrix names may measure.
		$this->groups = ['portal-managers'];
		$response = $this->controller(matrix: ['portal.measure-accessibility' => ['admin', 'portal-managers']])
			->store(slug: 'open-tilburg', axeVersion: '4.10.3', tags: AccessibilityStatement::TAGS, theme: 'vng', pages: [['url' => '/', 'measured' => true]]);
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testANonManagerIsRefused()

	public function testAnUnmeasuredPageIsKeptAsNotMeasured(): void {
		$this->controller()->store(
			slug: 'open-tilburg',
			axeVersion: '4.10.3',
			tags: AccessibilityStatement::TAGS,
			theme: 'vng',
			pages: [
				['url' => '/', 'measured' => true, 'violations' => []],
				['url' => '/extern', 'measured' => false, 'reason' => 'The page refused to load in a frame.', 'violations' => [['rule' => 'image-alt', 'impact' => 'critical', 'nodes' => 1]]],
				['url' => '/stil', 'measured' => 'yes'],
			]
		);

		$row = array_values($this->storedRows('accessibilityMeasurement'))[0];
		$this->assertSame(['url' => '/extern', 'measured' => false, 'reason' => 'The page refused to load in a frame.'], $row['pages'][1]);
		$this->assertSame(['url' => '/stil', 'measured' => false, 'reason' => AccessibilityRun::NO_REASON], $row['pages'][2]);
		$this->assertValidAgainstSchema(row: $row, schema: 'accessibilityMeasurement');

		$statement = $this->controller()->statement(portal: 'open-tilburg', locale: 'nl')->getData()['statement'];
		$this->assertSame([], $statement['issues']);
		$this->assertSame(['/extern', '/stil'], array_column($statement['notMeasured'], 'url'));
	}//end testAnUnmeasuredPageIsKeptAsNotMeasured()

	public function testAnAOrBClaimNeedsACompleteRecentAudit(): void {
		$controller = $this->controller();

		$refused = $controller->update(slug: 'open-tilburg', audit: ['result' => 'A'], registerUrl: '', pages: []);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame('audit_incomplete', $refused->getData()['error']);

		$old = $controller->update(slug: 'open-tilburg', audit: ['party' => 'Stichting', 'date' => '2022-01-01', 'reportUrl' => 'https://example.nl/r.pdf', 'result' => 'B'], registerUrl: '', pages: []);
		$this->assertSame('audit_expired', $old->getData()['error']);

		$saved = $controller->update(
			slug: 'open-tilburg',
			audit: ['party' => 'Stichting', 'date' => '2026-06-01', 'reportUrl' => 'https://example.nl/r.pdf', 'result' => 'B'],
			registerUrl: 'https://www.toegankelijkheidsverklaring.nl/register/1',
			pages: ['/contact', 'https://elders.nl', '/contact']
		);
		$this->assertSame(Http::STATUS_OK, $saved->getStatus());
		$portal = array_values($this->storedRows('portal'))[0];
		$this->assertSame(['/contact'], $portal['accessibilityPages']);
		$this->assertValidAgainstSchema(row: $portal, schema: 'portal');
		$this->assertSame(['/', '/zoeken', AccessibilityMeasurements::NOT_FOUND_PROBE, '/contact'], $saved->getData()['pages']);
		$this->assertSame('B', $saved->getData()['statement']['status']);

		// Clearing the audit stores an empty object, which the schema admits.
		$controller->update(slug: 'open-tilburg', audit: [], registerUrl: '', pages: []);
		$this->assertValidAgainstSchema(row: array_values($this->storedRows('portal'))[0], schema: 'portal');
	}//end testAnAOrBClaimNeedsACompleteRecentAudit()

	public function testTheStatementIsPublicAndAnUnknownPortalIsNotFound(): void {
		$this->assertSame(Http::STATUS_OK, $this->controller(signedIn: false)->statement(portal: 'open-tilburg', locale: 'nl')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(signedIn: false)->statement(portal: 'nergens', locale: 'nl')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(signedIn: false)->store(slug: 'open-tilburg', axeVersion: '4', tags: [], theme: '', pages: [])->getStatus());
	}//end testTheStatementIsPublicAndAnUnknownPortalIsNotFound()

	/**
	 * @param array<string, list<string>> $matrix   The action matrix.
	 * @param bool                        $signedIn Whether a user is signed in.
	 */
	private function controller(array $matrix = [], bool $signedIn = true): AccessibilityController {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn((string)json_encode($matrix));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (): bool => $this->admin);
		$groups->method('getUserGroupIds')->willReturnCallback(fn (): array => $this->groups);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		$measurements = new AccessibilityMeasurements($this->fakeReader(), $this->fakeWriter());
		$resolver     = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturnCallback(fn ($request, ?string $portalSlug = null): ?array => $measurements->portalBySlug(slug: (string)$portalSlug));

		return new AccessibilityController(
			$this->createMock(IRequest::class),
			$measurements,
			new AccessibilityRun(),
			new AccessibilityStatement(),
			new ActionAuthService($config, $groups),
			$session,
			$resolver,
			$clock
		);
	}//end controller()

	/**
	 * @param array<string, mixed> $row    The stored row.
	 * @param string               $schema The register schema.
	 */
	private function assertValidAgainstSchema(array $row, string $schema): void {
		unset($row['uuid'], $row['_schema'], $row['id']);
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/portaliq_register.json'), true);
		$fragment = $register['components']['schemas'][$schema];
		$json     = json_decode((string)json_encode(['type' => 'object', 'required' => $fragment['required'], 'properties' => $fragment['properties']]), false);
		$result   = (new Validator())->validate(json_decode((string)json_encode($row), false), $json);
		$this->assertTrue($result->isValid(), $schema.' row fits the register schema: '.json_encode($result->error()?->args()));
	}//end assertValidAgainstSchema()
}//end class
