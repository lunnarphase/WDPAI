DROP VIEW IF EXISTS view_appointment_details CASCADE;
DROP VIEW IF EXISTS view_doctor_details CASCADE;
DROP TRIGGER IF EXISTS trg_review_request ON appointments;
DROP FUNCTION IF EXISTS notify_patient_review_request();
DROP TABLE IF EXISTS review_reports CASCADE;
DROP TABLE IF EXISTS reviews CASCADE;
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
    bio TEXT,
    visit_price DECIMAL(10,2),
    visit_duration INTEGER DEFAULT 30
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
    type VARCHAR(50) DEFAULT 'general',
    related_id INTEGER,
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
    cancel_comment TEXT,
    review_submitted BOOLEAN DEFAULT FALSE
);

-- Opinie pacjentów (jedna opinia na wizytę)
CREATE TABLE reviews (
    id SERIAL PRIMARY KEY,
    id_appointment INTEGER UNIQUE NOT NULL REFERENCES appointments(id) ON DELETE CASCADE,
    id_doctor INTEGER NOT NULL REFERENCES doctors(id) ON DELETE CASCADE,
    id_patient INTEGER NOT NULL REFERENCES patients(id) ON DELETE CASCADE,
    rating INTEGER NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Zgłoszenia opinii do moderacji
CREATE TABLE review_reports (
    id SERIAL PRIMARY KEY,
    id_review INTEGER NOT NULL REFERENCES reviews(id) ON DELETE CASCADE,
    id_reporter INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category VARCHAR(100) NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    admin_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

-- Profile lekarzy z cennikiem
INSERT INTO doctors (id_user, bio, visit_price, visit_duration) VALUES 
(2, 'Doświadczony kardiolog z 15-letnim stażem pracy w wiodących klinikach kardiologicznych. Specjalizuje się w diagnostyce i leczeniu chorób sercowo-naczyniowych.', 200.00, 30),
(3, 'Pediatra z wieloletnim doświadczeniem w opiece nad dziećmi od noworodka do 18. roku życia. Cierpliwy i empatyczny w pracy z małymi pacjentami.', 150.00, 30),
(4, 'Specjalista neurologii klinicznej z certyfikatami europejskiego towarzystwa neurologicznego. Zajmuje się m.in. bólami głowy, epilepsją i chorobami neurodegeneracyjnymi.', 250.00, 45);

-- Specjalizacje lekarzy
INSERT INTO doctors_specializations (id_doctor, id_specialization) VALUES 
(1, 1),
(2, 2),
(3, 3);
COMMIT;

-- Trigger: powiadomienie o prośbie o opinię po zakończeniu wizyty
CREATE OR REPLACE FUNCTION notify_patient_review_request()
RETURNS TRIGGER AS $$
DECLARE
    v_patient_user_id INTEGER;
    v_doctor_name VARCHAR;
BEGIN
    IF NEW.status = 'completed' AND (OLD.status IS NULL OR OLD.status != 'completed') THEN
        SELECT p.id_user INTO v_patient_user_id
        FROM patients p WHERE p.id = NEW.id_patient;

        SELECT u.username INTO v_doctor_name
        FROM doctors d JOIN users u ON d.id_user = u.id
        WHERE d.id = NEW.id_doctor;

        IF v_patient_user_id IS NOT NULL THEN
            INSERT INTO notifications (id_user, message, type, related_id)
            VALUES (
                v_patient_user_id,
                'Twoja wizyta u dr ' || v_doctor_name || ' dobiegła końca. Podziel się opinią i pomóż innym pacjentom wybrać odpowiedniego specjalistę.',
                'review_request',
                NEW.id
            );
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_review_request
AFTER UPDATE ON appointments
FOR EACH ROW
EXECUTE FUNCTION notify_patient_review_request();

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