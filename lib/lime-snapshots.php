<?php  

// Block direct includes
if ( !defined('WPINC') ) {
	header("HTTP/1.0 404 Not Found");
	exit;
}

function wple_admin_page_snapshots() {
	global $wpdb;

	$snapshots = wple_get_snapshots();
	$snapshots = array_reverse($snapshots);

	$date_format = get_option('date_format') . ' ' . get_option('time_format');

	include(WPLE_PATH . '/admin-templates/page-snapshots.php');
}

function wple_do_snapshot_download( $filename, $nice_filename ) {
	if ( !is_file(wple_snapshot_dir() . '/' . $filename) ) {
		throw new WPLE_Exception(WPLE_MSG_SNAPSHOT_NOT_FOUND);
	}

	header('Content-Type: text/x-sql');
	header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
	header('Content-Disposition: attachment; filename="' . $nice_filename . '"');

    if ( isset($_SERVER['HTTP_USER_AGENT']) && preg_match('~MSIE ([0-9].[0-9]{1,2})~', $_SERVER['HTTP_USER_AGENT']) ) {
    	// IE?
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
    } else {
        header('Pragma: no-cache');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    }

	readfile(wple_snapshot_dir() . '/' . $filename);

	return true;
}

function wple_get_snapshots() {
	$snapshots = array();
	$dir = wple_snapshot_dir() . '/';
	$csv = fopen($dir . 'list.csv', 'r');
	if ( !$csv ) {
		throw new WPLE_Exception(WPLE_MSG_FILE_READ_ERROR);
	}

	while (($data = fgetcsv($csv, 1000, ",")) !== FALSE) {
		if ( count( $data) < 3 ) {
			// silently ignore invalid line
			continue;
		}
		$snapshots[] = array(
			'filename' => $data[0],
			'tables' => explode('|', $data[1]),
			'created' => intval($data[2]),
			'size' => wple_format_bytes(intval($data[3])),
		);
	}

	fclose($csv);
	return $snapshots;
}

function wple_add_snapshot( $filename, $tables, $time = null ) {
	$time = !$time ? time(): $time;

	$dir = wple_snapshot_dir() . '/';
	$csv = fopen($dir . 'list.csv', 'a');
	if ( !$csv ) {
		throw new WPLE_Exception(WPLE_MSG_FILE_CREAT_ERROR);
	}

	fputcsv($csv, array($filename, implode('|', $tables), $time, filesize($dir . $filename)) );
	fclose($csv);
}

function wple_remove_snapshot( $filename ) {
	$snapshots = wple_get_snapshots();

	$dir = wple_snapshot_dir() . '/';
	$csv = fopen($dir . 'list.csv', 'w');
	if ( !$csv ) {
		throw new WPLE_Exception(WPLE_MSG_FILE_CREAT_ERROR);
	}

	foreach ($snapshots as $snapshot) {
		if ( $snapshot['filename'] == $filename ) {
			if ( is_file( $dir . $snapshot['filename'] ) ) {
				unlink( $dir . $snapshot['filename'] );
			}
		} else {
			$snapshot['tables'] = implode('|', $snapshot['tables']);
			fputcsv($csv, $snapshot);
		}
	}
	fclose($csv);
}





