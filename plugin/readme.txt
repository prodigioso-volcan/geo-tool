=== GEO Tool by JUNGLE ===
Contributors: prodigiosovolcan
Tags: seo, geo, alt text, meta description, ai
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks that a site is ready for search engines and AI assistants, and proposes the missing texts with AI for a person to review.

== Description ==

Before a site goes live many small things have to be right: every image with alt text, every page with a meta description, no "lorem ipsum" left, a site that does not ask search engines to ignore it. GEO Tool checks them all, tells you what is missing and helps you fill it in.

This plugin:

* Audits images, pages, posts, categories, authors and site settings. The audit uses no AI and has no cost.
* Proposes, with the AI provider you connected in Settings > Connectors, the alt text of images and the meta descriptions, SEO titles, excerpts and category descriptions that are missing.
* Never changes anything by itself: a person approves, edits or dismisses each proposal.
* Saves approved text in the media library, in the content and in the fields of the SEO plugin (Yoast SEO or Rank Math), and lets you undo it.
* Finds the images a theme paints when the page is shown (ACF blocks, for instance), not only the ones saved in the content.
* Shows a dashboard by area that tells you at a glance whether everything is green.

= What it needs =

* WordPress 7.0 or later.
* Yoast SEO or Rank Math, for meta descriptions, titles and most site checks. Without one of them only images are handled.
* An AI provider connected in Settings > Connectors, with an API key. API keys are billed by use by the provider and are separate from a ChatGPT, Claude or Gemini subscription. The plugin never reads or stores the key.

= Privacy =

To describe an image, the plugin sends a reduced copy of it (1024 px on its longest side at most), its file name, its title, its current alt text and the title of one page where it is used to the AI provider you connected. To propose a meta description, a title, an excerpt or a category description, it sends the title and up to 3000 characters of the visible text of that published page, and only if you allowed it in the settings. Nothing is sent anywhere else. A daily limit of requests, 200 by default, protects you from unexpected costs.

= Known limits =

* Meta descriptions and titles are read from and written to Yoast SEO or Rank Math. With both active, Yoast SEO is used.
* With Rank Math, the first lines of the content that it publishes when nobody wrote a description do not count as a description.
* Images placed with Elementor are updated in the media library but not rewritten inside those pages. The dashboard tells you how many.
* The plugin composes the content and its blocks, not the header or footer of the theme.
* SVG files and files that are not images are not audited.
* One language per site: texts are written in the site language.

== Installation ==

1. Upload the zip in Plugins > Add New > Upload Plugin and activate it.
2. Follow the setup wizard: connect an AI provider, test it, choose your preferences and run the first audit.
3. Review what is left in the GEO Tool menu.

== Frequently Asked Questions ==

= Can I use my ChatGPT, Claude or Gemini subscription instead of an API key? =

The official connectors (OpenAI, Anthropic and Google) need an API key, which is billed by use and is separate from a subscription. A community connector, AI Provider for ChatGPT, signs in with a ChatGPT Plus, Pro or Go plan and needs no API key; it is not maintained by this project and has not been tested with it. GEO Tool works with any provider registered in the WordPress AI Client that offers a model accepting images.

= Does it overwrite alt text someone wrote by hand? =

No. In a page, alt text is replaced only when it is empty, is a copy of the old media library text, or fails the audit rules. Good, page-specific alt text is kept. Approved, ignored and decorative decisions are never questioned again unless someone edits the text by hand.

= How do I update it? =

New versions published in the project's GitHub repository show up in the Plugins screen like any other update. The plugin only asks GitHub for the latest release; it sends no data about your site.

= Background tasks do not run =

The plugin relies on WP-Cron. If the dashboard shows background tasks in red, check that WP-Cron works on the site or set up a server cron job.

== Changelog ==

= 0.4.0 =
* Beyond images: checks meta descriptions, SEO titles, excerpts, headings, leftover filler text, empty ACF fields, featured images, category descriptions, author profiles and 15 site settings.
* Proposes meta descriptions, titles, excerpts and category descriptions with AI, for review. Needs Yoast SEO or Rank Math, and your permission to send page text to the AI provider.
* New top-level GEO Tool menu with a dashboard by area and the Images, Texts and Site tabs.
* Images: usages are read from the page as it is shown, so images painted by the theme are found. Images no page uses are listed apart; images the theme always shows without alt text are proposed as decorative without using AI.
* Works with Yoast SEO and with Rank Math.
* Test pages (WordPress sample content, pages titled "Test", "Prueba 2"...) are flagged for deletion instead of being completed, and can be sent to the trash from the list.
* Works with AI models that reason before answering.

= 0.3.0 =
* The plugin is now called GEO Tool by JUNGLE (folder and text domain: geo-tool). If you had Revisor ALT installed, uninstall it and install this one.

= 0.2.2 =
* Works with connectors that do not use an API key (sign-in or local models): availability is now asked to WordPress instead of inferred from the key.
* Documentation: list of available providers.

= 0.2.1 =
* The plugin author is now JUNGLE.

= 0.2.0 =
* The plugin was renamed to Revisor ALT by JUNGLE.
* New versions are offered in the Plugins screen, from the project's GitHub releases.
* Capabilities are restored if the site's roles are reset.
* Over-long AI answers are rewritten shorter instead of being cut.
* Security hardening after an external review.

= 0.1.1 =
* Security: image metadata (such as GPS position) is now removed on every server before an image is sent to the AI provider. If it cannot be removed, the image is not sent.

= 0.1.0 =
* First version.
