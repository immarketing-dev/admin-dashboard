-- Admin Dashboard - database schema
-- Import:  mysql -u USER -p DATABASE < install/schema.sql
--
-- Engine InnoDB, charset utf8mb4 throughout.
--
-- Minimum server version: MySQL 5.7.8 / MariaDB 10.2.7. Both are hard
-- requirements, not recommendations: the "quotes" table uses the JSON
-- column type, which older servers reject outright, and "wiki_articles"
-- declares two TIMESTAMP columns that both default to CURRENT_TIMESTAMP
-- (one of them also ON UPDATE CURRENT_TIMESTAMP) - MySQL before 5.6.5
-- allows only one such column per table and this statement will fail
-- to import on it.
--
-- 21 tables total: 14 reconstructed from the columns the application
-- queries actually use (see docs/ for background), plus 7 that were
-- already created ad hoc by the application code and are reproduced
-- here verbatim (only "IF NOT EXISTS" and the ENGINE/CHARSET/COLLATE
-- clause were added): quotes, milestone_comments, ticket_notes,
-- wiki_client_shares, calendar_events, event_contacts, sso_tokens.
--
-- Foreign keys and pre-existing installations: this schema declares
-- FOREIGN KEY constraints (with ON DELETE CASCADE / SET NULL) that the
-- live private installation never had, because its tables were created
-- ad hoc without any. That is a deliberate choice, not an oversight -
-- a public product should ship a schema that enforces its own
-- integrity, and the cascade/null-out behaviour below is what the
-- application's own logic already assumes. The practical consequence
-- is a behavioural difference by install history:
--   * Fresh install (this file): deleting a contact sets
--     tasks.contact_id / finances.contact_id / support_tickets.contact_id
--     to NULL; deleting a task cascades away its task_milestones,
--     client_assets and time_entries; deleting a wiki_articles row
--     cascades away its wiki_attachments.
--   * Existing database imported before this schema existed: none of
--     that happens automatically - deleting a contact or task leaves
--     the child rows in place (orphaned, but harmless, since every
--     JOIN in the application already filters rows whose parent is
--     missing).
-- Adding these constraints to an existing database afterwards is
-- optional and NOT done by this file. Doing so requires first finding
-- and cleaning up any orphan rows the ad hoc tables have accumulated
-- (e.g. task_milestones/client_assets/time_entries rows whose task_id
-- no longer exists in tasks) - ALTER TABLE ... ADD CONSTRAINT ... FOREIGN
-- KEY will otherwise fail with an error naming the first offending row.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -- Core ---------------------------------------------------------------

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(100) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  -- Der zweite Faktor (TOTP, RFC 6238). Das Geheimnis steht als Base32
  -- da, weil Authenticator-Apps es so erwarten.
  --
  -- totp_confirmed_at ist bewusst getrennt: eingerichtet ist nicht
  -- dasselbe wie bestaetigt. Erst ein eingetippter Code beweist, dass
  -- die App das Geheimnis wirklich hat - griffe die Einrichtung sofort,
  -- sperrte ein Fehler beim Abscannen den Benutzer aus.
  totp_secret       VARCHAR(64) DEFAULT NULL,
  totp_confirmed_at DATETIME DEFAULT NULL,
  -- Name, Rolle und Zustand. 'admin' als Standard, damit eine Zeile aus
  -- der Zeit vor Version 18 nicht ohne Rolle dasteht: eine Installation,
  -- die bis dahin mit einem Konto lief, hatte genau diese.
  --
  -- Drei Rollen und keine frei zusammenstellbare Rechtematrix: die waere
  -- fuer ein Werkzeug dieser Groesse zu viel Apparat, und in der Praxis
  -- stellt sie niemand um. Was sie duerfen, steht in
  -- includes/users.php - eine Seite, die dort fehlt, ist gesperrt statt
  -- versehentlich offen.
  name          VARCHAR(255) NOT NULL DEFAULT '',
  role          VARCHAR(20)  NOT NULL DEFAULT 'admin',
  -- Abschalten statt loeschen: an einem Benutzer haengen Protokoll-
  -- eintraege und erfasste Zeiten.
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ersatzcodes fuer den zweiten Faktor. Kein Beiwerk: ein zweiter
-- Faktor, der beim Verlust des Telefons aussperrt, tauscht ein
-- Aussperrungsproblem gegen ein anderes.
--
-- Gehasht wie Passwoerter, weil sie welche sind - acht Zeichen aus
-- einem 31er-Alphabet liessen sich gegen einen schnellen Hash
-- durchprobieren.
CREATE TABLE IF NOT EXISTS totp_backup_codes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  code_hash  VARCHAR(255) NOT NULL,
  used_at    DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_backup_user (user_id, used_at),
  CONSTRAINT fk_backup_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  action_type VARCHAR(50) NOT NULL,
  description TEXT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip          VARCHAR(45) DEFAULT NULL,
  -- Wer war es? Bis Version 18 liess sich die Frage nicht stellen.
  -- ON DELETE SET NULL: ein geloeschter Benutzer nimmt seine Spuren
  -- nicht mit.
  user_id     INT DEFAULT NULL,
  KEY idx_logs_type_created (action_type, created_at),
  -- Fuer auth_is_locked()/auth_note_lockout(): exakter Spaltenvergleich
  -- auf ip statt LIKE auf description, das sich mit einer praeparierten
  -- E-Mail-Adresse im Login-Formular vergiften liesse.
  KEY idx_logs_lockout (action_type, ip, created_at),
  CONSTRAINT fk_logs_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taken verbatim from d:\Downloads\admin-dashboard\sso.php:7
