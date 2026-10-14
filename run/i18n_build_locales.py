#!/usr/bin/env python3
"""Build www/pl|de|es|fr/ from www/en/ with real translations."""
from __future__ import annotations

import json
import re
import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WWW = ROOT / "www"
EN = WWW / "en"

LOCALES = {
    "pl": {"prefix": "/pl", "html": "pl", "og": "pl_PL", "label": "Polski"},
    "de": {"prefix": "/de", "html": "de", "og": "de_DE", "label": "Deutsch"},
    "es": {"prefix": "/es", "html": "es", "og": "es_ES", "label": "Español"},
    "fr": {"prefix": "/fr", "html": "fr", "og": "fr_FR", "label": "Français"},
}

# English (and shared UI) → locale
PHRASES: dict[str, dict[str, str]] = {
    "pl": {
        "Made in Ukraine": "Produkt z Ukrainy",
        "STRIM Algo LLC · Binance spot": "STRIM Algo LLC · Binance spot",
        "STRIM Algo LLC · Binance spot via cloud dashboard": "STRIM Algo LLC · Binance spot przez panel w chmurze",
        "Light theme": "Jasny motyw",
        "Theme": "Motyw",
        "Menu": "Menu",
        "Home": "Strona główna",
        "Blog": "Blog",
        "About": "O platformie",
        "Pricing": "Cennik",
        "Guide": "Instrukcja",
        "Terms": "Regulamin",
        "Log in": "Zaloguj",
        "Sign up": "Rejestracja",
        "Try free for 3 days": "Wypróbuj za darmo (3 dni)",
        "Log in to dashboard": "Zaloguj do panelu",
        "Binance spot · Ukrainian SaaS · no profit share": "Binance spot · SaaS z Ukrainy · bez % od zysku",
        "Automate Binance spot trading <span class=\"mark\">without emotions</span>": "Automatyzuj handel spot na Binance <span class=\"mark\">bez emocji</span>",
        "Cloud dashboard for grid trading. Your funds stay on the exchange —\n            we only execute your rules 24/7. API keys without withdrawal permission.": "Panel w chmurze do grid tradingu. Środki zostają na górze —\n            wykonujemy Twoje reguły 24/7. Klucze API bez wypłat.",
        "Crypto trading involves risk. StrIM is software, not investment advice.\n            Subscription 500 UAH / 30 days after trial.": "Handel krypto wiąże się z ryzykiem. StrIM to oprogramowanie, nie porada inwestycyjna.\n            Subskrypcja 500 UAH / 30 dni po trialu.",
        "Cloud dashboard": "Panel w chmurze",
        "Strategy levels": "Poziomy strategii",
        "Event log": "Dziennik zdarzeń",
        "How it works and why it is safe": "Jak to działa i dlaczego jest bezpieczne",
        "Funds never move to our accounts — only automation of your rules via the Binance API.": "Środki nie trafiają na nasze konta — tylko automatyzacja reguł przez API Binance.",
        "Create an API key": "Utwórz klucz API",
        "Enable only <strong>Read</strong> and <strong>Spot trade</strong>. Keep <strong>Withdrawals</strong> disabled — StrIM cannot withdraw funds from the exchange.": "Włącz tylko <strong>Read</strong> i <strong>Spot trade</strong>. Wyłącz <strong>Withdrawals</strong> — StrIM nie może wypłacać środków.",
        "Set up your grid": "Skonfiguruj siatkę (grid)",
        "Set DCA levels and take-profit targets. The system follows your plan without impulsive decisions.": "Ustaw poziomy DCA i take-profit. System działa według planu, bez impulsów.",
        "Stay in control": "Kontroluj proces",
        "Telegram alerts for trades, portfolio and event log in a clear dashboard.": "Alerty Telegram o transakcjach, portfelu i dzienniku w przejrzystym panelu.",
        "Step-by-step guide": "Instrukcja krok po kroku",
        "Risk disclosure": "Ryzyka",
        "Featured on the blog": "Polecane na blogu",
        "How to set up grid trading on Binance": "Jak skonfigurować grid trading na Binance",
        "Step-by-step: spot grid basics (range, levels, step) and safe API automation without withdrawal rights.": "Krok po kroku: podstawy siatki spot, parametry i bezpieczne API bez wypłat.",
        "Read article": "Czytaj artykuł",
        "All blog posts": "Wszystkie artykuły",
        "Transparent pricing — no profit share": "Przejrzyste ceny — bez % od zysku",
        "We do not take a cut of your profits. You only pay for access to the tool — like a SaaS subscription.": "Nie bierzemy procentu od zysku. Płacisz tylko za dostęp do narzędzia — jak subskrypcja SaaS.",
        "500 UAH": "500 UAH",
        "/ 30 days": "/ 30 dni",
        "After sign-up — 3-day trial at no charge": "Po rejestracji — 3 dni trialu bez opłat",
        "Unlimited grids (strategies)": "Nieograniczona liczba siatek (strategii)",
        "Telegram notifications": "Powiadomienia Telegram",
        "Safe API connection (no withdrawal)": "Bezpieczne API (bez wypłat)",
        "Support in Ukrainian": "Wsparcie po ukraińsku",
        "Start free trial": "Rozpocznij trial",
        "Payment methods": "Metody płatności",
        "Terms of service": "Regulamin",
        "What is in the dashboard": "Co jest w panelu",
        "Few screens — maximum clarity for spot trading on one exchange.": "Mało ekranów — maksimum przejrzystości na jednej giełdzie.",
        "Grid and levels": "Siatka i poziomy",
        "Start, pause, entry levels, take-profit — your parameters.": "Start, pauza, poziomy wejścia, take-profit — Twoje parametry.",
        "Portfolio": "Portfel",
        "USDT balance and positions synced with Binance.": "Saldo USDT i pozycje zsynchronizowane z Binance.",
        "Security": "Bezpieczeństwo",
        "API without withdrawal; we do not store your exchange password. See <a href=\"/offer.html\">terms</a>.": "API bez wypłat; nie przechowujemy hasła do giełdy. Zobacz <a href=\"/offer.html\">regulamin</a>.",
        "<strong>Disclaimer:</strong> Cryptocurrency trading involves substantial risk of loss.\n      StrIM is software to automate your trading strategies only and does not provide financial or investment advice.\n      All trading decisions are yours. No futures, margin or withdrawal via API.": "<strong>Zastrzeżenie:</strong> Handel kryptowalutami niesie ryzyko straty.\n      StrIM to oprogramowanie do automatyzacji strategii, nie porada finansowa.\n      Decyzje inwestycyjne podejmujesz sam. Bez futures, marży i wypłat przez API.",
        "Privacy": "Prywatność",
        "Refunds": "Zwroty",
        "Risks": "Ryzyka",
        "Frequently asked questions (FAQ)": "Najczęściej zadawane pytania (FAQ)",
        "Short answers about the bot, Binance keys, balance and subscription. Detailed steps — in the <a href=\"/en/instruction.html\">guide</a>.": "Krótkie odpowiedzi o bocie, kluczach Binance, saldzie i subskrypcji. Szczegóły — w <a href=\"/pl/instruction.html\">instrukcji</a>.",
        "Why does USDT balance show zero?": "Dlaczego saldo USDT pokazuje zero?",
        "Exchange access error (401 / 403)": "Błąd dostępu do giełdy (401 / 403)",
        "Why is the bot idle?": "Dlaczego bot jest bezczynny?",
        "How are referral rewards paid?": "Jak naliczane są nagrody referral?",
        "Realized and unrealized P/L": "Zrealizowany i niezrealizowany P/L",
        "EXPIRED status (subscription ended)": "Status EXPIRED (subskrypcja wygasła)",
        "Did not find an answer?": "Nie znalazłeś odpowiedzi?",
        "Information on this site is not financial advice.": "Informacje na stronie nie są poradą finansową.",
        "StrIM — Binance spot grid trading automation | StrIM": "StrIM — automatyzacja grid tradingu na Binance | StrIM",
        "Automate Binance spot trading: Ukrainian SaaS, funds stay on the exchange, API without withdrawal. 3-day trial, 500 UAH/month.": "Automatyzacja spot na Binance: SaaS z Ukrainy, środki na giełdzie, API bez wypłat. Trial 3 dni, 500 UAH/mies.",
        "Blog — Binance trading automation | StrIM": "Blog — automatyzacja handlu Binance | StrIM",
        "StrIM blog: Trading, automation and security": "Blog StrIM: handel, automatyzacja i bezpieczeństwo",
        "Read more": "Czytaj dalej",
        "Setup guide": "Instrukcja konfiguracji",
        "The full step-by-step guide with screenshots is currently available in <strong>Ukrainian</strong>:": "Pełna instrukcja ze zrzutami jest obecnie po <strong>ukraińsku</strong>:",
        "Open Ukrainian guide": "Otwórz instrukcję UA",
        "Quick links in English:": "Szybkie linki:",
        "Site language": "Język strony",
        "Language:": "Język:",
        "2 June 2026": "2 czerwca 2026",
        "8 min read": "8 min czytania",
        "How to set up grid trading on Binance: step-by-step guide": "Jak skonfigurować grid trading na Binance: instrukcja",
        "Grid trading on Binance": "Grid trading na Binance",
        "Conclusion": "Podsumowanie",
        "Reminder: crypto trading involves risk. Spot grids reduce some risks but do not guarantee profit. Diversify and test strategies. See <a href=\"/en/risks.html\">risk disclosure</a>.": "Uwaga: handel krypto wiąże się z ryzykiem. Siatka spot nie gwarantuje zysku. Dywersyfikuj i testuj strategie. Zobacz <a href=\"/pl/risks.html\">ujawnienie ryzyk</a>.",
        "Spot grid trading on Binance is about levels and size — not guessing the market. After setting parameters, connect the exchange to StrIM and try a <strong>3-day free trial</strong>.": "Grid spot na Binance to poziomy i wielkość — nie zgadywanie rynku. Po konfiguracji połącz giełdę ze StrIM i wypróbuj <strong>3 dni trialu</strong>.",
    },
    "de": {
        "Made in Ukraine": "Produkt aus der Ukraine",
        "STRIM Algo LLC · Binance spot via cloud dashboard": "STRIM Algo LLC · Binance Spot über Cloud-Dashboard",
        "Light theme": "Helles Design",
        "Theme": "Design",
        "Menu": "Menü",
        "Home": "Start",
        "Blog": "Blog",
        "About": "Über uns",
        "Pricing": "Preise",
        "Guide": "Anleitung",
        "Terms": "AGB",
        "Log in": "Anmelden",
        "Sign up": "Registrieren",
        "Try free for 3 days": "3 Tage kostenlos testen",
        "Log in to dashboard": "Zum Dashboard",
        "Binance spot · Ukrainian SaaS · no profit share": "Binance Spot · Ukrainisches SaaS · kein Gewinnanteil",
        "Automate Binance spot trading <span class=\"mark\">without emotions</span>": "Binance-Spot-Handel <span class=\"mark\">ohne Emotionen</span> automatisieren",
        "Cloud dashboard for grid trading. Your funds stay on the exchange —\n            we only execute your rules 24/7. API keys without withdrawal permission.": "Cloud-Dashboard für Grid-Trading. Mittel bleiben auf der Börse —\n            wir führen Ihre Regeln 24/7 aus. API-Schlüssel ohne Auszahlung.",
        "Crypto trading involves risk. StrIM is software, not investment advice.\n            Subscription 500 UAH / 30 days after trial.": "Krypto-Handel birgt Risiken. StrIM ist Software, keine Anlageberatung.\n            Abo 500 UAH / 30 Tage nach der Testphase.",
        "Cloud dashboard": "Cloud-Dashboard",
        "Strategy levels": "Strategielevel",
        "Event log": "Ereignisprotokoll",
        "How it works and why it is safe": "So funktioniert es — und warum es sicher ist",
        "Funds never move to our accounts — only automation of your rules via the Binance API.": "Gelder gehen nicht auf unsere Konten — nur Automatisierung über die Binance-API.",
        "Create an API key": "API-Schlüssel erstellen",
        "Enable only <strong>Read</strong> and <strong>Spot trade</strong>. Keep <strong>Withdrawals</strong> disabled — StrIM cannot withdraw funds from the exchange.": "Nur <strong>Read</strong> und <strong>Spot trade</strong> aktivieren. <strong>Withdrawals</strong> deaktiviert lassen.",
        "Set up your grid": "Grid einrichten",
        "Set DCA levels and take-profit targets. The system follows your plan without impulsive decisions.": "DCA-Level und Take-Profit festlegen. Das System folgt Ihrem Plan.",
        "Stay in control": "Behalten Sie die Kontrolle",
        "Telegram alerts for trades, portfolio and event log in a clear dashboard.": "Telegram-Benachrichtigungen zu Trades, Portfolio und Logbuch.",
        "Step-by-step guide": "Schritt-für-Schritt-Anleitung",
        "Risk disclosure": "Risikohinweis",
        "Featured on the blog": "Empfohlen im Blog",
        "How to set up grid trading on Binance": "Grid Trading auf Binance einrichten",
        "Step-by-step: spot grid basics (range, levels, step) and safe API automation without withdrawal rights.": "Schritt für Schritt: Spot-Grid, Parameter und sichere API ohne Auszahlung.",
        "Read article": "Artikel lesen",
        "All blog posts": "Alle Beiträge",
        "Transparent pricing — no profit share": "Transparente Preise — kein Gewinnanteil",
        "We do not take a cut of your profits. You only pay for access to the tool — like a SaaS subscription.": "Kein Anteil an Ihrem Gewinn. Sie zahlen nur für den Zugang — wie ein SaaS-Abo.",
        "500 UAH": "500 UAH",
        "/ 30 days": "/ 30 Tage",
        "After sign-up — 3-day trial at no charge": "Nach Registrierung — 3 Tage Test ohne Gebühr",
        "Unlimited grids (strategies)": "Unbegrenzte Grids (Strategien)",
        "Telegram notifications": "Telegram-Benachrichtigungen",
        "Safe API connection (no withdrawal)": "Sichere API (keine Auszahlung)",
        "Support in Ukrainian": "Support auf Ukrainisch",
        "Start free trial": "Testphase starten",
        "Payment methods": "Zahlungsmethoden",
        "Terms of service": "Nutzungsbedingungen",
        "What is in the dashboard": "Was im Dashboard ist",
        "Few screens — maximum clarity for spot trading on one exchange.": "Wenige Bildschirme — maximale Klarheit für Spot auf einer Börse.",
        "Grid and levels": "Grid und Level",
        "Start, pause, entry levels, take-profit — your parameters.": "Start, Pause, Einstiegslevel, Take-Profit — Ihre Parameter.",
        "Portfolio": "Portfolio",
        "USDT balance and positions synced with Binance.": "USDT-Saldo und Positionen synchron mit Binance.",
        "Security": "Sicherheit",
        "API without withdrawal; we do not store your exchange password. See <a href=\"/offer.html\">terms</a>.": "API ohne Auszahlung; kein Speichern des Börsenpassworts. Siehe <a href=\"/offer.html\">AGB</a>.",
        "<strong>Disclaimer:</strong> Cryptocurrency trading involves substantial risk of loss.\n      StrIM is software to automate your trading strategies only and does not provide financial or investment advice.\n      All trading decisions are yours. No futures, margin or withdrawal via API.": "<strong>Haftungsausschluss:</strong> Krypto-Handel birgt Verlustrisiken.\n      StrIM ist Software zur Automatisierung Ihrer Strategien, keine Anlageberatung.\n      Alle Entscheidungen treffen Sie selbst. Keine Futures, Margin oder Auszahlung per API.",
        "Privacy": "Datenschutz",
        "Refunds": "Erstattungen",
        "Risks": "Risiken",
        "Frequently asked questions (FAQ)": "Häufige Fragen (FAQ)",
        "Short answers about the bot, Binance keys, balance and subscription. Detailed steps — in the <a href=\"/en/instruction.html\">guide</a>.": "Kurze Antworten zu Bot, Binance-Keys, Saldo und Abo. Details in der <a href=\"/de/instruction.html\">Anleitung</a>.",
        "Why does USDT balance show zero?": "Warum zeigt USDT-Saldo null?",
        "Exchange access error (401 / 403)": "Börsenzugriff fehlgeschlagen (401 / 403)",
        "Why is the bot idle?": "Warum ist der Bot inaktiv?",
        "How are referral rewards paid?": "Wie werden Referral-Prämien ausgezahlt?",
        "Realized and unrealized P/L": "Realisierte und unrealisierte P/L",
        "EXPIRED status (subscription ended)": "Status EXPIRED (Abo beendet)",
        "Did not find an answer?": "Keine Antwort gefunden?",
        "Information on this site is not financial advice.": "Keine Finanzberatung auf dieser Website.",
        "StrIM — Binance spot grid trading automation | StrIM": "StrIM — Binance Spot Grid Trading | StrIM",
        "Automate Binance spot trading: Ukrainian SaaS, funds stay on the exchange, API without withdrawal. 3-day trial, 500 UAH/month.": "Binance Spot automatisieren: Ukrainisches SaaS, Gelder auf der Börse, API ohne Auszahlung. 3 Tage Test, 500 UAH/Monat.",
        "Blog — Binance trading automation | StrIM": "Blog — Binance-Automatisierung | StrIM",
        "StrIM blog: Trading, automation and security": "StrIM Blog: Handel, Automatisierung, Sicherheit",
        "Read more": "Weiterlesen",
        "Setup guide": "Einrichtungsanleitung",
        "The full step-by-step guide with screenshots is currently available in <strong>Ukrainian</strong>:": "Die vollständige Anleitung mit Screenshots ist derzeit auf <strong>Ukrainisch</strong>:",
        "Open Ukrainian guide": "Ukrainische Anleitung öffnen",
        "Quick links in English:": "Schnelllinks:",
        "Site language": "Sprache",
        "Language:": "Sprache:",
        "2 June 2026": "2. Juni 2026",
        "8 min read": "8 Min. Lesezeit",
        "How to set up grid trading on Binance: step-by-step guide": "Grid Trading auf Binance: Schritt-für-Schritt",
        "Grid trading on Binance": "Grid Trading auf Binance",
        "Conclusion": "Fazit",
        "Reminder: crypto trading involves risk. Spot grids reduce some risks but do not guarantee profit. Diversify and test strategies. See <a href=\"/en/risks.html\">risk disclosure</a>.": "Hinweis: Krypto-Handel ist riskant. Spot-Grids garantieren keinen Gewinn. Siehe <a href=\"/de/risks.html\">Risikohinweis</a>.",
        "Spot grid trading on Binance is about levels and size — not guessing the market. After setting parameters, connect the exchange to StrIM and try a <strong>3-day free trial</strong>.": "Spot-Grid auf Binance: Level und Größe — nicht Markt timing. Verbinden Sie die Börse mit StrIM und testen Sie <strong>3 Tage kostenlos</strong>.",
    },
    "es": {
        "Made in Ukraine": "Producto de Ucrania",
        "STRIM Algo LLC · Binance spot via cloud dashboard": "STRIM Algo LLC · spot Binance vía panel en la nube",
        "Light theme": "Tema claro",
        "Theme": "Tema",
        "Menu": "Menú",
        "Home": "Inicio",
        "Blog": "Blog",
        "About": "Plataforma",
        "Pricing": "Tarifas",
        "Guide": "Guía",
        "Terms": "Términos",
        "Log in": "Entrar",
        "Sign up": "Registro",
        "Try free for 3 days": "Probar gratis (3 días)",
        "Log in to dashboard": "Entrar al panel",
        "Binance spot · Ukrainian SaaS · no profit share": "Binance spot · SaaS ucraniano · sin % de beneficio",
        "Automate Binance spot trading <span class=\"mark\">without emotions</span>": "Automatiza el spot en Binance <span class=\"mark\">sin emociones</span>",
        "Cloud dashboard for grid trading. Your funds stay on the exchange —\n            we only execute your rules 24/7. API keys without withdrawal permission.": "Panel en la nube para grid trading. Fondos en el exchange —\n            ejecutamos tus reglas 24/7. API sin permiso de retiro.",
        "Crypto trading involves risk. StrIM is software, not investment advice.\n            Subscription 500 UAH / 30 days after trial.": "El trading conlleva riesgos. StrIM es software, no asesoramiento. Suscripción 500 UAH / 30 días tras la prueba.",
        "Cloud dashboard": "Panel en la nube",
        "Strategy levels": "Niveles de estrategia",
        "Event log": "Registro de eventos",
        "How it works and why it is safe": "Cómo funciona y por qué es seguro",
        "Funds never move to our accounts — only automation of your rules via the Binance API.": "Los fondos no van a nuestras cuentas — solo automatización vía API de Binance.",
        "Create an API key": "Crear clave API",
        "Enable only <strong>Read</strong> and <strong>Spot trade</strong>. Keep <strong>Withdrawals</strong> disabled — StrIM cannot withdraw funds from the exchange.": "Activa solo <strong>Read</strong> y <strong>Spot trade</strong>. <strong>Withdrawals</strong> desactivado.",
        "Set up your grid": "Configurar la cuadrícula",
        "Set DCA levels and take-profit targets. The system follows your plan without impulsive decisions.": "Niveles DCA y take-profit. El sistema sigue tu plan.",
        "Stay in control": "Mantén el control",
        "Telegram alerts for trades, portfolio and event log in a clear dashboard.": "Alertas de Telegram sobre operaciones y cartera.",
        "Step-by-step guide": "Guía paso a paso",
        "Risk disclosure": "Riesgos",
        "Featured on the blog": "Destacado en el blog",
        "How to set up grid trading on Binance": "Cómo configurar grid trading en Binance",
        "Step-by-step: spot grid basics (range, levels, step) and safe API automation without withdrawal rights.": "Paso a paso: grid spot, parámetros y API segura sin retiros.",
        "Read article": "Leer artículo",
        "All blog posts": "Todos los artículos",
        "Transparent pricing — no profit share": "Precios transparentes — sin % de beneficio",
        "We do not take a cut of your profits. You only pay for access to the tool — like a SaaS subscription.": "Sin comisión sobre beneficios. Solo pagas el acceso al software.",
        "500 UAH": "500 UAH",
        "/ 30 days": "/ 30 días",
        "After sign-up — 3-day trial at no charge": "Tras registrarte — 3 días de prueba gratis",
        "Unlimited grids (strategies)": "Grids ilimitados (estrategias)",
        "Telegram notifications": "Notificaciones Telegram",
        "Safe API connection (no withdrawal)": "API segura (sin retiros)",
        "Support in Ukrainian": "Soporte en ucraniano",
        "Start free trial": "Iniciar prueba",
        "Payment methods": "Formas de pago",
        "Terms of service": "Términos del servicio",
        "What is in the dashboard": "Qué hay en el panel",
        "Few screens — maximum clarity for spot trading on one exchange.": "Pocas pantallas — máxima claridad en un exchange.",
        "Grid and levels": "Grid y niveles",
        "Start, pause, entry levels, take-profit — your parameters.": "Inicio, pausa, niveles, take-profit — tus parámetros.",
        "Portfolio": "Cartera",
        "USDT balance and positions synced with Binance.": "Saldo USDT y posiciones sincronizados con Binance.",
        "Security": "Seguridad",
        "API without withdrawal; we do not store your exchange password. See <a href=\"/offer.html\">terms</a>.": "API sin retiros; no guardamos la contraseña del exchange. Ver <a href=\"/offer.html\">términos</a>.",
        "<strong>Disclaimer:</strong> Cryptocurrency trading involves substantial risk of loss.\n      StrIM is software to automate your trading strategies only and does not provide financial or investment advice.\n      All trading decisions are yours. No futures, margin or withdrawal via API.": "<strong>Aviso:</strong> El trading de cripto implica riesgo de pérdidas.\n      StrIM es software de automatización, no asesoramiento financiero.\n      Tus decisiones son tuyas. Sin futuros, margen ni retiros por API.",
        "Privacy": "Privacidad",
        "Refunds": "Reembolsos",
        "Risks": "Riesgos",
        "Frequently asked questions (FAQ)": "Preguntas frecuentes (FAQ)",
        "Short answers about the bot, Binance keys, balance and subscription. Detailed steps — in the <a href=\"/en/instruction.html\">guide</a>.": "Respuestas breves sobre el bot, claves Binance y suscripción. Detalles en la <a href=\"/es/instruction.html\">guía</a>.",
        "Why does USDT balance show zero?": "¿Por qué el saldo USDT es cero?",
        "Exchange access error (401 / 403)": "Error de acceso al exchange (401 / 403)",
        "Why is the bot idle?": "¿Por qué el bot está inactivo?",
        "How are referral rewards paid?": "¿Cómo se pagan las recompensas de referidos?",
        "Realized and unrealized P/L": "P/L realizado y no realizado",
        "EXPIRED status (subscription ended)": "Estado EXPIRED (suscripción finalizada)",
        "Did not find an answer?": "¿No encontraste respuesta?",
        "Information on this site is not financial advice.": "La información no es asesoramiento financiero.",
        "StrIM — Binance spot grid trading automation | StrIM": "StrIM — automatización grid spot Binance | StrIM",
        "Automate Binance spot trading: Ukrainian SaaS, funds stay on the exchange, API without withdrawal. 3-day trial, 500 UAH/month.": "Automatiza spot en Binance: SaaS ucraniano, fondos en el exchange, API sin retiros. Prueba 3 días, 500 UAH/mes.",
        "Blog — Binance trading automation | StrIM": "Blog — automatización Binance | StrIM",
        "StrIM blog: Trading, automation and security": "Blog StrIM: trading, automatización y seguridad",
        "Read more": "Leer más",
        "Setup guide": "Guía de configuración",
        "The full step-by-step guide with screenshots is currently available in <strong>Ukrainian</strong>:": "La guía completa con capturas está en <strong>ucraniano</strong>:",
        "Open Ukrainian guide": "Abrir guía en ucraniano",
        "Quick links in English:": "Enlaces rápidos:",
        "Site language": "Idioma del sitio",
        "Language:": "Idioma:",
        "2 June 2026": "2 de junio de 2026",
        "8 min read": "8 min de lectura",
        "How to set up grid trading on Binance: step-by-step guide": "Grid trading en Binance: guía paso a paso",
        "Grid trading on Binance": "Grid trading en Binance",
        "Conclusion": "Conclusión",
        "Reminder: crypto trading involves risk. Spot grids reduce some risks but do not guarantee profit. Diversify and test strategies. See <a href=\"/en/risks.html\">risk disclosure</a>.": "Aviso: el trading implica riesgos. El grid spot no garantiza beneficios. Ver <a href=\"/es/risks.html\">riesgos</a>.",
        "Spot grid trading on Binance is about levels and size — not guessing the market. After setting parameters, connect the exchange to StrIM and try a <strong>3-day free trial</strong>.": "El grid spot en Binance es disciplina de niveles. Conecta el exchange a StrIM y prueba <strong>3 días gratis</strong>.",
    },
    "fr": {
        "Made in Ukraine": "Produit ukrainien",
        "STRIM Algo LLC · Binance spot via cloud dashboard": "STRIM Algo LLC · spot Binance via tableau de bord cloud",
        "Light theme": "Thème clair",
        "Theme": "Thème",
        "Menu": "Menu",
        "Home": "Accueil",
        "Blog": "Blog",
        "About": "Plateforme",
        "Pricing": "Tarifs",
        "Guide": "Guide",
        "Terms": "CGU",
        "Log in": "Connexion",
        "Sign up": "Inscription",
        "Try free for 3 days": "Essai gratuit (3 jours)",
        "Log in to dashboard": "Ouvrir le tableau de bord",
        "Binance spot · Ukrainian SaaS · no profit share": "Binance spot · SaaS ukrainien · sans % sur les gains",
        "Automate Binance spot trading <span class=\"mark\">without emotions</span>": "Automatisez le spot Binance <span class=\"mark\">sans émotions</span>",
        "Cloud dashboard for grid trading. Your funds stay on the exchange —\n            we only execute your rules 24/7. API keys without withdrawal permission.": "Tableau de bord cloud pour le grid trading. Fonds sur l’exchange —\n            nous exécutons vos règles 24/7. Clés API sans retrait.",
        "Crypto trading involves risk. StrIM is software, not investment advice.\n            Subscription 500 UAH / 30 days after trial.": "Le trading comporte des risques. StrIM est un logiciel, pas un conseil financier. Abonnement 500 UAH / 30 jours après l’essai.",
        "Cloud dashboard": "Tableau de bord cloud",
        "Strategy levels": "Niveaux de stratégie",
        "Event log": "Journal d’événements",
        "How it works and why it is safe": "Fonctionnement et sécurité",
        "Funds never move to our accounts — only automation of your rules via the Binance API.": "Les fonds ne vont pas sur nos comptes — automatisation via l’API Binance uniquement.",
        "Create an API key": "Créer une clé API",
        "Enable only <strong>Read</strong> and <strong>Spot trade</strong>. Keep <strong>Withdrawals</strong> disabled — StrIM cannot withdraw funds from the exchange.": "Activez <strong>Read</strong> et <strong>Spot trade</strong> uniquement. <strong>Withdrawals</strong> désactivé.",
        "Set up your grid": "Configurer la grille",
        "Set DCA levels and take-profit targets. The system follows your plan without impulsive decisions.": "Niveaux DCA et take-profit. Le système suit votre plan.",
        "Stay in control": "Gardez le contrôle",
        "Telegram alerts for trades, portfolio and event log in a clear dashboard.": "Alertes Telegram pour les trades et le portefeuille.",
        "Step-by-step guide": "Guide pas à pas",
        "Risk disclosure": "Risques",
        "Featured on the blog": "À la une du blog",
        "How to set up grid trading on Binance": "Configurer le grid trading sur Binance",
        "Step-by-step: spot grid basics (range, levels, step) and safe API automation without withdrawal rights.": "Pas à pas : grille spot, paramètres et API sans retrait.",
        "Read article": "Lire l’article",
        "All blog posts": "Tous les articles",
        "Transparent pricing — no profit share": "Tarifs transparents — sans % sur les gains",
        "We do not take a cut of your profits. You only pay for access to the tool — like a SaaS subscription.": "Aucune commission sur vos gains. Vous payez l’accès au logiciel.",
        "500 UAH": "500 UAH",
        "/ 30 days": "/ 30 jours",
        "After sign-up — 3-day trial at no charge": "Après inscription — essai 3 jours gratuit",
        "Unlimited grids (strategies)": "Grilles illimitées (stratégies)",
        "Telegram notifications": "Notifications Telegram",
        "Safe API connection (no withdrawal)": "API sécurisée (sans retrait)",
        "Support in Ukrainian": "Support en ukrainien",
        "Start free trial": "Commencer l’essai",
        "Payment methods": "Moyens de paiement",
        "Terms of service": "Conditions d’utilisation",
        "What is in the dashboard": "Contenu du tableau de bord",
        "Few screens — maximum clarity for spot trading on one exchange.": "Peu d’écrans — clarté maximale sur un exchange.",
        "Grid and levels": "Grille et niveaux",
        "Start, pause, entry levels, take-profit — your parameters.": "Démarrage, pause, niveaux, take-profit — vos paramètres.",
        "Portfolio": "Portefeuille",
        "USDT balance and positions synced with Binance.": "Solde USDT et positions synchronisés avec Binance.",
        "Security": "Sécurité",
        "API without withdrawal; we do not store your exchange password. See <a href=\"/offer.html\">terms</a>.": "API sans retrait ; pas de stockage du mot de passe exchange. Voir <a href=\"/offer.html\">CGU</a>.",
        "<strong>Disclaimer:</strong> Cryptocurrency trading involves substantial risk of loss.\n      StrIM is software to automate your trading strategies only and does not provide financial or investment advice.\n      All trading decisions are yours. No futures, margin or withdrawal via API.": "<strong>Avertissement :</strong> Le trading crypto comporte un risque de perte.\n      StrIM est un logiciel d’automatisation, pas un conseil financier.\n      Vos décisions vous appartiennent. Pas de futures, marge ni retrait via API.",
        "Privacy": "Confidentialité",
        "Refunds": "Remboursements",
        "Risks": "Risques",
        "Frequently asked questions (FAQ)": "Foire aux questions (FAQ)",
        "Short answers about the bot, Binance keys, balance and subscription. Detailed steps — in the <a href=\"/en/instruction.html\">guide</a>.": "Réponses courtes sur le bot, les clés Binance et l’abonnement. Détails dans le <a href=\"/fr/instruction.html\">guide</a>.",
        "Why does USDT balance show zero?": "Pourquoi le solde USDT est à zéro ?",
        "Exchange access error (401 / 403)": "Erreur d’accès à l’exchange (401 / 403)",
        "Why is the bot idle?": "Pourquoi le bot est inactif ?",
        "How are referral rewards paid?": "Comment sont payées les récompenses de parrainage ?",
        "Realized and unrealized P/L": "P/L réalisé et non réalisé",
        "EXPIRED status (subscription ended)": "Statut EXPIRED (abonnement terminé)",
        "Did not find an answer?": "Pas de réponse ?",
        "Information on this site is not financial advice.": "Les informations ne constituent pas un conseil financier.",
        "StrIM — Binance spot grid trading automation | StrIM": "StrIM — automatisation grid spot Binance | StrIM",
        "Automate Binance spot trading: Ukrainian SaaS, funds stay on the exchange, API without withdrawal. 3-day trial, 500 UAH/month.": "Automatisez le spot Binance : SaaS ukrainien, fonds sur l’exchange, API sans retrait. Essai 3 jours, 500 UAH/mois.",
        "Blog — Binance trading automation | StrIM": "Blog — automatisation Binance | StrIM",
        "StrIM blog: Trading, automation and security": "Blog StrIM : trading, automatisation, sécurité",
        "Read more": "Lire la suite",
        "Setup guide": "Guide d’installation",
        "The full step-by-step guide with screenshots is currently available in <strong>Ukrainian</strong>:": "Le guide complet avec captures est en <strong>ukrainien</strong> :",
        "Open Ukrainian guide": "Ouvrir le guide ukrainien",
        "Quick links in English:": "Liens rapides :",
        "Site language": "Langue du site",
        "Language:": "Langue :",
        "2 June 2026": "2 juin 2026",
        "8 min read": "8 min de lecture",
        "How to set up grid trading on Binance: step-by-step guide": "Grid trading sur Binance : guide pas à pas",
        "Grid trading on Binance": "Grid trading sur Binance",
        "Conclusion": "Conclusion",
        "Reminder: crypto trading involves risk. Spot grids reduce some risks but do not guarantee profit. Diversify and test strategies. See <a href=\"/en/risks.html\">risk disclosure</a>.": "Rappel : le trading comporte des risques. Voir <a href=\"/fr/risks.html\">risques</a>.",
        "Spot grid trading on Binance is about levels and size — not guessing the market. After setting parameters, connect the exchange to StrIM and try a <strong>3-day free trial</strong>.": "Le grid spot sur Binance, ce sont des niveaux et des tailles. Connectez l’exchange à StrIM et essayez <strong>3 jours gratuits</strong>.",
    },
}

