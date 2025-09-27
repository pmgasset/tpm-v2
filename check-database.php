<?php
/**
 * File: check-database.php
 * Location: /wp-content/plugins/TallasseePropertyBookings/check-database.php
 * 
 * Check if database tables exist and create them if needed
 * Access via: https://240jordanview.com/wp-content/plugins/TallasseePropertyBookings/check-database.php
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load WordPress
$wp_load_path = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

if (!file_exists($wp_load_path)) {
    die("ERROR: Cannot find wp-load.php at: {$wp_load_path}");
}

require_once($wp_load_path);

header('Content-Type: text/plain');

echo "=== GUEST MANAGEMENT SYSTEM - DATABASE CHECK ===\n\n";

global $wpdb;

// Tables that should exist
$tables = array(
    'gms_reservations',
    'gms_guest_agreements',
    'gms_identity_verification',
    'gms_communications'
);

echo "1. CHECKING TABLES:\n\n";

$missing_tables = array();

foreach ($tables as $table) {
    $full_table_name = $wpdb->prefix . $table;
    $query = "SHOW TABLES LIKE '{$full_table_name}'";
    $table_exists = $wpdb->get_var($query);
    
    if ($table_exists) {
        echo "   ✅ {$full_table_name} - EXISTS\n";
    } else {
        echo "   ❌ {$full_table_name} - MISSING\n";
        $missing_tables[] = $table;
    }
}

echo "\n";

// If tables are missing, create them
if (!empty($missing_tables)) {
    echo "2. MISSING TABLES - ATTEMPTING TO CREATE...\n\n";
    
    $class_file = dirname(__FILE__) . '/includes/class-database.php';
    
    if (!file_exists($class_file)) {
        echo "   ❌ ERROR: Cannot find class-database.php at: {$class_file}\n";
        exit;
    }
    
    require_once($class_file);
    
    if (!class_exists('GMS_Database')) {
        echo "   ❌ ERROR: GMS_Database class not found after loading file\n";
        exit;
    }
    
    echo "   Creating tables...\n";
    GMS_Database::createTables();
    
    echo "   Re-checking...\n\n";
    
    foreach ($missing_tables as $table) {
        $full_table_name = $wpdb->prefix . $table;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table_name}'");
        
        if ($table_exists) {
            echo "   ✅ {$full_table_name} - CREATED\n";
        } else {
            echo "   ❌ {$full_table_name} - STILL MISSING\n";
            if ($wpdb->last_error) {
                echo "      MySQL Error: {$wpdb->last_error}\n";
            }
        }
    }
} else {
    echo "2. ALL TABLES EXIST ✅\n";
}

echo "\n";

// Show table structure for gms_reservations
echo "3. TABLE STRUCTURE (gms_reservations):\n\n";

$table_name = $wpdb->prefix . 'gms_reservations';
$columns = $wpdb->get_results("DESCRIBE {$table_name}");

if ($columns) {
    foreach ($columns as $column) {
        echo "   - {$column->Field} ({$column->Type}) ";
        if ($column->Null === 'NO') echo "[REQUIRED] ";
        if ($column->Key === 'PRI') echo "[PRIMARY KEY] ";
        echo "\n";
    }
} else {
    echo "   ❌ Cannot describe table\n";
}

echo "\n";

// Check database info
echo "4. DATABASE INFO:\n";
echo "   Database: " . DB_NAME . "\n";
echo "   Table Prefix: " . $wpdb->prefix . "\n";
echo "   WordPress Version: " . get_bloginfo('version') . "\n";
echo "   PHP Version: " . phpversion() . "\n";

echo "\n=== END CHECK ===\n";
?>