<?php
// SPDX-License-Identifier: EUPL-1.2
//
// THE PLAIN VERSION OF A SITE PAGE (site-honest-without-javascript REQ-SHJ-002).
//
// What a visitor whose browser runs no JavaScript reads: the portal's title and
// main menu, the page's title, summary and body, the publication search and
// detail, and for every part that needs JavaScript a sentence saying so with a
// link to the full page. `PlainPageRenderer` builds all of it; this file only
// writes it out, every value through `p()`. The one exception is the markdown
// body, which `PlainMarkdown` has already escaped and reduced to a fixed subset.
//
// NO SCRIPT ELEMENT, NOT EVEN DATA. The page has to work, and be measured, as
// the visitor gets it; `tests/site-plain.spec.mjs` fails on any `<script` in
// this file. The stylesheets and theme come from the partial `site.php`
// includes too, so both versions wear the same theme.

declare(strict_types=1);

/** @var array $_ */
/** @var \OCP\IL10N $l */
$appId = 'portaliq';
$locale = (string)($_['locale'] ?? 'nl');
$head = (array)($_['head'] ?? []);
$plain = (array)($_['plain'] ?? []);
$blocks = (array)($plain['blocks'] ?? []);
$portalTitle = (string)($plain['portalTitle'] ?? '');
$pageTitle = (string)($plain['title'] ?? '');
$headTitle = (string)($head['title'] ?? '');
if ((int)($plain['status'] ?? 200) === 404 || $headTitle === '') {
    $headTitle = trim($pageTitle . ($portalTitle !== '' ? ' - ' . $portalTitle : ''), ' -');
}

if ($headTitle === '') {
    $headTitle = 'Portaal';
}

?><!DOCTYPE html>
<html lang="<?php p($locale); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php p($headTitle); ?></title>
    <?php if (($head['description'] ?? '') !== '') { ?>
    <meta name="description" content="<?php p($head['description']); ?>">
    <?php } ?>
    <?php
    // The same robots value SiteHead gives this route on `/site`, and the
    // `/site` address as canonical: the plain version is a rendering of that
    // page, not a page of its own, so a crawler indexes the one address.
    ?>
    <meta name="robots" content="<?php p(((int)($plain['status'] ?? 200) !== 404 && ($head['robots'] ?? '') !== '') ? $head['robots'] : 'noindex'); ?>">
    <?php if ((int)($plain['status'] ?? 200) !== 404 && ($head['canonical'] ?? '') !== '') { ?>
    <link rel="canonical" href="<?php p($head['canonical']); ?>">
    <?php } ?>
    <?php include __DIR__ . '/parts/site-stylesheets.php'; ?>
