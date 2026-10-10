<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Service\Cms\PlainMarkdown;
use OCA\Portaliq\Service\Cms\PlainPageRenderer;
use OCA\Portaliq\Service\Cms\PlainPublicationBlocks;
use OCA\Portaliq\Service\Cms\PlainPublicationReader;
use OCA\Portaliq\Service\Cms\PlainVocabulary;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\InstanceLoopback;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The real plain page renderer over the doubles a test hands it: a reader,
 * a URL generator, optionally a publication reader; the words are the
 * English source strings.
 */
class PlainRendererFactory {

	/**
	 * The renderer.
	 *
	 * @param CmsReader                   $reader       The page and menu reads.
	 * @param IURLGenerator               $urlGenerator The links.
	 * @param PlainPublicationReader|null $publications The publication reads; one that is never reached by default.
	 *
	 * @return PlainPageRenderer
	 */
	public static function make(CmsReader $reader, IURLGenerator $urlGenerator, ?PlainPublicationReader $publications=null): PlainPageRenderer {
		$mocks = new class('plain') extends TestCase {

			/**
			 * A double of a class or interface.
			 *
			 * @param string $class The class.
			 *
			 * @return object
			 */
			public function double(string $class): object {
				return $this->createMock($class);
			}//end double()
		};

		$l10n = $mocks->double(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []): string => vsprintf($text, (array)$params));
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, ($count === 1) ? $one : $many));
		$factory = $mocks->double(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$publications ??= new PlainPublicationReader($mocks->double(InstanceLoopback::class), $mocks->double(IAppManager::class), new NullLogger());
		$vocabulary     = new PlainVocabulary();

		return new PlainPageRenderer(
			$reader,
			new PlainMarkdown(),
			new PlainPublicationBlocks($publications, $vocabulary),
			$vocabulary,
			$urlGenerator,
			$factory
		);
	}//end make()
}//end class
