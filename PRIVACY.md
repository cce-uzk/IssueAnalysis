# IssueAnalysis Privacy

This documentation does not warrant completeness or correctness. Please report any
missing or wrong information via the
[issue tracker](https://github.com/cce-uzk/IssueAnalysis/issues).

## General Information

The plugin reads the error files that ILIAS writes to the error log directory
(Administration → System Settings and Maintenance → Logging → Error Logging). These
files are created by ILIAS itself, independent of the plugin. Besides the error
message and the stacktrace they contain the request data of the failed request
(GET and POST parameters, cookies, session values, server variables), which may
include personal data such as user IDs, login names, IP addresses or entered text.

## Integrated Services

- The plugin employs the following ILIAS services, please consult the respective
  privacy documentation:
    - **Logging Service**: the error log directory and the error files written by
      ILIAS.
    - [Access Control](../../../../../../../Services/AccessControl/PRIVACY.md): read
      permission on the system folder (administration).
    - **Cron Service**: the optional automatic import.

## External Services

The plugin does not send any data to external services.

## Configuration

- **Plugin administration**
    - **Retention (days)**: imported entries older than this are deleted at the next
      import. `0` disables the deletion by age.
    - **Max. records**: when exceeded, the oldest entries are deleted at the next
      import. `0` disables the limit.
    - **Mask sensitive data** (enabled by default): masks system paths, host names,
      IPv4 and IPv6 addresses, e-mail addresses, URLs, the client ID, session IDs
      and the values of sensitive keys (e.g. passwords, tokens, cookies, session
      values such as the user ID) in the detail view.
    - **Time and line limit per import**.
- **Cron job administration**: the automatic import (`xial_import`) is inactive by
  default.

## Data being stored

- **Error entries** (`xial_log`): for each imported error file the **error code**
  (file name), the **time of the error** (modification time of the file), the
  **severity**, the **first line of the error message**, the **file and line** of
  the error and a hash of the stacktrace. The columns for user ID, session ID, IP
  address and user agent hash exist, but are not filled for ILIAS error files.
- **Error types** (`xial_error`): per distinct stacktrace the **stacktrace without
  request data**, file, line, first and last occurrence and number of occurrences.
  The stacktrace is stored as written by ILIAS, without masking; error messages and
  arguments in the stacktrace can contain personal data.
- **Hidden error types**: the **user ID of the administrator** who hid an error type,
  the **time** and an optional note.
- **Import tracking** (`xial_source`): file path, inode and read position of the
  imported files.
- **Plugin settings** and the time of the last manual and automatic import are
  stored in the ILIAS settings.
- **Session**: the filter values of the list and one-time tokens for actions are
  stored in the session of the administrator.
- The **request data** of an error file is **not stored** in the database.

## Data being presented

- Only users with read permission on the system folder (administrators) can open
  the plugin.
- The list shows the stored error entries. The **detail view reads the original
  error file** and shows its complete content including the request data. If
  "Mask sensitive data" is enabled, the content is masked as described above.
  Values are recognised by patterns and key names; personal data in free text
  (e.g. in error messages or entered text with unknown keys) can remain visible.
- "Copy for sharing" always copies a masked version of the content.
- The statistics show numbers of errors per time and per file and recent error
  messages.

## Data being deleted

- Entries are deleted at each import according to the retention period and the
  maximum number of records. Error types without remaining entries are deleted.
- Reducing the retention period deletes older entries immediately.
- "Delete all data" in the list deletes all entries, error types and the import
  tracking.
- Uninstalling the plugin deletes all plugin tables and settings.
- The original error files are **not deleted** by the plugin; they remain in the
  error log directory until they are removed by the operator.
- When an ILIAS user account is deleted, the user ID stored for hidden error types
  is not removed.

## Data being exported

- The list does not offer an export at the moment. The CSV and JSON export prepared
  in the code would contain the stored columns of the selected entries (JSON
  including the stored stacktrace of the error type) without masking.
- The plugin does not take part in the ILIAS export.
