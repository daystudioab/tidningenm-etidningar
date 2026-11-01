# Tidningen M – E-tidningar

WordPress-tillägg för tidningenm.se. Byggt av Studio Day.

E-tidningar som egen innehållstyp: ISSUU-läsare, automatiska omslag, rutnät med alla nummer (`[etidningar]`), import av de gamla nummersidorna och bestående 301-omdirigeringar från gamla adresser.

Tillägget ligger i mappen `wp-content/plugins/m-etidningar/` på sajten (zip-filen och mappen heter `m-etidningar`, inte som repot).

## Ny version

1. Höj `Version:` i `m-etidningar.php` (och `MET_VERSION` under).
2. Committa och pusha till `main`. Commit-meddelandet blir releasens anteckningar.
3. GitHub Actions ser att versionen är ny, bygger `m-etidningar.zip` och publicerar releasen `v` + versionsnumret.
   Ändringar utan ny version ger ingen release.
4. Sajten visar uppdateringen under **Tillägg** (kollar var 12:e timme, eller direkt via länken *Sök efter uppdateringar* på tilläggets rad).

## Publikt repo

Repot är publikt, så sajten hämtar releaser utan nyckel. Om repot görs privat behöver `wp-config.php` en läsnyckel
(fine-grained token med **Contents: Read-only**): `define( 'STUDIODAY_GITHUB_TOKEN', 'github_pat_…' );`

## Innehåll

- `lib/plugin-update-checker/` – [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) v5.6 (MIT), sköter uppdateringarna.
