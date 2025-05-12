# Changelog

All notable changes to the "Fluent Forms Nutshell Integration" plugin will be documented in this file.

## [1.7.0] - 2025-05-10

### Added
- Internationalization of Admin UI
### Fixed
- Potential XSS vulnerabilities in admin interface

## [1.6.0] - 2025-03-03

### Added
- CSRF protection via WordPress nonces for all AJAX operations
- Enhanced security for admin interfaces and form submissions

### Changed
- Improved data sanitization throughout the plugin
- Enhanced output escaping for all user-facing content
- Upgraded API credential handling for better security
- Stricter validation of form IDs and other critical parameters
- Combined the main php file and activator php file

### Fixed
- Potential XSS vulnerabilities in admin interface
- Security issues with unvalidated API responses
- Improved error handling for failed API requests

## [1.5.0] - 2025-03-02

### Added
- Smart cache handling for users and pipelines with automatic refresh when items not found
- Available Forms list with improved visual organization
- Form status indicators showing which forms are included/excluded

### Changed
- Reorganized admin UI for better user experience
- Improved section organization with logical grouping of related features
- Enhanced API validation to prevent errors with invalid user or pipeline IDs
- Better placement of action buttons and notification areas

## [1.4.0] - 2025-02-28

### Added
- New centralized logging system that uses WordPress debug.log
- Improved debugging capabilities with consistent formatting

### Changed
- Removed custom log file in favor of WordPress standard debug.log
- Updated admin UI to clarify logging behavior
- Code cleanup and removed excessive debug statements

## [1.3.0] - 2025-02-27

### Added
- Lead notes creation with template support and dynamic field values
- Company/account connection and linking to leads
- Improved field value extraction for complex form structures

### Changed
- Enhanced error handling and recovery
- Optimized API interactions

## [1.2.0] - 2025-02-27

### Added
- Pipeline (stageset) assignment for leads
- Support for fixed pipelines or dynamically selected via form fields
- Pipeline reference list in admin UI
- Caching system for pipelines data

### Changed
- Improved API response handling
- Enhanced field mapping interface

## [1.1.0] - 2025-02-26

### Added
- Lead assignment to specific Nutshell users
- Owner selection via direct field mapping or default assignment
- User caching for improved performance
- Support for agent email lookup

## [1.0.0] - 2025-02-26

### Added
- Initial release
- Basic integration with Fluent Forms and Nutshell CRM
- Lead and contact creation
- Custom field mapping
- Form field to Nutshell field mapper
- Admin interface for configuration