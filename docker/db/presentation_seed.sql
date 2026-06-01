-- Presentation data seed for MediSchedule
-- This script is idempotent for demo users/accounts and can be re-run safely.

BEGIN;

-- Ensure required specializations exist for additional demo doctors.
INSERT INTO specializations (name)
VALUES
    ('Dermatologia'),
    ('Ortopedia')
ON CONFLICT (name) DO NOTHING;

-- Upsert demo admins and doctors with known presentation passwords.
WITH role_map AS (
    SELECT id, name FROM roles
),
seed_users AS (
    SELECT * FROM (VALUES
        ('admin@medischedule.pl', '$2y$10$jHhsgH/T6Hz1PwHJH2D.7.t7cpiKy9Q0ddq5gbJp5l/O/yLc4Og4i', 'Administrator', 'admin'),
        ('admin2@medischedule.pl', '$2y$10$jHhsgH/T6Hz1PwHJH2D.7.t7cpiKy9Q0ddq5gbJp5l/O/yLc4Og4i', 'Paulina Maj', 'admin'),

        ('kardiolog@medischedule.pl', '$2y$10$MqUWw./sPR/FcuOLw0AV4.tpzgyoqDNETmloeX708FC1nKj1ETp2i', 'Jan Kowalski', 'doctor'),
        ('pediatra@medischedule.pl', '$2y$10$MqUWw./sPR/FcuOLw0AV4.tpzgyoqDNETmloeX708FC1nKj1ETp2i', 'Anna Nowak', 'doctor'),
        ('neurolog@medischedule.pl', '$2y$10$MqUWw./sPR/FcuOLw0AV4.tpzgyoqDNETmloeX708FC1nKj1ETp2i', 'Piotr Wisniewski', 'doctor'),
        ('rodzinny@medischedule.pl', '$2y$10$MqUWw./sPR/FcuOLw0AV4.tpzgyoqDNETmloeX708FC1nKj1ETp2i', 'Marek Zielinski', 'doctor'),
        ('ewa.dermatolog@medischedule.pl', '$2y$10$MqUWw./sPR/FcuOLw0AV4.tpzgyoqDNETmloeX708FC1nKj1ETp2i', 'Ewa Lewandowska', 'doctor'),
        ('tomasz.ortopeda@medischedule.pl', '$2y$10$MqUWw./sPR/FcuOLw0AV4.tpzgyoqDNETmloeX708FC1nKj1ETp2i', 'Tomasz Wojcik', 'doctor')
    ) AS t(email, pass_hash, username, role_name)
)
INSERT INTO users (email, password, username, id_role, is_blocked)
SELECT su.email, su.pass_hash, su.username, rm.id, FALSE
FROM seed_users su
JOIN role_map rm ON rm.name = su.role_name
ON CONFLICT (email) DO UPDATE
SET
    password = EXCLUDED.password,
    username = EXCLUDED.username,
    id_role = EXCLUDED.id_role,
    is_blocked = FALSE;

-- Upsert doctor profiles.
WITH doctor_profile_seed AS (
    SELECT * FROM (VALUES
        ('kardiolog@medischedule.pl', 'Kardiolog z doswiadczeniem klinicznym i profilaktyka chorob serca.', 220.00::DECIMAL(10,2), 30),
        ('pediatra@medischedule.pl', 'Pediatra prowadzacy konsultacje dzieci i mlodziezy.', 180.00::DECIMAL(10,2), 30),
        ('neurolog@medischedule.pl', 'Neurolog zajmujacy sie migrenami i diagnostyka ukladu nerwowego.', 240.00::DECIMAL(10,2), 45),
        ('rodzinny@medischedule.pl', 'Lekarz rodzinny prowadzacy kompleksowa opieke doroslych pacjentow.', 170.00::DECIMAL(10,2), 30),
        ('ewa.dermatolog@medischedule.pl', 'Dermatolog konsultujacy zmiany skorne i terapie miejscowe.', 210.00::DECIMAL(10,2), 30),
        ('tomasz.ortopeda@medischedule.pl', 'Ortopeda specjalizujacy sie w urazach i bolach stawow.', 230.00::DECIMAL(10,2), 30)
    ) AS t(email, bio, visit_price, visit_duration)
),
resolved AS (
    SELECT u.id AS user_id, dps.bio, dps.visit_price, dps.visit_duration
    FROM doctor_profile_seed dps
    JOIN users u ON u.email = dps.email
)
INSERT INTO doctors (id_user, bio, visit_price, visit_duration)
SELECT r.user_id, r.bio, r.visit_price, r.visit_duration
FROM resolved r
LEFT JOIN doctors d ON d.id_user = r.user_id
WHERE d.id IS NULL;

