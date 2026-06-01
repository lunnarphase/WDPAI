-- Migration: add is_blocked column and login_attempts table
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_blocked BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS login_attempts (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN NOT NULL DEFAULT FALSE
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_email ON login_attempts(email);
CREATE INDEX IF NOT EXISTS idx_login_attempts_attempted_at ON login_attempts(attempted_at);

CREATE TABLE IF NOT EXISTS blocked_ips (
    ip_address VARCHAR(45) PRIMARY KEY,
    blocked_until TIMESTAMP,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_blocked_ips_blocked_until ON blocked_ips(blocked_until);
CREATE INDEX IF NOT EXISTS idx_notifications_user_created_at ON notifications(id_user, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_user_type_created_at ON notifications(id_user, type, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_user_is_read ON notifications(id_user, is_read);
CREATE UNIQUE INDEX IF NOT EXISTS uq_appointments_active_slot
ON appointments (id_doctor, appointment_date, appointment_time)
WHERE status <> 'cancelled';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM roles GROUP BY name HAVING COUNT(*) > 1) THEN
        CREATE UNIQUE INDEX IF NOT EXISTS uq_roles_name ON roles(name);
    ELSE
        RAISE NOTICE 'Skipping uq_roles_name creation due to duplicated role names.';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM specializations GROUP BY name HAVING COUNT(*) > 1) THEN
        CREATE UNIQUE INDEX IF NOT EXISTS uq_specializations_name ON specializations(name);
    ELSE
        RAISE NOTICE 'Skipping uq_specializations_name creation due to duplicated specialization names.';
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_users_email_format') THEN
        ALTER TABLE users
            ADD CONSTRAINT chk_users_email_format
            CHECK (position('@' in email) > 1) NOT VALID;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_users_username_len') THEN
        ALTER TABLE users
            ADD CONSTRAINT chk_users_username_len
            CHECK (char_length(trim(username)) >= 3) NOT VALID;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_patients_pesel_digits') THEN
        ALTER TABLE patients
            ADD CONSTRAINT chk_patients_pesel_digits
            CHECK (pesel ~ '^[0-9]{11}$') NOT VALID;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_doctors_visit_price_non_negative') THEN
        ALTER TABLE doctors
            ADD CONSTRAINT chk_doctors_visit_price_non_negative
            CHECK (visit_price IS NULL OR visit_price >= 0) NOT VALID;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_doctors_visit_duration_range') THEN
        ALTER TABLE doctors
            ADD CONSTRAINT chk_doctors_visit_duration_range
            CHECK (visit_duration BETWEEN 10 AND 240) NOT VALID;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_notifications_type_nonempty') THEN
        ALTER TABLE notifications
            ADD CONSTRAINT chk_notifications_type_nonempty
            CHECK (char_length(trim(type)) > 0) NOT VALID;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_appointments_status_values') THEN
        ALTER TABLE appointments
            ADD CONSTRAINT chk_appointments_status_values
            CHECK (status IN ('confirmed', 'completed', 'cancelled', 'noshow')) NOT VALID;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_doctor_availability_time_order') THEN
        ALTER TABLE doctor_availability
            ADD CONSTRAINT chk_doctor_availability_time_order
            CHECK (start_time < end_time) NOT VALID;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_doctor_schedule_templates_name_nonempty') THEN
        ALTER TABLE doctor_schedule_templates
            ADD CONSTRAINT chk_doctor_schedule_templates_name_nonempty
            CHECK (char_length(trim(name)) > 0) NOT VALID;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_doctor_schedule_templates_time_order') THEN
        ALTER TABLE doctor_schedule_templates
            ADD CONSTRAINT chk_doctor_schedule_templates_time_order
            CHECK (start_time < end_time) NOT VALID;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_review_reports_status_values') THEN
        ALTER TABLE review_reports
            ADD CONSTRAINT chk_review_reports_status_values
            CHECK (status IN ('pending', 'dismissed', 'resolved')) NOT VALID;
    END IF;
END $$;

-- Migration: add trigger for review requests
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
                'Twoja wizyta u dr ' || v_doctor_name || ' dobie gla konca. Podziel sie opinia i pomoz innym pacjentom wybrac odpowiedniego specjaliste.',
                'review_request',
                NEW.id
            );
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_review_request ON appointments;
CREATE TRIGGER trg_review_request
AFTER UPDATE ON appointments
FOR EACH ROW
EXECUTE FUNCTION notify_patient_review_request();
