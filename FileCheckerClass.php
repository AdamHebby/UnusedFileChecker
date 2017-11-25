<?php

/**
 * Finds files in directory which are not referenced anywhere within the same
 * directory. Searches for almost full paths (see line 102) and for filenames
 * in debug mode (outputs all matches on which full path wasn't initially found)
 */
class UnusedFileChecker {
    protected $configFile           = "ufc-config.json";
    protected $defaultOutputFile    = 'FC_output.txt';
    protected $defaultSaveStateFile = 'FC_SaveState.txt';
    protected $files                = array();
    protected $config               = array();
    protected $matchedFiles         = array();
    protected $loadedFromSaveState  = false;
    protected $findParams           = '';
    protected $previousRunTime      = 0;
    protected $skippedCount         = 0;
    protected $currentCount         = 0;
    protected $saveStateEvery;
    protected $gitLSDirectories;
    protected $searchIn;
    protected $changedDir;
    protected $FCDir;
    protected $debug;
    protected $homeDir;
    protected $ignores;
    protected $outputFile;
    protected $searchDir;
    protected $time;
    protected $timeElapsed;
    protected $totalFiles;
    protected $updated;
    public $saveState;
    public $saveStateFile;

    public function __construct($debugMode = NULL, $saveState = NULL)
    {
        $this->loadConfigFile();
        $this->saveState  = $saveState;
        $this->FCDir      = rtrim(__DIR__, '/') . '/';
        $this->debug      = $debugMode;
        $this->debug      = ($debugMode !== NULL) 
            ? $debugMode 
            : $this->config["debug_mode"];
        $this->saveState      = ($saveState !== NULL) 
            ? $saveState 
            : $this->config["save_state"];
        $this->homeDir    = $this->getHomeDirectory();
        $this->searchDir  = $this->searchIn;
        $this->searchDir  = str_replace(
            '~', $this->getHomeDirectory(), $this->searchDir
        );
        $this->makeOutputFile($this->defaultOutputFile, 'outputFile');
        $this->ignores    = array();
        // Saves cd'ing on every git grep
        var_dump($this->searchDir);
        $this->changedDir = chdir($this->searchDir);
    }

    /**
     * Loads and parses the UFC config file
     */
    private function loadConfigFile()
    {
        if (@fopen($this->configFile, 'r') !== false 
            && file_get_contents($this->configFile) !== '') {
            $config = preg_replace(
                '/\s*(?!<\")\/\*[^\*]+\*\/(?!\")\s*/', 
                '', file_get_contents($this->configFile));
            $config = json_decode($config, true);
            if ($config == NULL) {
                // Invalid Config
                exit("Error: Invalid config file \n");
            }
            // Store config for generic use
            $this->config = $config;

            // Store config values in specific variables
            $this->searchIn             = (
                isset($config["directory"]) && 
                $config["directory"] !== '') 
            ? $config["directory"] 
            : exit("Invalid config file! - Invalid 'directory' \n");

            $this->defaultOutputFile    = (
                isset($config["output_file"]) && 
                $config["output_file"] !== '') 
            ? $config["output_file"] 
            : exit("Invalid config file! - Invalid 'output_file' \n");

            $this->defaultSaveStateFile = (
                isset($config["savestate_file"]) && 
                $config["savestate_file"] !== '') 
            ? $config["savestate_file"] 
            : exit("Invalid config file! - Invalid 'savestate_file' \n");

            $this->ignores              = (
                isset($config["ignore_regexes"]) && 
                $config["ignore_regexes"] !== '') 
            ? $config["ignore_regexes"] 
            : exit("Invalid config file! - Invalid 'ignore_regexes' \n");

            $this->saveStateEvery       = (
                isset($config["save_every_files"]) && 
                $config["save_every_files"] !== '') 
            ? $config["save_every_files"] 
            : exit("Invalid config file! - Invalid 'save_every_files' \n");

            $this->gitLSDirectories     = (
                isset($config["search_only"]) && 
                $config["search_only"] !== '') 
            ? $config["search_only"] 
            : exit("Invalid config file! - Invalid 'search_only' \n");

        }
    }

    /**
     * Gets the home directory of current user across different platforms
     * https://stackoverflow.com/a/32528391
     */
    private function getHomeDirectory()
    {
        $home = getenv('HOME');
        if (!empty($home)) {
            // Unix systems 
            $home = rtrim($home, '/');
        } elseif (
            !empty($_SERVER['HOMEDRIVE']) && 
            !empty($_SERVER['HOMEPATH'])
        ) {
            // Windows systems
            $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
            $home = rtrim($home, '\\/');
        }
        return empty($home) ? NULL : $home;
    }

