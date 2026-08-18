<?php
// SPDX-License-Identifier: EUPL-1.2
//
// THE PAGE EDITOR'S DOCUMENT.
//
// It includes the SAME asset partial the public site does, and that is the
// decision the editor rests on: its canvas mounts the real blocks, and a block
// only looks like itself under the same stylesheets, the same webfonts and the
// same token cascade. An editor rendered inside Nextcloud's admin chrome would
// be previewing a measurably different page — `server.css` alone is 587 rules
// that outrank anything this app scopes.
//
// The editor's OWN chrome is loaded after, and it is scoped to `.pq-editor`
// so it cannot reach into the canvas. A rule from the editor leaking into a
// block would make the preview wrong in exactly the way this whole arrangement
// exists to prevent.

declare(strict_types=1);

/** @var array $_ */
require __DIR__ . '/partials/site-assets.php';

$editorConfig = ($_['editorConfig'] ?? []);

?><!DOCTYPE html>
<html lang="<?php p($locale); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php p(($portalConfig['title'] ?? 'Portaal') . ' — pagina bewerken'); ?></title>
    <link rel="icon" href="<?php p($favicon); ?>">
    <?php foreach ($stylesheets as $href) { ?>
    <link rel="stylesheet" href="<?php p($href); ?>">
    <?php } ?>
    <link rel="stylesheet" href="<?php p($asset($appId, 'css/editor.css')); ?>">
    <?php if ($portalTokenCss !== '') { ?>
    <style><?php print_unescaped($portalTokenCss); ?></style>
    <?php } ?>
</head>
<body class="pq-editor-body">
    <?php
    // THE SKIP LINK IS SERVER-RENDERED, for the same reason it is on the public
    // site: emitted by the bundle it would not exist until the bundle had
    // downloaded, parsed and mounted, and the person who most needs it is the
    // one tabbing into a page that has not finished loading.
    //
    // The editor's own first landmark is the canvas — the thing an author came
    // to work on — and reaching it otherwise means tabbing past the whole block
    // library and the layer tree on every load.
    ?>
    <p>
        <a id="skip-link"
           class="utrecht-skip-link utrecht-skip-link--visible-on-focus pq-site__skip"
           href="#pq-main">Direct naar de pagina</a>
    </p>

    <?php
    // DATA, NOT CODE — `type="application/json"` is not executed, so it needs
    // no CSP nonce and cannot become an injection vector.
    ?>
    <script type="application/json" id="portaliq-editor-config"><?php
        print_unescaped(json_encode($editorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    ?></script>

    <div id="portaliq-editor"></div>

    <?php emit_script_tag($asset($appId, 'js/portaliq-editor.js')); ?>
</body>
</html>
