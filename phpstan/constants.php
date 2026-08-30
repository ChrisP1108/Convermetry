<?php
/**
 * PHPStan bootstrap: the constants the plugin defines for itself.
 *
 * convermetry.php defines these inside an `else` branch guarded by a PHP
 * version check, so static analysis cannot see them as unconditional
 * definitions. They are declared here instead — with the same types the real
 * definitions produce — so every `CVM_*` fetch in src/ resolves.
 *
 * WordPress's own constants come from szepeviktor/phpstan-wordpress's
 * bootstrap; nothing here duplicates them.
 */

declare(strict_types=1);

define('CVM_VERSION', '0.0.0');
define('CVM_PLUGIN_FILE', '/plugin/convermetry/convermetry.php');
define('CVM_PLUGIN_DIR', '/plugin/convermetry/');
define('CVM_PLUGIN_URL', 'https://example.test/wp-content/plugins/convermetry/');
