DROP VIEW IF EXISTS view_doctor_details CASCADE;
DROP TABLE IF EXISTS appointments CASCADE;
DROP TABLE IF EXISTS doctors_specializations CASCADE;
DROP TABLE IF EXISTS doctors CASCADE;
DROP TABLE IF EXISTS patients CASCADE;
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

-- Harmonogram i Wizyty (Relacja 1:N)
CREATE TABLE appointments (
    id SERIAL PRIMARY KEY,
    id_patient INTEGER REFERENCES patients(id) ON DELETE CASCADE,
    id_doctor INTEGER REFERENCES doctors(id) ON DELETE CASCADE,
    appointment_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status VARCHAR(20) DEFAULT 'Oczekująca'
);

INSERT INTO roles (name) VALUES ('admin'), ('doctor'), ('patient');
INSERT INTO specializations (name) VALUES ('Kardiologia'), ('Pediatria'), ('Neurologia');

CREATE VIEW view_doctor_details AS
SELECT u.username as doctor_name, s.name as specialization
FROM doctors d
JOIN users u ON d.id_user = u.id
JOIN doctors_specializations ds ON d.id = ds.id_doctor
JOIN specializations s ON ds.id_specialization = s.id;