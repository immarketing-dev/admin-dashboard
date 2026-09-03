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
CREDS="$CREDS|['\"]?($COMPOUND)['\"]?[[:space:]]*=>[[:space:]]*['\"][^'\"]+['\"]"
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

# --- 3. Unversionierte CDN-Referenzen -----------------------------------
cdn=$(grep -rn '@latest' . \
          --include='*.php' --include='*.ini' --include='*.yml' --include='*.yaml' --include='*.conf' \
          "${SCAN_EXCLUDES[@]}" 2>/dev/null)
if [ -n "$cdn" ]; then
  echo "CDN: unversionierte Referenz:"
  echo "$cdn"
  fail=1
fi

# --- 4. Dateien aus uploads/ --------------------------------------------
stray=$(find uploads -type f ! -name '.gitkeep' ! -name '.htaccess' 2>/dev/null)
if [ -n "$stray" ]; then
  echo "DATEN: Datei in uploads/ die nicht dorthin gehoert:"
  echo "$stray"
  fail=1
fi

[ "$fail" -eq 0 ] && echo "OK: alle Pruefungen bestanden."
exit "$fail"