WITH doctor_profile_seed AS (
    SELECT * FROM (VALUES
        ('kardiolog@medischedule.pl', 'Kardiolog z doswiadczeniem klinicznym i profilaktyka chorob serca.', 220.00::DECIMAL(10,2), 30),
        ('pediatra@medischedule.pl', 'Pediatra prowadzacy konsultacje dzieci i mlodziezy.', 180.00::DECIMAL(10,2), 30),
        ('neurolog@medischedule.pl', 'Neurolog zajmujacy sie migrenami i diagnostyka ukladu nerwowego.', 240.00::DECIMAL(10,2), 45),
        ('rodzinny@medischedule.pl', 'Lekarz rodzinny prowadzacy kompleksowa opieke doroslych pacjentow.', 170.00::DECIMAL(10,2), 30),
        ('ewa.dermatolog@medischedule.pl', 'Dermatolog konsultujacy zmiany skorne i terapie miejscowe.', 210.00::DECIMAL(10,2), 30),
        ('tomasz.ortopeda@medischedule.pl', 'Ortopeda specjalizujacy sie w urazach i bolach stawow.', 230.00::DECIMAL(10,2), 30)
    ) AS t(email, bio, visit_price, visit_duration)
)
UPDATE doctors d
SET
    bio = dps.bio,
    visit_price = dps.visit_price,
    visit_duration = dps.visit_duration
FROM doctor_profile_seed dps
JOIN users u ON u.email = dps.email
WHERE d.id_user = u.id;

-- Reset and map doctor specializations for seeded doctors.
DELETE FROM doctors_specializations ds
USING doctors d, users u
WHERE ds.id_doctor = d.id
  AND d.id_user = u.id
  AND u.email IN (
      'kardiolog@medischedule.pl',
      'pediatra@medischedule.pl',
      'neurolog@medischedule.pl',
      'rodzinny@medischedule.pl',
      'ewa.dermatolog@medischedule.pl',
      'tomasz.ortopeda@medischedule.pl'
  );

WITH doctor_spec_seed AS (
    SELECT * FROM (VALUES
        ('kardiolog@medischedule.pl', 'Kardiologia'),
        ('pediatra@medischedule.pl', 'Pediatria'),
        ('neurolog@medischedule.pl', 'Neurologia'),
        ('rodzinny@medischedule.pl', 'Medycyna rodzinna'),
        ('ewa.dermatolog@medischedule.pl', 'Dermatologia'),
        ('tomasz.ortopeda@medischedule.pl', 'Ortopedia')
    ) AS t(email, spec_name)
)
INSERT INTO doctors_specializations (id_doctor, id_specialization)
SELECT d.id, s.id
FROM doctor_spec_seed dss
JOIN users u ON u.email = dss.email
JOIN doctors d ON d.id_user = u.id
JOIN specializations s ON s.name = dss.spec_name
ON CONFLICT DO NOTHING;

-- Demo patients table.
CREATE TEMP TABLE demo_patients_seed (
    seq INT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    pesel CHAR(11) NOT NULL
) ON COMMIT DROP;

