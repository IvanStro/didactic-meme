#!/usr/bin/env python3
"""Translate FAQ bodies and blog grid article in pl/de/es/fr."""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "www"

FAQ_PAIRS = {
    "pl": [
        ("The dashboard shows <b>free USDT on your Binance spot wallet</b> linked to your API keys. Zero means no free USDT on spot (funds may be in orders, other assets or another sub-account).",
         "Panel pokazuje <b>wolne USDT na portfelu spot Binance</b> powiązanym z kluczami API. Zero oznacza brak wolnego USDT na spot (środki mogą być w zleceniach lub innych aktywach)."),
        ("Grid DCA requires free USDT on spot. Top up spot and ensure the key has spot trading access.",
         "Do dokładek siatki potrzebne jest wolne USDT na spot. Doładuj spot i sprawdź uprawnienia do handlu spot."),
        ("Binance rejected the API request. Common causes:", "Binance odrzuciło żądanie API. Typowe przyczyny:"),
        ("Key expired or deleted.", "Klucz wygasł lub został usunięty."),
        ("Missing <b>Reading</b> and <b>Spot Trading</b> permissions.", "Brak uprawnień <b>Reading</b> i <b>Spot Trading</b>."),
        ("StrIM may <b>pause the strategy automatically</b>. After fixing keys — press <b>Start</b> in the dashboard.",
         "StrIM może <b>automatycznie wstrzymać strategię</b>. Po naprawie kluczy naciśnij <b>Start</b> w panelu."),
        ("<b>Strategy paused</b> — press Start.", "<b>Strategia wstrzymana</b> — naciśnij Start."),
        ("<b>Subscription ended</b> — renew access", "<b>Subskrypcja wygasła</b> — odnów dostęp"),
        ("<b>Price did not reach</b> DCA or TP level.", "<b>Cena nie doszła</b> do poziomu DCA lub TP."),
        ("<b>Not enough USDT</b> for Binance minimum order.", "<b>Za mało USDT</b> na minimalne zlecenie Binance."),
        ("<b>API issue</b> — see exchange access error.", "<b>Problem z API</b> — zobacz błąd dostępu do giełdy."),
        ("Referrer is rewarded after the invited user’s subscription payment is confirmed (current amount in dashboard and <a href=\"/offer.html\">terms</a>).",
         "Polecający otrzymuje nagrodę po potwierdzeniu płatności za subskrypcję (kwota w panelu i <a href=\"/offer.html\">regulaminie</a>)."),
        ("Credited after admin confirmation.", "Naliczenie po potwierdzeniu przez administratora."),
        ("Withdrawal — BSC USDT (BEP-20).", "Wypłata — BSC USDT (BEP-20)."),
        ("One payment — one credit.", "Jedna płatność — jedno naliczenie."),
        ("<b>Realized</b> — P/L from completed sells in the journal.", "<b>Zrealizowany</b> — P/L z zakończonych sprzedaży w dzienniku."),
        ("<b>Unrealized</b> — mark-to-market of open position on the exchange.", "<b>Niezrealizowany</b> — wycena otwartej pozycji na giełdzie."),
        ("Not a guarantee of future results.", "Nie gwarantuje przyszłych wyników."),
        ("If trial and paid period have ended, the account becomes <b>EXPIRED</b>:",
         "Gdy trial i opłacony okres minęły, konto przechodzi w <b>EXPIRED</b>:"),
        ("<b>Start / Pause / Stop</b> and quick start — <b>unavailable</b>.", "<b>Start / Pause / Stop</b> i szybki start — <b>niedostępne</b>."),
        ("All active strategies are set to <b>pause</b>.", "Wszystkie aktywne strategie są na <b>pauzie</b>."),
        ("Bot may <b>close open positions</b> per TP rules (wind-down) but <b>does not open new cycles</b> (BUY0, DCA).",
         "Bot może <b>zamknąć pozycje</b> według TP, ale <b>nie otwiera nowych cykli</b> (BUY0, DCA)."),
        ("Dashboard view and payment remain available. After payment press <b>Start</b>.",
         "Podgląd panelu i płatność dostępne. Po opłacie naciśnij <b>Start</b>."),
        ("Details: <a href=\"/offer.html\">terms</a>", "Szczegóły: <a href=\"/offer.html\">regulamin</a>"),
        ("StrIM automates spot trading; it is not investment advice.", "StrIM automatyzuje handel spot; to nie jest porada inwestycyjna."),
        ("More: <a href=\"/pl/risks.html\">Risks</a>", "Więcej: <a href=\"/pl/risks.html\">Ryzyka</a>"),
        ("Email <a href=\"mailto:support@strim.in.ua\">support@strim.in.ua</a> or Telegram support (link in dashboard).",
         "Napisz na <a href=\"mailto:support@strim.in.ua\">support@strim.in.ua</a> lub Telegram (link w panelu)."),
    ],
    "de": [
        ("The dashboard shows <b>free USDT on your Binance spot wallet</b> linked to your API keys. Zero means no free USDT on spot (funds may be in orders, other assets or another sub-account).",
         "Das Dashboard zeigt <b>freies USDT auf der Binance-Spot-Wallet</b>. Null bedeutet kein freies USDT auf Spot."),
        ("Grid DCA requires free USDT on spot. Top up spot and ensure the key has spot trading access.",
         "Für Grid-Nachkäufe wird freies USDT auf Spot benötigt."),
        ("Binance rejected the API request. Common causes:", "Binance hat die API-Anfrage abgelehnt. Typische Ursachen:"),
        ("Key expired or deleted.", "Schlüssel abgelaufen oder gelöscht."),
        ("Missing <b>Reading</b> and <b>Spot Trading</b> permissions.", "Fehlende Rechte <b>Reading</b> und <b>Spot Trading</b>."),
        ("StrIM may <b>pause the strategy automatically</b>. After fixing keys — press <b>Start</b> in the dashboard.",
         "StrIM kann die Strategie <b>automatisch pausieren</b>. Nach der Reparatur <b>Start</b> drücken."),
        ("<b>Strategy paused</b> — press Start.", "<b>Strategie pausiert</b> — Start drücken."),
        ("<b>Subscription ended</b> — renew access", "<b>Abo beendet</b> — Zugang verlängern"),
        ("<b>Price did not reach</b> DCA or TP level.", "<b>Preis hat DCA/TP nicht erreicht</b>."),
        ("<b>Not enough USDT</b> for Binance minimum order.", "<b>Nicht genug USDT</b> für Mindestorder."),
        ("<b>API issue</b> — see exchange access error.", "<b>API-Problem</b> — siehe Zugriffsfehler."),
        ("Referrer is rewarded after the invited user’s subscription payment is confirmed (current amount in dashboard and <a href=\"/offer.html\">terms</a>).",
         "Referrer erhält Prämie nach bestätigter Zahlung (Betrag im Dashboard und <a href=\"/offer.html\">AGB</a>)."),
        ("Credited after admin confirmation.", "Gutschrift nach Admin-Bestätigung."),
        ("Withdrawal — BSC USDT (BEP-20).", "Auszahlung — BSC USDT (BEP-20)."),
        ("One payment — one credit.", "Eine Zahlung — eine Gutschrift."),
        ("<b>Realized</b> — P/L from completed sells in the journal.", "<b>Realisiert</b> — P/L aus Verkäufen im Journal."),
        ("<b>Unrealized</b> — mark-to-market of open position on the exchange.", "<b>Unrealisiert</b> — Bewertung der offenen Position."),
        ("Not a guarantee of future results.", "Keine Garantie für die Zukunft."),
        ("If trial and paid period have ended, the account becomes <b>EXPIRED</b>:",
         "Nach Test und bezahlter Zeit wird das Konto <b>EXPIRED</b>:"),
        ("<b>Start / Pause / Stop</b> and quick start — <b>unavailable</b>.", "<b>Start / Pause / Stop</b> — <b>nicht verfügbar</b>."),
        ("All active strategies are set to <b>pause</b>.", "Alle Strategien werden <b>pausiert</b>."),
        ("Bot may <b>close open positions</b> per TP rules (wind-down) but <b>does not open new cycles</b> (BUY0, DCA).",
         "Bot kann Positionen per TP schließen, aber <b>keine neuen Zyklen</b> öffnen."),
        ("Dashboard view and payment remain available. After payment press <b>Start</b>.",
         "Dashboard und Zahlung bleiben verfügbar. Danach <b>Start</b>."),
        ("StrIM automates spot trading; it is not investment advice.", "StrIM automatisiert Spot-Handel, keine Anlageberatung."),
        ("Email <a href=\"mailto:support@strim.in.ua\">support@strim.in.ua</a> or Telegram support (link in dashboard).",
         "E-Mail <a href=\"mailto:support@strim.in.ua\">support@strim.in.ua</a> oder Telegram-Support."),
    ],
    "es": [
        ("The dashboard shows <b>free USDT on your Binance spot wallet</b> linked to your API keys. Zero means no free USDT on spot (funds may be in orders, other assets or another sub-account).",
         "El panel muestra <b>USDT libre en la wallet spot de Binance</b>. Cero = sin USDT libre en spot."),
        ("Grid DCA requires free USDT on spot. Top up spot and ensure the key has spot trading access.",
         "Las compras del grid requieren USDT libre en spot."),
        ("Binance rejected the API request. Common causes:", "Binance rechazó la solicitud API. Causas habituales:"),
        ("Key expired or deleted.", "Clave caducada o eliminada."),
        ("Missing <b>Reading</b> and <b>Spot Trading</b> permissions.", "Faltan permisos <b>Reading</b> y <b>Spot Trading</b>."),
        ("StrIM may <b>pause the strategy automatically</b>. After fixing keys — press <b>Start</b> in the dashboard.",
         "StrIM puede <b>pausar la estrategia</b>. Tras corregir claves, pulsa <b>Start</b>."),
        ("<b>Strategy paused</b> — press Start.", "<b>Estrategia en pausa</b> — pulsa Start."),
        ("<b>Subscription ended</b> — renew access", "<b>Suscripción finalizada</b> — renueva el acceso"),
        ("StrIM automates spot trading; it is not investment advice.", "StrIM automatiza el spot; no es asesoramiento financiero."),
        ("Email <a href=\"mailto:support@strim.in.ua\">support@strim.in.ua</a> or Telegram support (link in dashboard).",
         "Escribe a <a href=\"mailto:support@strim.in.ua\">support@strim.in.ua</a> o Telegram (enlace en el panel)."),
    ],
    "fr": [
        ("The dashboard shows <b>free USDT on your Binance spot wallet</b> linked to your API keys. Zero means no free USDT on spot (funds may be in orders, other assets or another sub-account).",
         "Le tableau de bord affiche l’<b>USDT libre sur le portefeuille spot Binance</b>. Zéro = pas d’USDT libre en spot."),
        ("Grid DCA requires free USDT on spot. Top up spot and ensure the key has spot trading access.",
         "Les rachats du grid nécessitent de l’USDT libre en spot."),
        ("Binance rejected the API request. Common causes:", "Binance a rejeté la requête API. Causes fréquentes :"),
        ("StrIM automates spot trading; it is not investment advice.", "StrIM automatise le spot ; ce n’est pas un conseil financier."),
        ("Email <a href=\"mailto:support@strim.in.ua\">support@strim.in.ua</a> or Telegram support (link in dashboard).",
         "Écrivez à <a href=\"mailto:support@strim.in.ua\">support@strim.in.ua</a> ou Telegram (lien dans le tableau de bord)."),
    ],
}

