import 'package:flutter/material.dart';

/// Token brand website RT05 TAKEDA (`website/resources/css/app.css` :root) diterjemahkan ke komponen native:
/// navy #123e65, kuning #f2cd5a, sky #eef5fa, ink #183b50, muted #536a79, line #d8e3e9;
/// Bricolage Grotesque (judul) + Public Sans (teks), TTF terbundel di assets/fonts (tanpa unduhan runtime).
class Brand {
  static const navy = Color(0xFF123E65);
  static const navyDeep = Color(0xFF0B2A47);
  static const yellow = Color(0xFFF2CD5A);
  static const sky = Color(0xFFEEF5FA);
  static const skyDeep = Color(0xFFD9EAF6);
  static const ink = Color(0xFF183B50);
  static const muted = Color(0xFF536A79);
  static const line = Color(0xFFD8E3E9);

  static const fontBody = 'PublicSans';
  static const fontDisplay = 'BricolageGrotesque';
  static const success = Color(0xFF1E7A4C);
  static const successBg = Color(0xFFE3F4EA);
  static const danger = Color(0xFFB3261E);
  static const dangerBg = Color(0xFFFBE7E5);
  static const warning = Color(0xFF8A5A00);
  static const warningBg = Color(0xFFFFF3D1);
}

ThemeData buildTheme() {
  final base = ThemeData(
    useMaterial3: true,
    fontFamily: Brand.fontBody,
    colorScheme: ColorScheme.fromSeed(seedColor: Brand.navy, primary: Brand.navy, secondary: Brand.yellow, surface: Colors.white, error: Brand.danger),
    scaffoldBackgroundColor: Brand.sky,
  );
  final body = base.textTheme.apply(fontFamily: Brand.fontBody, bodyColor: Brand.ink, displayColor: Brand.ink);
  TextStyle? display(TextStyle? s) => s?.copyWith(fontFamily: Brand.fontDisplay, fontWeight: FontWeight.w700, color: Brand.ink);

  return base.copyWith(
    textTheme: body.copyWith(
      headlineLarge: display(body.headlineLarge),
      headlineMedium: display(body.headlineMedium),
      headlineSmall: display(body.headlineSmall),
      titleLarge: display(body.titleLarge),
      titleMedium: body.titleMedium?.copyWith(fontWeight: FontWeight.w700),
    ),
    appBarTheme: AppBarTheme(
      backgroundColor: Brand.navy,
      foregroundColor: Colors.white,
      elevation: 0,
      centerTitle: false,
      titleTextStyle: const TextStyle(fontFamily: Brand.fontDisplay, fontSize: 20, fontWeight: FontWeight.w700, color: Colors.white),
    ),
    cardTheme: CardThemeData(
      color: Colors.white,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: const BorderSide(color: Brand.line),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: Brand.navy,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(48),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: Brand.navy,
        minimumSize: const Size.fromHeight(48),
        side: const BorderSide(color: Brand.navy),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: Brand.line),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: Brand.line),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: Brand.navy, width: 2),
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: Colors.white,
      indicatorColor: Brand.yellow,
      labelTextStyle: WidgetStateProperty.all(const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
      height: 68,
    ),
    chipTheme: base.chipTheme.copyWith(side: const BorderSide(color: Brand.line)),
    snackBarTheme: const SnackBarThemeData(behavior: SnackBarBehavior.floating),
  );
}
