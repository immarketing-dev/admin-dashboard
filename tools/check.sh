#!/usr/bin/env bash
# Verifikationsharness: Syntax + Leak-Scan.
# Aufruf aus dem Repo-Wurzelverzeichnis: bash tools/check.sh
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

fail=0

# --- 1. PHP-Syntax ------------------------------------------------------
if command -v php >/dev/null 2>&1; then
  while IFS= read -r f; do
    if ! out=$(php -l "$f" 2>&1); then
      echo "SYNTAX: $out"
      fail=1
    fi
  done < <(find . -name '*.php' -not -path './vendor/*' -not -path './.git/*')
else
  echo "HINWEIS: php nicht gefunden - Syntaxpruefung uebersprungen."
fi

# --- 1b. CSS-Struktur-Pruefung (tokens.css/app.css) ---------------------
# Laeuft ohne Baseline-Argument, deshalb nur die Struktur-Pruefungen
# (Rohfarben, undefinierte Tokens, [data-theme]-Platzierung) - die
# Parity-Pruefungen gegen die alte design.css brauchen eine lokale Kopie,
# die es in einem frischen Klon nicht gibt (siehe tools/check_css.php).
if command -v php >/dev/null 2>&1; then
  if ! out=$(php tools/check_css.php 2>&1); then
    echo "CSS: $out"
    fail=1
  fi
else
  echo "HINWEIS: php nicht gefunden - CSS-Pruefung uebersprungen."
fi

# --- PHP-Code ausserhalb der Tags --------------------------------------
# "php -l" hat hier eine prinzipielle blinde Stelle: fehlt ein oeffnendes
# PHP-Tag, ist der Code einfach literaler Text und damit syntaktisch
# gueltig. Die Seite liefert dann ihren eigenen Quelltext im Browser aus.
# Genau das ist einmal passiert, in zehn von elf Seiten gleichzeitig, weil
# ein fehlerhaftes Muster wiederholt wurde. Der Tokenizer findet es sicher.
if command -v php >/dev/null 2>&1; then
  if ! out=$(php tools/check_php_tags.php 2>&1); then
    echo "PHP-TAGS: $out"
    fail=1
  fi
else
  echo "HINWEIS: php nicht gefunden - PHP-Tag-Pruefung uebersprungen."
fi

# --- Session-Bootstrap -------------------------------------------------
# Prueft, dass die Anwendung einen eigenen Session-Namen benutzt. Mit dem
# Standardnamen PHPSESSID kollidiert sie mit jeder anderen PHP-Anwendung,
# die auf einer Schwesterdomain ein domainweites Cookie setzt - der Login
# scheitert dann still, ohne Fehlermeldung.
if command -v php >/dev/null 2>&1; then
  if ! out=$(php tools/test_session.php 2>&1); then
    echo "SESSION: $out"
    fail=1
  fi
fi

# --- 2a. Identifikatoren der privaten Installation ----------------------
# Pro-Installation-Liste von Strings (DB-User, DB-Name, Hostname, E-Mail,
# SMTP-Host etc.), die niemals in eine versionierte Datei geschrieben
# werden duerfen. Diese Liste steht bewusst NICHT in dieser Datei: eine
# Zeichenkette hier waere selbst schon die Veroeffentlichung, die dieser
# Scan eigentlich verhindern soll. Stattdessen liegt sie in
# tools/leakscan-local.txt - lokal, per .gitignore ausgeschlossen. Siehe
# tools/leakscan-local.txt.example fuer das Format.
SCAN_EXCLUDES=(--exclude-dir=.git --exclude-dir=vendor
               --exclude-dir=superpowers --exclude-dir=.superpowers --exclude=check.sh --exclude=LICENSE)

