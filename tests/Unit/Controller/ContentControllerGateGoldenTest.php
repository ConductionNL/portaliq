<?php

/**
 * ContentControllerGateGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionFilter;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContentController;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * The gated content routes answer every portal and caller as recorded: the
 * 404 for no portal, the 401 and 403 that name the sign-in modes, and the
 * cache header of what is served.
 *
 * @covers \OCA\Portaliq\Controller\ContentController
 * @covers \OCA\Portaliq\Controller\PublicContentGate
 * @uses   \OCA\Portaliq\Service\PortalSessionService
 * @uses   \OCA\Portaliq\Service\Session\SessionTrust
 */
class ContentControllerGateGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/content-controller-gate.json';

	/**
	 * Each portal situation: the resolved portal (null for none) and the
	 * subject behind a bearer (null for none).
	 */
	private const SITUATIONS = [
		'unresolved' => [null, null],
		'open' => [['slug' => 'p-open'], null],
		'publicMode' => [['slug' => 'p-pub', 'authentication' => ['modes' => ['digid', 'public']]], null],
		'signInNoBearer' => [['slug' => 'p-in', 'authentication' => ['modes' => ['digid', 7], 'minTrust' => 'substantial']], null],
		'signInLowTrust' => [['slug' => 'p-in', 'authentication' => ['modes' => ['digid'], 'minTrust' => 'substantial']], ['trust' => 'low']],
		'signInEnoughTrust' => [['slug' => 'p-in', 'authentication' => ['modes' => ['digid'], 'minTrust' => 'substantial']], ['trust' => 'high']],
		'signInDefaultTrust' => [['slug' => 'p-in', 'authentication' => ['modes' => ['eherkenning']]], ['trust' => 'low']],
	];

	/**
	 * What the session was asked so far.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * Every gated route answers as recorded.
	 *
	 * @return void
	 */
	public function testEveryGatedRouteAnswersAsRecorded(): void {
		$actual = [];
		foreach (self::SITUATIONS as $name => [$portal, $subject]) {
			foreach (['anonymous' => ['', false], 'bearer' => ['Bearer t-1', false], 'editor' => ['', true]] as $who => [$header, $editor]) {
				$make = fn (): ContentController => $this->controller(portal: $portal, subject: $subject, header: $header, editor: $editor);
				$actual[$name][$who] = [
					'menus' => $this->answer(response: fn (): JSONResponse => $make()->menus(portal: 'asked')),
					'pages' => $this->answer(response: fn (): JSONResponse => $make()->pages(portal: 'asked')),
					'page' => $this->answer(response: fn (): JSONResponse => $make()->page(route: '/over', portal: 'asked')),
					'glossary' => $this->answer(response: fn (): JSONResponse => $make()->glossary(portal: 'asked')),
					'faq' => $this->answer(response: fn (): JSONResponse => $make()->faq(portal: 'asked')),
					'finder' => $this->answer(response: fn (): JSONResponse => $make()->finder(portal: 'asked', finder: 'f')),
					'contributions' => $this->answer(response: fn (): JSONResponse => $make()->contributions(portal: 'asked')),
				];
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testEveryGatedRouteAnswersAsRecorded()

	/**
	 * Status, body, cache header and the session log of one response.
	 *
	 * @param callable $response Makes the response.
	 *
	 * @return array<string, mixed>
	 */
	private function answer(callable $response): array {
		$this->log = [];
		$made = $response();
		$headers = (new ReflectionProperty(Response::class, 'headers'))->getValue($made);

		return ['status' => $made->getStatus(), 'data' => $made->getData(), 'cache' => ($headers['Cache-Control'] ?? null), 'log' => $this->log];
	}//end answer()

	/**
	 * The controller for one situation.
	 *
	 * @param array<string, mixed>|null $portal  The resolved portal.
	 * @param array<string, mixed>|null $subject The subject behind the bearer.
	 * @param string                    $header  The Authorization header.
	 * @param bool                      $editor  Whether a Nextcloud user is signed in.
	 *
	 * @return ContentController
	 */
	private function controller(?array $portal, ?array $subject, string $header, bool $editor): ContentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization') ? $header : '');
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn($portal);
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturnCallback(function (?string $authorizationHeader) use ($subject): ?array {
			$this->log[] = ['resolveFromBearer' => $authorizationHeader];
			if ($authorizationHeader === '') {
				return null;
			}

			return $subject;
		});
		$reader = $this->createMock(CmsReader::class);
		$reader->method('menus')->willReturn([['id' => 'm']]);
		$reader->method('pages')->willReturn([['id' => 'p']]);
		$reader->method('page')->willReturn(['route' => '/over']);
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateAnonymous')->willReturnCallback(function (): array {
			$this->log[] = 'aggregateAnonymous';

			return ['contributions' => [['id' => 'c-1']]];
		});
		$filter = $this->createMock(PortalContributionFilter::class);
		$filter->method('forPortal')->willReturnCallback(function (array $contributions, string $portalSlug): array {
			$this->log[] = ['forPortal' => $portalSlug];

			return $contributions;
		});
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($editor ? $this->createMock(IUser::class) : null);

		return new ContentController(
			'portaliq',
			$request,
			$resolver,
			$reader,
			$registry,
			$filter,
			$this->createMock(LoggerInterface::class),
			$session,
			$this->createMock(TrafficConfigResolver::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(PortalNoticeReader::class),
			$users
		);
	}//end controller()
}//end class
