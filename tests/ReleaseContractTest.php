<?php

declare(strict_types=1);

namespace CodeConjure\FoxPost\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A #195 szerződése: a csomag KIADHATÓ, és a kiadás le is van írva.
 *
 * A kiszervezés után (#116) a bolt produkciós kódja ennek a csomagnak a
 * `main` ágán állt: mérve 2026-10-10-én a `repos/connorhu/foxpost-lib/tags`
 * üres listát adott, a bolt pinje `dev-main@dev` volt. Egy push a `main`-re
 * így azonnal a bolt következő `composer update`-jének része lett —
 * verziókapu nélkül, és breaking change jelzése nélkül, mert az a constraint,
 * ami megállítaná, nem létezett.
 *
 * A tagot magát ez a teszt NEM tudja mérni, és nem is próbálja: a tag a
 * munkafán kívül él, a CI `actions/checkout`-ja pedig alapértelmezésben nem is
 * hozza le. Azt a két dolgot méri, ami a munkafában van, és ami a tag mellől
 * némán kirothad:
 *
 * 1. a `CHANGELOG.md` önkonzisztenciáját — a következő tag diffje csak akkor
 *    értelmezhető, ha minden kiadott verziónak van dátumozott szakasza ÉS
 *    hivatkozása;
 * 2. a `composer.json` kiadhatóságát — egy `dev-` ágra mutató futásidejű
 *    követelmény azt a mozgó célpontot vinné tovább a csomag FOGYASZTÓINAK,
 *    amit a #195 épp megszüntet.
 *
 * Az elvárás itt is a fájlból származik, nem másolt listából: a verziókat a
 * `CHANGELOG.md` szakaszcímeiből olvassuk ki, tehát egy új kiadás felvétele
 * hivatkozás nélkül azonnal pirosat ad.
 */
final class ReleaseContractTest extends TestCase
{
    private const string CHANGELOG = 'CHANGELOG.md';

    /**
     * A kiadott verziók szakaszcíme. A „Nem kiadott" szakasz szándékosan nem
     * illeszkedik: annak nincs dátuma, és nem is kap taget.
     */
    private const string RELEASED_HEADING = '/^## \[(\d+\.\d+\.\d+)\][^\S\n]*[–-][^\S\n]*(\d{4}-\d{2}-\d{2})[^\S\n]*$/mu';

    public function testTheChangelogExists(): void
    {
        self::assertFileExists(
            self::path(self::CHANGELOG),
            'A csomagnak nincs változásnaplója. Tag mellé enélkül senki nem tudja ' .
            'megmondani, mi van egy kiadásban — és mi változott a következőig.',
        );
    }

    public function testTheChangelogDocumentsAtLeastOneReleasedVersion(): void
    {
        self::assertNotSame(
            0,
            preg_match_all(self::RELEASED_HEADING, self::changelog()),
            'A változásnaplóban nincs egyetlen `## [x.y.z] – ÉÉÉÉ-HH-NN` formájú kiadás sem. ' .
            'A csomag így tag nélküli `dev-main`-en áll, ami a #195 kiindulási állapota.',
        );
    }

    /**
     * A naplót felülről olvassák. Ha egy új kiadás a régi ALÁ kerül, a
     * „mi változott legutóbb" kérdésre a fájl rossz választ ad — és ez a
     * fajta hiba nem tűnik fel, mert a fájl továbbra is teljesen rendben
     * NÉZ ki.
     */
    public function testTheReleasedVersionsAreListedNewestFirst(): void
    {
        $versions = self::releasedVersions();
        $sorted = $versions;
        usort($sorted, static fn (string $a, string $b): int => version_compare($b, $a));

        self::assertSame(
            $sorted,
            $versions,
            'A változásnapló kiadásai nem csökkenő verziósorrendben állnak.',
        );
    }

    /**
     * Minden kiadott verzióhoz kell egy hivatkozás-definíció a napló alján
     * (`[0.1.0]: https://...`). Ez az a sor, ami a `## [0.1.0]` címből
     * kattinthatót csinál — és pontosan ez az, amit egy új kiadásnál el
     * szokás felejteni: a cím szögletes zárójelben marad, link nélkül.
     */
    public function testEveryReleasedVersionCarriesALinkDefinition(): void
    {
        $changelog = self::changelog();

        foreach (self::releasedVersions() as $version) {
            self::assertMatchesRegularExpression(
                '/^\[' . preg_quote($version, '/') . '\]:\s*\S+$/m',
                $changelog,
                \sprintf('A `%s` kiadásnak nincs hivatkozás-definíciója a változásnapló alján.', $version),
            );
        }
    }

    /**
     * Egy kiadott csomag futásidejű követelményei között nem lehet ágra
     * mutató (`dev-`) hivatkozás: azt a fogyasztó örökli, és nála ugyanúgy
     * verziókapu nélküli mozgó célpont lesz, mint ami a #195-öt kiváltotta.
     * A csomagnak ma nincs ilyen követelménye — ez a teszt a jövőbeni
     * felvételt fogja meg, és a `require-dev`-et szándékosan nem méri, mert
     * azt a fogyasztó nem örökli.
     */
    public function testNoRuntimeRequirementPointsAtADevelopmentBranch(): void
    {
        foreach (self::runtimeRequirements() as $package => $constraint) {
            self::assertDoesNotMatchRegularExpression(
                '/(^|\|\|?|\s)\s*dev-|-dev\b|@dev\b/',
                $constraint,
                \sprintf(
                    'A `%s` követelménye (`%s`) fejlesztési ágra mutat. Ezt a csomag minden ' .
                    'fogyasztója örökli, verziókapu nélkül — tageld a függőséget, és írj rá constraintet.',
                    $package,
                    $constraint,
                ),
            );
        }
    }

    /** @return list<string> */
    private static function releasedVersions(): array
    {
        preg_match_all(self::RELEASED_HEADING, self::changelog(), $matches);

        return $matches[1];
    }

    /** @return array<string, string> */
    private static function runtimeRequirements(): array
    {
        $require = self::decode('composer.json')['require'] ?? null;
        self::assertIsArray($require, 'A `composer.json` nem deklarál `require` szakaszt.');

        $requirements = [];

        foreach ($require as $package => $constraint) {
            if ($package === 'php' || !\is_string($constraint)) {
                continue;
            }

            $requirements[(string) $package] = $constraint;
        }

        return $requirements;
    }

    private static function changelog(): string
    {
        return self::read(self::CHANGELOG);
    }

    /** @return array<mixed> */
    private static function decode(string $relative): array
    {
        $decoded = json_decode(self::read($relative), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::path($relative));
        \assert(\is_string($contents));

        return $contents;
    }

    private static function path(string $relative): string
    {
        return \dirname(__DIR__) . '/' . $relative;
    }
}