INSERT INTO demo_patients_seed (seq, full_name, email, pesel)
VALUES
    (1,  'Adam Kaczmarek',      'demo.patient.01@medischedule.pl', '90000000001'),
    (2,  'Marta Wysocka',       'demo.patient.02@medischedule.pl', '90000000002'),
    (3,  'Krzysztof Zawadzki',  'demo.patient.03@medischedule.pl', '90000000003'),
    (4,  'Monika Czarnecka',    'demo.patient.04@medischedule.pl', '90000000004'),
    (5,  'Lukasz Pawlak',       'demo.patient.05@medischedule.pl', '90000000005'),
    (6,  'Natalia Dudek',       'demo.patient.06@medischedule.pl', '90000000006'),
    (7,  'Michal Walczak',      'demo.patient.07@medischedule.pl', '90000000007'),
    (8,  'Joanna Szymanska',    'demo.patient.08@medischedule.pl', '90000000008'),
    (9,  'Piotr Borkowski',     'demo.patient.09@medischedule.pl', '90000000009'),
    (10, 'Karolina Lis',        'demo.patient.10@medischedule.pl', '90000000010'),
    (11, 'Tomasz Mazur',        'demo.patient.11@medischedule.pl', '90000000011'),
    (12, 'Agnieszka Krol',      'demo.patient.12@medischedule.pl', '90000000012'),
    (13, 'Damian Wieczorek',    'demo.patient.13@medischedule.pl', '90000000013'),
    (14, 'Weronika Baran',      'demo.patient.14@medischedule.pl', '90000000014'),
    (15, 'Patryk Sikora',       'demo.patient.15@medischedule.pl', '90000000015'),
    (16, 'Emilia Ostrowska',    'demo.patient.16@medischedule.pl', '90000000016'),
    (17, 'Mateusz Chmielewski', 'demo.patient.17@medischedule.pl', '90000000017'),
    (18, 'Paulina Glowacka',    'demo.patient.18@medischedule.pl', '90000000018'),
    (19, 'Bartosz Kubiak',      'demo.patient.19@medischedule.pl', '90000000019'),
    (20, 'Aleksandra Piatek',   'demo.patient.20@medischedule.pl', '90000000020'),
    (21, 'Rafal Sobczak',       'demo.patient.21@medischedule.pl', '90000000021'),
    (22, 'Klaudia Jastrzebska', 'demo.patient.22@medischedule.pl', '90000000022'),
    (23, 'Szymon Urban',        'demo.patient.23@medischedule.pl', '90000000023'),
    (24, 'Magdalena Kurek',     'demo.patient.24@medischedule.pl', '90000000024'),
    (25, 'Filip Krawczyk',      'demo.patient.25@medischedule.pl', '90000000025'),
    (26, 'Gabriela Stepien',    'demo.patient.26@medischedule.pl', '90000000026'),
    (27, 'Adrian Olszewski',    'demo.patient.27@medischedule.pl', '90000000027'),
    (28, 'Kinga Pietrzak',      'demo.patient.28@medischedule.pl', '90000000028'),
    (29, 'Sebastian Borowski',  'demo.patient.29@medischedule.pl', '90000000029'),
    (30, 'Julia Kaminska',      'demo.patient.30@medischedule.pl', '90000000030'),
    (31, 'Marcin Witkowski',    'demo.patient.31@medischedule.pl', '90000000031'),
    (32, 'Dominika Michalska',  'demo.patient.32@medischedule.pl', '90000000032');

-- Upsert patient user accounts.
WITH patient_role AS (
    SELECT id FROM roles WHERE name = 'patient'
)
INSERT INTO users (email, password, username, id_role, is_blocked)
SELECT
    dps.email,
    '$2y$10$EpoBLS2ujDXqNuRBUZ.B/.lb9RjCyk5RmOPrMANke7XSXcoYP..jm',
    dps.full_name,
    pr.id,
    FALSE
FROM demo_patients_seed dps
CROSS JOIN patient_role pr
ON CONFLICT (email) DO UPDATE
SET
    password = EXCLUDED.password,
    username = EXCLUDED.username,
    id_role = EXCLUDED.id_role,
    is_blocked = FALSE;

-- Ensure patient profile rows exist and keep PESEL deterministic.
INSERT INTO patients (id_user, pesel, phone)
SELECT u.id, dps.pesel, NULL
FROM demo_patients_seed dps
JOIN users u ON u.email = dps.email
LEFT JOIN patients p ON p.id_user = u.id
WHERE p.id IS NULL;

UPDATE patients p
SET pesel = dps.pesel
FROM demo_patients_seed dps
JOIN users u ON u.email = dps.email
WHERE p.id_user = u.id;

-- Remove previously generated demo appointments so script remains re-runnable.
DELETE FROM appointments a
USING patients p, users u
WHERE a.id_patient = p.id
  AND p.id_user = u.id
  AND u.email LIKE 'demo.patient.%@medischedule.pl';

