import 'package:flutter/material.dart';

/// Розумний кошик — application entrypoint (P0 scaffold).
///
/// This is the minimal, compiling shell established in phase P0. The real
/// design system, go_router shell + tabs, Dio client and Riverpod providers
/// are added in phase M1 (see docs/03 §1–2 and docs/04). Keeping P0 dependency-
/// light guarantees `flutter analyze` / `flutter test` pass before M1 lands.
void main() {
  runApp(const RozumnyiKoshykApp());
}

class RozumnyiKoshykApp extends StatelessWidget {
  const RozumnyiKoshykApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Розумний кошик',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF00A651)),
        useMaterial3: true,
      ),
      darkTheme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF00A651),
          brightness: Brightness.dark,
        ),
        useMaterial3: true,
      ),
      home: const _ScaffoldPlaceholder(),
    );
  }
}

class _ScaffoldPlaceholder extends StatelessWidget {
  const _ScaffoldPlaceholder();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: const [
            Icon(Icons.shopping_cart_outlined, size: 64),
            SizedBox(height: 12),
            Text(
              'Розумний кошик',
              key: Key('app-title'),
              style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600),
            ),
            SizedBox(height: 4),
            Text('P0 scaffold — screens land in phases M1–M8'),
          ],
        ),
      ),
    );
  }
}
