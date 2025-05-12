# Fluent Forms Nutshell Integration

Integrate Fluent Forms with the Nutshell CRM to automatically create leads, contacts, and related data upon form submission. This plugin provides fine-grained control over field mappings, lead assignment, pipeline selection, and more—directly from your WordPress admin for a seamless integration with [Nutshell CRM](https://www.nutshell.com/)


## Features

- Flexible field mapping via admin UI
- Assign leads to specific Nutshell users
- Select or dynamically assign pipelines
- Supports lead notes with dynamic content
- Per-form inclusion/exclusion control

## Why Choose This Plugin Over the Official One?

[Nutshell Analytics](https://wordpress.org/plugins/nutshell-analytics/) is the official plugin provided by Nutshell. It enables basic lead creation by intercepting form fills and activating analytics tracking. While it's quick to set up, it has limited customization:

- Field mapping is handled through the Nutshell UI with minimal control.
- You can't dynamically assign leads to specific users based on form inputs or page URLs.
- You can't assign leads to specific pipelines or stages.
  
**This plugin is ideal for users who need full control over lead creation workflows**, including:

- Conditional form-level inclusion
- Custom field mapping from Fluent Forms fields
- Dynamic or default lead owner assignment
- Pipeline selection (fixed or via field input)
- Lead notes with mergeable field values

**This plugin is designed for users who need deeper integration and full customization of how leads and contacts are created in Nutshell. It may be necssary to customize this plugin to your exact needs, depending on how complex. Feel free to fork this repo and customize it to your business needs**


## Requirements

- WordPress 5.8 or higher
- Fluent Forms Pro (recommended)
- PHP 7.4 or higher

## Installation

1. Upload the plugin files to the `/wp-content/plugins/` directory, or install via the WordPress Plugin Installer.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Fluent Forms → Nutshell Integration** to configure your API credentials, field mappings, and form settings.

## Usage

1. **Connect your Nutshell account**  
   Enter your API credentials in the plugin settings.

2. **Map your fields**  
   Use the field mapping interface to connect Fluent Form fields with Nutshell lead/contact fields.

3. **Configure form behavior**  
   Choose which forms trigger Nutshell integration. Assign pipelines, set default or dynamic owners, and customize lead notes.

4. **Test the integration**  
   Submit a test form and verify the lead is created in your Nutshell account.

## Developer Resources

- [Nutshell API Reference](https://developers.nutshell.com/reference)
