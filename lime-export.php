<?php  
/*
Plugin Name: Lime Export
Description: Advanced Database export utility
Version: 0.1
Author: Siyan Panayotov
Author URI: http://sleeping-sailor.com
License: GPLv2 or later
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

include_once('config.php');
include_once('helpers.php');

add_action( 'admin_menu', 'wple_register_pages' );
add_action( 'load-tools_page_lime-export', 'wple_admin_init' );

function wple_register_pages() {
	add_submenu_page('tools.php', __('Database Export'), __('Database Export'), 'manage_options', 'lime-export', 'wple_admin_page');
}

function wple_admin_init() {
	if ( isset($_POST['wple_download']) && check_admin_referer('wple_download','wple_download') ) {
		try {
			wple_do_export();
		} catch (WPLE_Exception $e) {
			wp_redirect( add_query_arg('message', $e->getErrNum()) );
			exit();
		}
	}

	wp_enqueue_style('lemon-export-style', WPLE_URL . '/assets/style.css');
	wp_enqueue_script('lemon-export-script', WPLE_URL . '/assets/func.js');
}

function wple_admin_page() {
	global $wpdb;

	$tables = wple_get_existing_tables();

	include(WPLE_PATH . '/export-page.php');
}

function wple_do_export() {
	global $wpdb, $wple_export_file, $wple_time_start;

	if ( empty($_POST['wple_export_tables']) ) {
		throw new WPLE_Exception( WPLE_MSG_NO_SELECTION );
	}

	$filename = $wpdb->dbname . '.' . date('Y-m-d') . '.sql';
	$export_tables = $_POST['wple_export_tables'];
	$existing_tables = wple_get_existing_tables();

	$wple_export_file = tmpfile();
	$wple_time_start = time();
	$table_separator = "\n-- --------------------------------------------------------\n\n";

	if ( !$wple_export_file ) {
		throw new WPLE_Exception( WPLE_MSG_TMPFILE_ERROR );
	}

	$head = "-- Lime Export SQL Dump\n" .
			"-- version " . WPLE_VERSION . "\n" . 
			"--\n" .
			"-- Host: " . $wpdb->dbhost . "\n" .
			"-- Database: " . $wpdb->dbname . "\n" . 
			"-- Generation Time: " . date('r') . "\n\n";


    $head .= "SET time_zone = \"+00:00\";\n";

    $old_tz = $wpdb->get_var('SELECT @@session.time_zone');
    $wpdb->query('SET time_zone = "+00:00"');

	wple_output_handler($head);
	foreach ($export_tables as $table_al) {
		$table = $wpdb->prefix . $table_al;
		if ( !in_array($table, $existing_tables) ) {
			continue;
		}

		$query = 'SELECT * FROM `' . $table . '` ';

		wple_output_handler($table_separator);
		wple_export_structure($table);
		wple_export_data($table, $query);
	}

	$wpdb->query('SET time_zone = "' . $old_tz . '"');


	header('Content-Type: text/x-sql');
	header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
	header('Content-Disposition: attachment; filename="' . $filename . '"');

    if ( isset($_SERVER['HTTP_USER_AGENT']) && preg_match('~MSIE ([0-9].[0-9]{1,2})~', $_SERVER['HTTP_USER_AGENT']) ) {
    	// IE?
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
    } else {
        header('Pragma: no-cache');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    }

	fflush($wple_export_file);
	rewind($wple_export_file);
	fpassthru($wple_export_file);

	exit();
}

function wple_export_structure($table) {
	global $wpdb;

	$schema_create = wple_export_comment( __('Table structure for table') . ' ' . $table) . "\n";
    $auto_increment = '';

    // Table status
    $result = mysql_query('SHOW TABLE STATUS FROM `' . $wpdb->dbname . '` LIKE \'' . wple_addslashes($table) . '\'', $wpdb->dbh);
    if ($result != FALSE) {
        if (mysql_num_rows($result) > 0) {
            $tmpres = mysql_fetch_array($result, MYSQL_ASSOC);
            // Here we optionally add the AUTO_INCREMENT next value,
            // but starting with MySQL 5.0.24, the clause is already included
            // in SHOW CREATE TABLE so we'll remove it below
            if ( !empty($tmpres['Auto_increment']) ) {
                $auto_increment .= ' AUTO_INCREMENT=' . $tmpres['Auto_increment'] . ' ';
            }
        }
        mysql_free_result($result);
    }


    // Table structure
	$result = mysql_query('SHOW CREATE TABLE `' . $table . '` ', $wpdb->dbh);


    if ($result != FALSE && ($row = mysql_fetch_array($result, MYSQL_NUM))) {
        $create_query = $row[1];
        unset($row);

		// Convert end of line chars to one that we want (note that MySQL doesn't return query it will accept in all cases)
		if (strpos($create_query, "(\r\n ")) {
			$create_query = str_replace("\r\n", "\n", $create_query);
		} elseif (strpos($create_query, "(\r ")) {
			$create_query = str_replace("\r", "\n", $create_query);
		}

		$create_query = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $create_query);
		$schema_create .= $create_query;
    }

	$schema_create = preg_replace('/AUTO_INCREMENT\s*=\s*([0-9])+/', '', $schema_create);

	$schema_create .= $auto_increment . ";\n";

	wple_output_handler($schema_create);

    mysql_free_result($result);
}

function wple_export_data($table, $sql_query) {
	global $wpdb;
	$i = 0; 
	$j = 0;

	$result = mysql_query($sql_query, $wpdb->dbh);
	$fields_cnt = mysql_num_fields($result);
	$meta = array();
	$flags = array();

    $search = array("\x00", "\x0a", "\x0d", "\x1a"); //\x08\\x09, not required
    $replace = array('\0', '\n', '\r', '\Z');

	$query_size = 0;
	$current_row = 0;
	$field_set = array();

	while ( $i < @mysql_num_fields( $result ) ) {
		$meta[$i] = @mysql_fetch_field( $result );
		$flags[$i] = mysql_field_flags($result, $i);
		$field_set[$i] = $meta[$i]->name;
		$i++;
	}

	$schema_insert = "\n" . wple_export_comment( __('Dumping data for table') . ' ' . $table ) . "\n";
	$schema_insert .= "INSERT INTO `" . $table . "` (`" . implode('`, `', $field_set) . "`) VALUES\n";

	while ($row = mysql_fetch_array($result, MYSQL_NUM)) {
		$values = array();
		$current_row++;

		for ($j=0; $j < $fields_cnt; $j++) { 
            if (!isset($row[$j]) || is_null($row[$j])) {
                $values[] = 'NULL';
            } elseif ($meta[$j]->numeric && $meta[$j]->type != 'timestamp' && ! $meta[$j]->blob) {
	            // a number
	            // timestamp is numeric on some MySQL 4.1, BLOBs are sometimes numeric
                $values[] = $row[$j];
            } elseif (stristr($flags[$j], 'BINARY') && $meta[$j]->blob && isset($GLOBALS['sql_hex_for_blob'])) {
	            // a true BLOB
                if (empty($row[$j]) && $row[$j] != '0') {
                	// empty blobs need to be different, but '0' is also empty :-(
                    $values[] = '\'\'';
                } else {
                    $values[] = '0x' . bin2hex($row[$j]);
                }
            } elseif ($meta[$j]->type == 'bit') {
            	// detection of 'bit' works only on mysqli extension
                $values[] = "b'" . wple_addslashes(wple_escape_bit($row[$j], $meta[$j]->length)) . "'";
            } else {
            	// something else -> treat as a string
                $values[] = '\'' . str_replace($search, $replace, wple_addslashes($row[$j])) . '\'';
            }
		}

        if ($current_row == 1) {
            $insert_line  = $schema_insert . '(' . implode(', ', $values) . ')';
        } else {
            $insert_line  = '(' . implode(', ', $values) . ')';
            if ( WPLE_MAX_QUERY_SIZE > 0 && $query_size + strlen($insert_line) > WPLE_MAX_QUERY_SIZE) {
                if (!wple_output_handler(";\n")) {
                    return FALSE;
                }
                $query_size = 0;
                $current_row = 1;
                $insert_line = $schema_insert . $insert_line;
            }
        }
        $query_size += strlen($insert_line);
            
        unset($values);

        wple_output_handler(($current_row == 1 ? '' : ",\n") . $insert_line);
	}

    mysql_free_result($result);

	if ($current_row > 0) {
	    wple_output_handler(";\n");
	}

}

function wple_output_handler( $line ) {
	global $wple_export_file, $wple_time_start;
	
    $write_result = @fwrite($wple_export_file, $line);
    if ( !$write_result || ($write_result != strlen($line))) {
    	throw new WPLE_Exception( WPLE_MSG_NO_SPACE );
    }

    $time_now = time();

    if ($wple_time_start >= $time_now + 30) {
        $wple_time_start = $time_now;
        header('X-pmaPing: Pong');
    }
}

function wple_export_comment( $text ) {
	return (empty($text) ? '' : "--\n-- ") . $text . "\n--\n";
}