</head>
<body>
    <p>
        <a id="skip-link"
           class="utrecht-skip-link utrecht-skip-link--visible-on-focus pq-site__skip"
           href="#pq-main"><?php p($l->t('Skip to the content')); ?></a>
    </p>
    <header class="pq-plain__header container">
        <p class="utrecht-paragraph pq-plain__portal">
            <a class="utrecht-link" href="<?php p((string)($plain['homeUrl'] ?? '')); ?>"><?php p($portalTitle !== '' ? $portalTitle : 'Portaal'); ?></a>
        </p>
        <?php if ((array)($plain['menu'] ?? []) !== []) { ?>
        <nav aria-label="<?php p((string)($plain['menuLabel'] ?? '')); ?>">
            <ul class="utrecht-link-list">
                <?php foreach ((array)$plain['menu'] as $item) { ?>
                <li class="utrecht-link-list__item">
                    <a class="utrecht-link utrecht-link-list__link" href="<?php p($item['href']); ?>"><?php p($item['label']); ?></a>
                    <?php if ((array)($item['items'] ?? []) !== []) { ?>
                    <ul class="utrecht-link-list">
                        <?php foreach ((array)$item['items'] as $child) { ?>
                        <li class="utrecht-link-list__item"><a class="utrecht-link utrecht-link-list__link" href="<?php p($child['href']); ?>"><?php p($child['label']); ?></a></li>
                        <?php } ?>
                    </ul>
                    <?php } ?>
                </li>
                <?php } ?>
            </ul>
        </nav>
        <?php } ?>
    </header>
    <main id="pq-main" class="pq-plain container" tabindex="-1">
        <p class="utrecht-paragraph pq-plain__note">
            <?php p((string)($plain['plainNote'] ?? '')); ?>
            <a class="utrecht-link" href="<?php p((string)($plain['fullUrl'] ?? '')); ?>"><?php p((string)($plain['fullLabel'] ?? '')); ?></a>
        </p>
        <h1 class="utrecht-heading-1"><?php p($pageTitle); ?></h1>
        <?php if ((string)($plain['summary'] ?? '') !== '') { ?>
        <p class="utrecht-paragraph utrecht-paragraph--lead"><?php p((string)$plain['summary']); ?></p>
        <?php } ?>
        <?php foreach ($blocks as $block) { ?>
        <?php $kind = (string)($block['kind'] ?? ''); ?>
        <section class="pq-plain__block pq-plain__block--<?php p($kind); ?>">
            <?php if ($kind === 'html') { ?>
            <?php print_unescaped((string)$block['html']); ?>
            <?php } elseif ($kind === 'heading') { ?>
            <?php $level = max(1, min(6, (int)$block['level'])); ?>
            <h<?php p((string)$level); ?> class="utrecht-heading-<?php p((string)$level); ?>"><?php p((string)$block['text']); ?></h<?php p((string)$level); ?>>
            <?php } elseif ($kind === 'paragraph') { ?>
            <p class="utrecht-paragraph<?php p(($block['lead'] ?? false) === true ? ' utrecht-paragraph--lead' : ''); ?>"><?php p((string)$block['text']); ?></p>
            <?php } elseif ($kind === 'list') { ?>
            <?php $tag = (($block['ordered'] ?? false) === true ? 'ol' : 'ul'); ?>
            <<?php p($tag); ?> class="utrecht-<?php p($tag === 'ol' ? 'ordered-list' : 'unordered-list'); ?>">
                <?php foreach ((array)$block['items'] as $line) { ?>
                <li><?php p((string)$line['title']); ?><?php if ((string)$line['text'] !== '') { ?> <?php p((string)$line['text']); ?><?php } ?></li>
                <?php } ?>
            </<?php p($tag); ?>>
            <?php } elseif ($kind === 'links') { ?>
            <?php if ((string)($block['heading'] ?? '') !== '') { ?>
            <h2 class="utrecht-heading-3"><?php p((string)$block['heading']); ?></h2>
            <?php } ?>
            <?php if ((string)($block['intro'] ?? '') !== '') { ?>
            <p class="utrecht-paragraph"><?php p((string)$block['intro']); ?></p>
            <?php } ?>
            <ul class="utrecht-link-list">
                <?php foreach ((array)$block['links'] as $link) { ?>
                <li class="utrecht-link-list__item">
                    <a class="utrecht-link utrecht-link-list__link" href="<?php p((string)$link['href']); ?>"><?php p((string)$link['label']); ?></a>
                    <?php if ((string)($link['description'] ?? '') !== '') { ?>
                    <span class="utrecht-link-list__description"><?php p((string)$link['description']); ?></span>
                    <?php } ?>
                </li>
                <?php } ?>
            </ul>
            <?php } elseif ($kind === 'search') { ?>
            <?php if ((string)($block['title'] ?? '') !== '') { ?>
            <h2 class="utrecht-heading-2"><?php p((string)$block['title']); ?></h2>
            <?php } ?>
            <form method="get" action="<?php p((string)$block['action']); ?>" role="search">
                <input type="hidden" name="route" value="<?php p((string)$block['route']); ?>">
                <?php if ((string)($block['portal'] ?? '') !== '') { ?>
                <input type="hidden" name="portal" value="<?php p((string)$block['portal']); ?>">
                <?php } ?>
                <label class="utrecht-form-label" for="pq-plain-search"><?php p((string)$block['label']); ?></label>
                <input id="pq-plain-search" class="utrecht-textbox utrecht-textbox--html-input" type="search" name="_search" value="<?php p((string)$block['query']); ?>">
                <button type="submit" class="utrecht-button utrecht-button--primary-action"><?php p((string)$block['submit']); ?></button>
            </form>
            <?php if ((string)($block['state'] ?? '') !== 'ok') { ?>
            <p class="utrecht-paragraph" role="status"><?php p((string)$block['sentence']); ?> <a class="utrecht-link" href="<?php p((string)$block['fullUrl']); ?>"><?php p((string)$block['fullLabel']); ?></a></p>
            <?php } else { ?>
            <p class="utrecht-paragraph" role="status"><?php p((string)$block['totalText']); ?></p>
            <?php if ((string)($block['empty'] ?? '') !== '') { ?>
            <p class="utrecht-paragraph"><?php p((string)$block['empty']); ?></p>
            <?php } ?>
            <?php if ((array)$block['results'] !== []) { ?>
            <ol class="pq-plain__results">
                <?php foreach ((array)$block['results'] as $result) { ?>
                <li>
                    <?php if ((string)$result['href'] !== '') { ?>
                    <h3 class="utrecht-heading-3"><a class="utrecht-link" href="<?php p((string)$result['href']); ?>"><?php p((string)$result['title']); ?></a></h3>
                    <?php } else { ?>
                    <h3 class="utrecht-heading-3"><?php p((string)$result['title']); ?></h3>
                    <?php } ?>
                    <?php if ((string)$result['date'] !== '') { ?>
                    <p class="utrecht-paragraph"><?php p((string)$result['date']); ?></p>
                    <?php } ?>
                    <?php if ((string)$result['summary'] !== '') { ?>
                    <p class="utrecht-paragraph"><?php p((string)$result['summary']); ?></p>
                    <?php } ?>
                </li>
                <?php } ?>
            </ol>
            <?php } ?>
            <?php if ((string)$block['previous'] !== '' || (string)$block['next'] !== '') { ?>
            <nav aria-label="<?php p((string)$block['pageText']); ?>">
                <?php if ((string)$block['previous'] !== '') { ?>
                <a class="utrecht-link" rel="prev" href="<?php p((string)$block['previous']); ?>"><?php p((string)$block['previousLabel']); ?></a>
                <?php } ?>
                <span><?php p((string)$block['pageText']); ?></span>
                <?php if ((string)$block['next'] !== '') { ?>
                <a class="utrecht-link" rel="next" href="<?php p((string)$block['next']); ?>"><?php p((string)$block['nextLabel']); ?></a>
                <?php } ?>
            </nav>
            <?php } ?>
            <?php } ?>
            <?php } elseif ($kind === 'publication') { ?>
            <?php if ((string)($block['state'] ?? '') === 'ok') { ?>
            <h2 class="utrecht-heading-2"><?php p((string)$block['title']); ?></h2>
            <?php if ((string)$block['summary'] !== '') { ?>
            <p class="utrecht-paragraph"><?php p((string)$block['summary']); ?></p>
            <?php } ?>
            <?php if ((array)$block['facts'] !== []) { ?>
            <dl class="utrecht-data-list">
                <?php foreach ((array)$block['facts'] as $fact) { ?>
                <dt><?php p((string)$fact['label']); ?></dt>
                <dd><?php p((string)$fact['value']); ?></dd>
                <?php } ?>
            </dl>
            <?php } ?>
            <?php if ((array)$block['documents'] !== []) { ?>
            <h3 class="utrecht-heading-3"><?php p((string)$block['documentsLabel']); ?></h3>
            <ul class="utrecht-link-list">
                <?php foreach ((array)$block['documents'] as $document) { ?>
                <?php $facts = trim(implode(', ', array_filter([(string)$document['type'], (string)$document['size']]))); ?>
                <li class="utrecht-link-list__item">
                    <?php if ((string)$document['href'] !== '') { ?>
                    <a class="utrecht-link utrecht-link-list__link" href="<?php p((string)$document['href']); ?>" download><?php p((string)$document['name']); ?></a>
                    <?php } else { ?>
                    <?php p((string)$document['name']); ?>
                    <?php } ?>
                    <?php if ($facts !== '') { ?>
                    <span class="utrecht-link-list__description">(<?php p($facts); ?>)</span>
                    <?php } ?>
                </li>
                <?php } ?>
            </ul>
            <?php } ?>
            <?php } elseif ((string)($block['state'] ?? '') === 'not-found') { ?>
            <h2 class="utrecht-heading-2"><?php p((string)$block['title']); ?></h2>
            <p class="utrecht-paragraph"><?php p((string)$block['sentence']); ?></p>
            <?php } else { ?>
            <p class="utrecht-paragraph" role="status"><?php p((string)$block['sentence']); ?> <a class="utrecht-link" href="<?php p((string)$block['fullUrl']); ?>"><?php p((string)$block['fullLabel']); ?></a></p>
            <?php } ?>
            <?php } else { ?>
            <p class="utrecht-paragraph pq-plain__needs-js"><?php p((string)($block['sentence'] ?? '')); ?> <a class="utrecht-link" href="<?php p((string)($block['fullUrl'] ?? '')); ?>"><?php p((string)($block['fullLabel'] ?? '')); ?></a></p>
            <?php } ?>
        </section>
        <?php } ?>
    </main>
</body>
</html>
