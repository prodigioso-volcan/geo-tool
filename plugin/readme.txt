=== Revisor ALT by JUNGLE ===
Contributors: prodigiosovolcan
Tags: alt text, accessibility, images, ai, media library
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audits image alt text in the media library and in your content, and proposes better alt text with AI for a person to review.

== Description ==

Many images end up with no alt text, or with a file name or the word "image" as alt text. That hurts people who use screen readers, and it hurts search visibility.

This plugin:

* Audits every image in the media library and every use of it in your posts and pages. The audit uses no AI and has no cost.
* Proposes alt text with the AI provider you connected in Settings > Connectors (OpenAI, Anthropic or Google).
* Never changes anything by itself: a person approves, edits, ignores or marks each image as decorative.
* Applies approved text to the media library and to the content already published, and lets you undo it.
* Shows a dashboard that tells you at a glance whether everything is green.

= What it needs =

* WordPress 7.0 or later.
* An AI provider connected in Settings > Connectors, with an API key. API keys are billed by use by the provider and are separate from a ChatGPT, Claude or Gemini subscription. The plugin never reads or stores the key.

= Privacy =

To describe an image, the plugin sends a reduced copy of it (1024 px on its longest side at most), its file name, its title, its current alt text and the title of one page where it is used to the AI provider you connected. Nothing is sent anywhere else. A daily limit of requests, 200 by default, protects you from unexpected costs.

= Known limits =

* Images placed with page builders (for example Elementor) or custom fields are updated in the media library but not rewritten inside those pages. The dashboard tells you how many.
* SVG files and files that are not images are not audited.
* One language per site: alt text is written in the site language.

== Installation ==

1. Upload the zip in Plugins > Add New > Upload Plugin and activate it.
2. Follow the setup wizard: connect an AI provider, test it, choose your preferences and run the first audit.
3. Review the proposals in Media > AI alt text.

== Frequently Asked Questions ==

= Can I use my ChatGPT, Claude or Gemini subscription instead of an API key? =

The official connectors (OpenAI, Anthropic and Google) need an API key, which is billed by use and is separate from a subscription. A community connector, AI Provider for ChatGPT, signs in with a ChatGPT Plus, Pro or Go plan and needs no API key; it is not maintained by this project and has not been tested with it. Revisor ALT works with any provider registered in the WordPress AI Client that offers a model accepting images.

= Does it overwrite alt text someone wrote by hand? =

No. In a page, alt text is replaced only when it is empty, is a copy of the old media library text, or fails the audit rules. Good, page-specific alt text is kept. Approved, ignored and decorative decisions are never questioned again unless someone edits the text by hand.

= How do I update it? =

New versions published in the project's GitHub repository show up in the Plugins screen like any other update. The plugin only asks GitHub for the latest release; it sends no data about your site.

= Background tasks do not run =

The plugin relies on WP-Cron. If the dashboard shows background tasks in red, check that WP-Cron works on the site or set up a server cron job.

== Changelog ==

= 0.2.2 =
* Works with connectors that do not use an API key (sign-in or local models): availability is now asked to WordPress instead of inferred from the key.
* Documentation: list of available providers.

= 0.2.1 =
* The plugin author is now JUNGLE.

= 0.2.0 =
* The plugin is now called Revisor ALT by JUNGLE (folder and text domain: revisor-alt).
* New versions are offered in the Plugins screen, from the project's GitHub releases.
* Capabilities are restored if the site's roles are reset.
* Over-long AI answers are rewritten shorter instead of being cut.
* Security hardening after an external review.

= 0.1.1 =
* Security: image metadata (such as GPS position) is now removed on every server before an image is sent to the AI provider. If it cannot be removed, the image is not sent.

= 0.1.0 =
* First version.
