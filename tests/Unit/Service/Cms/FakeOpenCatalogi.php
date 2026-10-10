<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainPublicationReader;
use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\InternalBaseUrl;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The real PlainPublicationReader over the real InstanceLoopback, with only
 * the HTTP client answering from a table: opencatalogi as a test sees it.
 */
class FakeOpenCatalogi extends TestCase {

	/** @var list<string> Every URL called. */
	public array $calls = [];

	/**
	 * A reader whose calls answer by path (the query is ignored, but recorded).
	 *
	 * @param array<string, array{0: int, 1: mixed}|callable> $answers Status and body by path, or a callable(query) returning them.
	 * @param bool                                            $enabled Whether opencatalogi is on.
	 *
	 * @return PlainPublicationReader
	 */
	public function reader(array $answers, bool $enabled=true): PlainPublicationReader {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($answers): IResponse {
				$this->calls[] = $url;
				$path          = (string)parse_url($url, PHP_URL_PATH);
				parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
				$answer = ($answers[$path] ?? [404, []]);
				if (is_callable($answer) === true) {
					$answer = $answer($query);
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($answer[0]);
				$response->method('getBody')->willReturn(json_encode($answer[1]));
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example'.$path);
		$base = $this->createMock(InternalBaseUrl::class);
		$base->method('configured')->willReturn('');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($enabled);

		return new PlainPublicationReader(new InstanceLoopback($clients, $urls, $base, new NullLogger()), $apps, new NullLogger());
	}//end reader()

	/**
	 * Words that are the English source strings.
	 *
	 * @return IL10N
	 */
	public function words(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []): string => vsprintf($text, (array)$params));
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, ($count === 1) ? $one : $many));

		return $l10n;
	}//end words()
}//end class