-- Build appointment matrix for first 24 demo patients: completed, completed, confirmed, noshow/cancelled.
WITH seeded_doctors AS (
    SELECT
        ROW_NUMBER() OVER (ORDER BY d.id) AS rn,
        d.id AS doctor_id,
        COALESCE(s.name, 'Medycyna rodzinna') AS specialization
    FROM doctors d
    JOIN users u ON u.id = d.id_user
    LEFT JOIN doctors_specializations ds ON ds.id_doctor = d.id
    LEFT JOIN specializations s ON s.id = ds.id_specialization
    WHERE u.email IN (
        'kardiolog@medischedule.pl',
        'pediatra@medischedule.pl',
        'neurolog@medischedule.pl',
        'rodzinny@medischedule.pl',
        'ewa.dermatolog@medischedule.pl',
        'tomasz.ortopeda@medischedule.pl'
    )
),
doctor_count AS (
    SELECT COUNT(*)::INT AS total FROM seeded_doctors
),
seeded_patients AS (
    SELECT
        dps.seq,
        p.id AS patient_id
    FROM demo_patients_seed dps
    JOIN users u ON u.email = dps.email
    JOIN patients p ON p.id_user = u.id
),
appt_seed AS (
    SELECT
        sp.seq,
        sp.patient_id,
        d1.doctor_id AS doctor_primary,
        d1.specialization AS spec_primary,
        d2.doctor_id AS doctor_secondary,
        d2.specialization AS spec_secondary
    FROM seeded_patients sp
    CROSS JOIN doctor_count dc
    JOIN seeded_doctors d1 ON d1.rn = ((sp.seq - 1) % dc.total) + 1
    JOIN seeded_doctors d2 ON d2.rn = (sp.seq % dc.total) + 1
)
INSERT INTO appointments (
    id_patient,
    id_doctor,
    appointment_date,
    appointment_time,
    status,
    recommendations,
    cancel_reason,
    cancel_comment,
    review_submitted
)
SELECT
    patient_id,
    doctor_primary,
    CURRENT_DATE - (20 + seq),
    make_time(8 + (seq % 6), CASE WHEN seq % 2 = 0 THEN 30 ELSE 0 END, 0),
    'completed',
    CASE spec_primary
        WHEN 'Kardiologia' THEN 'Kontrola cisnienia 2x dziennie i ograniczenie soli.'
        WHEN 'Pediatria' THEN 'Nawadnianie i kontrola temperatury przez 3 dni.'
        WHEN 'Neurologia' THEN 'Regularny sen i dziennik objawow do kolejnej wizyty.'
        WHEN 'Medycyna rodzinna' THEN 'Morfologia i lipidogram kontrolnie za miesiac.'
        WHEN 'Dermatologia' THEN 'Emolient 2x dziennie i unikanie drazniacych kosmetykow.'
        WHEN 'Ortopedia' THEN 'Odciazenie stawu i zimne oklady 2x dziennie.'
        ELSE 'Kontrola stanu zdrowia zgodnie z zaleceniami lekarza.'
    END,
    NULL,
    NULL,
    FALSE
FROM appt_seed
WHERE seq <= 24

UNION ALL

SELECT
    patient_id,
    doctor_secondary,
    CURRENT_DATE - (60 + seq),
    make_time(10 + (seq % 4), CASE WHEN seq % 2 = 0 THEN 0 ELSE 30 END, 0),
    'completed',
    CASE spec_secondary
        WHEN 'Kardiologia' THEN 'Spacery minimum 30 minut dziennie, kontynuacja terapii.'
        WHEN 'Pediatria' THEN 'Stopniowy powrot do aktywnosci, kontrola za tydzien.'
        WHEN 'Neurologia' THEN 'Ograniczyc stres i utrzymac stale pory snu.'
        WHEN 'Medycyna rodzinna' THEN 'Powtorzyc badania krwi i monitorowac samopoczucie.'
        WHEN 'Dermatologia' THEN 'Kontynuowac preparat miejscowy przez 14 dni.'
        WHEN 'Ortopedia' THEN 'Delikatna rehabilitacja i kontrola bolu po wysilku.'
        ELSE 'Stosowac sie do zaleconego planu leczenia.'
    END,
    NULL,
    NULL,
    FALSE
FROM appt_seed
WHERE seq <= 24

UNION ALL

SELECT
    patient_id,
    doctor_primary,
    CURRENT_DATE + (5 + seq),
    make_time(9 + (seq % 5), CASE WHEN seq % 2 = 0 THEN 0 ELSE 30 END, 0),
    'confirmed',
    NULL,
    NULL,
    NULL,
    FALSE