# FAQ body EN paragraphs
FAQ_EN_EXTRA = {
    "The dashboard shows <b>free USDT on your Binance spot wallet</b> linked to your API keys. Zero means no free USDT on spot (funds may be in orders, other assets or another sub-account).": {
        "pl": "<p>Panel pokazuje <b>wolne USDT na portfelu spot Binance</b> powiązanym z kluczami API. Zero oznacza brak wolnego USDT na spot.</p>",
        "de": "<p>Das Dashboard zeigt <b>freies USDT auf Ihrer Binance-Spot-Wallet</b>. Null bedeutet kein freies USDT auf Spot.</p>",
        "es": "<p>El panel muestra <b>USDT libre en la wallet spot de Binance</b>. Cero significa que no hay USDT libre en spot.</p>",
        "fr": "<p>Le tableau de bord affiche l’<b>USDT libre sur le portefeuille spot Binance</b>. Zéro signifie aucun USDT libre en spot.</p>",
    },
}

# Blog grid full prose EN - translate in build for each locale via shorter approach: copy EN grid and apply PHRASES + blog-specific dict

BLOG_GRID_EN = {
    "Crypto markets are volatile. While some traders try to time entries and exits, others use a systematic approach to benefit from price swings. One of the most effective tools is <strong>grid trading</strong>. This article explains how spot grid orders work and how to configure them.": "pl",
}


