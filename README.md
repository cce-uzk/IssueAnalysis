# IssueAnalysis for ILIAS 10

ILIAS 10 UserInterfaceHook plugin (ID `xial`) for analysing the error files that ILIAS writes to its error log directory. Administrators import the error files into the database and get a filterable list, a detail view and statistics under **Administration → Issue Analysis** ("Fehleranalyse").

Identical errors are recognised by a hash of their stacktrace, so error types that occur thousands of times can be hidden with one click. The database only holds metadata; stacktrace and request data of a single error are read from the original error file when the detail view is opened.

Developed by the CompetenceCenter E-Learning, University of Cologne.

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Updating](#updating)
- [Configuration (administrators)](#configuration-administrators)
- [Usage](#usage)
- [Permissions](#permissions)
- [Data protection and data storage](#data-protection-and-data-storage)
- [How it works](#how-it-works)
- [Tests and code style](#tests-and-code-style)
- [Troubleshooting](#troubleshooting)
- [License and contact](#license-and-contact)

## Features

- Manual import ("Import Now") and optional cron job for the error files of the ILIAS error log directory
- Incremental import: files that were already imported are skipped; time and entry limit per run
- Deduplication of error types by a SHA-256 hash of the stacktrace (without request data)
- Hiding and showing of error types; a hidden type hides all its occurrences
- ILIAS 10 data table with sorting, pagination and column selection; filter for severity, error code, full text and hidden errors
- Detail view with the complete content of the error file, optionally masked, and "Copy Original" / "Copy Safe for Sharing"
- Statistics: errors per time range, top error files, recent critical errors
- Retention period and maximum number of records

## Requirements

- ILIAS 10.0 – 10.x
- PHP 8.2 or higher with `json` and `mbstring`
- Read access of the web server user (and of the cron user) to the ILIAS error log directory

## Installation

1. Copy the plugin to

   ```
   public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/IssueAnalysis/
   ```

   e.g. with `git clone https://github.com/cce-uzk/IssueAnalysis.git IssueAnalysis`.
2. In the ILIAS root directory run `composer du` so that the ILIAS class map contains the plugin classes.
3. Administration → Extending ILIAS → Plugins: install and activate **IssueAnalysis**. The database tables are created by `sql/dbupdate.php`.
4. Make sure the error log directory is configured (see below). The menu entry **Issue Analysis** appears in the Administration menu.

## Updating

1. Replace the plugin directory with the new version.
2. Run `composer du` in the ILIAS root directory.
3. Administration → Extending ILIAS → Plugins: **Update** IssueAnalysis to run the database steps.

## Configuration (administrators)

**Error log directory.** The plugin reads the directory configured in Administration → System Settings and Maintenance → Logging → Error Logging (`error_path` in `ilias.ini.php`). The path is never configured in the plugin.

**Plugin settings** (Administration → Extending ILIAS → Plugins → IssueAnalysis → Configure):

| Setting | Default | Description |
|---|---|---|
| Retention (days) | 30 | Entries older than this are deleted at the next import and not imported at all. `0` disables the deletion by age. Reducing the value deletes older entries immediately. |
| Max. records | 10000 | The oldest entries above this number are deleted at the next import. `0` disables the limit. |
| Mask sensitive data | on | Masks paths, host names, IPv4/IPv6 addresses, e-mail addresses, URLs, client ID, session IDs and the values of sensitive keys (passwords, tokens, cookies, session values such as the user ID) in the detail view. |
| Time limit per import (s) | 120 | A run stops after this time; the next run continues. |
| Entries per import | 1000 | A run stops after this number of imported files. |

**Cron job.** Administration → System Settings and Maintenance → Cron Jobs → **IssueAnalysis Log Import** (`xial_import`). The job is inactive by default; the default schedule is every 30 minutes.

## Usage

- **Error List**: "Import Now" imports new error files and reports the number of imported and skipped files. "Clear All Data" deletes all imported data (the error files are kept). The row actions open the detail view or hide/show the error type of the row.
- **Filter**: severity, error code, full text (message, file, code) and "Hidden Errors" (hidden types are excluded by default).
- **Detail view**: error code, time, severity, file and line, whether the original file is still available, and the complete content of the file. If the file was deleted, the stored stacktrace of the error type is shown instead.
- **Statistics**: choose a time range (today, yesterday, last week, last month, last 3 months).

## Permissions

- All views and actions require read permission on the system folder (Administration). The check is done in the controller for every request; anonymous users are always rejected.
- The menu entry is only shown to users with this permission.
- Actions that change data ("Import Now", "Clear All Data") are protected by one-time tokens against repeated and forged requests; the data table actions use the ILIAS URL builder.

## Data protection and data storage

**Stored in the ILIAS database**
- Per error file: error code, time, severity, first line of the message, file, line and stacktrace hash
- Per error type: the stacktrace without request data (not masked), occurrences, first and last occurrence, and for hidden types the user ID of the administrator
- Read position of the imported files

**Not stored**: the request data (GET/POST, cookies, session) of the error files. It is read from the original file for the detail view only.

The original error files are written by ILIAS and are not changed or deleted by the plugin. No data is sent to external services.

Details on stored, presented, deleted and exported data are listed in [PRIVACY.md](PRIVACY.md).

## How it works

### Import

1. The importer reads the error log directory and skips files whose error code (file name without `.log`) has already been imported.
2. For each new file it extracts the first line as message, the first `file.php:line` reference, and the stacktrace part before the first request data section (`-- GET Data --`, `-- POST Data --`, `-- Files --`, `-- Cookies --`).
3. The SHA-256 hash of this stacktrace part identifies the error type. The type is inserted into `xial_error` or its counter and last occurrence are updated; the single error is inserted into `xial_log`.
4. After the run, entries outside the retention period or above the maximum number of records are deleted, as well as error types without entries.

### Database

| Table | Content |
|---|---|
| `xial_log` | One row per error file (error code, time, severity, message, file, line, stacktrace hash) |
| `xial_error` | One row per error type (stacktrace hash as primary key, stacktrace, counters, hidden flag) |
| `xial_source` | Import tracking per file (path, inode, offset) |

The list and the statistics only read these tables. The detail view reads one error file.

### Structure

```
IssueAnalysis/
├── plugin.php                     Plugin metadata
├── classes/
│   ├── class.ilIssueAnalysisPlugin.php        Plugin, cron provider, uninstall
│   ├── class.ilIssueAnalysisAdminGUI.php      Entry point below ilAdministrationGUI
│   ├── class.ilIssueAnalysisGUI.php           List, detail view, statistics, actions
│   ├── class.ilIssueAnalysisTableGUI.php      Data table and data retrieval
│   ├── class.ilIssueAnalysisConfigGUI.php     Plugin settings
│   ├── class.ilIssueAnalysisImporter.php      Import of the error files
│   ├── class.ilIssueAnalysisRepo.php          Database access
│   ├── class.ilIssueAnalysisSanitizer.php     Masking of sensitive data
│   ├── class.ilIssueAnalysisSettings.php      Settings (ilSetting "xial")
│   ├── Cron/                                  Cron job
│   └── MainBar/                               Menu entry
├── src/                           Namespaced services and exceptions (ILIAS\Plugin\xial)
├── sql/dbupdate.php               Database steps
├── templates/default/             Template for the content of an error file
├── css/xial.css                   Styles of the detail view
├── js/xial.js                     Copy to clipboard in the detail view
├── lang/                          German and English language files
└── tests/                         PHPUnit tests
```

## Tests and code style

Unit tests (PHPUnit 10.5, as used by ILIAS 10) cover the error file parser and the stacktrace hash, the sanitizer, the settings, the double submission guard and the string truncation. They run without an ILIAS installation:

```bash
cd public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/IssueAnalysis
<path to>/phpunit -c phpunit.xml
```

The code follows the ILIAS [PHP coding style](https://github.com/ILIAS-eLearning/ILIAS/blob/release_10/docs/development/php-coding-style.md) (PSR-12 with ILIAS additions) and the [JavaScript coding style](https://github.com/ILIAS-eLearning/ILIAS/blob/release_10/docs/development/js-coding-style.md). Checks from the ILIAS root directory:

```bash
libs/composer/vendor/bin/php-cs-fixer fix --dry-run --diff --config=./CI/PHP-CS-Fixer/code-format.php_cs <plugin directory>
node_modules/.bin/eslint -c .eslintrc.json --no-eslintrc <plugin directory>/js/xial.js
```

## Troubleshooting

| Symptom | Cause and solution |
|---|---|
| "Import completed: No new entries found" although errors occurred | Check the error log directory in the Logging settings and the read permissions of the web server user |
| Import stops before all files are imported | Time or entry limit reached; run the import again or raise the limits |
| Cron job is missing in the cron job administration | Check that the plugin is active; deactivate and activate it again |
| Cron job imports nothing | The cron user needs read access to the error log directory |
| Original file "No longer available (DB data only)" in the detail view | The error file was deleted or rotated; the stored stacktrace of the error type is shown |
| Class not found after an update | Run `composer du` in the ILIAS root directory |

Plugin messages are written to the ILIAS log with the component `xial`.

## License and contact

GNU General Public License v3.0, the same license as ILIAS. See [LICENSE](LICENSE).

Developed by the CompetenceCenter E-Learning, University of Cologne.
Issues and questions: [GitHub issues](https://github.com/cce-uzk/IssueAnalysis/issues) or nadimo.staszak@uni-koeln.de.
