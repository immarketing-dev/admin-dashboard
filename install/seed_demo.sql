-- Demo data. Import after schema.sql:
--   mysql -u USER -p DATABASE < install/seed_demo.sql
--
-- WARNING: this file hardcodes numeric ids - contact_id 1/2, task_id 1/2 -
-- on the assumption that it is loading into a freshly created, empty
-- database where AUTO_INCREMENT starts at 1. Only import it once, and
-- only right after schema.sql on a database that has no rows yet. If
-- rows already exist, the hardcoded ids will silently attach these
-- milestones/invoices/tickets to whatever unrelated contact or task
-- already happens to have that id, instead of failing loudly.
--
-- All names, companies and domains below are invented for demonstration
-- purposes only. Domains use the .example / .org / .net reserved ranges
-- (RFC 2606) and do not resolve to real sites.

SET NAMES utf8mb4;

INSERT INTO contacts (name, company, email, phone, website, street, zip, city, country, contact_type, source, notes) VALUES
  ('Anna Berger',  'Berger Design GmbH', 'anna@berger-design.example',  '+49 30 1234567', 'https://berger-design.example',  'Hauptstr. 1',  '10115', 'Berlin',  'Deutschland', 'Kunde',    'Empfehlung', 'Langjährige Kundin, bevorzugt Rückmeldung per E-Mail.'),
  ('Bruno Kaiser', 'Kaiser Logistik',    'bruno@kaiser-logistik.example','+49 40 7654321', 'https://kaiser-logistik.example','Am Hafen 12',  '20095', 'Hamburg', 'Deutschland', 'Kunde',    'Website',    'Rechnungen bitte an die Buchhaltung.'),
  ('Clara Vogt',   NULL,                 'clara.vogt@example.org',       NULL,             NULL,                             NULL,           NULL,    NULL,      NULL,          'Interessent','Messe',      'Erstgespräch steht noch aus.');

INSERT INTO tasks (title, category, description, status, contact_id, start_date, deadline) VALUES
  ('Relaunch Unternehmenswebsite', 'Webentwicklung', 'Neuaufbau der Website inklusive Redaktionssystem und Umzug.', 'In Bearbeitung', 1, CURRENT_DATE - INTERVAL 30 DAY, CURRENT_DATE + INTERVAL 14 DAY),
  ('Onlineshop-Anbindung',         'E-Commerce',     'Anbindung des Warenwirtschaftssystems an den Shop.',          'Offen',          2, CURRENT_DATE - INTERVAL 5 DAY,  CURRENT_DATE + INTERVAL 45 DAY);

INSERT INTO task_milestones (task_id, title, is_completed) VALUES
  (1, 'Konzept und Wireframes', 1),
  (1, 'Designentwurf',          1),
  (1, 'Umsetzung Frontend',     0),
  (1, 'Redaktionssystem',       0),
  (2, 'Schnittstelle spezifizieren', 0),
  (2, 'Testumgebung aufsetzen',      0);

INSERT INTO finances (type, title, contact_id, amount, status, record_date, due_date, notes, is_recurring) VALUES
  ('INCOME',  'Anzahlung Relaunch',    1, 2400.00, 'Bezahlt', CURRENT_DATE - INTERVAL 25 DAY, CURRENT_DATE - INTERVAL 11 DAY, 'Erste Rate von drei.', 0),
  ('INCOME',  'Zwischenrechnung',      1, 1800.00, 'Offen',   CURRENT_DATE - INTERVAL 3 DAY,  CURRENT_DATE + INTERVAL 11 DAY, NULL, 0),
  ('EXPENSE', 'Hosting Jahrespaket', NULL,  180.00, 'Bezahlt', CURRENT_DATE - INTERVAL 60 DAY, NULL, 'Läuft jährlich weiter.', 1);

INSERT INTO support_tickets (contact_id, subject, message, status, priority) VALUES
  (2, 'Kontaktformular verschickt keine E-Mails', 'Seit gestern kommen keine Anfragen mehr an.', 'Offen', 'Hoch');

INSERT INTO leads_inbox (name, email, phone, subject, message, source) VALUES
  ('Daniel Roth', 'daniel.roth@example.net', '+49 89 111222', 'Anfrage Landingpage', 'Wir bräuchten eine Landingpage für eine Kampagne im Frühjahr.', 'Kontaktformular');

INSERT INTO wiki_articles (title, content, category, tags, is_pinned) VALUES
  ('Deployment-Checkliste', '<p>Vor jedem Deployment abarbeiten:</p><ul><li>Datenbank sichern</li><li>Wartungsseite aktivieren</li><li>Cache leeren</li></ul>', 'Betrieb', 'deployment,checkliste', 1),
  ('Rechnungsnummern',      '<p>Format <code>RE-JJJJ-NNN</code>, fortlaufend je Kalenderjahr.</p>', 'Buchhaltung', 'rechnung', 0);

INSERT INTO monitored_urls (url_name, url_link) VALUES
  ('Beispielseite', 'https://example.com');
