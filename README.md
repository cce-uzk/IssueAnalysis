# IssueAnalysis Plugin for ILIAS 9

> **A comprehensive error log analysis plugin for ILIAS administrators**

The IssueAnalysis plugin is a UIComponent UserInterfaceHook plugin for ILIAS 9 that provides administrators with comprehensive error log analysis capabilities. It integrates seamlessly into the ILIAS administration interface, allowing administrators to import, analyze, and manage ILIAS error logs through a modern, user-friendly interface.

**Plugin ID:** `xial`
**Compatible with:** ILIAS 9.0 - 9.999
**License:** GPL v3 (same as ILIAS)

## Table of Contents

- [Features](#features)
- [System Requirements](#system-requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Security Features](#security-features)
- [Data Management](#data-management)
- [Troubleshooting](#troubleshooting)
- [Technical Documentation](#technical-documentation)
- [Development](#development)
- [Support](#support)

## Features

### Error Log Management
- **Automated log import** from ILIAS error log directory
- **Manual import** with "Import Now" button
- **Incremental import** with file tracking to prevent duplicate imports
- **Retention-aware import** (only imports entries within retention period)
- **Performance limits** with configurable time and line limits

### Advanced Analysis Interface
- **Modern ILIAS UI table** with sorting, pagination, and comprehensive filtering
- **Multi-criteria filters**: Severity levels, date ranges, error codes, full-text search
- **Detailed error views** with complete stacktraces and request context
- **Clickable error codes** for quick access to detailed information
- **Customizable table columns** (Level and Message columns can be hidden)

### Data Management
- **Rolling window retention** with automatic cleanup of old entries
- **Configurable data limits** with oldest-first deletion when limits are reached
- **Sensitive data masking** automatically sanitizes passwords, tokens, and cookies
- **Export capabilities** for CSV and JSON formats supporting filtered data

### Automated Operations
- **Cron job integration** for scheduled, unattended imports
- **ILIAS Cron Manager** compatibility with flexible scheduling
- **Background processing** without requiring user intervention
- **Status monitoring** and comprehensive error reporting

### Security & Privacy
- **Administrator-only access** with full RBAC integration
- **Automatic data sanitization** of sensitive information
- **CSRF protection** for all plugin operations
- **Secure file access** with proper permission validation

## System Requirements

- **ILIAS Version:** 9.0 - 9.999
- **PHP Version:** 8.0+ with `declare(strict_types=1)` support
- **Database:** MySQL/MariaDB (uses ILIAS database abstraction)
- **Web Server:** Apache/Nginx with read access to ILIAS error log directory
- **Permissions:** System administrator privileges required for plugin usage

## Installation

### Step 1: Download and Extract
```bash
# Navigate to ILIAS plugin directory
cd /path/to/ilias/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/

# Clone or extract the plugin
git clone https://github.com/cce-uzk/IssueAnalysis.git
# OR extract from archive to: IssueAnalysis/
```

### Step 2: Install via ILIAS Administration
1. Navigate to **Administration → Plugins** in your ILIAS installation
2. Locate the **IssueAnalysis** plugin in the list
3. Click **Install** to create database tables and register components
4. Click **Activate** to enable the plugin
5. Database schema and cron job registration will be handled automatically

### Step 3: Verify Installation
- Confirm **Administration** menu shows "Fehleranalyse" entry
- Check **Administration → Cron Jobs** for "IssueAnalysis Log Import" job
- Verify plugin appears as active in **Administration → Plugins**

## Configuration

### Step 1: Error Log Directory Setup

Before using the plugin, ensure ILIAS error logging is properly configured:

1. Navigate to **Administration → System Settings → Logging → Error Logging**
2. Set the "Error Log Directory" path to a directory accessible by the web server
3. Ensure the web server user has read permissions on the directory and log files
4. Verify that ILIAS is actively writing error logs to this location

### Step 2: Plugin Settings

Access plugin settings via **Administration → Fehleranalyse → Settings**:

#### Data Retention Configuration
- **Retention Period (Days)**: Automatically delete entries older than the specified number of days
  - `0` = Retention disabled (entries are never automatically deleted)
  - Recommended: 30-90 days for typical installations
- **Maximum Records**: Total database limit with rolling window cleanup
  - `0` = Unlimited storage (not recommended for production)
  - When limit is reached, oldest entries are automatically removed

#### Data Security Settings
- **Mask Sensitive Data**: Automatically sanitize sensitive information in error logs
  - **Recommended: Enabled** to protect passwords, tokens, cookies, and personal data
  - Masks data while preserving error analysis capabilities

#### Import Performance Settings
- **Time Limit per Import**: Maximum execution time for single import operation
  - Default: 120 seconds
  - Adjust based on server performance and log file sizes
- **Entries Limit per Import**: Maximum number of entries processed per import run
  - Default: 1000 entries
  - Prevents memory exhaustion on large log files

#### Cron Job Information
The settings page displays information about the automated import cron job available in **Administration → Cron Jobs**.

## Usage

### Manual Error Log Import

For immediate analysis of recent errors:

1. Navigate to **Administration → Fehleranalyse**
2. Click the **"Import Now"** button in the main interface
3. Review the import results summary showing:
   - Number of new entries imported
   - Number of entries skipped (duplicates)
   - Processing time and performance metrics
4. New entries will immediately appear in the error list table

### Error Analysis Interface

#### Filtering and Search
The plugin provides comprehensive filtering options:

- **Severity Filter**: Filter by error levels (ERROR, WARNING, NOTICE, FATAL_ERROR, PARSE_ERROR)
- **Date Range**: Specify start and end dates for temporal analysis
- **Error Code Search**: Filter by specific error codes or patterns
- **Full-Text Search**: Search across error messages, file paths, and error codes
- **Combined Filters**: Use multiple filters simultaneously for precise analysis

#### Viewing Error Details
- **Quick View**: Error codes in the table are clickable for immediate access to details
- **Detail View**: Comprehensive error information including:
  - Complete error messages and descriptions
  - Full stack traces with collapsible frames
  - Request context and sanitized request data
  - User information and system state at time of error
- **Copy Functions**: Copy original or sanitized versions of error data for sharing

#### Table Management
- **Column Customization**: Show/hide Level and Message columns via table controls
- **Sorting**: Click any column header to sort data (timestamp, severity, file, etc.)
- **Pagination**: Navigate through large result sets with configurable page sizes
- **Export Options**: Export filtered data to CSV or JSON formats

### Automated Import (Cron Job)

#### Setting Up Automated Import
1. Navigate to **Administration → Cron Jobs** in ILIAS
2. Locate **"IssueAnalysis Log Import"** (Job ID: `xial_import`)
3. **Activate** the cron job
4. Configure the **schedule** (default: every 30 minutes)
5. Optionally test the job using the **"Start Now"** button

#### Monitoring Automated Import
- **Status Monitoring**: Check job status and last execution time in Cron Jobs administration
- **Result Tracking**: Review import statistics and any errors in the job history
- **Log Monitoring**: Plugin operations are logged to ILIAS system logs with component "xial"

## Data Management

### Database Architecture

The plugin uses a three-table architecture optimized for performance and scalability:

- **`xial_log`**: Primary error entries containing timestamp, severity, message, file path, user information, and error codes
- **`xial_detail`**: Detailed error information including stack traces and request data (separated for performance optimization)
- **`xial_source`**: File tracking system for incremental import functionality (prevents duplicate processing)

### Automated Data Retention

#### Time-Based Retention
- Entries older than the configured retention period are automatically deleted
- Cleanup runs during import operations and when retention settings are modified
- Retention can be disabled by setting the period to 0 (not recommended for production)

#### Count-Based Limits
- When the maximum record limit is reached, oldest entries are automatically removed
- Implements a rolling window approach to maintain recent error history
- Protects against database growth in high-error environments

#### Manual Data Management
- **Settings Changes**: Modifying retention settings triggers immediate cleanup of affected data
- **Source Tracking Reset**: Administrators can reset import tracking to allow re-processing of log files
- **Selective Cleanup**: Retention cleanup respects filter settings and only processes eligible entries

### Data Export Capabilities

The plugin provides flexible export options for further analysis:

- **CSV Export**: Structured data export suitable for spreadsheet analysis and reporting
- **JSON Export**: Machine-readable format for programmatic processing and integration
- **Filtered Exports**: Export functionality respects active filters, allowing targeted data extraction
- **Sanitized Exports**: All exports automatically apply data sanitization rules for safe sharing

## Security Features

### Access Control and Permissions
- **Administrator-Only Access**: Plugin functionality is restricted to users with system administration privileges
- **RBAC Integration**: Full integration with ILIAS Role-Based Access Control system
- **Session Validation**: All operations require valid ILIAS authentication and active sessions
- **Permission Verification**: Each request validates user permissions before processing

### Comprehensive Data Sanitization

The plugin automatically sanitizes sensitive information in error logs:

#### Credential Protection
- **Passwords**: Any field containing "password", "pwd", "pass", or similar patterns
- **API Tokens**: Session tokens, authentication tokens, and API keys
- **Cookies**: All cookie values are masked to prevent session hijacking
- **Private Keys**: SSH keys, certificates, and cryptographic material

#### Privacy Protection
- **IP Address Masking**: Last octet of IP addresses is masked for privacy compliance
- **User Agent Hashing**: User agent strings are hashed for anonymization while preserving uniqueness
- **Personal Data**: Any detected personal information is automatically sanitized

#### Data Integrity
- **Selective Masking**: Sanitization preserves error analysis capabilities while protecting sensitive data
- **Reversible Masking**: Original data structure is maintained for debugging purposes
- **Export Safety**: All export functions apply sanitization rules automatically

### Security Compliance
- **CSRF Protection**: All plugin operations include CSRF token validation to prevent cross-site request forgery
- **Input Validation**: All user inputs are validated and sanitized before processing
- **Secure File Access**: File system operations include proper permission checks and path validation
- **Audit Logging**: All administrative actions are logged for security auditing

## Troubleshooting

### Common Issues and Solutions

#### Import Problems

**"No entries imported"**
- Verify error log directory path in **Administration → System Settings → Logging**
- Ensure web server user has read permissions on log directory and files
- Check that log files contain parseable error entries in expected format
- Review plugin import limits (time/line limits) in plugin settings
- Confirm ILIAS is actively writing to the configured error log directory

**"Import stops prematurely"**
- Increase time limit in plugin settings if import times out
- Increase memory limit in PHP configuration for large log files
- Check for corrupted log files or unexpected format changes
- Review import line limits and adjust if necessary

#### Cron Job Issues

**"Cron job not visible in administration"**
- Verify plugin is properly activated in **Administration → Plugins**
- Confirm plugin implements `ilCronJobProvider` interface correctly
- Try deactivating and reactivating the plugin to re-register cron job
- Check ILIAS logs for plugin initialization errors

**"Cron job fails to execute"**
- Verify error log directory permissions for background processes
- Check PHP time limits and memory limits for cron execution
- Review cron job logs in **Administration → Cron Jobs** for specific error messages
- Ensure ILIAS cron job mechanism is properly configured on the server

#### Permission and Access Issues

**"Permission denied errors"**
- Confirm web server user can read error log directory and files
- Verify file permissions are correctly set (typically 644 for files, 755 for directories)
- Check that ILIAS error logging is enabled and properly configured
- Ensure administrator privileges are properly assigned to users

### Debugging and Monitoring

#### Plugin Log Files
All plugin operations are logged to ILIAS system logs with component identifier "xial":

```bash
# Monitor plugin operations in real-time
tail -f /path/to/ilias/logs/ilias.log | grep xial

# Search for specific error patterns
grep "xial.*ERROR" /path/to/ilias/logs/ilias.log
```

#### Import Statistics and Monitoring
Each import operation provides detailed statistics:

- **Import Results**: Number of new entries imported and entries skipped
- **Performance Metrics**: Processing time and memory usage
- **Error Details**: Specific errors and warnings encountered during import
- **File Status**: Information about processed files and tracking status

#### System Health Checks
- **Database Performance**: Monitor query performance on large error datasets
- **Disk Space**: Ensure adequate space for log file processing and database growth
- **Memory Usage**: Monitor PHP memory usage during large imports
- **Log Rotation**: Verify log rotation doesn't interfere with import tracking

## Technical Documentation

### Architecture Overview

The IssueAnalysis plugin follows modern ILIAS 9 design patterns and best practices:

- **UIComponent UserInterfaceHook Plugin**: Integrates seamlessly into ILIAS administration interface
- **Modern ILIAS 9 Framework**: Utilizes current UI components and design patterns
- **Repository Pattern**: Clean separation of data access logic from business logic
- **Service-Oriented Design**: Modular architecture with dedicated service classes
- **Dependency Injection**: Uses ILIAS DIC (Dependency Injection Container) throughout

### Component Structure

```
IssueAnalysis/
├── plugin.php                                      # Plugin definition and metadata
├── classes/
│   ├── class.ilIssueAnalysisPlugin.php            # Main plugin class with cron provider
│   ├── class.ilIssueAnalysisGUI.php               # Primary interface controller
│   ├── class.ilIssueAnalysisAdminGUI.php          # Administration interface
│   ├── class.ilIssueAnalysisTableGUI.php          # Error list table component
│   ├── class.ilIssueAnalysisRepo.php              # Database repository layer
│   ├── class.ilIssueAnalysisImporter.php          # Log parsing and import engine
│   ├── class.ilIssueAnalysisSettings.php          # Configuration management
│   ├── class.ilIssueAnalysisConfigGUI.php         # Settings interface
│   ├── class.ilIssueAnalysisSanitizer.php         # Data sanitization service
│   ├── class.ilIssueAnalysisUIHookGUI.php         # UI hook implementation
│   ├── MainBar/
│   │   └── ilIssueAnalysisMainBarProvider.php     # ILIAS GlobalScreen menu integration
│   └── Cron/
│       ├── class.ilIssueAnalysisImportJob.php     # Automated import cron job
│       └── class.ilIssueAnalysisCronJobFactory.php # Cron job factory pattern
├── sql/
│   └── dbupdate.php                                # Database schema and migrations
├── lang/
│   ├── ilias_de.lang                              # German language file
│   └── ilias_en.lang                              # English language file
└── templates/
    └── images/
        └── icon_issueanalysis.svg                 # Plugin icon (SVG format)
```

### Performance Optimizations

#### Import Performance
- **Incremental Processing**: Only processes new log entries using file tracking system
- **Chunked Processing**: Configurable limits prevent memory exhaustion and timeout issues
- **Parallel-Safe Design**: Multiple import processes can run simultaneously without conflicts
- **Retention-Aware Import**: Skips entries outside retention window to improve efficiency

#### Database Performance
- **Optimized Schema**: Indexed columns for common query patterns (timestamp, severity, file)
- **Lazy Loading**: Detailed error information loaded only when explicitly requested
- **Query Optimization**: Efficient filtering and pagination queries
- **Automatic Cleanup**: Retention system prevents database bloat

#### Memory Management
- **Stream Processing**: Large log files processed in chunks rather than loaded entirely
- **Resource Cleanup**: Proper resource disposal in all processing loops
- **Memory Limits**: Configurable limits prevent excessive memory usage

## Development

### Development Requirements

#### System Requirements
- **ILIAS Version**: 9.0 - 9.999
- **PHP Version**: 8.0+ with strict types support (`declare(strict_types=1)`)
- **Database**: MySQL 8.0+ or MariaDB 10.3+ (uses ILIAS database abstraction layer)
- **Web Server**: Apache 2.4+ or Nginx with proper PHP-FPM configuration

#### Development Environment
- **IDE Support**: Modern IDE with PHP 8+ support and ILIAS code completion
- **Debugging**: Xdebug configuration for development and debugging
- **Testing**: Access to ILIAS 9 test installation for plugin development

### Code Standards and Conventions

#### ILIAS Compliance
- **ILIAS Coding Standards**: Strict adherence to ILIAS plugin development guidelines
- **UI Framework**: Exclusive use of ILIAS 9 UI components (no legacy UI elements)
- **Naming Conventions**: Follows ILIAS class and method naming patterns
- **Database Layer**: Uses ILIAS database abstraction for all database operations

#### Modern PHP Practices
- **Strict Typing**: All files use `declare(strict_types=1)`
- **Constructor Property Promotion**: Utilizes PHP 8+ constructor features where appropriate
- **Type Declarations**: Complete type hints for all method parameters and return values
- **PSR Compliance**: Follows PSR-4 autoloading and PSR-3 logging standards

### Extension and Customization Points

The plugin architecture supports various extension scenarios:

#### Import System Extensions
- **Custom Log Formats**: Extend `ilIssueAnalysisImporter` to support additional log formats
- **Additional Parsers**: Add support for different error log structures
- **Enhanced Filtering**: Implement additional import filtering criteria

#### Data Processing Extensions
- **Custom Sanitization Rules**: Extend `ilIssueAnalysisSanitizer` with organization-specific sanitization
- **Additional Export Formats**: Add new export formats beyond CSV and JSON
- **Custom Data Transformations**: Implement additional data processing pipelines

#### UI and Interface Extensions
- **Additional Views**: Create custom analysis views and dashboards
- **Enhanced Filtering**: Add domain-specific filter options
- **Custom Reports**: Implement specialized reporting functionality

#### Integration Extensions
- **Additional Cron Jobs**: Use the cron job factory pattern for custom automated tasks
- **External System Integration**: Add connectors for external monitoring systems
- **API Extensions**: Implement additional API endpoints for programmatic access

### Plugin Architecture Benefits

#### Maintainability
- **Modular Design**: Clear separation of concerns across service classes
- **Repository Pattern**: Database operations isolated in dedicated repository classes
- **Dependency Injection**: Facilitates testing and component replacement

#### Scalability
- **Performance Optimization**: Built-in performance monitoring and optimization
- **Resource Management**: Efficient memory and processing resource utilization
- **Concurrent Processing**: Support for parallel import operations

#### Security
- **Input Validation**: Comprehensive validation of all user inputs
- **Permission Checks**: Multi-layer permission validation
- **Data Sanitization**: Automatic sanitization of sensitive information

## Support and Community

### Documentation Resources
- **Comprehensive README**: This document provides complete usage and development guidance
- **Inline Documentation**: Extensive code comments and PHPDoc annotations
- **Technical Architecture**: Detailed component and interaction documentation

### Community Support
- **ILIAS Community Forums**: Standard ILIAS plugin support channels and discussions
- **GitHub Issues**: Issue tracking and feature requests via repository issue system
- **Developer Documentation**: Additional technical resources for plugin developers

### Issue Reporting and Contributions
- **Bug Reports**: Submit detailed bug reports with reproduction steps and environment information
- **Feature Requests**: Propose new features with clear use cases and implementation considerations
- **Code Contributions**: Follow standard ILIAS plugin contribution guidelines for code submissions

## License and Attribution

This plugin is released under the **GNU General Public License v3 (GPL v3)**, maintaining compatibility with ILIAS core licensing.

### Plugin Metadata
- **Plugin ID**: `xial`
- **Version**: 1.0.0
- **Compatibility**: ILIAS 9.0 - 9.999
- **Maintained by**: University of Cologne Computing Centre
- **Repository**: https://github.com/cce-uzk/IssueAnalysis

---

*For additional technical support or specific implementation questions, please refer to the ILIAS community forums or submit issues through the GitHub repository.*