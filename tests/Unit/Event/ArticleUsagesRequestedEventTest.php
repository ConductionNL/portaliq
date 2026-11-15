<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Event;

use OCA\Portaliq\Event\ArticleUsagesRequestedEvent;
use PHPUnit\Framework\TestCase;

/**
 * marketing-articles-from-pipelinq: the usage event carries the article's
 * identities and keeps every well-formed usage an app adds, in order.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
 */
class ArticleUsagesRequestedEventTest extends TestCase {

	public function testItCarriesTheArticlesIdentities(): void {
		$event = new ArticleUsagesRequestedEvent(articleId: 'a-1', slug: 'een', legacyRef: 'pipelinq:abc');

		$this->assertSame(['a-1', 'een', 'pipelinq:abc'], [$event->getArticleId(), $event->getSlug(), $event->getLegacyRef()]);
		$this->assertSame([], $event->getUsages());
	}//end testItCarriesTheArticlesIdentities()

	public function testItKeepsWellFormedUsagesTrimmedAndDropsTheRest(): void {
		$event = new ArticleUsagesRequestedEvent(articleId: 'a-1');
		$event->addUsage(id: ' t-1 ', title: 'Najaarsmailing', kind: 'campaignTemplate', app: 'pipelinq', url: '/apps/pipelinq/t-1');
		$event->addUsage(id: 'b-1', title: 'Blast', kind: 'blast', app: 'pipelinq');
		$event->addUsage(id: '', title: 'No id', kind: 'page', app: 'portaliq');
		$event->addUsage(id: 'p-1', title: ' ', kind: 'page', app: 'portaliq');
		$event->addUsage(id: 'p-2', title: 'Page', kind: '', app: 'portaliq');
		$event->addUsage(id: 'p-3', title: 'Page', kind: 'page', app: '');

		$this->assertSame([
			['id' => 't-1', 'title' => 'Najaarsmailing', 'kind' => 'campaignTemplate', 'app' => 'pipelinq', 'url' => '/apps/pipelinq/t-1'],
			['id' => 'b-1', 'title' => 'Blast', 'kind' => 'blast', 'app' => 'pipelinq', 'url' => ''],
		], $event->getUsages());
	}//end testItKeepsWellFormedUsagesTrimmedAndDropsTheRest()

	public function testItStopsAtTwoHundredUsages(): void {
		$event = new ArticleUsagesRequestedEvent(articleId: 'a-1');
		for ($i = 0; $i < 205; $i++) {
			$event->addUsage(id: 'u-' . $i, title: 'Usage', kind: 'page', app: 'portaliq');
		}

		$this->assertCount(200, $event->getUsages());
		$this->assertSame('u-199', $event->getUsages()[199]['id']);
	}//end testItStopsAtTwoHundredUsages()
}//end class
