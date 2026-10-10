<?php
// SPDX-License-Identifier: EUPL-1.2
//
// THE PLAIN VERSION OF A SITE PAGE (site-honest-without-javascript REQ-SHJ-002).
//
// The server renders this page for a visitor without JavaScript, and it holds
// NO SCRIPT AT ALL: not the bundle, not the traffic client, not a JSON config
// block. `tests/site-plain.spec.mjs` fails on any script element here. It
// wears the site's theme through the same stylesheet partial `site.php`
// includes.
//
// Everything printed comes from PlainPageRenderer: markdown and widget text
// arrive as HTML that class built from escaped text (PlainMarkdown keeps a
// fixed subset and safe link targets only); every other value is escaped
// here with p().

declare(strict_types=1);

/** @var array $_ */

$locale = (string)($_['locale'] ?? 'nl');
$plain = (array)($_['plain'] ?? []);
$head = (array)($_['head'] ?? []);
$portalTitle = (string)($plain['portalTitle'] ?? '');
$pageTitle = (string)($plain['title'] ?? '');
$documentTitle = $pageTitle;
if ($portalTitle !== '' && $portalTitle !== $pageTitle) {
    $documentTitle = ($pageTitle !== '') ? $pageTitle . ' - ' . $portalTitle : $portalTitle;
}
$robots = ((int)($plain['status'] ?? 404) === 200 && ($head['robots'] ?? '') !== '') ? (string)$head['robots'] : 'noindex';

?><!DOCTYPE html>
<html lang="<?php p($locale); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php p($documentTitle !== '' ? $documentTitle : 'Portaal'); ?></title>
    <meta name="robots" content="<?php p($robots); ?>">
    <?php if ((string)($plain['canonical'] ?? '') !== '') { ?>
    <link rel="canonical" href="<?php p((string)$plain['canonical']); ?>">
    <?php } ?>
    <?php include __DIR__ . '/parts/site-stylesheets.php'; ?>
    <?php if ((string)($_['siteIcon'] ?? '') !== '') { ?>
    <link rel="icon" href="<?php p((string)$_['siteIcon']); ?>">
    <?php } ?>
