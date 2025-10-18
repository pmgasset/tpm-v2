<?php
if (!function_exists('dbDelta')) {
    function dbDelta($sql) {
        if (is_string($sql) && preg_match('/CREATE\s+TABLE\s+`?([^`\s]+)`?/i', $sql, $matches)) {
            $table = $matches[1];

            if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && property_exists($GLOBALS['wpdb'], 'existing_tables')) {
                $GLOBALS['wpdb']->existing_tables[$table] = true;
            }
        }

        return true;
    }
}
