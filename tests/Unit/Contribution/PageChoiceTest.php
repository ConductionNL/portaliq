<?php

/**
 * A portal shows the pages its administrator chose, in the chosen order; an
 * account sees only the pages and collections left to it
 * (operate-pages-per-portal-and-client).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PageChoice;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class PageChoiceTest extends TestCase {

	/**
	 * Pipelinq's pages as the aggregate carries them.
	 *
	 * @return array<string, mixed>
	 */
	private static function aggregate(): array {
		return [
			'audience' => 'client',
			'organisation' => 'gemeente-x',
			'contributions' => [
				[
					'app' => 'pipelinq',
					'label' => 'Pipelinq',
					'collections' => [['id' => 'quotes'], ['id' => 'invoices'], ['id' => 'inbox', 'kind' => 'inbox']],
					'pages' => [
						['id' => 'quotes', 'blocks' => [['type' => 'collection', 'collection' => 'quotes']]],
						['id' => 'invoices', 'blocks' => [['type' => 'collection', 'collection' => 'invoices']]],
						['id' => 'cases', 'blocks' => [['type' => 'collection', 'collection' => 'quotes']]],
					],
				],
				['app' => 'docs', 'label' => 'Docs', 'collections' => [['id' => 'files']], 'pages' => [['id' => 'documents', 'blocks' => [['type' => 'collection', 'collection' => 'files']]]]],
			],
		];
	}

	/**
	 * @return array<int, array<int, string>> The page ids of each contribution.
	 */
	private static function ids(array $aggregate): array {
		return array_map(static fn (array $c): array => array_column($c['pages'], 'id'), $aggregate['contributions']);
	}

	public function testAPortalHidesAndOrdersPages(): void {
		$out = (new PageChoice())->arrange(self::aggregate(), [
			['page' => 'pipelinq:quotes', 'hidden' => true],
			['page' => 'pipelinq:invoices'],
			['page' => 'pipelinq:cases'],
		]);

		$this->assertSame([['invoices', 'cases'], ['documents']], self::ids($out), 'quotes is gone; the rest keep the listed order');

		$moved = (new PageChoice())->arrange(self::aggregate(), [['page' => 'pipelinq:cases'], ['page' => 'pipelinq:invoices']]);
		$this->assertSame(['cases', 'invoices', 'quotes'], self::ids($moved)[0], 'listed pages first, in the listed order, then the unlisted one');
	}

	public function testAPageTheChoiceDoesNotNameKeepsItsPlace(): void {
		$out = (new PageChoice())->arrange(self::aggregate(), [['page' => 'pipelinq:invoices', 'hidden' => true]]);

		$this->assertSame([['quotes', 'cases'], ['documents']], self::ids($out), "an app installed later (docs) is not hidden by an older choice");
		$this->assertSame('docs', $out['contributions'][1]['app']);
	}

	public function testNoChoiceAnswersAsToday(): void {
		$choice = new PageChoice();
		$this->assertSame(self::aggregate(), $choice->arrange(self::aggregate(), null));
		$this->assertSame(self::aggregate(), $choice->arrange(self::aggregate(), []));
		$this->assertSame(self::aggregate(), $choice->arrange(self::aggregate(), ['junk', ['page' => 'not a key']]));
		$this->assertSame(self::aggregate(), $choice->withoutHidden(self::aggregate(), []));
		$this->assertSame(self::aggregate(), $choice->withoutHidden(self::aggregate(), null));
	}

	public function testHiddenPageForAnAccountDropsItsCollections(): void {
		$out = (new PageChoice())->withoutHidden(self::aggregate(), ['pipelinq:invoices']);

		$this->assertSame(['quotes', 'cases'], self::ids($out)[0]);
		$this->assertSame(['quotes', 'inbox'], array_column($out['contributions'][0]['collections'], 'id'), 'invoices closes; an inbox no page shows stays');
		$this->assertSame(['files'], array_column($out['contributions'][1]['collections'], 'id'), 'another app is untouched');

		$shared = (new PageChoice())->withoutHidden(self::aggregate(), ['pipelinq:quotes']);
		$this->assertContains('quotes', array_column($shared['contributions'][0]['collections'], 'id'), 'a collection another page still shows stays open');
	}

	public function testTheRegistryAppliesTheAccountsHiddenPagesOncePerRequest(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['portaliq']);
		$apps->method('getAppInfo')->willReturn(['id' => 'portaliq']);
		$provider = new class () {
			public function getContribution(array $subject): array {
				return [
					'collections' => [['id' => 'quotes', 'register' => 'r', 'schema' => 'quote'], ['id' => 'invoices', 'register' => 'r', 'schema' => 'invoice']],
					'pages' => [
						['id' => 'quotes', 'blocks' => [['type' => 'collection', 'collection' => 'quotes']]],
						['id' => 'invoices', 'blocks' => [['type' => 'collection', 'collection' => 'invoices']]],
					],
				];
			}

			public function getAudiences(): array {
				return ['client'];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($id === 'OCA\\Portaliq\\Portal\\PortalContributionProvider') ? $provider : throw new \RuntimeException('no service')
		);

		$accounts = $this->createMock(PortalAccountLookup::class);
		$accounts->expects($this->once())->method('bySubjectRef')->with('s-1')->willReturn(['subjectRef' => 's-1', 'hiddenPages' => ['portaliq:invoices']]);

		$registry = new PortalContributionRegistry($apps, $container, $this->createMock(LoggerInterface::class), accounts: $accounts);
		$subject  = ['subjectRef' => 's-1', 'audience' => 'client', 'organisation' => 'o', 'trust' => 'high'];

		$first = $registry->aggregateFor($subject);
		$registry->aggregateFor($subject);

		$this->assertSame(['quotes'], array_column($first['contributions'][0]['collections'], 'id'));
		$this->assertSame(['quotes'], array_column($first['contributions'][0]['pages'], 'id'));
	}
}
