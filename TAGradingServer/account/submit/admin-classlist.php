<?php
require "../../toolbox/functions.php";

check_administrator();

define('TMP_XLSX_PATH', '/tmp/_HSS_xlsx');
define('TMP_CSV_PATH',  '/tmp/_HSS_csv');

/**
 * Due to security policy enacted by SuPHP, use of xslx2csv is disallowed in
 * this script, but is permitted in a separate CGI script.
 *
 * This script will work with cgi-bin/xlsx_to_csv.cgi to convert an uploaded
 * XLSX file to CSV.  Since HWGrading doesn't use session() / $_SESSION, all
 * pertinent info must be passed via URL parameters, which cannot be considered
 * secure information.
 *
 * IMPORTANT: Expected data uploads contain data regulated by
 * FERPA (20 U.S.C. � 1232g)
 * 
 * As this information must be made secure, existence of this data
 * (e.g. filenames) should not be shared by URL paramaters.  Therefore,
 * filenames will be hardcoded.
 *
 * Path for detected XLSX files            /tmp/_HSS_xlsx
 * Path for xlsx to CSV converted files    /tmp/_HSS_csv
 *
 * THESE FILES MUST BE IMMEDIATELY PURGED
 * (1) after the information is inserted into DB.  --OR--
 * (2) when the script is abruptly halted due to error condition.  e.g. die()
 *
 * Both conditions can be met as a closure registered with
 * register_shutdown_function()
 */
 
//Verify:  Is this a new upload or a CSV converted from XLSX?
if (isset($_GET['xlsx2csv']) && $_GET['xlsx2csv'] == 1) {

	//CSV converted from XLSX
	$csvFile = TMP_CSV_PATH;

	//Callback to purge temporary files that contain data restricted by FERPA.
	//The temp files will be purged when this script ends, FOR ANY REASON.
//	register_shutdown_function(
//		function() {
//			if (file_exists(TMP_XLSX_PATH)) {
//				unlink(TMP_XLSX_PATH);
//			}
//
//			if (file_exists(TMP_CSV_PATH)) {
//				unlink(TMP_CSV_PATH);
//			}
//		}
//	);
} else {

	//New upload. 
	//Verify that upload is a true CSV or XLSX file (check file extension and MIME type)
	$fileType = pathinfo($_FILES['classlist']['name'], PATHINFO_EXTENSION);
	$fh = finfo_open(FILEINFO_MIME_TYPE);
	$mimeType = finfo_file($fh, $_FILES['classlist']['tmp_name']);
	finfo_close($fh); //No longer needed once mime type is determined.
	
	if ($fileType == 'xlsx' && $mimeType == 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {

		//XLSX detected, need to do conversion.  Call up CGI script.
		if (copy($_FILES['classlist']['tmp_name'], TMP_XLSX_PATH)) {
			header("Location: {$BASE_URL}/cgi-bin/xlsx_to_csv.cgi?course={$_GET['course']}");
		} else {
			die("Error isolating uploaded XLSX.  Please contact tech support.");
		}
	} else if (($fileType == 'csv' && $mimeType == 'text/plain')) {

		//CSV detected.  No conversion needed.
		$csvFile = $_FILES['classlist']['tmp_name'];
	} else {

		//Neither XLSX or CSV detected.  Good bye...
		die("Only xlsx or csv files are allowed!");
	}
}

// Get CSV ini config
$csvFieldsINI = parse_ini_file("../../toolbox/configs/student_csv_fields.ini", false, INI_SCANNER_RAW);
if ($csvFieldsINI === false) {
	die("Cannot read student list CSV confuguration file.  Please contact your sysadmin.");
}

// Read file into row-by-row array.  Returns false on failure.
$contents = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($contents === false) {
	die("File was not properly uploaded.  Please contact tech support.");
}

// Massage student CSV file into generalized data array
$rows = array();
unset($contents[0]); //header should be thrown away
foreach($contents as $content) {
	$details = explode(",", trim($content));
	$rows[] = array( 'student_first_name' => $details[$csvFieldsINI['student_first_name']],
	                 'student_last_name'  => $details[$csvFieldsINI['student_last_name']],
	                 'student_rcs'        => explode("@", $details[$csvFieldsINI['student_email']])[0],
	                 'student_section'    => intval($details[$csvFieldsINI['student_section']]) );
	
	//Validate massaged data
	$val = end($rows);
	$err = "";

	//First name must be alpha characters or certain punctuation.
	$err .= (preg_match("~^[a-zA-Z'`\- ]+$~", $val['student_first_name'])) ? "" : "Error in student first name column.<br>";
	//Last name must be alpha characters or certain punctuation.
	$err .= (preg_match("~^[a-zA-Z'`\- ]+$~", $val['student_last_name'])) ? "" : "Error in student last name column.<br>";

	//Student section must be greater than zero (intval($str) returns zero when $str is not integer)
	$err .= ($val['student_section'] > 0) ? "" : "Error in student section column.<br>";
	
	//No check on rcs (computing login ID) -- different Univeristies have different formats.

	if (empty($err) === false) {
		die($err . "Contact your sysadmin.");
	}
}

//Collect existing student list, group data by rcs
$students = array();
\lib\Database::query("SELECT * FROM students");
foreach(\lib\Database::rows() as $student) {
    $students[$student['student_rcs']] = $student;    
}

// Go through all students in the CSV file. Either the student is in the database so we have to update his
// section, the student doesn't exist in the database and is in the CSV so we have to insert the student completely
// or the student exists in the database, but not the CSV, in which case we have to drop the student (unless
// student_manual is true)
\lib\Database::beginTransaction();
foreach ($rows as $row) {
	$columns = array("student_rcs", "student_first_name", "student_last_name", "student_section_id", "student_grading_id");
	$values = array($row['student_rcs'], $row['student_first_name'], $row['student_last_name'], $row['student_section'], 1);
	$rcs = $row['student_rcs'];
	if (array_key_exists($rcs, $students)) {
		if (isset($_POST['ignore_manual_1']) && $_POST['ignore_manual_1'] == true && $students[$rcs]['student_manual'] == 1) {
			continue;
		}
		\lib\Database::query("UPDATE students SET student_section_id=? WHERE student_rcs=?", array(intval($details[6]), $rcs));
		unset($students[$rcs]);
	}
	else {
		$db->query("INSERT INTO students (" . (implode(",", $columns)) . ") VALUES (?, ?, ?, ?, ?)", $values);
		\lib\Database::query("INSERT INTO late_days (student_rcs, allowed_lates, since_timestamp) VALUES(?, ?, TIMESTAMP '1970-01-01 00:00:01')", array($rcs, __DEFAULT_LATE_DAYS_STUDENT__));
	}
}

//
//Server exception in the above for-each block when xlsx to csv conversion done.
//Likely due to $_POST data lost when cgi script is called.
//

foreach ($students as $rcs => $student) {
	if (isset($_POST['ignore_manual_2']) && $_POST['ignore_manual_2'] == true && $student['student_manual'] == 1) {
		continue;
	}
	$_POST['missing_students'] = intval($_POST['missing_students']);
	if ($_POST['missing_students'] == -2) {
		continue;
	}
	else if ($_POST['missing_students'] == -1) {
		\lib\Database::query("DELETE FROM students WHERE student_rcs=?", array($rcs));
	}
	else {
		\lib\Database::query("UPDATE students SET student_section_id=? WHERE student_rcs=?", array($_POST['missing_students'], $rcs));
	}
}

\lib\Database::commit();

header("Location: {$BASE_URL}/account/admin-classlist.php?course={$_GET['course']}&update=1");
