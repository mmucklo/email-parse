<?php

/**
 * Polyfills for PHP 7.1 compatibility
 * These functions are only defined if they don't already exist in the PHP version being used.
 */

if (!function_exists('str_starts_with')) {
    /**
     * Checks if a string starts with a given substring.
     *
     * @param string $haystack The string to search in
     * @param string $needle The substring to search for
     * @return bool Returns true if haystack starts with needle, false otherwise
     */
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    /**
     * Checks if a string ends with a given substring.
     *
     * @param string $haystack The string to search in
     * @param string $needle The substring to search for
     * @return bool Returns true if haystack ends with needle, false otherwise
     */
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('str_contains')) {
    /**
     * Checks if a string contains a given substring.
     *
     * @param string $haystack The string to search in
     * @param string $needle The substring to search for
     * @return bool Returns true if haystack contains needle, false otherwise
     */
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
