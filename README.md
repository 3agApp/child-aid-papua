# Child Aid Papua – 1% Spende

WordPress-Plugin für den 3ag.education-Shop. Drei Funktionen:

1. **Story-Seite** – Die Child-Aid-Papua-Seite wird unter `https://deine-site.ch/child-aid-papua` ausgeliefert (eigenständige Seite, unabhängig vom Theme).
2. **Checkout-Hinweis** – Im WooCommerce-Checkout (und auf der Warenkorb- und Danke-Seite sowie in den Bestell-E-Mails) erscheint: *«Mit dieser Bestellung spenden wir CHF xx.xx an das Child-Aid-Papua-Schulprojekt»* mit Link auf die Story-Seite.
3. **Spendenbericht** – Unter **WooCommerce → Child Aid Spenden** siehst du den Spendenbetrag für einen frei wählbaren Zeitraum, inkl. Aufstellung pro Bestellung und CSV-Export.

## Installation

1. Den Ordner `child-aid-papua` nach `wp-content/plugins/` kopieren (oder das ZIP unter **Plugins → Installieren → Plugin hochladen** hochladen).
2. Plugin aktivieren. Die Permalink-Regeln werden bei der Aktivierung automatisch aktualisiert; falls `/child-aid-papua` einen 404 liefert, einmal **Einstellungen → Permalinks → Speichern** klicken.

## Funktionsweise der Spendenberechnung

- Standard: **1 %** vom Bestelltotal (inkl. Versand und MwSt.). Der Prozentsatz ist auf der Berichtsseite einstellbar.
- Der Betrag und der Prozentsatz werden beim Checkout auf der Bestellung gespeichert (`_cap_donation_amount`, `_cap_donation_percentage`).
- Der Bericht zählt Bestellungen mit Status **In Bearbeitung** und **Fertiggestellt** und rechnet **Rückerstattungen automatisch ab**. Auch Bestellungen von vor der Plugin-Aktivierung werden erfasst (dann mit dem aktuellen Prozentsatz).
- Berechnungsbasis anpassbar per Filter: `cap_donation_cart_base`, `cap_donation_order_base`, Statusliste per `cap_report_order_statuses`.

## Block-Checkout

Beim klassischen Checkout erscheint der Hinweis direkt in der Bestellübersicht. Beim Block-Checkout (Gutenberg) wird der Hinweis oberhalb des Formulars angezeigt; alternativ kann der Shortcode `[child_aid_donation_notice]` an beliebiger Stelle platziert werden.

## Seite aktualisieren

Die HTML-Seite liegt unter `templates/child-aid-papua-page.html` und kann dort direkt ersetzt/bearbeitet werden.

## Kompatibilität

- WordPress ≥ 6.0, PHP ≥ 7.4, WooCommerce ≥ 7.0
- HPOS (High-Performance Order Storage) kompatibel
- Funktioniert auch ohne WooCommerce – dann wird nur die Story-Seite ausgeliefert.
