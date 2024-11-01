=== WP Easy Export Import ===
Contributors: nerdaryan, wpcafe
Donate link: https://wp.cafe
Tags: import, export
Requires at least: 5.1
Tested up to: 5.2
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Makes it easier to migrate posts between sites.

== Description ==

Goal of this plugin is to make post migration between sites easier. Simply select the post type you wish to migrate in host site and then run "import" from guest site.

**Features**

* Migrate any post types.
* Migrates custom fields.
* Download and create featured images as attachment.
* Download any image found in post_content and creates attachment and updates `img` src attribute.
* Check existing posts by `post_name` and if exists then update existing.
* Migrate terms and taxonomies

After installing the plugin go to WP-Admin->Tools->WP Easy Export Import and then select the type of site.

**Host site**

*The site from where posts will be migrated.*

After setting site type as "host site", set secret key and select the post type you want to migrate along with meta fields.

**Guest site**

*The site where where posts will be migrate.*

After setting site type as "guest site", copy and paste secret key you have added in "host site" and enter url of host site. Once saved click on import and do not close/refresh the page while import is running.

== Installation ==

This section describes how to install the plugin and get it working.

e.g.

1. Upload `wp-easy-export-import.php` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to Tools and follow the configuration process.

== Frequently Asked Questions ==

= Is this secure? =

Yes, unless you share your secret key. Assume secret key as a password.

== Screenshots ==

1. This screen shot description corresponds to screenshot-1.(png|jpg|jpeg|gif). Note that the screenshot is taken from
the /assets directory or the directory that contains the stable readme.txt (tags or trunk). Screenshots in the /assets
directory take precedence. For example, `/assets/screenshot-1.png` would win over `/tags/4.3/screenshot-1.png`
(or jpg, jpeg, gif).
2. This is the second screen shot

== Changelog ==

= 1.0.0 =
Initial release of this version.