DROP VIEW IF EXISTS view_doctor_details CASCADE;
DROP TABLE IF EXISTS appointments CASCADE;
DROP TABLE IF EXISTS doctors_specializations CASCADE;
DROP TABLE IF EXISTS doctors CASCADE;
DROP TABLE IF EXISTS patients CASCADE;
DROP TABLE IF EXISTS notifications CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS specializations CASCADE;
DROP TABLE IF EXISTS roles CASCADE;

-- Tabele słownikowe (3NF)
CREATE TABLE roles (
    id SERIAL PRIMARY KEY,
    name VARCHAR(20) NOT NULL
);

CREATE TABLE specializations (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

-- Główna tabela użytkowników
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    username VARCHAR(100) NOT NULL,
    id_role INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE
);

-- Profile szczegółowe (Relacja 1:1)
CREATE TABLE patients (
    id SERIAL PRIMARY KEY,
    id_user INTEGER UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    pesel CHAR(11) UNIQUE NOT NULL,
    phone VARCHAR(20)
);

CREATE TABLE doctors (
    id SERIAL PRIMARY KEY,
    id_user INTEGER UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    bio TEXT
);

-- Relacja N:M (Lekarze i ich specjalizacje)
CREATE TABLE doctors_specializations (
    id_doctor INTEGER REFERENCES doctors(id) ON DELETE CASCADE,
    id_specialization INTEGER REFERENCES specializations(id) ON DELETE CASCADE,
    PRIMARY KEY (id_doctor, id_specialization)
);

CREATE TABLE notifications (
    id SERIAL PRIMARY KEY,
    id_user INTEGER REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Harmonogram i Wizyty (Relacja 1:N)
CREATE TABLE appointments (
    id SERIAL PRIMARY KEY,
    id_patient INTEGER REFERENCES patients(id) ON DELETE CASCADE,
    id_doctor INTEGER REFERENCES doctors(id) ON DELETE CASCADE,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    status VARCHAR(50) DEFAULT 'confirmed',
    recommendations TEXT,
    cancel_reason TEXT,
    cancel_comment TEXT
);

BEGIN;
INSERT INTO roles (name) VALUES ('admin'), ('doctor'), ('patient');
INSERT INTO specializations (name) VALUES ('Kardiologia'), ('Pediatria'), ('Neurologia');

-- Hasło dla wszystkich kont to: admin
INSERT INTO users (email, password, username, id_role) VALUES 
('admin@admin', '$2y$10$//rDsSveI/6Kkva/05WJY..EoeseX9MSzipriOKtwNMc1LXTyyEyG', 'Admin', 1),
('kowalski@med.pl', '$2y$10$//rDsSveI/6Kkva/05WJY..EoeseX9MSzipriOKtwNMc1LXTyyEyG', 'Jan Kowalski', 2),
('nowak@med.pl', '$2y$10$//rDsSveI/6Kkva/05WJY..EoeseX9MSzipriOKtwNMc1LXTyyEyG', 'Anna Nowak', 2),
('wisniewski@med.pl', '$2y$10$//rDsSveI/6Kkva/05WJY..EoeseX9MSzipriOKtwNMc1LXTyyEyG', 'Piotr Wiśniewski', 2);

-- Profile lekarzy (powiązanie z users.id od 2 do 4)
INSERT INTO doctors (id_user, bio) VALUES 
(2, 'Doświadczony kardiolog.'),
(3, 'Świetny pediatra.'),
(4, 'Specjalista neurologii.');

-- Specjalizacje lekarzy
-- Kardiologia (id 1) dla Kowalskiego (doctor.id 1)
-- Pediatria (id 2) dla Nowak (doctor.id 2)
-- Neurologia (id 3) dla Wiśniewskiego (doctor.id 3)
INSERT INTO doctors_specializations (id_doctor, id_specialization) VALUES 
(1, 1),
(2, 2),
(3, 3);
COMMIT;

CREATE VIEW view_doctor_details AS
SELECT u.username as doctor_name, s.name as specialization
FROM doctors d
JOIN users u ON d.id_user = u.id
JOIN doctors_specializations ds ON d.id = ds.id_doctor
JOIN specializations s ON ds.id_specialization = s.id;

CREATE VIEW view_appointment_details AS
SELECT 
    a.id as appointment_id,
    a.appointment_date,
    a.appointment_time,
    a.status,
    p_u.username as patient_name,
    d_u.username as doctor_name
FROM appointments a
JOIN patients p ON a.id_patient = p.id
JOIN users p_u ON p.id_user = p_u.id
JOIN doctors d ON a.id_doctor = d.id
JOIN users d_u ON d.id_user = d_u.id;

CREATE OR REPLACE FUNCTION check_doctor_availability_func()
RETURNS TRIGGER AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM appointments
        WHERE id_doctor = NEW.id_doctor
          AND appointment_date = NEW.appointment_date
          AND appointment_time = NEW.appointment_time
          AND status != 'cancelled'
          AND id != COALESCE(NEW.id, -1)
    ) THEN
        RAISE EXCEPTION 'Termin koliduje z inną wizytą dla tego lekarza.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER check_doctor_availability_trigger
BEFORE INSERT OR UPDATE ON appointments
FOR EACH ROW
EXECUTE FUNCTION check_doctor_availability_func();