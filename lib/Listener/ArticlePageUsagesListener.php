<?php

/**
 * Portaliq ArticlePageUsagesListener
 *
 * Portaliq's own answer to "where is this article used": every CMS page whose
 * markdown body names the article as `articleRef`, by id or by slug
 * (marketing-articles-from-pipelinq). Drafts count too, so an editor sees a
 * page that will show the article once it is published. Built at read time
 * and stored nowhere.
 *
 * @category Listener
 * @package  OCA\Portaliq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\Portaliq\Event\ArticleUsagesRequestedEvent;
use OCA\Portaliq\Service\Articles\ArticleStore;
use OCA\Portaliq\Service\ObjectServiceLookup;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adds the pages that name an article to its usages.
 *
 * @template-implements IEventListener<ArticleUsagesRequestedEvent>
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
 */
class ArticlePageUsagesListener implements IEventListener {
	use ObjectServiceLookup;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For OpenRegister's ObjectService, resolved on use.
	 * @param IURLGenerator      $urls      Builds the link to the page in the admin.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add each page that names the article.
	 *
	 * @param Event $event The usages question.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	public function handle(Event $event): void {
		if (($event instanceof ArticleUsagesRequestedEvent) === false) {
			return;
		}

		$names = array_values(array_filter([$event->getArticleId(), $event->getSlug()], static fn (string $name): bool => $name !== ''));
		if ($names === []) {
			return;
		}

		foreach ($this->pages() as $page) {
			$ref = ($page['body']['articleRef'] ?? null);
			$id  = ArticleStore::idOf(row: $page);
			if (is_string($ref) === false || $id === '' || in_array(trim($ref), $names, true) === false) {
				continue;
			}

			$event->addUsage(
				id: $id,
				title: (string)($page['title'] ?? $page['route'] ?? $id),
				kind: 'page',
				app: 'portaliq',
				url: $this->urls->linkTo(appName: '', file: 'index.php') . '/apps/portaliq/pages/' . rawurlencode($id)
			);
		}
	}//end handle()

	/**
	 * Every CMS page, published or not.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function pages(): array {
		$service = $this->objectService();
		if ($service === null) {
			return [];
		}

		try {
			$rows = $service->findAll(
				config: ['filters' => ['register' => 'portaliq', 'schema' => 'page']],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: pages could not be read for article usages', ['reason' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ((array)($rows ?? []) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end pages()
}//end class
