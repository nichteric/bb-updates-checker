# BB Updates Checker

# Plugin

This plugin adds the 'check for updates' functionality to themes and plugins.
It should added to the `mu-plugins` folder.

The update url is hardcoded in the plugin:

```
define('BB_UPDATE_CHECKER_URL', 'https://your.update.server/path/');

```

To activate this mechanism add the following lines to the plugin header or the
theme `style.css`.

```
* BB Updates:     enabled
```

# Server

Place this file somewhere on your web server. Create two sub-directories
`themes` and `plugins` where you can upload the files to be served.

Set the correct value of `BB_UPDATE_CHECKER_URL` in `bb-updates-checker.php`.
