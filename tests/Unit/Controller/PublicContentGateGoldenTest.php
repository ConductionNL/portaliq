<?php

/**
 * Golden outcomes of the public content gate on the catalogue and news reads:
 * every read answers an unknown portal, an anonymous caller of a sign-in
 * portal, a caller whose trust is too low and a permitted caller exactly as
 * recorded, and a refused read never reaches the reader.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ContentCatalogueController;
use OCA\Portaliq\Controller\ContentNewsController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PublicCatalogue;
use OCA\Portaliq\Service\PublicNewsReader;
use OCA\Portaliq\Service\Session\BearerSessionResolver;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The gate's allowed and refused outcomes, per read.
 */
class PublicContentGateGoldenTest extends TestCase {
	/**
	 * The callers, as [portal modes or null for no portal, bearer, subject].
	 *
	 * @return array<string, array{0: array<int, string>|null, 1: string, 2: array<string, mixed>|null}>
	 */
	private static function callers(): array {
		return [
			'unknownPortal' => [null, '', null],
			'anonymous'     => [['digid'], '', null],
			'lowTrust'      => [['digid'], 'Bearer x', ['trust' => 'low']],
			'signedIn'      => [['digid'], 'Bearer x', ['trust' => 'substantial']],
			'public'        => [['public'], '', null],
		];
	}//end callers()

	/**
	 * The recorded answer per read and caller: status, body and Cache-Control.
	 *
	 * @return array<string, array{0: int, 1: array<string, mixed>, 2: string}>
	 */
	private static function golden(): array {
		$missing = [404, ['error' => 'not_found'], 'private, no-store'];
		$signIn  = [401, ['error' => 'authentication_required', 'authentication' => ['modes' => ['digid']]], 'private, no-store'];
		$trust   = [403, ['error' => 'insufficient_trust', 'authentication' => ['modes' => ['digid']]], 'private, no-store'];
		$golden  = [];
		$allowed = [
			'catalogue.kinds'  => ['kinds' => [['kind' => 'news']]],
			'catalogue.detail' => ['title' => 'T'],
			'news.index'       => ['items' => [['id' => 'n1']]],
			'news.show'        => ['item' => ['id' => 'n1']],
		];
		foreach ($allowed as $read => $body) {
			$golden[$read.'.unknownPortal'] = $missing;
			$golden[$read.'.anonymous']     = $signIn;
			$golden[$read.'.lowTrust']      = $trust;
			$golden[$read.'.signedIn']      = [200, $body, 'private, no-store'];
			$golden[$read.'.public']        = [200, $body, 'public, max-age=300, must-revalidate'];
		}

		return $golden;
	}//end golden()

	/**
	 * Build a catalogue controller for one caller.
	 *
	 * @param array{0: array<int, string>|null, 1: string, 2: array<string, mixed>|null} $caller The caller.
	 *
	 * @return array{0: ContentCatalogueController, 1: \PHPUnit\Framework\MockObject\MockObject}
	 */
	private function catalogue(array $caller): array {
		$reader = $this->createMock(PublicCatalogue::class);
		$reader->method('kindsFor')->willReturn([['kind' => 'news']]);
		$reader->method('detailFor')->willReturn(['title' => 'T']);
		$reader->method('itemsFor')->willReturn([]);
		[$request, $resolver, $session] = $this->doubles($caller);

		return [new ContentCatalogueController('portaliq', $request, $resolver, $session, $reader), $reader];
	}//end catalogue()

	/**
	 * Build a news controller for one caller.
	 *
	 * @param array{0: array<int, string>|null, 1: string, 2: array<string, mixed>|null} $caller The caller.
	 *
	 * @return array{0: ContentNewsController, 1: \PHPUnit\Framework\MockObject\MockObject}
	 */
	private function news(array $caller): array {
		$reader = $this->createMock(PublicNewsReader::class);
		$reader->method('listFor')->willReturn([['id' => 'n1']]);
		$reader->method('itemFor')->willReturn(['id' => 'n1']);
		[$request, $resolver, $session] = $this->doubles($caller);

		return [new ContentNewsController('portaliq', $request, $resolver, $session, $reader), $reader];
	}//end news()

