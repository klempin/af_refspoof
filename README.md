# af_refspoof
*af_refspoof* is a plugin for [Tiny Tiny RSS](https://tt-rss.org/). It proxies images through Tiny Tiny RSS and adds a fake referral header.

A lot of sites protect their images from hotlinking, but unfortunately it also breaks rss reader functionality (especialy the web ones).

## Installation

Download the current release and place the folder named *af_refspoof* inside the Tiny Tiny RSS plugins.local directory. In the backend, look for the plugin "*af_refspoof*" and enable it.

You can enable *af_refspoof* for single feeds by going to the Edit Feed dialogue box and checking the box "Fake referral for this feed" in the Plugins tab.

You can also enable the plugin for entire domains by going into preferences and opening the Fake referral panel in the Feeds tab.