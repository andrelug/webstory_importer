=== Web Story Importer ===
Contributors: your-name
Donate link: https://yourwebsite.com/donate
Tags: web stories, import, google web stories
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Imports Web Stories from a ZIP file containing HTML and assets into the Google Web Stories plugin format.

== Description ==

This plugin allows you to upload a ZIP archive containing a pre-built Web Story (an HTML file and its associated assets folder).
It processes the ZIP, uploads images to the WordPress Media Library, updates asset paths in the HTML, and attempts to create a new Web Story post compatible with the official Google Web Stories plugin.

This requires the Google Web Stories plugin to be installed and active.

== Installation ==

1. Upload the `web-story-importer` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Make sure the Google Web Stories plugin is installed and activated.
4. Go to the "Web Story Importer" menu in the WordPress admin sidebar to upload your ZIP file.

== Frequently Asked Questions ==

= Does this work with any Web Story export? =

It's designed for ZIP files containing a single HTML file and an `assets` folder with images/media. Compatibility with various export tools may vary.

= Does it preserve animations and complex layouts? =

The conversion process attempts to map the HTML structure to the Google Web Stories format. Simple layouts, text, and images should be preserved. Complex animations or features unique to the exporting tool might not be fully translated.

== Changelog ==

= 1.0.0 =
* Initial release.