</head>
<body>
    <p>
        <a id="skip-link"
           class="utrecht-skip-link utrecht-skip-link--visible-on-focus pq-site__skip"
           href="#pq-main">Direct naar de inhoud</a>
    </p>
    <header class="pq-plain__header">
        <div class="container">
            <p class="utrecht-paragraph pq-plain__portal">
                <a class="utrecht-link" href="<?php p((string)($plain['homeUrl'] ?? '')); ?>"><?php p($portalTitle !== '' ? $portalTitle : 'Portaal'); ?></a>
            </p>
            <?php if (($plain['menu'] ?? []) !== []) { ?>
            <nav aria-label="<?php p((string)($plain['menuLabel'] ?? '')); ?>">
                <ul class="utrecht-link-list">
                    <?php foreach ((array)$plain['menu'] as $item) { ?>
                    <li class="utrecht-link-list__item"><a class="utrecht-link" href="<?php p((string)$item['href']); ?>"><?php p((string)$item['name']); ?></a></li>
                    <?php } ?>
                </ul>
            </nav>
            <?php } ?>
        </div>
    </header>
    <main id="pq-main" class="container pq-plain" tabindex="-1">
        <h1 class="utrecht-heading-1"><?php p($pageTitle); ?></h1>
        <?php if ((string)($plain['summary'] ?? '') !== '') { ?>
        <p class="utrecht-paragraph utrecht-paragraph--lead"><?php p((string)$plain['summary']); ?></p>
        <?php } ?>

        <?php foreach ((array)($plain['blocks'] ?? []) as $block) { ?>
        <?php $kind = (string)($block['kind'] ?? ''); ?>
        <section class="pq-plain__block pq-plain__block--<?php p($kind); ?>">
            <?php if ($kind === 'html') { ?>
            <?php print_unescaped((string)$block['html']); ?>
            <?php } elseif ($kind === 'needsJs') { ?>
            <p class="utrecht-paragraph"><?php p((string)$block['text']); ?> <a class="utrecht-link" href="<?php p((string)$block['href']); ?>"><?php p((string)$block['linkText']); ?></a></p>
            <?php } elseif (($block['state'] ?? '') === 'unavailable') { ?>
            <?php if ($kind === 'search') { include __DIR__ . '/parts/site-plain-search-form.php'; } ?>
            <p class="utrecht-paragraph" role="status"><?php p((string)$block['unavailable']['text']); ?> <a class="utrecht-link" href="<?php p((string)$block['unavailable']['href']); ?>"><?php p((string)$block['unavailable']['linkText']); ?></a></p>
            <?php } elseif ($kind === 'search') { ?>
            <?php include __DIR__ . '/parts/site-plain-search-form.php'; ?>
            <p class="utrecht-paragraph" role="status"><?php p((string)$block['totalText']); ?></p>
            <?php if ($block['results'] !== []) { ?>
            <ol class="pq-plain__results">
                <?php foreach ((array)$block['results'] as $result) { ?>
                <li>
                    <h2 class="utrecht-heading-3">
                        <?php if ((string)$result['href'] !== '') { ?>
                        <a class="utrecht-link" href="<?php p((string)$result['href']); ?>"><?php p((string)$result['title']); ?></a>
                        <?php } else { ?>
                        <?php p((string)$result['title']); ?>
                        <?php } ?>
                    </h2>
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
            <?php if ($block['previous'] !== null || $block['next'] !== null) { ?>
            <nav aria-label="<?php p((string)$block['navLabel']); ?>">
                <p class="utrecht-paragraph">
                    <?php if ($block['previous'] !== null) { ?>
                    <a class="utrecht-link" rel="prev" href="<?php p((string)$block['previous']['href']); ?>"><?php p((string)$block['previous']['text']); ?></a>
                    <?php } ?>
                    <?php p((string)$block['pageText']); ?>
                    <?php if ($block['next'] !== null) { ?>
                    <a class="utrecht-link" rel="next" href="<?php p((string)$block['next']['href']); ?>"><?php p((string)$block['next']['text']); ?></a>
                    <?php } ?>
                </p>
            </nav>
            <?php } ?>
            <?php } elseif ($kind === 'publication') { ?>
            <h2 class="utrecht-heading-2"><?php p((string)$block['title']); ?></h2>
            <?php if ((string)$block['summary'] !== '') { ?>
            <p class="utrecht-paragraph"><?php p((string)$block['summary']); ?></p>
            <?php } ?>
            <?php if ($block['rows'] !== []) { ?>
            <dl class="pq-plain__facts">
                <?php foreach ((array)$block['rows'] as $row) { ?>
                <dt><?php p((string)$row['label']); ?></dt>
                <dd><?php p(implode(', ', (array)$row['values'])); ?></dd>
                <?php } ?>
            </dl>
            <?php } ?>
            <?php if ($block['documents'] !== []) { ?>
            <h3 class="utrecht-heading-3"><?php p((string)$block['documentsHeading']); ?></h3>
            <ul class="utrecht-link-list">
                <?php foreach ((array)$block['documents'] as $document) { ?>
                <li class="utrecht-link-list__item">
                    <?php if ((string)$document['href'] !== '') { ?>
                    <a class="utrecht-link" href="<?php p((string)$document['href']); ?>" download><?php p((string)$document['name']); ?></a>
                    <?php } else { ?>
                    <?php p((string)$document['name']); ?>
                    <?php } ?>
                    <?php if ((string)$document['facts'] !== '') { ?>(<?php p((string)$document['facts']); ?>)<?php } ?>
                </li>
                <?php } ?>
            </ul>
            <?php } ?>
            <?php } ?>
        </section>
        <?php } ?>

        <p class="utrecht-paragraph pq-plain__full">
            <a class="utrecht-link" href="<?php p((string)($plain['fullUrl'] ?? '')); ?>"><?php p((string)($plain['fullLinkText'] ?? '')); ?></a>
        </p>
    </main>
</body>
</html>
