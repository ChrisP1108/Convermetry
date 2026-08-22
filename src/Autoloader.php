<?php
namespace Convermetry;

if (!defined('ABSPATH')) { exit; }

/**
 * Minimal PSR-4 autoloader.
 *
 * Maps one or more namespace prefixes to filesystem directories and
 * registers itself with spl_autoload_register so that classes in the
 * Convermetry namespace are loaded on demand without Composer.
 */
final class Autoloader
{
    /**
     * Registered namespace prefix → base directory mappings.
     *
     * Keys are namespace prefixes with a trailing backslash (e.g. "Convermetry\").
     * Values are absolute directory paths with a trailing directory separator.
     *
     * @var array<string, string>
     */
    private array $prefixes = [];

    /**
     * Registers a namespace prefix with its corresponding base directory.
     *
     * @param string $prefix  The namespace prefix (e.g. "Convermetry").
     * @param string $baseDir Absolute path to the directory that contains the
     *                        classes for this prefix (e.g. "/path/to/plugin/src").
     * @return void
     */
    public function addNamespace(string $prefix, string $baseDir): void
    {
        $prefix  = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->prefixes[$prefix] = $baseDir;
    }

    /**
     * Static factory that boots the autoloader in a single call.
     *
     * @param string $pluginDir Absolute path to the plugin root directory,
     *                          with or without a trailing separator.
     * @return void
     */
    public static function boot(string $pluginDir): void
    {
        $instance = new self();
        $instance->addNamespace('Convermetry', rtrim($pluginDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'src');
        $instance->register();
    }

    /**
     * Activates the autoloader by registering it with spl_autoload_register.
     *
     * @return void
     */
    public function register(): void
    {
        spl_autoload_register($this->autoload(...));
    }

    /**
     * Resolves a fully-qualified class name to a file path and includes it.
     *
     * @param string $class Fully-qualified class name (e.g. "Convermetry\Admin\AnalyticsPage").
     * @return void
     */
    private function autoload(string $class): void
    {
        foreach ($this->prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file     = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_readable($file)) {
                require_once $file;
            }
        }
    }
}