-- (only IF NOT EXISTS and the engine clause were added).
CREATE TABLE IF NOT EXISTS sso_tokens (
  token      CHAR(64) PRIMARY KEY,
  used       TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Weg zurueck ins eigene Panel. Vorher gab es keinen: wer sein
-- Passwort verlor, brauchte Zugriff auf die Datenbank.
--
-- Gespeichert wird der HASH des Tokens, nicht das Token selbst. Wer die
-- Datenbank liest - ein Backup, ein Auszug, eine Einschleusung - haette
-- sonst einen gueltigen Anmeldeweg in der Hand. Ein einfaches SHA-256
-- genuegt hier, anders als beim Passwort: das Token ist selbst schon
-- 256 Bit Zufall und damit nicht zu raten.
CREATE TABLE IF NOT EXISTS password_resets (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reset_token (token_hash),
  KEY idx_reset_user (user_id, used_at),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Was das Panel verschickt hat. Neun Sorten Mail gehen hinaus, und
-- nirgends stand hinterher, was wann an wen ging und ob der Server es
-- angenommen hat.
--
-- Bewusst nicht in logs: dort steht ein Satz Freitext ohne Empfaenger,
-- ohne Betreff und ohne Ergebnis, und die Tabelle wird nach
-- log_retention_days geleert. Ein Versandnachweis wird Monate spaeter
-- gebraucht - siehe MAIL_LOG_MIN_TAGE in includes/mail_log.php.
CREATE TABLE IF NOT EXISTS mail_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  -- Schluessel aus mail_templates(), oder ein eigener Name fuer die
  -- wenigen Mails ohne Vorlage.
  template   VARCHAR(50)  NOT NULL DEFAULT '',
  recipient  VARCHAR(255) NOT NULL,
  subject    VARCHAR(255) NOT NULL DEFAULT '',
  -- 'sent' oder 'failed'.
  status     VARCHAR(20)  NOT NULL DEFAULT 'sent',
  error      TEXT,
  -- Woran die Mail hing: "Angebot ANG-2026-003", "Ticket #14".
  context    VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mail_created (created_at),
  KEY idx_mail_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- CRM ----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS contacts (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  name                    VARCHAR(255) NOT NULL,
  company                 VARCHAR(255) DEFAULT NULL,
  email                   VARCHAR(255) DEFAULT NULL,
  phone                   VARCHAR(50)  DEFAULT NULL,
  website                 VARCHAR(255) DEFAULT NULL,
  street                  VARCHAR(255) DEFAULT NULL,
  zip                     VARCHAR(20)  DEFAULT NULL,
  city                    VARCHAR(120) DEFAULT NULL,
  country                 VARCHAR(120) DEFAULT NULL,
  contact_type            VARCHAR(50)  NOT NULL DEFAULT 'Kunde',
  source                  VARCHAR(100) DEFAULT NULL,
  notes                   TEXT,
  portal_token            CHAR(64)     DEFAULT NULL,
  portal_pin              VARCHAR(255) DEFAULT NULL,
  portal_pin_attempts     TINYINT UNSIGNED DEFAULT 0,
  portal_pin_locked_until DATETIME     DEFAULT NULL,
  -- Stundensatz dieses Kunden. Ein Projekt darf ihn ueberschreiben.
  hourly_rate             DECIMAL(10,2) DEFAULT NULL,
  -- Umsatzsteuer-Identifikationsnummer. Ein PDF braucht sie nicht, eine
  -- elektronische Rechnung zwischen Unternehmen schon.
  vat_id                  VARCHAR(30)  DEFAULT NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at     DATETIME DEFAULT NULL,
  UNIQUE KEY uq_contacts_portal_token (portal_token),
  KEY idx_contacts_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads_inbox (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(255) NOT NULL,
  email      VARCHAR(255) DEFAULT NULL,
  phone      VARCHAR(50)  DEFAULT NULL,
  subject    VARCHAR(255) DEFAULT NULL,
  message    TEXT,
  source     VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- Projects -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS tasks (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  title            VARCHAR(255) NOT NULL,
  category         VARCHAR(100) DEFAULT NULL,
  description      TEXT,
  status           ENUM('Offen','In Bearbeitung','Erledigt','Storniert')
                     NOT NULL DEFAULT 'Offen',
  contact_id       INT DEFAULT NULL,
  start_date       DATE DEFAULT NULL,
  deadline         DATE DEFAULT NULL,
  client_feedback  TEXT,
  feedback_seen    TINYINT(1) NOT NULL DEFAULT 0,
  is_timer_running TINYINT(1) NOT NULL DEFAULT 0,
  timer_start      DATETIME DEFAULT NULL,
  -- Stundensatz dieses Projekts. Hat Vorrang vor dem des Kunden.
  hourly_rate      DECIMAL(10,2) DEFAULT NULL,
  -- Wer ist zustaendig? tasks.contact_id ist der Kunde, das hier die
  -- eigene Person.
  assigned_user_id INT DEFAULT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tasks_contact (contact_id),
  KEY idx_tasks_status  (status),
  CONSTRAINT fk_tasks_contact FOREIGN KEY (contact_id)
    REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_user FOREIGN KEY (assigned_user_id)
    REFERENCES users(id) ON DELETE SET NULL,
  deleted_at     DATETIME DEFAULT NULL,
  feedback_by_contact_id INT DEFAULT NULL,
  feedback_by_name VARCHAR(255) NOT NULL DEFAULT '',
  feedback_at      DATETIME DEFAULT NULL,
  KEY idx_tasks_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_milestones (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  task_id       INT NOT NULL,
  title         VARCHAR(255) NOT NULL,
  is_completed  TINYINT(1) NOT NULL DEFAULT 0,
  approved_at   DATETIME DEFAULT NULL,
  approval_seen TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ms_task (task_id),
  CONSTRAINT fk_ms_task FOREIGN KEY (task_id)
    REFERENCES tasks(id) ON DELETE CASCADE,
  waiting_on   VARCHAR(20) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taken verbatim from d:\Downloads\admin-dashboard\portal.php:31
-- (base table) plus column admin_seen, which the same private
-- installation later added via d:\Downloads\admin-dashboard\index.php:272.
-- Only IF NOT EXISTS and the engine clause were added.
CREATE TABLE IF NOT EXISTS task_contacts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  task_id    INT NOT NULL,
  contact_id INT NOT NULL,
  role       VARCHAR(20) NOT NULL DEFAULT 'member',
  added_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_task_contact (task_id, contact_id),
  KEY idx_tc_contact (contact_id),
  CONSTRAINT fk_tc_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tc_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS milestone_comments (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  milestone_id INT NOT NULL,
  author       VARCHAR(20) NOT NULL DEFAULT 'client',
  admin_seen   TINYINT(1) NOT NULL DEFAULT 0,
  author_name  VARCHAR(255),
  message      TEXT NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ms (milestone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: the application code (index.php, tasks.php, portal.php) always
-- orders by "uploaded_at", never "created_at" - the column is named
-- uploaded_at here to match actual usage, not "created_at" as a naive
-- reconstruction from the INSERT column list alone would suggest.
CREATE TABLE IF NOT EXISTS project_comments (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  task_id           INT NOT NULL,
  author_contact_id INT DEFAULT NULL,
  author_name       VARCHAR(255) NOT NULL,
  message           TEXT NOT NULL,
  admin_seen        TINYINT(1) NOT NULL DEFAULT 0,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pc_task (task_id, created_at),
  CONSTRAINT fk_pc_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_contact FOREIGN KEY (author_contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_assets (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  task_id        INT NOT NULL,
  file_name      VARCHAR(255) NOT NULL,
  file_path      VARCHAR(255) NOT NULL,
  dashboard_seen TINYINT(1)  NOT NULL DEFAULT 0,
  uploaded_by    VARCHAR(50) DEFAULT 'client',
  uploaded_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assets_task (task_id),
  CONSTRAINT fk_assets_task FOREIGN KEY (task_id)
    REFERENCES tasks(id) ON DELETE CASCADE,
  uploaded_by_contact_id INT DEFAULT NULL,
  uploaded_by_name VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_entries (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  task_id          INT NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 0,
  note             VARCHAR(255) DEFAULT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Gesetzt, sobald die Zeit auf einer Rechnung steht. Ohne dieses
  -- Kennzeichen liesse sich dieselbe Stunde zweimal abrechnen.
  billed_at        DATETIME DEFAULT NULL,
  invoice_id       INT DEFAULT NULL,
  -- Erfasste Zeit gehoert jemandem. Ohne das ist der Stundenzettel eine
  -- Summe ohne Urheber, und aus "erfasste Zeit" wird nie eine
  -- Auslastung.
  user_id          INT DEFAULT NULL,
  KEY idx_time_task (task_id),
  KEY idx_time_user (user_id, created_at),
  KEY idx_time_unbilled (task_id, billed_at),
  CONSTRAINT fk_time_invoice FOREIGN KEY (invoice_id)
    REFERENCES finances(id) ON DELETE SET NULL,
  CONSTRAINT fk_time_task FOREIGN KEY (task_id)
    REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- Finance ---------------------------------------------------------------

CREATE TABLE IF NOT EXISTS finances (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  type             ENUM('INCOME','EXPENSE') NOT NULL DEFAULT 'INCOME',
  title            VARCHAR(255) NOT NULL,
  contact_id       INT DEFAULT NULL,
  custom_name      VARCHAR(255) DEFAULT NULL,
  amount           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status           VARCHAR(50) NOT NULL DEFAULT 'Offen',
  record_date      DATE DEFAULT NULL,
  due_date         DATE DEFAULT NULL,
  notes            TEXT,
  invoice_number   VARCHAR(50) DEFAULT NULL,
  invoice_pdf_path VARCHAR(255) DEFAULT NULL,
  is_recurring     TINYINT(1) NOT NULL DEFAULT 0,
  -- Die Aufstellung der Rechnung, im selben Format wie quotes.items:
  -- [{"desc":…,"qty":…,"price":…,"unit":…}]. NULL bei Eintraegen ohne
  -- Positionen - eine Ausgabe hat keine, und Rechnungen aus der Zeit vor
  -- Schemaversion 8 haben ihre nur im PDF.
  items            JSON DEFAULT NULL,
  tax_type         VARCHAR(30) NOT NULL DEFAULT 'kleinunternehmer',
  net_amount       DECIMAL(10,2) DEFAULT NULL,
  tax_amount       DECIMAL(10,2) DEFAULT NULL,
  -- Zahlungserinnerungen. Der Zaehler steht hier und nicht als
  -- abgeleitete Groesse in den Logs, weil die Logs nach
  -- log_retention_days geleert werden - die Mahnstufe einer Rechnung
  -- darf nicht davon abhaengen, wie lange das Protokoll aufgehoben wird.
  reminder_count   INT NOT NULL DEFAULT 0,
  last_reminder_at DATETIME DEFAULT NULL,
  -- Wiederkehrende Eintraege. is_recurring oben bleibt das Etikett
  -- ("Fixkosten", Filter und CSV haengen daran); recurrence ist das,
  -- was tatsaechlich etwas erzeugt: '', 'monthly', 'quarterly',
  -- 'yearly'. next_run ist der naechste faellige Termin, gesetzt nur
  -- auf der Vorlage. recurring_parent_id zeigt von der erzeugten
  -- Rechnung zurueck auf die Vorlage.
  recurrence       VARCHAR(20) NOT NULL DEFAULT '',
  next_run         DATE DEFAULT NULL,
  recurring_parent_id INT DEFAULT NULL,
  -- Der Beleg zu einer Ausgabe. Bewusst eine eigene Spalte neben
  -- invoice_pdf_path: das eine ist die selbst erzeugte Ausgangsrechnung,
  -- jederzeit neu erzeugbar, das andere ein fremdes Dokument, das es nur
  -- einmal gibt. Vermengt wuerde ein Beleg beim Neuerzeugen eines
  -- Rechnungs-PDFs ueberschrieben.
  receipt_path     VARCHAR(255) DEFAULT NULL,
  -- Die Kaeufer-Referenz. Bei oeffentlichen Auftraggebern die
  -- Leitweg-ID, sonst die Bestellnummer des Kunden. Fuer das PDF
  -- entbehrlich, fuer eine XRechnung nicht: ohne sie weist die Pruefung
  -- die Datei ab.
  buyer_reference  VARCHAR(80) DEFAULT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fin_contact (contact_id),
  KEY idx_fin_type_date (type, record_date),
  KEY idx_fin_next_run (next_run, recurrence),
  UNIQUE KEY uq_fin_invoice_number (invoice_number),
  CONSTRAINT fk_fin_contact FOREIGN KEY (contact_id)
    REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_fin_recurring_parent FOREIGN KEY (recurring_parent_id)
    REFERENCES finances(id) ON DELETE SET NULL,
  deleted_at     DATETIME DEFAULT NULL,
  KEY idx_finances_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taken verbatim from d:\Downloads\admin-dashboard\quotes.php:20
-- (only IF NOT EXISTS and the engine clause were added).
CREATE TABLE IF NOT EXISTS quotes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  quote_number   VARCHAR(50) NOT NULL,
  subject        VARCHAR(255) NOT NULL DEFAULT '',
  intro_text     TEXT,
  contact_id     INT NULL,
  custom_name    VARCHAR(255),
  status         VARCHAR(50) NOT NULL DEFAULT 'Entwurf',
  tax_type       VARCHAR(30) NOT NULL DEFAULT 'kleinunternehmer',
  items          JSON NOT NULL,
  notes          TEXT,
  total_amount   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  valid_until    DATE NULL,
  quote_pdf_path VARCHAR(255),
  -- Das Projekt, das aus diesem Angebot entstanden ist. Verhindert das
  -- versehentliche zweite: sonst stuende dieselbe Arbeit doppelt in der
  -- Liste, und beide Haelften waeren halb gepflegt. ON DELETE SET NULL,
  -- damit sich das Angebot nach dem Loeschen des Projekts erneut
  -- umwandeln laesst.
  converted_task_id INT DEFAULT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  deleted_at     DATETIME DEFAULT NULL,
  KEY idx_quotes_deleted (deleted_at),
  CONSTRAINT fk_quotes_task FOREIGN KEY (converted_task_id)
    REFERENCES tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- Support -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS support_tickets (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  contact_id INT DEFAULT NULL,
  subject    VARCHAR(255) NOT NULL,
  message    TEXT,
  status     VARCHAR(50) NOT NULL DEFAULT 'Offen',
  priority   ENUM('Niedrig','Mittel','Hoch','Kritisch')
               NOT NULL DEFAULT 'Mittel',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tickets_contact (contact_id),
  KEY idx_tickets_status  (status),
  CONSTRAINT fk_tickets_contact FOREIGN KEY (contact_id)
    REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taken verbatim from d:\Downloads\admin-dashboard\tickets.php:12
-- (base table) plus columns author, is_public, which the same private
-- installation later added via d:\Downloads\admin-dashboard\portal.php:45.
-- Only IF NOT EXISTS and the engine clause were added.
CREATE TABLE IF NOT EXISTS ticket_notes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT NOT NULL,
  note       TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  author     VARCHAR(20) NOT NULL DEFAULT 'admin',
  is_public  TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_tid (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- Wiki ----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS wiki_articles (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(255) NOT NULL,
  content    LONGTEXT,
  category   VARCHAR(100) DEFAULT NULL,
  tags       VARCHAR(255) DEFAULT NULL,
  is_pinned  TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
               ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_wiki_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: the application code (wiki.php, portal.php) always orders by
-- "uploaded_at", never "created_at" - named uploaded_at here to match
-- actual usage, not "created_at" as a naive reconstruction from the
-- INSERT column list alone would suggest.
CREATE TABLE IF NOT EXISTS wiki_attachments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  article_id INT NOT NULL,
  file_name  VARCHAR(255) NOT NULL,
  file_path  VARCHAR(255) NOT NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_watt_article (article_id),
  CONSTRAINT fk_watt_article FOREIGN KEY (article_id)
    REFERENCES wiki_articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taken verbatim from d:\Downloads\admin-dashboard\wiki.php:11
-- (only IF NOT EXISTS and the engine clause were added).
CREATE TABLE IF NOT EXISTS wiki_client_shares (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  article_id INT NOT NULL,
  contact_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_share (article_id, contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Der Verlauf des Monitors. Vorher wurde bei jedem Dashboardaufruf
-- gemessen und das Ergebnis weggeworfen: keine Quote, kein Verlauf, und
-- vor allem keine Moeglichkeit, einen Ausfall zu MELDEN - dafuer braucht
-- es den Vergleich mit der vorigen Messung.
--
-- ON DELETE CASCADE: wird eine Adresse aus der Ueberwachung genommen,
-- hat ihr Verlauf keinen Adressaten mehr.
CREATE TABLE IF NOT EXISTS url_checks (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  url_id      INT NOT NULL,
  -- 'online', 'slow' oder 'offline'. Drei Stufen, weil zwei zu wenig
  -- sind: eine Seite, die nach vier Sekunden antwortet, ist nicht
  -- "online" im Sinne von "in Ordnung".
  status      VARCHAR(10) NOT NULL DEFAULT 'online',
  http_code   INT NOT NULL DEFAULT 0,
  response_ms INT NOT NULL DEFAULT 0,
  error       VARCHAR(255) DEFAULT NULL,
  checked_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Auf (url_id, id): die letzte Messung je Adresse wird ueber die
  -- hoechste Kennung gesucht, nicht ueber den Zeitstempel - ein
  -- Cron-Lauf misst mehrere Adressen in derselben Sekunde.
  KEY idx_checks_url (url_id, id),
  KEY idx_checks_time (checked_at),
  CONSTRAINT fk_checks_url FOREIGN KEY (url_id)
    REFERENCES monitored_urls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- Calendar --------------------------------------------------------------

-- Taken verbatim from d:\Downloads\admin-dashboard\calendar.php:26
-- (only the engine clause was added; IF NOT EXISTS was already present).
CREATE TABLE IF NOT EXISTS calendar_events (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  title        VARCHAR(255) NOT NULL,
  description  TEXT,
  location     VARCHAR(255) DEFAULT '',
  meeting_url  VARCHAR(500) DEFAULT '',
  event_date   DATE NOT NULL,
  start_time   TIME DEFAULT NULL,
  end_time     TIME DEFAULT NULL,
  category     VARCHAR(50) DEFAULT 'Termin',
  color        VARCHAR(20) DEFAULT '#4a90d9',
  status       VARCHAR(30) DEFAULT 'Geplant',
  ics_uid      VARCHAR(150) DEFAULT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taken verbatim from d:\Downloads\admin-dashboard\calendar.php:47
-- (only the engine clause was added; IF NOT EXISTS was already present).
CREATE TABLE IF NOT EXISTS event_contacts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  event_id     INT NOT NULL,
  contact_id   INT NOT NULL,
  invite_token VARCHAR(64) DEFAULT NULL,
  invited_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_evt_con (event_id, contact_id),
  -- uk_token and idx_token both index invite_token - a genuine
  -- duplicate, verbatim from the original source (calendar.php:47) and
  -- deliberately kept rather than "cleaned up" here, since this table
  -- must stay byte-for-byte identical to what the live installation
  -- already runs. MySQL will emit a duplicate-index warning on import;
  -- that warning is expected and harmless.
  UNIQUE KEY uk_token (invite_token),
  INDEX idx_token (invite_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- Monitoring -----------------------------------------------------------

CREATE TABLE IF NOT EXISTS monitored_urls (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  url_name   VARCHAR(255) NOT NULL,
  url_link   VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A fresh installation is by definition already at the current schema
-- version. Without this row, run_migrations() would try to migrate
-- from version 0 onward on the very first page load and run ALTER
-- TABLE statements against columns/indexes that already exist - each
-- one an error-log line. This value must match SCHEMA_VERSION in
-- includes/migrations.php.
INSERT INTO settings (k, v) VALUES ('schema_version', '18')
  ON DUPLICATE KEY UPDATE v = VALUES(v);

SET foreign_key_checks = 1;
