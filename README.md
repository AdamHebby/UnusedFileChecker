# [Unused File Checker](https://github.com/AdamHebby/UnusedFileChecker)

Built in PHP - Unused File Checker does exactly what it sounds like it should, it checks a website repository for unused files for you to later remove or look into as to why they aren't referenced.

Useful features:

 * Save the state of Unused File Checker so you can continue later
 * Turn on Debug mode so Unused File Checker does a deeper search
 * Remove specific folders from Unused File Checker if you know they're okay
 * Limit Unused File Checker to specific folders
 * Ignore Regexes


### How to run?
Before you do anything, you need to check the config file and edit the "directory" value otherwise Unused File Checker won't know where to look!

```
php FileChecker.php 
```

### Flags

Debug Mode on. This takes longer but shows files which weren't found in the first run, it then searches purely for the filenames instead of the full path
```
--debug=true
```

Saves the programs progress regulary which can later be loaded to pickup right where you left off
```
--saveState=true
```

Shows some helpful information
```
--help
```
