# codeconjure/foxpost

[![CI](https://github.com/connorhu/foxpost-lib/actions/workflows/ci.yaml/badge.svg)](https://github.com/connorhu/foxpost-lib/actions/workflows/ci.yaml)

FoxPost WebAPI protokoll-kliens PHP-hez. Keretrendszer-független: PSR-18 HTTP klienst és
PSR-17 factorykat vár, semmi mást.

Öt művelet: csomagfeladás, címkelekérés, csomagtörlés, nyomkövetés, csomagautomata-lista.

Ez a csomag **nem** tartalmaz Sylius- vagy Symfony-integrációt. Ahhoz lásd:
[`codeconjure/foxpost-sylius-plugin`](https://github.com/connorhu/foxpost-sylius-plugin).

## Telepítés

```bash
composer require codeconjure/foxpost
```

## Verziózás

A csomag [SemVer](https://semver.org/lang/hu/) szerint verziózódik, és
tagolt kiadásokat ad — a kiadások tartalma a [CHANGELOG.md](CHANGELOG.md)-ben
van. A `0.x` soron a nyilvános API még mozoghat: `^0.1`-re érdemes
constraintet írni, `dev-main`-re nem.

## Dokumentáció

- [FoxPost integrációs segédlet](https://foxpost.hu/uzleti-partnereknek/integracios-segedlet/webapi-integracio)
- [WebAPI Swagger](https://webapi.foxpost.hu/swagger-ui/index.html)
