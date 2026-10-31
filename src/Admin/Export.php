<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

/**
 * The download plumbing shared by the CSV and JSON exports added to the
 * Analytics, Goals, Funnels and Submissions screens.
 *
 * Each export is its own admin-post action with a nonce of the same name, and
 * each handler checks its method, scope and nonce itself, in the numbered
 * order every other handler in this plugin uses — this class only builds the
 * link and writes the response. The older Submissions CSV and Activity Log
 * exports keep their own copies of the same steps.
 *
 * Both writers end the request: headers are sent, the body streams, and the
 * process exits, so nothing a later hook prints can land inside the file.
 */
final class Export
{
    /**
     * A nonce-carrying export link.
     *
     * Built with add_query_arg() rather than wp_nonce_url(), which
     * HTML-encodes its result; this value is escaped once where it is printed.
     *
     * @param string                $action The export's admin-post and nonce action.
     * @param array<string, string> $args   Further query arguments (a report period, filters).
     * @return string
     */
    public static function url(string $action, array $args = []): string
    {
        return add_query_arg(
            ['action' => $action, '_wpnonce' => wp_create_nonce($action)] + $args,
            admin_url('admin-post.php')
        );
    }

    /**
     * Whether the request carries a valid nonce for one export action.
     *
     * @param string $action The export's nonce action.
     * @return bool
     */
    public static function nonceIsValid(string $action): bool
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- type-checked here, sanitized on the next line.
        $nonce = $_GET['_wpnonce'] ?? null;

        return is_string($nonce)
            && wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), $action) !== false;
    }

    /**
     * Sends download headers and opens the response body for CSV rows.
     *
     * A UTF-8 byte-order mark goes first so spreadsheet applications read
     * accented text correctly.
     *
     * @param string $filename Download file name.
     * @return resource|null The php://output stream, or null when it cannot be opened.
     */
    public static function startCsv(string $filename)
    {
        self::headers('text/csv; charset=utf-8', $filename);

        $output = fopen('php://output', 'w');

        if ($output === false) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- $output is php://output, the download response itself; no file is written.
        fwrite($output, "\xEF\xBB\xBF");

        return $output;
    }

    /**
     * Writes one CSV row, escaping every cell against formula injection.
     *
     * escape: '' is required, not cosmetic: the default emits a deprecation
     * notice on PHP 8.4+, which would land inside the downloaded file.
     *
     * @param resource                $output The stream from {@see startCsv()}.
     * @param list<string|int|float> $cells  Cell values in column order.
     * @return void
     */
    public static function csvRow($output, array $cells): void
    {
        fputcsv($output, array_map(static fn($cell): string => self::cell((string) $cell), $cells), escape: '');
    }

    /**
     * Sends download headers and the whole JSON document, then exits.
     *
     * @param string               $filename Download file name.
     * @param array<string, mixed> $document The document.
     * @return never
     */
    public static function json(string $filename, array $document): never
    {
        self::headers('application/json; charset=utf-8', $filename);

        echo wp_json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Prefixes spreadsheet-formula trigger characters so Excel and Sheets
     * treat the value as text — the same rule the Submissions and Activity
     * Log exports apply.
     *
     * @param string $value Raw cell value.
     * @return string
     */
    public static function cell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "\t" . $value;
        }

        return $value;
    }

    /**
     * Clears any buffered output and sends the download headers.
     *
     * @param string $type     Content-Type value.
     * @param string $filename Download file name.
     * @return void
     */
    private static function headers(string $type, string $filename): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
