<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Search;

use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\Search\AnonymousPublicationSearch;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * search-sort-by-relevance REQ-SSR-005: the server reads the public search
 * as an anonymous visitor would.
 *
 * @spec openspec/changes/search-sort-by-relevance/tasks.md#t08
 */
class AnonymousPublicationSearchTest extends TestCase {

	/**
	 * The calls the loopback received.
	 *
	 * @var list<array{method: string, path: string, options: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * A search over a loopback answering one status and body.
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 *
	 * @return AnonymousPublicationSearch
	 */
	private function search(int $status, string $body): AnonymousPublicationSearch {
		$loopback = $this->createMock(InstanceLoopback::class);
		$loopback->method('request')->willReturnCallback(
			function (string $method, string $path, array $options=[]) use ($status, $body): IResponse {
				$this->calls[] = ['method' => $method, 'path' => $path, 'options' => $options];
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				$response->method('getBody')->willReturn($body);
				return $response;
			}
		);

		return new AnonymousPublicationSearch($loopback);
	}

	public function testATermIsSearchedFuzzilyAndAnonymously(): void {
		$search = $this->search(status: 200, body: '{"results":[{"name":"Hondenbelasting"}],"total":7}');

		$answer = $search->search(term: ' hondenbelasting ', page: 2, limit: 5);

		$this->assertSame(['results' => [['name' => 'Hondenbelasting']], 'total' => 7], $answer);
		$this->assertCount(1, $this->calls);
		$this->assertSame('GET', $this->calls[0]['method']);
		$this->assertSame(
			'/index.php/apps/opencatalogi/api/federation/publications?_limit=5&_page=2&_search=hondenbelasting&_fuzzy=true',
			$this->calls[0]['path']
		);
		$headers = $this->calls[0]['options']['headers'];
		$this->assertSame(['Accept' => 'application/json'], $headers, 'no cookie, no authorization');
		$this->assertArrayNotHasKey('auth', $this->calls[0]['options']);
		$this->assertArrayNotHasKey('cookies', $this->calls[0]['options']);
	}

	public function testNoTermAsksForEveryPublicRow(): void {
		$search = $this->search(status: 200, body: '{"results":[],"total":0}');

		$search->search(term: '');

		$this->assertSame('/index.php/apps/opencatalogi/api/federation/publications?_limit=100&_page=1', $this->calls[0]['path']);
	}

	public function testAMissingTotalCountsTheRows(): void {
		$search = $this->search(status: 200, body: '{"results":[{"name":"A"},"not a row",{"name":"B"}]}');

		$this->assertSame(['results' => [['name' => 'A'], ['name' => 'B']], 'total' => 2], $search->search(term: 'a'));
	}

	public function testARefusalAnswersNothing(): void {
		$search = $this->search(status: 403, body: '{"results":[{"name":"A"}],"total":1}');

		$this->assertSame(['results' => [], 'total' => 0], $search->search(term: 'a'));
	}

	public function testAnUnreadableBodyAnswersNothing(): void {
		$search = $this->search(status: 200, body: '<html>');

		$this->assertSame(['results' => [], 'total' => 0], $search->search(term: 'a'));
	}

	public function testAFailedCallAnswersNothing(): void {
		$loopback = $this->createMock(InstanceLoopback::class);
		$loopback->method('request')->willThrowException(new RuntimeException('connection refused'));

		$this->assertSame(['results' => [], 'total' => 0], (new AnonymousPublicationSearch($loopback))->search(term: 'a'));
	}
}
