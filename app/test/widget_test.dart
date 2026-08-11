import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rozumnyi_koshyk_app/main.dart';

void main() {
  testWidgets('App boots and shows the title', (WidgetTester tester) async {
    await tester.pumpWidget(const RozumnyiKoshykApp());
    expect(find.byKey(const Key('app-title')), findsOneWidget);
    expect(find.text('Розумний кошик'), findsWidgets);
  });
}
