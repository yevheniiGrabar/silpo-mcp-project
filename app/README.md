# app/ — Розумний кошик (Flutter)

Flutter client for the "Розумний кошик" hackathon app. Talks **only** to our
Laravel BFF (`../api`) — never directly to `mcp.silpo.ua` and never holds the
Silpo token (see `../AGENTS.md`).

## First checkout — generate native shells

The committed scaffold is Dart-level (pubspec + `lib/` + `test/`). Native
platform folders are generated once (kept out of P0 to avoid hand-written
gradle drift):

```bash
cd app
flutter create . --org ua.rozumnyikoshyk --platforms=ios,android
flutter pub get
```

CI does this automatically (see `.github/workflows/ci.yml`).

## Acceptance (verify-gate)

```bash
flutter pub get
flutter analyze         # no errors
flutter test            # green
flutter build apk --debug
```

## Structure (feature-first, docs/03 §1)

```
lib/
  app/        # MaterialApp, theme (docs/04), go_router shell  ← M1
  core/       # api/ (Dio), models/ (freezed DTO)             ← M1
  features/   # auth, home, wizard, generation, menu,
              # shopping_list, cart                            ← M2–M8
  shared/     # reusable widgets
```

Requires Flutter stable ≥ 3.24, Dart 3.