	/**
	 * The request, resolver and session doubles for one caller.
	 *
	 * @param array{0: array<int, string>|null, 1: string, 2: array<string, mixed>|null} $caller The caller.
	 *
	 * @return array{0: IRequest, 1: PortalResolver, 2: BearerSessionResolver}
	 */
	private function doubles(array $caller): array {
		[$modes, $bearer, $subject] = $caller;
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization') ? $bearer : '');
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => $default);
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturnCallback(
			static fn (IRequest $request, ?string $portalSlug): ?array => ($modes === null || $portalSlug !== 'asked') ? null : ['slug' => 'asked', 'authentication' => ['modes' => $modes, 'minTrust' => 'substantial']]
		);
		$session = $this->createMock(BearerSessionResolver::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return [$request, $resolver, $session];
	}//end doubles()

	/**
	 * The Cache-Control header the controller itself set.
	 *
	 * @param Response $response The response.
	 *
	 * @return string
	 */
	private function cacheControl(Response $response): string {
		$headers = \Closure::bind(fn (): ?array => $this->headers, $response, Response::class)();

		return (string)($headers['Cache-Control'] ?? '');
	}//end cacheControl()

	/**
	 * One read of one caller, as [status, body, Cache-Control].
	 *
	 * @param string                                                                     $read   The read.
	 * @param array{0: array<int, string>|null, 1: string, 2: array<string, mixed>|null} $caller The caller.
	 *
	 * @return array{0: int, 1: mixed, 2: string}
	 */
	private function answer(string $read, array $caller): array {
		$response = match ($read) {
			'catalogue.kinds'  => $this->catalogue($caller)[0]->kinds(portal: 'asked'),
			'catalogue.detail' => $this->catalogue($caller)[0]->detail(portal: 'asked', app: 'a', kind: 'k', slug: 's'),
			'news.index'       => $this->news($caller)[0]->index(portal: 'asked', limit: 2),
			'news.show'        => $this->news($caller)[0]->show(id: 'n1', portal: 'asked'),
		};

		return [$response->getStatus(), $response->getData(), $this->cacheControl($response)];
	}//end answer()

	/**
	 * Every read answers every caller as recorded.
	 *
	 * @return void
	 */
	public function testEveryReadAnswersEveryCallerAsRecorded(): void {
		$actual = [];
		foreach (array_keys(self::golden()) as $key) {
			[$read, $caller] = [substr($key, 0, strrpos($key, '.')), substr($key, strrpos($key, '.') + 1)];
			$actual[$key]    = $this->answer(read: $read, caller: self::callers()[$caller]);
		}

		$this->assertSame(self::golden(), $actual);
	}//end testEveryReadAnswersEveryCallerAsRecorded()

	/**
	 * The catalogue index refuses and admits like the other reads.
	 *
	 * @return void
	 */
	public function testTheCatalogueIndexIsGated(): void {
		$statuses = [];
		foreach (self::callers() as $name => $caller) {
			$statuses[$name] = $this->catalogue($caller)[0]->index(portal: 'asked')->getStatus();
		}

		$this->assertSame(['unknownPortal' => 404, 'anonymous' => 401, 'lowTrust' => 403, 'signedIn' => 200, 'public' => 200], $statuses);
	}//end testTheCatalogueIndexIsGated()

	/**
	 * A refused read never reaches the reader.
	 *
	 * @return void
	 */
	public function testARefusedReadNeverReachesTheReader(): void {
		foreach (['unknownPortal', 'anonymous', 'lowTrust'] as $name) {
			[$catalogue, $catalogueReader] = $this->catalogue(self::callers()[$name]);
			$catalogueReader->expects($this->never())->method($this->anything());
			$catalogue->index(portal: 'asked');
			$catalogue->kinds(portal: 'asked');
			$catalogue->detail(portal: 'asked', app: 'a', kind: 'k', slug: 's');
			[$news, $newsReader] = $this->news(self::callers()[$name]);
			$newsReader->expects($this->never())->method($this->anything());
			$news->index(portal: 'asked');
			$news->show(id: 'n1', portal: 'asked');
		}
	}//end testARefusedReadNeverReachesTheReader()
}//end class
