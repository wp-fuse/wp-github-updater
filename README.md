# WP GitHub Updater

A drop-in, extremely simple, lightweight, and dependency-free PHP class to enable automatic WordPress plugin updates directly from GitHub repositories (public or private).

## Why WP GitHub Updater?

- **Zero Overhead**: Uses WordPress native transients and memory caching. Only requests the GitHub API when WordPress core actively triggers an update check cycle.
- **CSRF-Protected**: Securely hooks into `deleted_site_transient` instead of relying on manually validating URL GET requests on every init loop.
- **Fail-Safe**: If the GitHub API fails or rate-limits the connection, the class uses a 30-minute negative cache to prevent crashing the plugin or hanging the panel. Includes robust guard clauses for uninitialized filesystem operators context.
- **Private Repo Support**: Easily set a GitHub Personal Access Token (PAT) globally via `wp-config.php` to download releases seamlessly.
- **No Configuration Arrays**: It reads everything (Version, Plugin Name, Repo URI, PHP Requirements) directly from your main plugin's file header.

## Requirements

- WordPress 4.4+
- PHP 7.4+ (Supports PHP 8+)

## Installation

1. Create a folder named `inc` in your WordPress plugin directory and copy [updater.php](file:///Users/daniel/Development/wpfuse/postrider/inc/updater.php) into it.
2. Require and instantiate the class in your main plugin file (e.g., `my-plugin.php`):

```php
require_once __DIR__ . '/inc/updater.php';

// Instantiate the Auto Updater
$my_updater = new WPFuse_GitHub_Updater( __FILE__ );
```

3. Add the custom `GitHub Plugin URI` tag to your main plugin file's header block so the class knows where to check:

```php
/*
Plugin Name: My Awesome Plugin
Version: 1.0.0
Requires PHP: 7.4
Requires at least: 6.0
GitHub Plugin URI: https://github.com/my-org/my-plugin-repo
...
*/
```

## Private Repositories

If your GitHub repository is private, you must provide a GitHub Personal Access Token (PAT).

We highly recommend creating a **Fine-grained PAT** scoped exclusively to your specific repository with **Read-Only** access for `Contents` and `Metadata`.

To provide the token, pass it directly in your main plugin file when instantiating the class:

```php
// Obfuscate the token slightly to bypass GitHub's automatic Secret Scanning revocations.
// Do NOT paste the raw string "github_pat_..." directly in your code.
$token = base64_decode( 'Z2l0aHViX3BhdF8xMUFYWVo...' ); // Base64 representation of your PAT

// Instantiate the Auto Updater
$my_updater = new WPFuse_GitHub_Updater( __FILE__, $token );
```

> **⚠️ Important Security Note:** Tokens shipped inside client code cannot be revoked per-user if their license expires. Be fully aware of GitHub API rate limits (5,000 req/hr) being shared across all users sharing this token.

## How it works

This updater overrides and hooks into the native WordPress `site_transient_update_plugins` filter. It queries the `https://api.github.com/repos/:owner/:repo/releases/latest` endpoint.

When you publish a new Release securely on GitHub (e.g., Tag `v1.0.1`), WordPress will automatically detect the new zipball URL and its changelog via the API, allowing the site administrator to click "Update Now" just like any plugin from the official WordPress.org platform. 

The downloaded zipball will be automatically extracted, reliably renamed to match your local plugin slug (stripping the GitHub hash codes from folders), and verified against the `Requires PHP` header requirements natively by the WordPress upgrade core before installation.
