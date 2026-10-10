# Változásnapló

A csomag a [SemVer](https://semver.org/lang/hu/) szerint verziózódik. A `0.x`
sor azt jelenti, hogy a nyilvános API még mozog: egy `0.y.z` → `0.(y+1).0`
váltás törő változást is tartalmazhat, a javító kiadások (`0.y.z` → `0.y.(z+1)`)
viszont visszafelé kompatibilisek.

## [Nem kiadott]

## [0.1.0] – 2026-10-10

Az első kiadás. Tartalma a FoxPost integráció kiszervezése a
`praemonstratensis/egyhazzene-bolt` boltból (#116): a protokoll-réteg ebbe a
csomagba került, a Sylius-réteg a
[`codeconjure/foxpost-sylius-plugin`](https://github.com/connorhu/foxpost-sylius-plugin)-ba.

### Hozzáadva

- `Client` — a FoxPost WebAPI öt művelete PSR-18 kliensen és PSR-17
  factorykon, keretrendszer-független módon: `createParcel()`, `getLabel()`,
  `deleteParcel()`, `getTracking()`, `getParcelLockers()`. A hitelesítő
  paraméterek `#[\SensitiveParameter]`-rel vannak jelölve.
- `Environment` enum (`Production`, `Sandbox`) a `baseUrl()` feloldásával.
- `DeliveryKind` enum a két átvételi módra; a backing érték szándékosan a
  HOST slugja, így a mag nem ismeri a bolt enumját.
- `CreateParcelRequest` — a feladási kérés kötelező és opcionális mezői, a
  konstruktorban futó bemeneti ellenőrzésekkel és `toApiPayload()`-dal.
- `FoxPostApiException` és a belőle származó `FoxPostAuthException`.
- Kapuk: PHPUnit (72 teszt), PHPStan level 9, ECS — GitHub Actionsön, PHP 8.4
  és 8.5 mátrixon, plusz egy `--prefer-lowest` tesztfutás (#196).

### Ismert korlátok

- A `getParcelLockers()` a nyilvános `cdn.foxpost.hu/foxplus.json`-t olvassa,
  nem a WebAPI-t — ez a FoxPost ajánlott útja a csomagautomata-listára.
- A `createParcel()` és a `getTracking()` nyers tömböt ad vissza, nem tipizált
  választ. Válasz-DTO-k egy későbbi `0.x` kiadás tárgya.

[Nem kiadott]: https://github.com/connorhu/foxpost-lib/compare/0.1.0...main
[0.1.0]: https://github.com/connorhu/foxpost-lib/releases/tag/0.1.0