BLOG_PROSE = {
    "pl": """<div class="prose">
            <p>Rynki krypto są zmienne. Zamiast zgadywać moment wejścia, wielu traderów stosuje <strong>grid trading</strong>. Ten artykuł wyjaśnia, jak działa siatka spot i jak ją skonfigurować.</p>
            <h2>Czym jest grid trading?</h2>
            <p>To seria zleceń kupna i sprzedaży na określonych poziomach cenowych — wizualnie tworzą „siatkę”.</p>
            <p>Zasada: <strong>kupuj taniej, sprzedawaj drożej</strong>. Spadek ceny uruchamia kupna, wzrost — sprzedaże.</p>
            <h2>Zalety siatki spot</h2>
            <ul>
              <li><strong>Brak likwidacji</strong> na spot — posiadasz aktywo.</li>
              <li><strong>Działa w bok</strong> — gdy cena oscyluje w zakresie.</li>
              <li><strong>Mniej emocji</strong> — algorytm trzyma plan.</li>
            </ul>
            <h2>Parametry podstawowe</h2>
            <ol>
              <li><strong>Zakres cen</strong> — korytarz pracy bota.</li>
              <li><strong>Liczba poziomów</strong> — więcej poziomów = więcej transakcji.</li>
              <li><strong>Krok siatki</strong> — odstęp między zleceniami.</li>
              <li><strong>Kapitał</strong> — np. USDT na strategię.</li>
            </ol>
            <h2>Automatyzacja ze StrIM</h2>
            <p><strong>StrIM</strong> to ukraiński SaaS do automatyzacji spot na Binance. Połączenie przez API bez wypłat — <a href="/pl/instruction.html">instrukcja</a>.</p>
            <blockquote>Uwaga: handel krypto wiąże się z ryzykiem. Zobacz <a href="/pl/risks.html">ryzyka</a>.</blockquote>
            <h2>Podsumowanie</h2>
            <p>Po konfiguracji połącz giełdę ze StrIM i wypróbuj <strong>3 dni trialu</strong>.</p>
          </div>""",
    "de": """<div class="prose">
            <p>Kryptomärkte sind volatil. <strong>Grid Trading</strong> nutzt Kursbewegungen systematisch. So funktioniert Spot-Grid und die Einrichtung.</p>
            <h2>Was ist Grid Trading?</h2>
            <p>Kauf- und Verkaufsorders auf definierten Preisleveln — ein „Grid“.</p>
            <p><strong>Günstiger kaufen, teurer verkaufen</strong>.</p>
            <h2>Vorteile auf Spot</h2>
            <ul>
              <li><strong>Keine Liquidationen</strong> auf Spot.</li>
              <li><strong>Seitwärtsmärkte</strong>.</li>
              <li><strong>Weniger Emotionen</strong>.</li>
            </ul>
            <h2>Parameter</h2>
            <ol>
              <li><strong>Preiskorridor</strong></li>
              <li><strong>Anzahl Level</strong></li>
              <li><strong>Grid-Schritt</strong></li>
              <li><strong>Kapital (USDT)</strong></li>
            </ol>
            <h2>StrIM Automatisierung</h2>
            <p><strong>StrIM</strong> — ukrainisches SaaS für Binance Spot. API ohne Auszahlung. <a href="/de/instruction.html">Anleitung</a>.</p>
            <blockquote>Hinweis: Handel ist riskant. <a href="/de/risks.html">Risiken</a>.</blockquote>
            <h2>Fazit</h2>
            <p>Verbinden Sie die Börse und testen Sie <strong>3 Tage kostenlos</strong>.</p>
          </div>""",
    "es": """<div class="prose">
            <p>Los mercados son volátiles. El <strong>grid trading</strong> aprovecha los movimientos. Cómo funciona el grid spot.</p>
            <h2>¿Qué es grid trading?</h2>
            <p>Órdenes de compra y venta en niveles de precio — una «cuadrícula».</p>
            <p><strong>Comprar más barato, vender más caro</strong>.</p>
            <h2>Ventajas en spot</h2>
            <ul><li><strong>Sin liquidaciones</strong> en spot.</li><li><strong>Mercados laterales</strong>.</li><li><strong>Menos emociones</strong>.</li></ul>
            <h2>Parámetros</h2>
            <ol><li><strong>Rango de precios</strong></li><li><strong>Niveles</strong></li><li><strong>Paso del grid</strong></li><li><strong>Inversión USDT</strong></li></ol>
            <h2>StrIM</h2>
            <p><strong>StrIM</strong> automatiza spot en Binance. <a href="/es/instruction.html">Guía</a>.</p>
            <blockquote>Riesgo: ver <a href="/es/risks.html">riesgos</a>.</blockquote>
            <h2>Conclusión</h2>
            <p>Prueba <strong>3 días gratis</strong> con StrIM.</p>
          </div>""",
    "fr": """<div class="prose">
            <p>Les marchés crypto sont volatils. Le <strong>grid trading</strong> structure les oscillations. Voici le grid spot.</p>
            <h2>Qu’est-ce que le grid trading ?</h2>
            <p>Ordres d’achat et de vente à des niveaux de prix — une « grille ».</p>
            <p><strong>Acheter bas, vendre haut</strong>.</p>
            <h2>Avantages du spot</h2>
            <ul><li><strong>Pas de liquidation</strong> en spot.</li><li><strong>Marché latéral</strong>.</li><li><strong>Moins d’émotions</strong>.</li></ul>
            <h2>Paramètres</h2>
            <ol><li><strong>Plage de prix</strong></li><li><strong>Nombre de niveaux</strong></li><li><strong>Pas de grille</strong></li><li><strong>Capital USDT</strong></li></ol>
            <h2>StrIM</h2>
            <p><strong>StrIM</strong> — SaaS ukrainien pour le spot Binance. <a href="/fr/instruction.html">Guide</a>.</p>
            <blockquote>Risque : voir <a href="/fr/risks.html">risques</a>.</blockquote>
            <h2>Conclusion</h2>
            <p>Essayez <strong>3 jours gratuits</strong>.</p>
          </div>""",
}


def patch_faq(code: str) -> None:
    path = ROOT / code / "faq.html"
    text = path.read_text(encoding="utf-8")
    for en, loc in FAQ_PAIRS.get(code, []):
        text = text.replace(en, loc)
    path.write_text(text, encoding="utf-8")


def patch_blog(code: str) -> None:
    path = ROOT / code / "blog/grid-trading-binance.html"
    text = path.read_text(encoding="utf-8")
    prose = BLOG_PROSE[code]
    text = re.sub(r'<div class="prose">.*?</div>\s*</article>', prose + "\n        </article>", text, count=1, flags=re.DOTALL)
    path.write_text(text, encoding="utf-8")


for code in ("pl", "de", "es", "fr"):
    patch_faq(code)
    patch_blog(code)
    print("patched", code)
