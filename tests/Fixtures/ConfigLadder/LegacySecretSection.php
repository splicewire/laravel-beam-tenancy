<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections ticket 09): a tenant-tier SECRET key, standing in for the flagship's per-tenant Stripe secret
 * (`services.stripe.secret`, `tenants.data.stripe_secret`). Imported legacy values must be stored encrypted and served
 * redacted. Tests use fake values only (F14).
 */
#[ConfigRoot(root: 'cs09sec', title: 'Legacy secret', description: 'A tenant-tier secret a workspace used to keep in tenants.data.')]
class LegacySecretSection extends ConfigSection
{
    public function __construct(
        #[Title('Secret'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live, secret: true)]
        public string|Optional|null $secret,
    ) {}
}