def apply_phrases(text: str, code: str) -> str:
    phrases = PHRASES.get(code, {})
    # longest first to avoid partial replacements
    for en, loc in sorted(phrases.items(), key=lambda x: -len(x[0])):
        text = text.replace(en, loc)
    return text


def mechanical_locale(text: str, code: str, meta: dict) -> str:
    prefix = meta["prefix"]
    text = text.replace("/en/", prefix + "/")
    text = text.replace('lang="en"', f'lang="{meta["html"]}"')
    text = text.replace("en_US", meta["og"])
    text = text.replace("https://strim.in.ua/en/", f"https://strim.in.ua{prefix}/")
    text = text.replace('hreflang="en"', f'hreflang="{meta["html"]}"')
    return text


def build_locale(code: str) -> None:
    meta = LOCALES[code]
    dest = WWW / code
    if dest.exists():
        shutil.rmtree(dest)
    shutil.copytree(EN, dest)
    for path in dest.rglob("*.html"):
        text = path.read_text(encoding="utf-8")
        text = mechanical_locale(text, code, meta)
        text = apply_phrases(text, code)
        # fix instruction quick links label
        text = text.replace("Quick links in English:", PHRASES[code].get("Quick links in English:", "Quick links:"))
        path.write_text(text, encoding="utf-8")
        print("  ", path.relative_to(ROOT))


