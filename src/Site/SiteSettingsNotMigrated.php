<?php

namespace Splicewire\Beam\Tenancy\Site;

use RuntimeException;

/** Site Settings were saved in a schema that has no site_settings table of its own yet ({@see SiteSettings}). */
final class SiteSettingsNotMigrated extends RuntimeException {}
