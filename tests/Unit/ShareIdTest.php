<?php

namespace Tests\Unit;

use App\Http\Controllers\SharesController;
use Tests\TestCase;

/**
 * Un partage sans mot de passe est lisible par quiconque a son lien : l'identifiant
 * doit être impossible à deviner. Deux paires adjectif-nom seules (~26 bits)
 * s'énuméraient en quelques minutes.
 */
class ShareIdTest extends TestCase
{
    private function haikuId(): string
    {
        $method = new \ReflectionMethod(SharesController::class, 'generateHaikuId');

        return $method->invoke(new SharesController());
    }

    public function test_haiku_ids_stay_readable_but_carry_a_random_suffix(): void
    {
        $this->assertMatchesRegularExpression('/^[a-z]+-[a-z]+-[a-z]+-[a-z]+-[a-km-np-z2-9]{8}$/', $this->haikuId());
    }

    public function test_haiku_ids_have_enough_entropy_not_to_be_enumerated(): void
    {
        $adjectives = count((new \ReflectionClass(\App\Haikunator::class))->getStaticPropertyValue('adjectives'));
        $nouns = count((new \ReflectionClass(\App\Haikunator::class))->getStaticPropertyValue('nouns'));
        // Mesuré sur un identifiant réel : tout ce qui suit les quatre mots est aléatoire
        $suffix = implode('-', array_slice(explode('-', $this->haikuId()), 4));
        $suffixBits = strlen($suffix) * log(32, 2);

        $bits = 2 * log($adjectives * $nouns, 2) + $suffixBits;

        $this->assertGreaterThanOrEqual(64, $bits);
    }

    public function test_haiku_ids_do_not_repeat(): void
    {
        $ids = array_map(fn () => $this->haikuId(), range(1, 2000));

        $this->assertCount(2000, array_unique($ids));
    }
}
