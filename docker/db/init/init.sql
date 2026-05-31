DROP VIEW IF EXISTS view_appointment_details CASCADE;
DROP VIEW IF EXISTS view_doctor_details CASCADE;
DROP TRIGGER IF EXISTS trg_review_request ON appointments;
DROP FUNCTION IF EXISTS notify_patient_review_request();
DROP TRIGGER IF EXISTS check_doctor_availability_trigger ON appointments;
DROP FUNCTION IF EXISTS check_doctor_availability_func();
DROP TABLE IF EXISTS blocked_ips CASCADE;
DROP TABLE IF EXISTS login_attempts CASCADE;
DROP TABLE IF EXISTS review_reports CASCADE;
DROP TABLE IF EXISTS reviews CASCADE;
DROP TABLE IF EXISTS appointments CASCADE;
DROP TABLE IF EXISTS doctor_schedule_templates CASCADE;
DROP TABLE IF EXISTS doctor_availability CASCADE;
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
    id_role INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    is_blocked BOOLEAN NOT NULL DEFAULT FALSE
);

-- Logi prób logowania (bezpieczeństwo)
CREATE TABLE login_attempts (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN NOT NULL DEFAULT FALSE
);
CREATE INDEX idx_login_attempts_email ON login_attempts(email);
CREATE INDEX idx_login_attempts_attempted_at ON login_attempts(attempted_at);

CREATE TABLE blocked_ips (
    ip_address VARCHAR(45) PRIMARY KEY,
    blocked_until TIMESTAMP,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_blocked_ips_blocked_until ON blocked_ips(blocked_until);

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
CREATE INDEX idx_notifications_user_created_at ON notifications(id_user, created_at DESC);
CREATE INDEX idx_notifications_user_type_created_at ON notifications(id_user, type, created_at DESC);
CREATE INDEX idx_notifications_user_is_read ON notifications(id_user, is_read);

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

-- Dostępność lekarza (zakresy godzinowe na konkretny dzień)
CREATE TABLE doctor_availability (
    id SERIAL PRIMARY KEY,
    id_doctor INTEGER NOT NULL REFERENCES doctors(id) ON DELETE CASCADE,
    date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL
);

-- Szablony tygodniowe lekarza
CREATE TABLE doctor_schedule_templates (
    id SERIAL PRIMARY KEY,
    id_doctor INTEGER NOT NULL REFERENCES doctors(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL
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
INSERT INTO specializations (name) VALUES ('Kardiologia'), ('Pediatria'), ('Neurologia'), ('Medycyna rodzinna');

-- Konta startowe
INSERT INTO users (email, password, username, id_role) VALUES 
('admin@medischedule.pl', '$2y$10$j.lupyyTuXSgcq5xb5T/feHdywl8umdI0uSFSeBjtqyg9cQP89NuO', 'Administrator', 1),
('kardiolog@medischedule.pl', '$2y$10$rQACxK30Fpmy187xgd93Aug9yEfJHTNugz0z6In4VP1cRou19MYKO', 'Jan Kowalski', 2),
('pediatra@medischedule.pl', '$2y$10$ugYop9lkO49FmaEqsbiMv.HcWuhVIOR.PYP38aWyQLZAngM9g2iaO', 'Anna Nowak', 2),
('neurolog@medischedule.pl', '$2y$10$g9diWoBBPfztRbmyc7BTguI3odHgcajNX534c6OIr4kyxTDLHltPa', 'Piotr Wisniewski', 2),
('rodzinny@medischedule.pl', '$2y$10$v3hz5XIPRiuqXcbPSQqb9OGgVCNhccv8ZGPqp3sus8iFu6IL3ZY6G', 'Marek Zielinski', 2);

-- Profile lekarzy z cennikiem
INSERT INTO doctors (id_user, bio, visit_price, visit_duration) VALUES 
(2, 'Specjalista kardiologii z wieloletnim doswiadczeniem klinicznym.', 220.00, 30),
(3, 'Pediatra prowadzacy konsultacje dzieci od wieku niemowlecego.', 180.00, 30),
(4, 'Neurolog zajmujacy sie diagnostyka i leczeniem schorzen ukladu nerwowego.', 240.00, 45),
(5, 'Lekarz medycyny rodzinnej prowadzacy kompleksowa opieke nad pacjentami.', 170.00, 30);

-- Specjalizacje lekarzy
INSERT INTO doctors_specializations (id_doctor, id_specialization) VALUES 
(1, 1),
(2, 2),
(3, 3),
(4, 4);
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