    /**
     * Main function in class, goes through all found files and greps the search
     * directory for references to those files, outputs it in output file, saves
     * every 50 iterations. Can grep twice if $this->debug == true.
     */
    public function runUnusedFileChecker()
    {
        $this->time = microtime(true);
        $this->getFileLists();

        // If user requested saving, and file doesn't already exist, make file
        if ($this->saveState !== false && $this->loadedFromSaveState !== true) {
            $this->makeOutputFile($this->defaultSaveStateFile, 'saveStateFile');
        }
        /* Loop through all found files, using currentCount so it continues 
        after load */
        for ($i = $this->currentCount; $i < count($this->files); $i++) {
            $originalValue = $this->prepareFileName($this->files[$i]);
            $modifiedValue = $originalValue;

            foreach ($this->config["replace_directories"] as $key => $value) {
                $modifiedValue = str_replace($key, $value, $modifiedValue);
            }

            $gitGrepResult = '';
            // Grep the whole directory for the file - this takes the longest
            // -chFI = count, no-names, fixed string search, no binary files
            $gitGrepResult = $this->gitGrepRepo(0, $modifiedValue, '-chFI');

            /* Check that there is a match and make sure it hasn't been updated
            in the past 3 years */
            if (($gitGrepResult == '0' || $gitGrepResult == '') &&
                in_array($originalValue, $this->updated) !== true) {
                // Save the record to a file with the full path relative to dir
                if ($this->debug !== false) {
                    $basename     = basename($originalValue);
                    $params       = '-FI --name-only';
                    $basenameGrep = $this->gitGrepRepo(1, $basename, $params);
                    $grepCount    = count($basenameGrep);
                    $contents     = "{$originalValue}\n";
                    $contents    .= "\tActual search term: ({$modifiedValue})";
                    $contents    .= "\n\tFile Name Search only: ({$basename})";
                    $contents    .= " {$grepCount}\n";
                    foreach ($basenameGrep as $value) {
                        $contents .= "\t{$value}\n";
                    }
                    $contents .= "\n";
                } else {
                    $contents = ($originalValue."\n");
                }
                file_put_contents($this->outputFile, $contents, FILE_APPEND);
            }
            // Update the progress stats
            $this->currentCount++;
            $this->progressStats();

            // Save class state - after a certain number of files (in config)
            if ($this->saveState !== false && 
                $this->currentCount % $saveStateEvery == 0) {
                $this->saveClassState();
            }
        }
        echo "\n";
    }

    /**
     * Remove ignored files from lists
     */
    private function removeIgnoredFiles()
    {
        // Test to see if file is in the ignore regex list, unset on first
        foreach ($this->files as $key => $value) {
            $preparedValue = $this->prepareFileName($value);
            foreach ($this->ignores as $ignore) {
                if (preg_match($ignore, $preparedValue) > 0) {
                    unset($this->files[$key]);
                    $this->skippedCount++;
                    break;
                }
            }
        }
        // Rekey array
        $this->files = array_values($this->files);
    }

    /**
     * Prepare filename for grepping
     */
    private function prepareFileName($originalValue)
    {
        /* Remove everything before and including the directory we are
        searching */
        $originalValue = str_replace($this->searchDir, '', $originalValue);
        // Replace spaces with escaped spaces
        $originalValue = str_replace(' ', '\\ ', $originalValue);
        return $originalValue;
    }

    /**
     * Generates output files from parameters. $retVar is what to assign the 
     * filename to ($this->$retvar), if $dir is not defined, use current 
     * directory. Will increment filenames if they exist (eg example_1.txt)
     */
    public function makeOutputFile($filename, $retVar, $dir = '')
    {
        // If dir not defined, use FCDir; makes sure it ends in trailing /
        if ($dir == '') {
            $dir = rtrim($this->FCDir, '/') . '/';
        } else {
            $dir = rtrim($dir, '/') . '/';
        }

        // Generate a new file -- eg files_0.txt
        if ($pos = strrpos($filename, '.')) {
            $name = substr($filename, 0, $pos);
            $ext  = substr($filename, $pos);
        } else {
            $name = $filename;
        }

        $newname = $filename;
        $counter = 1;

        while (
            ($f = @fopen("{$dir}{$newname}", 'r')) !== false && 
            fgets($f) !== '') {
            $newname = $name.'_'.$counter.$ext;
            $counter++;
        }
        $file = fopen("{$dir}{$newname}", 'w') or die('Unable to open file!');
        fclose($file);
        echo "Made new output file: {$dir}{$newname}\n";

        if (isset($retVar) !== false) {
            $this->$retVar = "{$dir}{$newname}";
        }
    }