LOCAL_LEAKLIST="tools/leakscan-local.txt"
if [ -f "$LOCAL_LEAKLIST" ]; then
  FORBIDDEN=$(grep -v '^[[:space:]]*#' "$LOCAL_LEAKLIST" | grep -v '^[[:space:]]*$' | paste -sd'|' -)
  if [ -n "$FORBIDDEN" ]; then
    hits=$(grep -rniE "$FORBIDDEN" . "${SCAN_EXCLUDES[@]}" --exclude="$(basename "$LOCAL_LEAKLIST")" 2>/dev/null)

    # Bewusst oeffentliche Zeichenketten herausnehmen - etwa die Adresse der
    # oeffentlichen Demo, die die README absichtlich verlinkt. Die Liste ist
    # versioniert (tools/leakscan-allow.txt), damit eine Ausnahme im Diff
    # sichtbar ist statt still in der lokalen Liste zu verschwinden.
    #
    # Eine Fundstelle faellt nur dann weg, wenn nach dem Entfernen der
    # freigegebenen Zeichenketten kein verbotener Ausdruck mehr uebrig ist.
    # Eine Zeile, die daneben noch ein echtes Kennzeichen traegt, bleibt.
    ALLOWLIST="tools/leakscan-allow.txt"
    if [ -n "$hits" ] && [ -f "$ALLOWLIST" ]; then
      allow_lines=$(grep -v '^[[:space:]]*#' "$ALLOWLIST" | grep -v '^[[:space:]]*$')
      if [ -n "$allow_lines" ]; then
        gefiltert=""
        while IFS= read -r zeile; do
          [ -z "$zeile" ] && continue
          rest="$zeile"
          while IFS= read -r erlaubt; do
            [ -z "$erlaubt" ] && continue
            rest=${rest//"$erlaubt"/}
          done <<< "$allow_lines"
          if printf '%s' "$rest" | grep -qiE "$FORBIDDEN"; then
            gefiltert="${gefiltert}${zeile}"$'\n'
          fi
        done <<< "$hits"
        hits=$(printf '%s' "$gefiltert")
      fi
    fi

    if [ -n "$hits" ]; then
      echo "LEAK: Identifikator der privaten Installation gefunden:"
      echo "$hits"
      fail=1
    fi
  fi
else
  echo "HINWEIS: lokaler Identifikator-Scan nicht konfiguriert (tools/leakscan-local.txt fehlt) - uebersprungen. Siehe tools/leakscan-local.txt.example."
fi

# --- 2b. Fest verdrahtete Zugangsdaten, generisch -----------------------
# Greift unabhaengig vom konkreten Passwort - dieses Skript muss das
# Secret nicht kennen, um es zu finden.
# Hinweis: String-Verkettung kann diesen Scan umgehen (z.B. 'pass' . 'word')
# Dies ist mit Regex nicht zu beheben.
#
# Schluessellistenanteile: COMPOUND vs BARE
# BARE (pass, password, secret, token) sind mehrdeutig - koennen UI-Labels sein.
# COMPOUND sind unambig - eindeutig Zugangsdaten (db_pass, api_key, etc).
# Deshalb: BARE + COMPOUND fuer PHP-Variablen (variable assignment ist eindeutig),
# aber COMPOUND nur fuer Array/INI/YAML (wo BARE mehr falsch-positive sind).
BARE='pass|password|secret|token'
COMPOUND='db_pass|db_password|smtp_pass|smtp_password|api_key|apikey|api_secret|auth_token|access_token|private_key|secret_key|passwd'
BARECOMP="$BARE|$COMPOUND"

CREDS="define[[:space:]]*\([[:space:]]*['\"](DB_PASS|SMTP_PASS)['\"][[:space:]]*,[[:space:]]*['\"].+['\"]"
CREDS="$CREDS|\\\$($BARECOMP)[[:space:]]*=[[:space:]]*['\"][^'\"]{6,}['\"]"
# Mindestlaenge acht: ohne sie las der Scan 'HTTP_X_API_KEY' => 'abc123'
# als hinterlegtes Geheimnis - der Header-NAME endet auf api_key. Was
# kuerzer als acht Zeichen ist, ist ein Testwert oder ein Geheimnis, das
# seinen Namen nicht verdient; ein echter Schluessel hat hier 48.
CREDS="$CREDS|['\"]?($COMPOUND)['\"]?[[:space:]]*=>[[:space:]]*['\"][^'\"]{8,}['\"]"
CREDS="$CREDS|^[[:space:]]*($COMPOUND)[[:space:]]*=[[:space:]]*['\"][^'\"]+['\"]"
CREDS="$CREDS|^[[:space:]]*($COMPOUND)[[:space:]]*:[[:space:]]*[^[:space:]][^[:space:]]*"

creds=$(grep -rniE "$CREDS" . \
          --include='*.php' --include='*.ini' --include='*.yml' --include='*.yaml' --include='*.conf' --include='*.sql' \
          "${SCAN_EXCLUDES[@]}" --exclude=.env.example 2>/dev/null)
if [ -n "$creds" ]; then
  echo "CREDENTIAL: fest verdrahtete Zugangsdaten gefunden:"
  echo "$creds"
  fail=1
fi

# --- 3. Keine fremden Herkuenfte im Ladepfad ----------------------------
# Alle Bibliotheken liegen unter assets/vendor/. Das ist die Voraussetzung
# fuer die Content-Security-Policy in .htaccess: sie erlaubt ausser der
# eigenen Herkunft nichts mehr. Schleicht sich wieder eine CDN-Einbindung
# ein, laedt sie im Betrieb schlicht nicht - und zwar still, weil ein
# blockiertes Skript keine sichtbare Fehlermeldung erzeugt, sondern nur
# eine Schaltflaeche, die nicht mehr reagiert.
extern=$(grep -rnE '(href|src)=["'"'"'][^"'"'"']*//(cdn|cdnjs|fonts\.googleapis|fonts\.gstatic|unpkg|ajax\.googleapis)' . \
          --include='*.php' --include='*.html' --include='*.js' \
          "${SCAN_EXCLUDES[@]}" 2>/dev/null)
if [ -n "$extern" ]; then
  echo "EXTERN: Einbindung von fremder Herkunft (gehoert nach assets/vendor/):"
  echo "$extern"
  fail=1
fi

# Unversionierte Referenzen bleiben verboten - auch in Kommentaren und
# Dokumentation, wo sie als Vorlage zum Kopieren dienen koennten.
cdn=$(grep -rn '@latest' . \
          --include='*.php' --include='*.ini' --include='*.yml' --include='*.yaml' --include='*.conf' \
          "${SCAN_EXCLUDES[@]}" 2>/dev/null)
if [ -n "$cdn" ]; then
  echo "CDN: unversionierte Referenz:"
  echo "$cdn"
  fail=1
fi

# --- 3b. PHP_SELF -------------------------------------------------------
# PHP_SELF haengt die PATH_INFO an. includes/auth.php entschied damit,
# welche Seite die Rollenpruefung sieht: eine Anfrage auf
# /settings.php/index.php prueft 'index.php' - fuer 'staff' erlaubt -
# waehrend der Server settings.php ausfuehrt. SCRIPT_NAME ist das
# aufgeloeste Skript und traegt die PATH_INFO nicht.
self=$(grep -rn "PHP_SELF" . \
          --include='*.php' "${SCAN_EXCLUDES[@]}" 2>/dev/null \
       | grep -v '^\./vendor/' | grep -v 'SCRIPT_NAME')
if [ -n "$self" ]; then
  echo "PHP_SELF: PATH_INFO-anfaellig, SCRIPT_NAME verwenden:"
  echo "$self"
  fail=1
fi

# --- 4. Dateien aus uploads/ --------------------------------------------
stray=$(find uploads -type f ! -name '.gitkeep' ! -name '.htaccess' 2>/dev/null)
if [ -n "$stray" ]; then
  echo "DATEN: Datei in uploads/ die nicht dorthin gehoert:"
  echo "$stray"
  fail=1
fi

# --- 4b. Schutzdateien in uploads/ ---------------------------------------
# Jedes Unterverzeichnis von uploads/ traegt eine .htaccess, die dort die
# PHP-Ausfuehrung sperrt. Sie ist eine Sicherheitsmassnahme, aber weil sie
# mit einem Punkt beginnt und leer aussieht, verschwindet sie leicht - ein
# unbedachtes "rm -rf uploads/wiki" beim Aufraeumen genuegte einmal.
for dir in uploads/*/; do
  [ -d "$dir" ] || continue
  if [ ! -f "${dir}.htaccess" ]; then
    echo "UPLOADS: ${dir}.htaccess fehlt - dort waere PHP ausfuehrbar."
    fail=1
  fi
done

# --- 4c. Kundenunterlagen sind gesperrt ----------------------------------
# Die vier Verzeichnisse mit Kundendaten duerfen vom Webserver gar nicht
# ausgeliefert werden - der einzige Weg hinein ist file.php, das vorher
# prueft, wer fragt. Eine blosse Anwesenheitspruefung wie oben genuegt
# hier nicht: die alte .htaccess war vorhanden und sperrte trotzdem nur
# die PHP-Ausfuehrung, waehrend jede Rechnung als PDF offen im Netz lag.
for dir in client_assets invoices quotes wiki; do
  pfad="uploads/$dir/.htaccess"
  if [ ! -f "$pfad" ]; then
    echo "UPLOADS: $pfad fehlt - Kundenunterlagen waeren oeffentlich abrufbar."
    fail=1
  elif ! grep -q 'Require all denied' "$pfad" || ! grep -q 'Deny from all' "$pfad"; then
    echo "UPLOADS: $pfad sperrt nicht (erwartet: 'Require all denied' UND 'Deny from all')."
    fail=1
  fi
done
# --- 4d. Keine direkten Links auf gesperrte Verzeichnisse ---------------
# Ein href="uploads/invoices/…" umgeht file.php und damit die
# Zugriffspruefung. Nach der Sperre oben laeuft er ohnehin ins Leere - ein
# toter Link faellt aber erst dem Kunden auf, dieser Scan schon vorher.
direkt=$(grep -rnE '(href|src)=("|'"'"'|`)?(\.\./)*uploads/(client_assets|invoices|quotes|wiki)' . \
           --include='*.php' --include='*.js' "${SCAN_EXCLUDES[@]}" 2>/dev/null)
if [ -n "$direkt" ]; then
  echo "UPLOADS: direkter Link auf ein gesperrtes Verzeichnis (file.php benutzen):"
  echo "$direkt"
  fail=1
fi

# --- 4e. Kein overflow-x:hidden am Seitenrumpf ---------------------------
# "hidden" macht aus dem Rumpf einen eigenen Scroll-Behaelter. Jedes
# position:sticky darin haelt sich dann an diesem Behaelter fest statt am
# Sichtfeld - der Seitenkopf und die Filterleiste scrollen wieder mit,
# ohne dass irgendetwas an ihren eigenen Regeln falsch waere. Der Fehler
# ist von der Ursache her nicht zu erraten, deshalb dieser Riegel.
# "clip" schneidet genauso ab und erzeugt keinen Behaelter.
if grep -nE '^\s*overflow-x:\s*hidden' assets/css/app.css | grep -q .; then
  echo "CSS: overflow-x:hidden gefunden - bricht position:sticky. 'clip' benutzen:"
  grep -nE '^\s*overflow-x:\s*hidden' assets/css/app.css
  fail=1
fi

# --- 5. Demo-Modus -------------------------------------------------------
# check_demo prueft die Annahmen des Schreibschutzes (jede POST-Seite
# gedeckt, kein Schreibzugriff im Anzeigepfad). test_seed_demo laesst die
# Demodaten gegen SQLite wirklich laufen - eine Syntaxpruefung wuerde
# einen falschen Spaltennamen nicht bemerken.
if command -v php >/dev/null 2>&1; then
  # Aufgerufene, aber nicht geladene Projektfunktionen. "php -l" sieht die
  # nicht - der Aufruf ist syntaktisch gueltig und faellt erst zur Laufzeit
  # mit HTTP 500 auf, moeglicherweise erst Wochen spaeter.
  if ! out=$(php tools/check_i18n.php 2>&1); then
    echo "I18N: $out"
    fail=1
  fi
  if ! out=$(php tools/test_i18n.php 2>&1); then
    echo "I18N-RENDER: $out"
    fail=1
  fi
  if ! out=$(php tools/check_includes.php 2>&1); then
    echo "INCLUDES: $out"
    fail=1
  fi
  if ! out=$(php tools/test_demo.php 2>&1); then
    echo "DEMO-RIEGEL: $out"
    fail=1
  fi
  if ! out=$(php tools/check_demo.php 2>&1); then
    echo "DEMO: $out"
    fail=1
  fi
  # Die Anordnung der Startseiten-Widgets kommt aus einer POST-Sendung.
  # Die Pruefung, die sie baendigt, laeuft ohne Datenbank.
  if ! out=$(php tools/test_dashboard_layout.php 2>&1); then
    echo "DASHBOARD-LAYOUT: $out"
    fail=1
  fi
  # Die Filter einer Liste ueberleben eine Sendung nur, wenn jede
  # Weiterleitung sie mitfuehrt - und nur die Filter, nichts sonst.
  if ! out=$(php tools/test_filter_state.php 2>&1); then
    echo "FILTER: $out"
    fail=1
  fi
  # Der Abgleich der Projektbeteiligten laeuft gegen den SQLite-Spiegel,
  # also gegen die echten Tabellen samt Fremdschluesseln.
  if ! out=$(php tools/test_task_members.php 2>&1); then
    echo "BETEILIGTE: $out"
    fail=1
  fi
  # Wer welche hochgeladene Datei bekommt. Ein Fehler darin gibt Kunden
  # die Unterlagen anderer Kunden - und faellt im Betrieb nie auf, weil
  # niemand die Rechnung sieht, die er faelschlich sehen duerfte.
  # Das Auffangnetz laeuft nur, wenn ohnehin schon etwas schiefging -
  # ein Fehler darin ersetzt eine leere Seite durch eine andere.
  # Platzhalter gegen uebergebene Werte. "php -l" sieht eine Abfrage,
  # der ein Wert fehlt, nicht - sie ist syntaktisch tadellos und faellt
  # erst zur Laufzeit auf, mitten im Speichern.
  if ! out=$(php tools/check_placeholders.php 2>&1); then
    echo "PLATZHALTER: $out"
    fail=1
  fi
  # Diese sieben liefen lange nur in der CI-Datei und nicht hier.
  # Das ist genau einmal teuer geworden: eine Aenderung, die lokal
  # gruen war, kippte die CI - check_soft_delete.php stand in
  # .github/workflows/ci.yml, aber nicht in dieser Datei. Seitdem
  # ist diese Datei die vollstaendige Suite, und die CI ruft nur
  # noch sie auf.
  if ! out=$(php tools/check_schema.php 2>&1); then
    echo "SCHEMA: $out"
    fail=1
  fi
  if ! out=$(php tools/check_soft_delete.php 2>&1); then
    echo "SOFT-DELETE: $out"
    fail=1
  fi
  if ! out=$(php tools/check_forms.php 2>&1); then
    echo "FORMULARE: $out"
    fail=1
  fi
  if ! out=$(php tools/test_env.php 2>&1); then
    echo "ENV: $out"
    fail=1
  fi
  if ! out=$(php tools/test_csrf.php 2>&1); then
    echo "CSRF: $out"
    fail=1
  fi
  if ! out=$(php tools/test_upload.php 2>&1); then
    echo "UPLOAD: $out"
    fail=1
  fi
  if ! out=$(php tools/test_mail_templates.php 2>&1); then
    echo "MAILVORLAGEN: $out"
    fail=1
  fi
  # Ausgabe ohne Filter. Weder php -l noch die uebrigen Pruefungen
  # sehen ein htmlspecialchars(), das fehlt - contacts.php gab
  # E-Mail, Telefon und Website roh aus, und zwar in einem href.
  if ! out=$(php tools/check_output_escaping.php 2>&1); then
    echo "AUSGABE: $out"
    fail=1
  fi
  # Gegen einen echten Server, sofern einer bereitsteht. Ohne
  # TEST_DB_HOST ueberspringt sich der Test - auf der
  # Entwicklungsmaschine gibt es keinen, in der CI schon.
  #
  # Das ist die Pruefung, die es lange nicht gab: alles andere hier
  # laeuft gegen SQLite, und was die Spiegelung durchwinkt, muss ein
  # echter Server nicht annehmen.
  if ! out=$(php tools/test_mysql.php 2>&1); then
    echo "MYSQL: $out"
    fail=1
  fi
  # SQL, das auf einer MySQL-Erweiterung beruht. Hier laeuft alles
  # gegen die SQLite-Spiegelung; was die durchwinkt, muss ein echter
  # Server nicht annehmen. Genau daran ist die Auswertungsseite auf
  # der Demo mit HTTP 500 gelaufen, waehrend sie hier rendert.
  if ! out=$(php tools/check_sql_portability.php 2>&1); then
    echo "SQL-PORTABILITAET: $out"
    fail=1
  fi
  # Einstellungsschluessel, die ins Leere gehen. Der Demo-Seed setzte
  # fuenf, die niemand liest, und liess vier ungesetzt, die gelesen
  # werden - sichtbar nur an leeren Feldern in der Demo.
  if ! out=$(php tools/check_settings_keys.php 2>&1); then
    echo "EINSTELLUNGEN: $out"
    fail=1
  fi
  if ! out=$(php tools/test_errors.php 2>&1); then
    echo "FEHLERSEITE: $out"
    fail=1
  fi
  # Zeitabrechnung: der gefaehrliche Fall ist die doppelte Rechnung.
  if ! out=$(php tools/test_time_billing.php 2>&1); then
    echo "ZEITABRECHNUNG: $out"
    fail=1
  fi
  # Rechnungspositionen und Summen - hier wird mit Geld gerechnet.
  if ! out=$(php tools/test_invoice_items.php 2>&1); then
    echo "POSITIONEN: $out"
    fail=1
  fi
  if ! out=$(php tools/test_file_access.php 2>&1); then
    echo "DATEIZUGRIFF: $out"
    fail=1
  fi
  if ! out=$(php tools/test_seed_demo.php 2>&1); then
    echo "SEED: $out"
    fail=1
  fi
  # Mahnstufen und wiederkehrende Eintraege. Die gefaehrlichen Faelle
  # sind hier das doppelte Verschicken und die Reihe, die sich bei jedem
  # Lauf verdoppelt.
  # Der Zahlungsabgleich. Der teure Fehler ist dort die falsch
  # zugeordnete Zahlung: die Rechnung steht auf bezahlt, die Mahnung
  # bleibt aus, und auffallen wuerde es zum Jahresabschluss.
  if ! out=$(php tools/test_payments.php 2>&1); then
    echo "ZAHLUNGEN: $out"
    fail=1
  fi
  # Die Datenauskunft. Eine, in der ein Bereich fehlt, sieht
  # vollstaendig aus - und eine mit fremden Daten ist selbst eine
  # Panne.
  if ! out=$(php tools/test_gdpr.php 2>&1); then
    echo "AUSKUNFT: $out"
    fail=1
  fi
  # Die Datensicherung. Sie wird an genau einem Tag gebraucht, und an
  # dem muss sie stimmen - der Test spielt den Abzug zurueck.
  if ! out=$(php tools/test_backup.php 2>&1); then
    echo "SICHERUNG: $out"
    fail=1
  fi
  # Der Verfall im Papierkorb. Er entfernt Zeilen und Dateien - beides
  # endgueltig, beides ohne Rueckfrage.
  if ! out=$(php tools/test_trash_retention.php 2>&1); then
    echo "PAPIERKORB: $out"
    fail=1
  fi
  if ! out=$(php tools/test_cron_billing.php 2>&1); then
    echo "MAHNUNG/WIEDERHOLUNG: $out"
    fail=1
  fi
  # Benutzer und Rollen. Zwei Stellen entscheiden ueber mehr als
  # Bequemlichkeit: eine Seite, die in der Rechteliste FEHLT, muss
  # gesperrt sein statt offen - und der letzte Verwalter darf sich nicht
  # selbst entfernen.
  if ! out=$(php tools/test_users.php 2>&1); then
    echo "BENUTZER/ROLLEN: $out"
    fail=1
  fi
  # Eingehende Mails. Die gefaehrlichste Stelle ist die Zuordnung: die
  # Kennung im Betreff kann jeder schreiben.
  if ! out=$(php tools/test_api_tickets.php 2>&1); then
    echo "MAIL->TICKET: $out"
    fail=1
  fi
  # Die elektronische Rechnung: wohlgeformt, Betraege stimmig,
  # Sonderzeichen maskiert. Ob ein konkreter Empfaenger die Datei
  # annimmt, prueft das NICHT - das gehoert vor dem ersten echten
  # Versand durch ein offizielles Werkzeug.
  if ! out=$(php tools/test_xrechnung.php 2>&1); then
    echo "XRECHNUNG: $out"
    fail=1
  fi
  # Der zweite Faktor. Die Rechnung wird gegen die Pruefvektoren aus
  # RFC 6238 geprueft - eine falsche Umsetzung, die zufaellig mit einer
  # App zusammenpasst, gaebe es sonst durchaus.
  if ! out=$(php tools/test_totp.php 2>&1); then
    echo "ZWEITER FAKTOR: $out"
    fail=1
  fi
  # Die Anfrage-Schnittstelle. Die heikelste Stelle ist das Zurueckweisen:
  # ohne eingerichteten Schluessel muss sie ZU sein, nicht offen.
  if ! out=$(php tools/test_api_leads.php 2>&1); then
    echo "SCHNITTSTELLE: $out"
    fail=1
  fi
  # Erreichbarkeit: die heikelste Stelle ist die Meldung. Sie muss genau
  # beim Zustandswechsel kommen - nicht bei jeder Messung, und nicht bei
  # der ersten, wo es keinen Vorzustand gibt.
  if ! out=$(php tools/test_uptime.php 2>&1); then
    echo "ERREICHBARKEIT: $out"
    fail=1
  fi
  # Angebot zu Projekt. Der Fall, an dem es still schiefginge, ist das
  # ZWEITE Projekt aus demselben Angebot.
  if ! out=$(php tools/test_quote_to_project.php 2>&1); then
    echo "ANGEBOT->PROJEKT: $out"
    fail=1
  fi
  # Mailprotokoll: das Kappen ueberlanger Werte und die eigene, laengere
  # Aufbewahrung sind die Stellen, an denen es still falsch wuerde.
  if ! out=$(php tools/test_mail_log.php 2>&1); then
    echo "MAILPROTOKOLL: $out"
    fail=1
  fi
  # Passwort-Zuruecksetzung. Die Zusagen sind hier alle Verneinungen:
  # ein Token gilt einmal, laeuft ab, und die Datenbank kennt nur seinen
  # Hash.
  if ! out=$(php tools/test_auth_reset.php 2>&1); then
    echo "PASSWORT-RESET: $out"
    fail=1
  fi
  # Belege: die Pfadschranke beim Loeschen ist hier die heikle Stelle -
  # die Funktion bekommt einen Pfad aus der Datenbank und entfernt damit
  # eine Datei.
  if ! out=$(php tools/test_receipts.php 2>&1); then
    echo "BELEGE: $out"
    fail=1
  fi
  # Auswertungen: Altersstufen, Zeitraumgrenzen, Stundensatzaufloesung.
  if ! out=$(php tools/test_reports.php 2>&1); then
    echo "AUSWERTUNG: $out"
    fail=1
  fi
  # Und die Auswertungsseite einmal wirklich rendern - php -l sieht
  # weder einen falschen Array-Schluessel im Markup noch ein max() auf
  # eine leere Liste, und im Browser waere beides ein weisses Fenster.
  if ! out=$(php tools/test_reports_render.php 2>&1); then
    echo "AUSWERTUNG (Darstellung): $out"
    fail=1
  fi
else
  echo "HINWEIS: php nicht gefunden - Demo-Pruefungen uebersprungen."
fi
[ "$fail" -eq 0 ] && echo "OK: alle Pruefungen bestanden."
exit "$fail"
