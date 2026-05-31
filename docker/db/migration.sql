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