    /**
     * Lists files in current directory (find) that start with FC, user picks
     * file to load from, function un-compresses it then unserializes it. Should
     * be used from outside of the class. Also forces some variables for reload.
     */
    public function loadSaveState($filename = false)
    {
        if ($filename !== true) {
            $count = 1;
            // Use bash to find files - not PHP (caching issues)
            $saveStateFile = $this->defaultSaveStateFile;
            $saveFiles = substr($saveStateFile, 0, strpos($saveStateFile, "."));
            exec("find {$this->FCDir}/{$saveFiles}* 2>/dev/null", $files);
            
            if (count($files) === 0) {
                exit("No Save State Files found! \n");
            }

            foreach ($files as $filename) {
                echo "{$count}) {$filename} \n";
                $count++;
            }
            $choice = readline('Which file would you like to load from? : ');

            if (is_numeric($choice) !== true) {
                exit("Error: Invalid Input, expecting a number! \n");
            }

            $choice -= 1;

            if (isset($files[$choice]) && file_exists($files[$choice])) {
                $contents = file_get_contents($files[$choice]);
                // Load and decompress contents to return for User
                $inflatedState                   = gzinflate($contents);
                $savedState                      = unserialize($inflatedState);
                $savedState->loadedFromSaveState = true;
                $savedState->saveStateFile       = $files[$choice];
                if (isset($savedState->currentCount) !== true) {
                    exit("Unable to use file: {$files[$choice]}\n");
                }
            } else {
                exit("Error: Invalid input or file doesn't exist! \n");
            }
        }
        return $savedState;
    }

    /**
     * Saves $this in current state, serializes and deflates (compresses) it,
     * then puts it in saveStateFile (makes one if it doesn't exist).
     */
    public function saveClassState()
    {
        if (file_exists($this->saveStateFile) !== false) {
            // Using clone so we only change clone; reset time on clone
            $thisClone                  = clone $this;
            $thisClone->time            = NULL;
            $thisClone->previousRunTime = $this->timeElapsed;
            $deflatedSaveState          = gzdeflate(serialize($thisClone), 4);
            file_put_contents($this->saveStateFile, $deflatedSaveState);
        } else {
            // File wasn't made on startup so saveState = false
            $this->makeOutputFile($this->defaultSaveStateFile, 'saveStateFile');
            $this->saveClassState();
        }
    }

    /**
     * Cleans symbolic links by turning them into real paths - if they're 
     * directories then it removes them 
     */
    protected function cleanSymbolicLinks()
    {
        foreach ($this->files as $key => $value) {
            $path = $this->prepareFileName($value);
            if (is_link($path)) {
                $path = realpath($path);
            }
            if (is_dir($path)) {
                // Remove from array
                unset($this->files[$key]);
                $this->skippedCount++;
            }
        }
        // Rekey array
        $this->files = array_values($this->files);
    }

    /**
     * Gets 2 lists of files from git (locally), files that have been updated
     * in the past 3 years, and current files known to git, excluding all files
     * in .gitingore and only in src and httpdocs
     */
    protected function getFileLists()
    {
        echo "Getting files using git...\n";
        
        $this->updated = array();
        $gitLogParams  = '--pretty=format: --name-only --since="3 years ago"';
        // Files that have been updated in the past 3 years
        exec("git log {$gitLogParams} | sort | uniq", $this->updated);
        
        $this->files = array();
        $directories = implode(' ', $this->gitLSDirectories);
        $gitLSParams = $directories . ' --exclude-standard';
        // current files known to git in src & httpdocs
        exec("git ls-files {$gitLSParams} | sort | uniq", $this->files);
        
        $this->removeIgnoredFiles();
        $this->cleanSymbolicLinks();
        $this->totalFiles = count($this->files);
    }

    /**
     * Uses `git grep` to grep a directory, slightly faster than grep and is 
     * more accurate (grep was used before, but kept returning files that were
     * referenced for some reason)
     */
    protected function gitGrepRepo($returnArray, $grepFor, $args)
    {
        if ($returnArray !== 1) {
            // Only assigns last value from git grep
            $gitGrepResult = exec("git grep {$args} \"{$grepFor}\"");
            return $gitGrepResult;
        } else {
            // Builds git grep to array
            $gitGrepResult = '';
            exec("git grep {$args} \"{$grepFor}\"", $gitGrepResult);
            return $gitGrepResult;
        }
    }

    /**
     * Displays pretty progress stats (percentage, files skipped, total files, 
     * processed files count, Average files processed per second, time elapsed, 
     * time left).
     */
    protected function progressStats()
    {
        $complete      = "{$this->currentCount}/{$this->totalFiles}";
        $filesLeft     = ($this->totalFiles - $this->currentCount);
        $perc          = floor(($this->currentCount / $this->totalFiles) * 100);
        $previousTime  = round($this->previousRunTime);
        $timeElapsed   = round(microtime(true)-$this->time) + $previousTime;
        $secondsLeft   = ($timeElapsed / $this->currentCount) * $filesLeft;
        $secondsLeft   = round($secondsLeft, -1, PHP_ROUND_HALF_UP);
        $elap          = gmdate('H:i:s', $timeElapsed);
        $timeLeft      = gmdate('H:i:s', $secondsLeft);
        $averagePerSec = round($this->currentCount / $timeElapsed, 2);
        $averagePerSec = number_format($averagePerSec, 2);
        $write         = sprintf(
            "\033[0G$perc%% - " . $complete .
            ' - Skipped ' . $this->skippedCount .
            ' - Elapsed ' . $elap . 
            ' - Left ' . $timeLeft . 
            ' - AVG ' . $averagePerSec . '/s');
        fwrite(STDERR, $write);
        $this->timeElapsed     = $timeElapsed;
    }
}