def build_locales_json() -> None:
    cfg_path = WWW / "assets/i18n/locales.json"
    cfg = json.loads(cfg_path.read_text(encoding="utf-8"))
    for code, meta in LOCALES.items():
        if "status" in cfg["locales"].get(code, {}):
            del cfg["locales"][code]["status"]
    paths = [
        ("/", "index"),
        ("/about.html", "about"),
        ("/tariffs.html", "tariffs"),
        ("/faq.html", "faq"),
        ("/instruction.html", "instruction"),
        ("/blog/", "blog"),
        ("/blog/grid-trading-binance.html", "grid"),
    ]
    for path_key, _ in paths:
        if path_key not in cfg["routes"]:
            cfg["routes"][path_key] = {"uk": path_key if path_key != "/" else "/"}
        entry = cfg["routes"][path_key]
        entry["en"] = entry.get("en") or ("/en/" if path_key == "/" else f"/en{path_key}")
        for code, meta in LOCALES.items():
            p = meta["prefix"]
            if path_key == "/":
                entry[code] = p + "/"
            else:
                entry[code] = p + path_key
    # reverse routes for each locale prefix
    for code, meta in LOCALES.items():
        p = meta["prefix"]
        for path_key, _ in paths:
            loc_path = (p + "/") if path_key == "/" else (p + path_key)
            if loc_path not in cfg["routes"]:
                cfg["routes"][loc_path] = {}
            for c2, m2 in {**LOCALES, "uk": {"prefix": ""}, "en": {"prefix": "/en"}}.items():
                if c2 == "uk":
                    cfg["routes"][loc_path]["uk"] = "/" if path_key == "/" else path_key
                elif c2 == "en":
                    cfg["routes"][loc_path]["en"] = "/en/" if path_key == "/" else f"/en{path_key}"
                else:
                    pp = m2["prefix"]
                    cfg["routes"][loc_path][c2] = (pp + "/") if path_key == "/" else (pp + path_key)
    cfg_path.write_text(json.dumps(cfg, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("updated", cfg_path)


def main() -> None:
    for code in LOCALES:
        print("build", code)
        build_locale(code)
    build_locales_json()
    print("done")


if __name__ == "__main__":
    main()
