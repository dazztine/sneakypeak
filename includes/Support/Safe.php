<?php
namespace SneakyPeak\Support;

defined('ABSPATH') || exit;

use Throwable;

/**
 * Class Safe
 *
 * Fail-safe wrapper for WordPress and WooCommerce hook callbacks.
 * Catches Throwable errors, logs them once per unique message per request to avoid flooding,
 * and returns the original argument safely.
 */
class Safe {

    /**
     * Cache of logged error signatures during the current request
     *
     * @var array<string, bool>
     */
    private static array $logged_errors = array();

    /**
     * Wrap a filter callback with a try-catch block.
     *
     * @param callable $callback The filter callback to execute safely.
     * @param int $default_arg_index Zero-based index of the parameter to return as the fallback default value (defaults to 0).
     * @return \Closure Wrapped filter function.
     */
    public static function filter(callable $callback, int $default_arg_index = 0): \Closure {
        return function (...$args) use ($callback, $default_arg_index) {
            try {
                return $callback(...$args);
            } catch (Throwable $e) {
                self::log_error($e);
                return $args[$default_arg_index] ?? null;
            }
        };
    }

    /**
     * Wrap an action callback with a try-catch block.
     *
     * @param callable $callback The action callback to execute safely.
     * @return \Closure Wrapped action function.
     */
    public static function action(callable $callback): \Closure {
        return function (...$args) use ($callback) {
            try {
                $callback(...$args);
            } catch (Throwable $e) {
                self::log_error($e);
            }
        };
    }

    /**
     * Log an error once per unique signature per request.
     *
     * @param Throwable $e
     */
    private static function log_error(Throwable $e): void {
        $sig = $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
        if (isset(self::$logged_errors[$sig])) {
            return;
        }
        self::$logged_errors[$sig] = true;

        error_log(sprintf(
            '[SneakyPeak] Error: %s in %s on line %d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}
