<?php
require 'FileCheckerClass.php';

$opts = array('debug::', 'saveState::', 'help::');
$opts = getopt('', $opts);

echo "###########################\n";
echo "    Unused File Checker    \n";
echo "###########################\n";

if (isset($opts['help'])) {
	echo "--debug=true \n\tDebug Mode on. This takes longer but shows files \n";
	echo "\twhich weren't found in the first run, it then searches \n";
	echo "\tpurely for the filenames instead of the full path \n\n";
	echo "--saveState=true \n\tSaves the programs progress regularly which \n";
	echo "\tcan later be loaded to pickup right where you left off\n\n";
	exit();
}

// Debug mode
if (isset($argv[1]) && $argv[1] == 1) {
	$debug = true;
} elseif (isset($opts['debug']) && $opts['debug'] === "true") {
	$debug = true;
} else {
	$debug = false;
}

// Save State mode
if (isset($argv[1]) && $argv[1] == 1) {
	$saveState = true;
} elseif (isset($opts['saveState']) && $opts['saveState'] === "true") {
	$saveState = true;
} else {
	$saveState = false;
}

echo "Would you like to start a new search or continue a previous search? \n";
echo "1) New Search \n";
echo "2) Continue a previous search \n";
$load = readline('');

if (is_numeric(trim($load)) && in_array($load, ['1','2']) !== false) {
	if ($load == '1') {

		// Run the program
		$UnusedFileChecker = new UnusedFileChecker($debug, $saveState);
		$UnusedFileChecker->runUnusedFileChecker();
	} elseif ($load == '2') {

		// Load previous attempt (save state) and continue
		$UnusedFileChecker = new UnusedFileChecker($debug, $saveState);
		$UnusedFileChecker = $UnusedFileChecker->loadSaveState();
		$UnusedFileChecker->runUnusedFileChecker();
	}
} else {
	exit("Invalid Input! \n");
}