FROM appt_seed
WHERE seq <= 24

UNION ALL

SELECT
    patient_id,
    doctor_secondary,
    CASE WHEN seq % 4 = 0 THEN CURRENT_DATE + (20 + seq) ELSE CURRENT_DATE - (7 + seq) END,
    make_time(13 + (seq % 4), CASE WHEN seq % 2 = 0 THEN 30 ELSE 0 END, 0),
    CASE WHEN seq % 4 = 0 THEN 'cancelled' ELSE 'noshow' END,
    NULL,
    CASE WHEN seq % 4 = 0 THEN 'konflikt terminow' ELSE NULL END,
    CASE WHEN seq % 4 = 0 THEN 'Zmiana planow sluzbowych.' ELSE NULL END,
    FALSE
FROM appt_seed
WHERE seq <= 24;

-- Additional upcoming appointments for remaining demo patients (25-32).
WITH seeded_doctors AS (
    SELECT
        ROW_NUMBER() OVER (ORDER BY d.id) AS rn,
        d.id AS doctor_id
    FROM doctors d
    JOIN users u ON u.id = d.id_user
    WHERE u.email IN (
        'kardiolog@medischedule.pl',
        'pediatra@medischedule.pl',
        'neurolog@medischedule.pl',
        'rodzinny@medischedule.pl',
        'ewa.dermatolog@medischedule.pl',
        'tomasz.ortopeda@medischedule.pl'
    )
),
doctor_count AS (
    SELECT COUNT(*)::INT AS total FROM seeded_doctors
),
seeded_patients AS (
    SELECT
        dps.seq,
        p.id AS patient_id
    FROM demo_patients_seed dps
    JOIN users u ON u.email = dps.email
    JOIN patients p ON p.id_user = u.id
    WHERE dps.seq > 24
)
INSERT INTO appointments (
    id_patient,
    id_doctor,
    appointment_date,
    appointment_time,
    status,
    recommendations,
    cancel_reason,
    cancel_comment,
    review_submitted
)
SELECT
    sp.patient_id,
    d.doctor_id,
    CURRENT_DATE + (40 + sp.seq),
    make_time(10 + (sp.seq % 4), CASE WHEN sp.seq % 2 = 0 THEN 0 ELSE 30 END, 0),
    'confirmed',
    NULL,
    NULL,
    NULL,
    FALSE
FROM seeded_patients sp
CROSS JOIN doctor_count dc
JOIN seeded_doctors d ON d.rn = ((sp.seq - 1) % dc.total) + 1;

-- Create reviews only for completed visits from selected patients, keeping app logic consistent.
WITH selected_demo_reviews AS (
    SELECT
        a.id AS appointment_id,
        a.id_doctor,
        a.id_patient,
        dps.seq,
        ROW_NUMBER() OVER (PARTITION BY a.id_patient ORDER BY a.appointment_date DESC, a.appointment_time DESC) AS rn
    FROM appointments a
    JOIN patients p ON p.id = a.id_patient
    JOIN users u ON u.id = p.id_user
    JOIN demo_patients_seed dps ON dps.email = u.email
    WHERE a.status = 'completed'
      AND dps.seq <= 18
),
rows_for_review AS (
    SELECT appointment_id, id_doctor, id_patient, seq
    FROM selected_demo_reviews
    WHERE rn = 1
)
INSERT INTO reviews (id_appointment, id_doctor, id_patient, rating, comment)
SELECT
    rfr.appointment_id,
    rfr.id_doctor,
    rfr.id_patient,
    ((rfr.seq % 5) + 1),
    CASE ((rfr.seq % 5) + 1)
        WHEN 5 THEN 'Bardzo rzeczowa konsultacja i jasne zalecenia.'
        WHEN 4 THEN 'Wizyta przebiegla sprawnie, czuje poprawe.'
        WHEN 3 THEN 'Pomocna konsultacja, nadal obserwuje objawy.'
        WHEN 2 THEN 'Podejscie poprawne, leczenie pomoglo czesciowo.'
        ELSE 'Objawy utrzymuja sie, rozwazam druga opinie.'
    END
FROM rows_for_review rfr;

UPDATE appointments a
SET review_submitted = TRUE
FROM reviews r
WHERE r.id_appointment = a.id;

COMMIT;
