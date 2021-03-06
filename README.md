# Mautic Do Not Contact Extras [![Latest Stable Version](https://poser.pugx.org/thedmsgroup/mautic-do-not-contact-extras-bundle/v/stable)](https://packagist.org/packages/thedmsgroup/mautic-do-not-contact-extras-bundle) [![License](https://poser.pugx.org/thedmsgroup/mautic-dnc-extras/license)](https://packagist.org/packages/thedmsgroup/mautic-do-not-contact-extras-bundle) [![Build Status](https://travis-ci.com/TheDMSGroup/mautic-dnc-extras.svg?branch=master)](https://travis-ci.com/TheDMSGroup/mautic-dnc-extras)
![](./Assets/img/donotcontactextras.png)

This Bundle creates an additional Do Not Contact Entity type for managing an uploadable and
editable list of items to reference as "add-on" DoNotContact Mautic Entities.

These can be items by channel, where 'email' and 'phone' are the currently supported channels.

This Bundle functions by hooking into a core event dispatch for DNC lookups, and adds items from the
custom list as DNC Entities. These add-on entities are lead-agnostic, compared to the mautic instances.

## Installation & Usage

Currently being tested with Mautic `2.15.x`.
If you have success/issues with other versions please report.

1. Install by running `composer require thedmsgroup/mautic-do-not-contact-extras-bundle`
   (or by extracting this repo to `/plugins/MauticDoNotContactExtrasBundle`)
2. Go to `/s/plugins/reload`
3. Click "Do Not Contact Extras" and configure as desired.
